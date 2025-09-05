<?php
// src/Services/QueryService.php
declare(strict_types=1);

namespace App\Services;

use RuntimeException;
use App\Llm\OllamaClient;
use App\Llm\OpenAIClient;
use App\Llm\AnthropicClient;
use App\Llm\AbstractLlmClient;

class QueryService
{
    private array $appCfg;
    private array $businessRules;
    private array $fieldGuide;
    private array $schema;
    private array $allowedTables;

    /** @var AbstractLlmClient */
    private AbstractLlmClient $llm;

    public function __construct(array $appCfg, array $businessRules, array $fieldGuide, array $schema)
    {
        $this->appCfg = $appCfg;
        $this->businessRules = $businessRules;
        $this->fieldGuide = $fieldGuide;
        $this->schema = $schema;
        
        // Handle both old and new schema formats
        $this->allowedTables = $this->extractAllowedTables($schema);

        // build default LLM from config
        $this->llm = $this->makeLlmClient($this->appCfg, null, null);
    }

    /** Extract allowed tables from schema (supports both old and new formats) */
    private function extractAllowedTables(array $schema): array
    {
        // New format
        if (isset($schema['tables']) && is_array($schema['tables'])) {
            $tables = array_keys($schema['tables']);
            
            // Filter out views if needed (optional)
            if (isset($schema['version']) && version_compare($schema['version'], '2.0', '>=')) {
                return array_filter($tables, function($table) {
                    $tableInfo = $this->schema['tables'][$table] ?? [];
                    return ($tableInfo['type'] ?? 'base table') !== 'view';
                });
            }
            
            return $tables;
        }
        
        // Old format fallback
        return array_keys($schema['tables'] ?? $schema['views'] ?? []);
    }

    /** Per-request override (provider/model) */
    public function overrideLlm(?string $provider, ?string $model): void
    {
        if ($provider || $model) {
            $this->llm = $this->makeLlmClient($this->appCfg, $provider ? strtolower($provider) : null, $model);
        }
    }

    public function getLlmIdentity(): array
    {
        return $this->llm->identity();
    }

    /** Your requested private factory */
    private function makeLlmClient(array $appCfg, ?string $provider = null, ?string $model = null): AbstractLlmClient
    {
        $cfg = $appCfg['llm'];
        $provider = $provider ?: $cfg['provider'];
        $p = $cfg['providers'][$provider] ?? null;
        if (!$p) {
            throw new RuntimeException("Unsupported LLM provider: {$provider}");
        }
        $model = $model ?: ($p['model'] ?? null);
        $timeout = (int)($p['timeout'] ?? 30);

        return match ($provider) {
            'ollama'    => new OllamaClient($p['base_url'], $model, $timeout),
            'openai'    => new OpenAIClient($p['api_key'] ?? '', $model, $p['base_url'] ?? 'https://api.openai.com', $timeout),
            'anthropic' => new AnthropicClient($p['api_key'] ?? '', $model, $p['base_url'] ?? 'https://api.anthropic.com', $timeout),
            default     => throw new RuntimeException("Unknown provider: {$provider}"),
        };
    }

    public function processQuery(string $query): array
    {
        $startTime = microtime(true);

        // Early validation using global business rules
        $this->validateQueryAgainstBusinessRules($query);

        try {

            $intentAnalysis = $this->detectQueryIntentWithBusinessRules($query);

            if (!is_array($intentAnalysis) || !isset($intentAnalysis['type']) || !isset($intentAnalysis['intents'])) {
                $intentAnalysis = ['type' => 'single', 'intents' => ['general']];
            }

            $intentType = $intentAnalysis['type'];
            $intents = $intentAnalysis['intents'];
            
            // Check if query was flagged as low domain relevance
            if (isset($intentAnalysis['domain_relevance']) && $intentAnalysis['domain_relevance'] === 'low') {
                $issues = $intentAnalysis['issues'] ?? ['unrelated_to_domain'];
                throw new RuntimeException('Query appears unrelated to laboratory/medical domain: ' . implode(', ', $issues));
            }
        } catch (\Throwable $e) {
            $intentType = 'single';
            $intents = ['general'];
            $intentAnalysis = ['type' => $intentType, 'intents' => $intents];
        }

        // UPDATED: Table selection with business rules
        $tablesToUse = $this->selectRelevantTablesWithBusinessRules($query, $intentType);

        $context = $this->buildPromptContext($intentType, $tablesToUse, $query);
        $rawSql = $this->callLLM($context, $query);

        $finalSql = $this->validateSql($rawSql);
        $actualTablesUsed = $this->extractTablesFromSQL($finalSql);

        return [
            'sql' => $finalSql,
            'raw_sql' => $rawSql,
            'intent' => $intentType,
            'intent_details' => $intents,
            'intent_debug' => $intentAnalysis,
            'tables_selected' => $tablesToUse,
            'tables_used' => $actualTablesUsed,
            'context' => $context,
            'processing_time_ms' => round((microtime(true) - $startTime) * 1000)
        ];
    }

    // Early validation using global business rules
    private function validateQueryAgainstBusinessRules(string $query): void
    {
        $validationRules = $this->businessRules['validation_rules'] ?? [];
        
        // Check for reject patterns (SQL injection, admin operations, etc.)
        $rejectPatterns = $validationRules['reject_patterns'] ?? [];
        foreach ($rejectPatterns as $pattern) {
            if (preg_match($pattern, $query)) {
                throw new RuntimeException('Query contains disallowed operations');
            }
        }
        
        // Check for overly broad queries
        $queryLower = strtolower(trim($query));
        $broadPatterns = [
            '/^(show|list|get|select)?\s*(all|everything|\*)\s*$/i',
            '/^(dump|export)\s/i',
            '/^(select\s+\*|all\s+data)/i'
        ];
        
        foreach ($broadPatterns as $pattern) {
            if (preg_match($pattern, $queryLower)) {
                throw new RuntimeException('Query is too broad - please be more specific about what data you need');
            }
        }
    }

    
    private function detectQueryIntentWithBusinessRules(string $query): array
    {
        $globalRules = $this->businessRules['global_rules'] ?? [];
        $defaultAssumptions = $globalRules['default_assumptions']['rules'] ?? [];
        $scopeLimits = $globalRules['query_scope_limits']['rules'] ?? [];
        
        $businessContext = "BUSINESS CONTEXT:\n";
        $businessContext .= "Default Assumptions:\n";
        foreach ($defaultAssumptions as $assumption) {
            $businessContext .= "- $assumption\n";
        }
        $businessContext .= "\nQuery Scope Guidelines:\n";
        foreach ($scopeLimits as $limit) {
            $businessContext .= "- $limit\n";
        }

        $intentPrompt = <<<PROMPT
You are analyzing a medical laboratory database query. Consider the business context when determining intent.

$businessContext

Analyze the query to determine type, intents, and domain relevance. Respond ONLY with valid JSON.

Examples:
Query: "How many VL tests?"
Response: {"type": "single", "intents": ["count"], "domain_relevance": "high", "assumptions": ["defaulting_to_vl"]}

Query: "Show me all data"  
Response: {"type": "single", "intents": ["list"], "domain_relevance": "low", "issues": ["too_broad"], "suggested_refinement": "Please specify which test type or specific data you need"}

Query: "What's the weather today?"
Response: {"type": "single", "intents": ["general"], "domain_relevance": "low", "issues": ["unrelated_to_domain"], "suggested_refinement": "This system is for laboratory data queries"}

Query: {$query}

Response:
PROMPT;

        try {
            $raw = $this->llm->generateJson($intentPrompt, 200);
            $raw = rtrim($raw);
            if (substr($raw, -1) !== '}') {
                $raw .= '}';
            }
            $parsed = json_decode($raw, true);
            
            if (json_last_error() === JSON_ERROR_NONE && isset($parsed['type'])) {
                return $parsed;
            }
        } catch (\Throwable $e) {
            // Log for debugging if needed
        }

        // Fallback to regex-based detection
        return $this->fallbackIntentDetection($query);
    }

    private function fallbackIntentDetection(string $query): array
    {
        $q = strtolower($query);
        $intents = [];
        $type = 'single';

        if (preg_match('/\b(how many|count|number of|total)\b/', $q)) {
            $intents[] = 'count';
        }
        if (preg_match('/\b(list|show|display|all|get)\b/', $q)) {
            $intents[] = 'list';
        }
        if (preg_match('/\b(average|mean|sum|max|min)\b/', $q)) {
            $intents[] = 'aggregate';
        }

        $multiPartRegex = '/\b(how many|count|number of).*\b(and|how many|what is|how much)\b/i';
        if (preg_match($multiPartRegex, $q) || count($intents) > 1) {
            $type = 'multi_part';
        }

        return [
            'type' => $type,
            'intents' => !empty($intents) ? $intents : ['general'],
            'domain_relevance' => 'medium', // Conservative fallback
            'method' => 'regex_fallback'
        ];
    }

    // Table selection with business rules
    private function selectRelevantTablesWithBusinessRules(string $query, string $intentType): array
    {
        $qLower = strtolower($query);
        $selectedTables = [];

        // table patterns with test type logic from field guide
        $testTypeLogic = $this->fieldGuide['test_type_logic'] ?? [];
        
        $tableGroups = [
            'vl|viral load|hiv|hiv vl' => [$testTypeLogic['vl']['table'] ?? 'form_vl'],
            'covid|coronavirus|covid19|covid-19' => [$testTypeLogic['covid19']['table'] ?? 'form_covid19'],
            'eid|infant|early infant diagnosis' => [$testTypeLogic['eid']['table'] ?? 'form_eid'],
            'tb|tuberculosis' => ['form_tb'],
            'hepatitis|hep' => ['form_hepatitis'],
            'facility|facilities|clinic|lab' => ['facility_details'],
            'batch|batches' => ['batch_details'],
            'user|users|staff' => ['user_details'],
        ];

        foreach ($tableGroups as $pattern => $tables) {
            if (preg_match("/\b($pattern)\b/i", $qLower)) {
                $selectedTables = array_merge($selectedTables, $tables);
            }
        }

        $selectedTables = array_unique($selectedTables);
        $selectedTables = array_intersect($selectedTables, $this->allowedTables);

        // Apply business rule: default to VL if ambiguous
        if (empty($selectedTables)) {
            if (preg_match('/\b(patient|test|sample)\b/i', $qLower)) {
                $selectedTables = [$testTypeLogic['vl']['table'] ?? 'form_vl'];
            } else {
                $selectedTables = ['facility_details'];
            }
        }

        // Apply business rule: limit tables per query
        $maxTables = $this->businessRules['validation_rules']['scope_limits']['max_tables_per_query'] ?? 3;
        return array_slice($selectedTables, 0, $maxTables);
    }

    // Build context with new structure
    private function buildPromptContext(string $intent, array $tablesToUse, string $query): array
    {
        $schemaInfo = $this->buildSchemaInfo($tablesToUse);
        $relationshipsInfo = $this->buildRelationshipsInfo($tablesToUse);
        $referenceDataInfo = $this->buildReferenceDataInfo($tablesToUse);
        
        // Build business rules context
        $businessRulesInfo = $this->buildBusinessRulesContext($intent, $tablesToUse);
        
        // UPDATED: Build field guide context
        $fieldGuideInfo = $this->buildFieldGuideContext($tablesToUse);

        $intentGuidance = $this->getIntentGuidance($intent);

        return [
            'schema' => $schemaInfo,
            'relationships' => $relationshipsInfo,
            'reference_data' => $referenceDataInfo,
            'business_rules' => $businessRulesInfo,
            'field_guide' => $fieldGuideInfo,
            'intent' => $intentGuidance
        ];
    }

    // Build business rules context
    private function buildBusinessRulesContext(string $intent, array $tablesToUse): string
    {
        $context = "BUSINESS RULES:\n";
        
        // Global privacy rules
        $privacyRules = $this->businessRules['global_rules']['privacy'] ?? [];
        if (!empty($privacyRules)) {
            $context .= "Privacy Requirements:\n";
            $forbiddenCols = $privacyRules['forbidden_columns'] ?? [];
            $context .= "- NEVER select: " . implode(', ', array_slice($forbiddenCols, 0, 8)) . "\n";
        }
        
        // Intent-specific rules
        $intentRules = $this->businessRules['intent_rules'][$intent] ?? [];
        if (!empty($intentRules['rules'])) {
            $context .= "\n{$intent} Query Rules:\n";
            foreach ($intentRules['rules'] as $rule) {
                $context .= "- $rule\n";
            }
        }
        
        // Default behaviors
        $defaultAssumptions = $this->businessRules['global_rules']['default_assumptions']['rules'] ?? [];
        if (!empty($defaultAssumptions)) {
            $context .= "\nDefault Assumptions:\n";
            foreach (array_slice($defaultAssumptions, 0, 3) as $assumption) {
                $context .= "- $assumption\n";
            }
        }
        
        return $context;
    }

    // Build field guide context  
    private function buildFieldGuideContext(array $tablesToUse): string
    {
        $context = "";
        
        // Terminology mapping
        $context .= "TERMINOLOGY MAPPING:\n";
        foreach ($this->fieldGuide['terminology_mapping'] as $term => $column) {
            $context .= "- \"$term\" = $column\n";
        }
        
        // Clinical thresholds for relevant test types
        $clinicalThresholds = $this->fieldGuide['clinical_thresholds'] ?? [];
        foreach ($tablesToUse as $table) {
            $testType = null;
            foreach ($this->fieldGuide['test_type_logic'] ?? [] as $type => $config) {
                if ($config['table'] === $table) {
                    $testType = $type;
                    break;
                }
            }
            
            if ($testType && isset($clinicalThresholds[$testType])) {
                $context .= "\n" . strtoupper($testType) . " CLINICAL THRESHOLDS:\n";
                $thresholds = $clinicalThresholds[$testType]['thresholds'] ?? [];
                foreach ($thresholds as $name => $info) {
                    $context .= "- $name: {$info['condition']} // {$info['description']}\n";
                }
            }
        }
        
        // Column semantics
        $context .= "\nCOLUMN MEANINGS:\n";
        foreach ($tablesToUse as $table) {
            if (isset($this->fieldGuide['column_semantics'][$table])) {
                $context .= "$table columns:\n";
                foreach ($this->fieldGuide['column_semantics'][$table] as $column => $meaning) {
                    $context .= "  - $column: $meaning\n";
                }
            }
        }
        
        return $context;
    }

    private function buildSchemaInfo(array $tablesToUse): string
    {
        $schemaInfo = "TABLES AND COLUMNS:\n";
        
        foreach ($tablesToUse as $table) {
            $tableInfo = $this->schema['tables'][$table] ?? null;
            
            if (!$tableInfo) {
                // Fallback to old format
                $columns = $this->schema['tables'][$table] ?? [];
                $schemaInfo .= "$table: " . implode(', ', array_slice($columns, 0, 15)) . "\n";
                continue;
            }
            
            $schemaInfo .= "\n$table ({$tableInfo['type']}):\n";
            
            $columns = $tableInfo['columns'] ?? [];
            foreach (array_slice($columns, 0, 20) as $column) {
                $name = $column['name'];
                $type = $column['type'];
                $nullable = $column['nullable'] ? 'NULL' : 'NOT NULL';
                $key = $column['key'] ? " [{$column['key']}]" : '';
                
                $schemaInfo .= "  - $name ($type, $nullable)$key";
                
                if (!empty($column['comment'])) {
                    $schemaInfo .= " // {$column['comment']}";
                }
                
                $schemaInfo .= "\n";
            }
        }
        
        return $schemaInfo;
    }

    private function buildRelationshipsInfo(array $tablesToUse): string
    {
        if (!isset($this->schema['relationships']) || empty($this->schema['relationships'])) {
            return "";
        }
        
        $relationshipsInfo = "TABLE RELATIONSHIPS:\n";
        $relevantRelationships = [];
        
        foreach ($this->schema['relationships'] as $relationship) {
            $fromTable = $relationship['from_table'];
            $toTable = $relationship['to_table'];
            
            if (in_array($fromTable, $tablesToUse) || in_array($toTable, $tablesToUse)) {
                $relevantRelationships[] = $relationship;
            }
        }
        
        foreach ($relevantRelationships as $rel) {
            $relationshipsInfo .= "- {$rel['from_table']}.{$rel['from_column']} -> {$rel['to_table']}.{$rel['to_column']}\n";
        }
        
        return $relationshipsInfo;
    }

    private function buildReferenceDataInfo(array $tablesToUse): string
    {
        if (!isset($this->schema['reference_data']) || empty($this->schema['reference_data'])) {
            return "";
        }
        
        $referenceDataInfo = "REFERENCE DATA (Sample values for lookup tables):\n";
        
        foreach ($tablesToUse as $table) {
            $refData = $this->schema['reference_data'][$table] ?? null;
            if (!$refData || empty($refData['data'])) {
                continue;
            }
            
            $referenceDataInfo .= "\n$table (showing {$refData['sample_rows']} of {$refData['total_rows']} rows):\n";
            
            // Show first few rows as examples
            foreach (array_slice($refData['data'], 0, 5) as $row) {
                $values = [];
                foreach ($row as $key => $value) {
                    $values[] = "$key: " . (is_string($value) ? "'$value'" : $value);
                }
                $referenceDataInfo .= "  - " . implode(', ', array_slice($values, 0, 3)) . "\n";
            }
        }
        
        return $referenceDataInfo;
    }

    // Use business rules for intent guidance
    private function getIntentGuidance(string $intent): string
    {
        $intentRules = $this->businessRules['intent_rules'][$intent] ?? [];
        $rules = $intentRules['rules'] ?? [];
        
        $guidance = "QUERY TYPE GUIDANCE ($intent):\n";
        foreach ($rules as $rule) {
            $guidance .= "- $rule\n";
        }
        
        // Add specific defaults from business rules
        if (isset($intentRules['default_limit'])) {
            $guidance .= "- Default LIMIT: {$intentRules['default_limit']}\n";
        }
        if (isset($intentRules['essential_columns'])) {
            $essentials = implode(', ', $intentRules['essential_columns']);
            $guidance .= "- Essential columns: $essentials\n";
        }
        
        return $guidance;
    }

    // Use business rules structure
    private function callLLM(array $context, string $query): string
    {
        $system = <<<TXT
You are a medical database SQL expert. Generate a valid MySQL SELECT query based on the user's question and context.

CRITICAL: Return ONLY the full SQL statement. No explanations, no comments, no markdown formatting. Single line of SQL only.

{$context['schema']}
{$context['relationships']}
{$context['reference_data']}
{$context['business_rules']}
{$context['field_guide']}
{$context['intent']}

RULES:
- Return a COMPLETE VALID MySQL SELECT query
- Use exact column and table names from schema
- Use proper JOINs based on relationships
- Reference sample data for lookup values
- Follow all business rules and privacy requirements
- No Mardown formatting
- No explanations, no comments, no extra text
- No extraneous whitespace or line breaks

Query: {$query}

MySQL SELECT statement:
TXT;

        return $this->llm->generateSql($system);
    }

    // Use business rules structure for validation
    private function validateSql(string $sql): string
    {
        if (!preg_match('/^\s*select\s/i', $sql)) {
            throw new RuntimeException('Non-SELECT SQL returned by LLM');
        }

        // Use business rules structure
        $privacyRules = $this->businessRules['global_rules']['privacy'] ?? [];
        $forbiddenColumns = $privacyRules['forbidden_columns'] ?? [];

        foreach ($forbiddenColumns as $column) {
            if (stripos($sql, $column) !== false) {
                throw new RuntimeException("Privacy violation: {$column} cannot be returned");
            }
        }

        if (!preg_match('/\bFROM\s+([a-zA-Z0-9_]+)/i', $sql)) {
            throw new RuntimeException('Missing FROM clause in generated SQL : ' . $sql);
        }

        preg_match_all('/\bfrom\s+([a-zA-Z0-9_]+)|\bjoin\s+([a-zA-Z0-9_]+)/i', $sql, $m);
        $tables = array_filter(array_merge($m[1] ?? [], $m[2] ?? []));

        foreach ($tables as $table) {
            if (!in_array($table, $this->allowedTables, true)) {
                throw new RuntimeException("Disallowed table: {$table}");
            }
        }

        return $sql;
    }

    private function extractTablesFromSQL(string $sql): array
    {
        $tables = [];

        if (preg_match_all('/\bFROM\s+(\w+)/i', $sql, $matches)) {
            $tables = array_merge($tables, $matches[1]);
        }

        if (preg_match_all('/\bJOIN\s+(\w+)/i', $sql, $matches)) {
            $tables = array_merge($tables, $matches[1]);
        }

        return array_unique($tables);
    }
}