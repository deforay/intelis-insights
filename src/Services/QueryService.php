<?php
// src/Services/QueryService.php
declare(strict_types=1);

namespace App\Services;

use Throwable;
use RuntimeException;
use App\Llm\LlmRouter;
use App\Llm\AbstractLlmClient;
use App\Services\RetrieverService;
use App\Services\ConversationContextService;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Cache\Psr16Cache;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Adapter\RedisAdapter;

class QueryService
{
    private array $appCfg;
    private array $businessRules;
    private array $fieldGuide;
    private array $schema;
    private array $allowedTables;
    private AbstractLlmClient $llm;
    private RetrieverService $retriever;

    private ?CacheInterface $cache = null;
    private string $cacheBuster = '0';


    // Conversation context
    private ConversationContextService $contextService;

    private LlmRouter $router;
    private array $stepOverrides = []; // e.g. ['sql' => ['provider'=>'ollama','model'=>'llama3'], 'intent'=>[...]]

    public function __construct(
        array $appCfg,
        array $businessRules,
        array $fieldGuide,
        array $schema,
        ?ConversationContextService $contextService = null,
        ?LlmRouter $router = null,
        ?CacheInterface $cache = null
    ) {
        $this->appCfg        = $appCfg;
        $this->businessRules = $businessRules;
        $this->fieldGuide    = $fieldGuide;
        $this->schema        = $schema;
        $this->allowedTables = $this->extractAllowedTables($schema);
        $this->contextService = $contextService ?? new ConversationContextService();
        $this->router         = $router ?? new LlmRouter($appCfg);
        $this->llm            = $this->router->client('sql');
        $this->retriever      = new RetrieverService($appCfg['rag_base_url'] ?? 'http://127.0.0.1:8089');

        $this->cacheBuster = (string)($this->appCfg['cache']['buster'] ?? '0');
        $this->cache       = $cache ?? $this->createCacheFromConfig($appCfg);
    }

    private function createCacheFromConfig(array $cfg): ?CacheInterface
    {
        $driver    = $cfg['cache']['driver'] ?? 'file';
        $namespace = $cfg['cache']['namespace'] ?? 'insights';

        try {
            switch ($driver) {
                case 'redis': {
                        $dsn     = $cfg['cache']['redis_dsn'] ?? 'redis://127.0.0.1:6379';
                        $conn    = RedisAdapter::createConnection($dsn); // ext-redis or predis
                        $adapter = new RedisAdapter($conn, $namespace);
                        return new Psr16Cache($adapter);
                    }
                case 'file': {
                        $path = $cfg['cache']['path'] ?? dirname(__DIR__, 2) . '/var/cache';
                        @mkdir($path, 0775, true);
                        $adapter = new FilesystemAdapter($namespace, 0, $path);
                        return new Psr16Cache($adapter);
                    }
                default:
                    return null;
            }
        } catch (Throwable $e) {
            return null; // fail open
        }
    }


    // (optional) tiny helpers so the rest of your code doesn’t care if cache is null
    private function cacheGet(string $key, $default = null)
    {
        return $this->cache ? $this->cache->get($key, $default) : $default;
    }
    private function cacheSet(string $key, $value, int $ttl = 300): void
    {
        if ($this->cache) {
            $this->cache->set($key, $value, $ttl);
        }
    }


    /** Back-compat: return the SQL step client by default */
    public function getLlmClient(): AbstractLlmClient
    {
        return $this->router->client('sql', $this->stepOverrides['sql'] ?? null);
    }

    /**
     * Per-request override.
     * - Legacy: overrideLlm($provider, $model) overrides ONLY the 'sql' step
     * - New: overrideLlmMap(['sql'=>['provider'=>..,'model'=>..], 'intent'=>[...], 'chart'=>[...] ])
     */
    public function overrideLlm(?string $provider, ?string $model): void
    {
        if ($provider || $model) {
            $override = array_filter(['provider' => $provider, 'model' => $model]);
            // Apply to ALL steps when using global override
            $this->stepOverrides['intent'] = $override;
            $this->stepOverrides['sql'] = $override;
            $this->stepOverrides['chart'] = $override;
        }
    }

    public function overrideLlmMap(array $map): void
    {
        // sanitize to known steps
        $allowed = ['intent', 'sql', 'chart'];
        foreach ($map as $step => $ov) {
            if (in_array($step, $allowed, true) && is_array($ov)) {
                $this->stepOverrides[$step] = array_filter([
                    'provider' => $ov['provider'] ?? null,
                    'model'    => $ov['model'] ?? null,
                ]);
            }
        }
    }

    public function getLlmIdentity(): array
    {
        return [
            'intent' => $this->router->identity('intent', $this->stepOverrides['intent'] ?? null),
            'sql'    => $this->router->identity('sql',    $this->stepOverrides['sql']    ?? null),
            'chart'  => $this->router->identity('chart',  $this->stepOverrides['chart']  ?? null),
        ];
    }

    /** Extract allowed tables from schema (supports both old and new formats) */
    private function extractAllowedTables(array $schema): array
    {
        // New format
        if (isset($schema['tables']) && is_array($schema['tables'])) {
            $tables = array_keys($schema['tables']);

            // Filter out views if needed (optional)
            if (isset($schema['version']) && version_compare($schema['version'], '2.0', '>=')) {
                return array_filter($tables, function ($table) {
                    $tableInfo = $this->schema['tables'][$table] ?? [];
                    return ($tableInfo['type'] ?? 'base table') !== 'view';
                });
            }

            return $tables;
        }

        // Old format fallback
        return array_keys($schema['tables'] ?? $schema['views'] ?? []);
    }


    public function processQuery(string $query): array
    {
        $startTime = microtime(true);

        // Early validation using global business rules
        $this->validateQueryAgainstBusinessRules($query);

        // Get conversation context
        $conversationContext = $this->contextService->getContextForNewQuery($query);

        try {
            // intent analysis with business rules AND conversation context
            $intentAnalysis = $this->detectQueryIntentWithBusinessRules($query, $conversationContext);

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
        } catch (Throwable $e) {
            $intentType = 'single';
            $intents = ['general'];
            $intentAnalysis = ['type' => $intentType, 'intents' => $intents];
        }

        // Table selection with business rules AND context
        $tablesToUse = $this->selectRelevantTablesWithBusinessRules($query, $intentType, $conversationContext);

        $ragContexts = [];
        if (!empty($this->appCfg['rag_enabled'])) {
            //$tableFilters = array_values(array_unique($tablesToUse));
            $search = $this->retrieveIntentContexts($query, $tablesToUse, 15);
            $ragContexts = $search['contexts'] ?? [];
        }

        // Build a compact prompt context: prefer RAG; fall back to legacy if empty
        $context = empty($ragContexts)
            ? $this->buildPromptContext($intentType, $tablesToUse, $query, $conversationContext)
            : $this->buildStrictRagPack($ragContexts, $tablesToUse);



        // Get SQL with verification
        $sqlResult = $this->callLLM($context, $query);


        $rawSql = $sqlResult['sql'];

        // If query mentions 'lab', prefer lab_id join/grouping
        if (preg_match('/\blabs?\b/i', $query)) {
            // look for lab_id (with or without backticks) anywhere in SQL
            $hasLabId = preg_match('/(^|[^a-z0-9_`])lab_id([^a-z0-9_`]|$)/i', $rawSql);
            if (!$hasLabId) {
                $sqlResult['verification']['matches_intent'] = false;
                // nudge confidence down if present
                $sqlResult['verification']['confidence'] =
                    min((float)($sqlResult['verification']['confidence'] ?? 0.8), 0.6);
                $sqlResult['concerns'][] = "Expected grouping/join by lab_id for 'by lab'.";
            }
        }

        // Default temporal field to sample_tested_datetime unless 'collection' is mentioned
        if (!preg_match('/collect(ed|ion)|sample_collection_date/i', $query)) {
            $hasTestedDate = preg_match('/(^|[^a-z0-9_`])sample_tested_datetime([^a-z0-9_`]|$)/i', $rawSql);
            if (!$hasTestedDate) {
                $sqlResult['verification']['matches_intent'] = false;
                $sqlResult['verification']['confidence'] =
                    min((float)($sqlResult['verification']['confidence'] ?? 0.8), 0.6);
                $sqlResult['concerns'][] = "Use sample_tested_datetime by default for time filters.";
            }
        }
        // --- end domain guardrails ---

        // Check verification before executing
        $verification = $sqlResult['verification'];
        if (!$verification['matches_intent'] && $verification['confidence'] < 0.6) {
            throw new RuntimeException(
                'Generated query may not match your request: ' .
                    ($verification['reasoning'] ?? 'Low confidence match') . ' SQL: ' . $rawSql
            );
        }
        // RAG grounding (only if we had contexts)
        if (!empty($ragContexts)) {
            try {
                $allowlist = $this->buildGroundingAllowlist($ragContexts, $context['rag_json'] ?? null, $tablesToUse);
                $this->enforceGrounding($sqlResult['sql'] ?? '', $allowlist);
            } catch (Throwable $g) {
                throw new RuntimeException('Grounding check failed: ' . $g->getMessage() . ' SQL: ' . ($sqlResult['sql'] ?? ''));
            }
        }
        $finalSql = $this->validateSql($rawSql);
        $actualTablesUsed = $this->extractTablesFromSQL($finalSql);

        $result = [
            'sql' => $finalSql,
            'raw_sql' => $rawSql,
            'verification' => $verification,
            'concerns' => $sqlResult['concerns'] ?? [],
            'intent' => $intentType,
            'intent_details' => $intents,
            'intent_debug' => $intentAnalysis,
            'tables_selected' => $tablesToUse,
            'tables_used' => $actualTablesUsed,
            'context' => $context,
            'conversation_context' => $conversationContext,
            'processing_time_ms' => round((microtime(true) - $startTime) * 1000),
            'citations' => $sqlResult['citations'] ?? [],
            'retrieved_context_ids' => array_map(fn($c) => $c['id'] ?? '', $ragContexts),

        ];

        // Add this query to conversation history
        $this->contextService->addQuery($query, $result);

        return $result;
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


    private function detectQueryIntentWithBusinessRules(string $query, array $conversationContext = []): array
    {
        $globalRules        = $this->businessRules['global_rules'] ?? [];
        $defaultAssumptions = $globalRules['default_assumptions']['rules'] ?? [];
        $scopeLimits        = $globalRules['query_scope_limits']['rules'] ?? [];

        $businessContext  = "BUSINESS CONTEXT:\n";
        $businessContext .= "Default Assumptions, DONT IGNORE THESE:\n";
        foreach ($defaultAssumptions as $assumption) {
            $businessContext .= "- $assumption\n";
        }
        $businessContext .= "\nQuery Scope Guidelines:\n";
        foreach ($scopeLimits as $limit) {
            $businessContext .= "- $limit\n";
        }

        // Conversation summary (unchanged)
        $contextInfo = "";
        if (!empty($conversationContext['has_context'])) {
            $contextInfo  = "\nCONVERSATION CONTEXT:\n";
            $contextInfo .= $conversationContext['context_summary'];
            if (!empty($conversationContext['suggested_filters'])) {
                $contextInfo .= "Previous filters that may apply:\n";
                foreach ($conversationContext['suggested_filters'] as $filter) {
                    $contextInfo .= "- $filter\n";
                }
            }
        } elseif (!empty($conversationContext['references_missing_context'])) {
            throw new RuntimeException($conversationContext['suggested_response']);
        }

        // RAG for intent (column-free)
        $intentSearch = $this->retrieveIntentFacts($query, 14);
        $intentPack   = $this->buildIntentRagPack($intentSearch);

        $intentPrompt = <<<PROMPT
You classify a medical lab analytics question. Use ONLY the ALLOWLIST facts below
(synonyms, test types, validation/rules, exemplars). If unsure, set domain_relevance="low".

Return ONLY JSON:
{
  "type": "single" | "multi_part",
  "intents": string[],                 // e.g. ["count"], ["list"], ["aggregate"], ["count","filter"], etc.
  "test_types": string[],              // subset of ["vl","covid19","eid","tb","hepatitis","cd4",...]
  "tables": string[],                  // concrete table names if clear
  "domain_relevance": "high" | "medium" | "low",
  "references_previous": true | false,
  "assumptions": string[]
}

ALLOWLIST:
{$intentPack['rag_json']}

$businessContext
$contextInfo

QUESTION: {$query}
PROMPT;

        try {
            $client = $this->router->client('intent', $this->stepOverrides['intent'] ?? null);
            $raw    = $client->generateJson($intentPrompt, 300);
            $resp   = json_decode(trim($raw), true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($resp)) {
                // Normalize fields
                $resp['type']                 = $resp['type'] ?? 'single';
                $resp['intents']              = array_values(array_filter($resp['intents'] ?? []));
                $resp['test_types']           = array_values(array_filter($resp['test_types'] ?? []));
                $resp['tables']               = array_values(array_filter($resp['tables'] ?? []));
                $resp['domain_relevance']     = $resp['domain_relevance'] ?? 'medium';
                $resp['references_previous']  = (bool)($resp['references_previous'] ?? false);
                $resp['assumptions']          = array_values(array_filter($resp['assumptions'] ?? []));

                // If tables not provided, infer from test_types
                if (empty($resp['tables']) && !empty($resp['test_types'])) {
                    $resp['tables'] = $this->mapTestTypesToTables($resp['test_types']);
                }

                // Final fallback if still nothing clear
                if (empty($resp['intents'])) {
                    $resp['intents'] = ['general'];
                }

                return $resp;
            }
        } catch (Throwable $e) {
            // fall through to regex fallback
        }

        // Fallback to your regex heuristic
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
    private function selectRelevantTablesWithBusinessRules(string $query, string $intentType, array $conversationContext = []): array
    {
        $qLower = strtolower($query);
        $selectedTables = [];


        // Table patterns with test type logic from field guide
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

        if (!empty($conversationContext['intent_last']) && !empty($conversationContext['intent_last']['tables'])) {
            $selectedTables = array_merge($selectedTables, (array)$conversationContext['intent_last']['tables']);
        }

        // If user says "by lab", force facility_details alongside analytic table
        if (preg_match('/\blab(s)?\b/i', $qLower)) {
            $selectedTables[] = 'facility_details';
        }
        if (preg_match('/\b(province|state|district|county|region|zone)\b/i', $qLower)) {
            $selectedTables[] = 'geographical_divisions';
        }

        // If query seems to reference previous context, use common tables from context
        if (!empty($conversationContext['has_context']) && empty($selectedTables)) {
            $commonTables = $conversationContext['common_tables'] ?? [];
            if (!empty($commonTables)) {
                $selectedTables = array_merge($selectedTables, $commonTables);
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

    // Build context with structure
    private function buildPromptContext(string $intent, array $tablesToUse, string $query, array $conversationContext = []): array
    {
        $schemaInfo = $this->buildSchemaInfo($tablesToUse);
        $relationshipsInfo = $this->buildRelationshipsInfo($tablesToUse);
        $referenceDataInfo = $this->buildReferenceDataInfo($tablesToUse);
        $businessRulesInfo = $this->buildBusinessRulesContext($intent, $tablesToUse);
        $fieldGuideInfo = $this->buildFieldGuideContext($tablesToUse);
        $intentGuidance = $this->getIntentGuidance($intent);

        // Add conversation context
        $conversationInfo = $this->buildConversationContext($conversationContext);

        return [
            'schema' => $schemaInfo,
            'relationships' => $relationshipsInfo,
            'reference_data' => $referenceDataInfo,
            'business_rules' => $businessRulesInfo,
            'field_guide' => $fieldGuideInfo,
            'conversation_context' => $conversationInfo, // NEW
            'intent' => $intentGuidance
        ];
    }

    // Build conversation context for LLM prompt
    private function buildConversationContext(array $conversationContext): string
    {
        if (empty($conversationContext['has_context'])) {
            return "";
        }

        $context = "CONVERSATION CONTEXT:\n";
        $context .= $conversationContext['context_summary'];

        if (!empty($conversationContext['suggested_filters'])) {
            $context .= "\nFilters from previous queries that may apply:\n";
            foreach ($conversationContext['suggested_filters'] as $filter) {
                $context .= "- $filter\n";
            }
        }

        return $context;
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
            $context .= "- NEVER add these in select query: " . implode(', ', array_slice($forbiddenCols, 0, 8)) . "\n";
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
            if (!$tableInfo) continue;

            $schemaInfo .= "\n$table ({$tableInfo['type']}):\n";

            $columns = $tableInfo['columns'] ?? [];
            foreach (array_slice($columns, 0, 20) as $column) {
                $name = $column['name'];
                $type = $column['type'];
                $nullable = $column['nullable'] ? 'NULL' : 'NOT NULL';
                $key = $column['key'] ? " [{$column['key']}]" : '';

                $schemaInfo .= "  - $name ($type, $nullable)$key";

                // Add special note for lab_id columns
                if (strtolower($name) === 'lab_id') {
                    $schemaInfo .= " // JOIN facility_details ON lab_id for lab names";
                }

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

    // Methods to manage conversation context
    public function clearConversationHistory(): void
    {
        $this->contextService->clearHistory();
    }

    public function getConversationHistory(): array
    {
        return $this->contextService->getHistory();
    }


    private function makeCacheKey(string $prefix, string $query, array $filters, array $tablesToUse, int $k): string
    {
        $finger = [
            'v'      => $this->cacheBuster,
            'db'     => $this->schema['database'] ?? '',
            'tables' => array_values($tablesToUse),
            'k'      => $k,
            'q'      => $query,
            'f'      => $filters,
        ];

        // Use dot separators + sha1 so the tail is always safe
        $raw = $prefix . '.' . sha1(json_encode($finger, JSON_UNESCAPED_SLASHES));
        return $this->sanitizeCacheKey($raw);
    }


    // Add this helper
    private function sanitizeCacheKey(string $key): string
    {
        // Replace reserved chars with dots, then collapse repeats
        $key = preg_replace('/[{}()\/\\\\@:]+/', '.', $key);
        $key = preg_replace('/\.+/', '.', $key);
        // Optional: PSR-6 adapters may limit length; keep it reasonable
        return strlen($key) > 120 ? substr($key, 0, 120) : $key;
    }


    private function retrieveIntentContexts(string $query, array $tablesToUse, int $k = 15): array
    {
        $filters = [
            'type'  => ['column', 'rule', 'table', 'relationship', 'threshold', 'exemplar', 'validation'],
            'table' => array_values(array_unique($tablesToUse)),
        ];

        $ttl = (int)($this->appCfg['cache']['ttl'] ?? 300);

        if ($this->cache) {
            $key = $this->makeCacheKey('rag.search', $query, $filters, $tablesToUse, $k);
            $cached = $this->cache->get($key);
            if (is_array($cached) && isset($cached['contexts'])) {
                return $cached;
            }

            $search = $this->retriever->search($query, $k, $filters);
            // store only the small part we need
            $this->cache->set($key, ['contexts' => $search['contexts'] ?? []], $ttl);
            return $search;
        }

        // No cache driver → direct
        return $this->retriever->search($query, $k, $filters);
    }


    private function retrieveIntentFacts(string $query, int $k = 14): array
    {
        // Only high-signal types for intent. No 'column' to avoid schema noise.
        $filters = [
            'type' => ['syn', 'test_type', 'rule', 'validation', 'exemplar', 'threshold']
        ];

        $ttl = (int)($this->appCfg['cache']['ttl'] ?? 300);
        if ($this->cache) {
            $key = $this->makeCacheKey('rag.intent', $query, $filters, [], $k);
            $cached = $this->cache->get($key);
            if (is_array($cached) && isset($cached['contexts'])) {
                return $cached;
            }
            $search = $this->retriever->search($query, $k, $filters);
            $this->cache->set($key, ['contexts' => $search['contexts'] ?? []], $ttl);
            return $search;
        }
        return $this->retriever->search($query, $k, $filters);
    }

    private function buildIntentRagPack(array $search): array
    {
        $contexts = $search['contexts'] ?? [];
        $allowed  = ['syn', 'test_type', 'rule', 'validation', 'exemplar', 'threshold'];

        // sort, filter, trim
        usort($contexts, fn($a, $b) => ($b['score'] <=> $a['score']));
        $contexts = array_values(array_filter($contexts, fn($c) => in_array(($c['type'] ?? ''), $allowed, true)));
        $contexts = array_slice($contexts, 0, 20);

        $pack = [];
        foreach ($contexts as $c) {
            $id = (string)($c['id'] ?? '');
            $t  = (string)($c['type'] ?? '');
            $x  = trim(preg_replace('/\s+/', ' ', (string)($c['text'] ?? '')));
            if (strlen($x) > 220) $x = substr($x, 0, 220) . '…';
            $pack[] = ['id' => $id, 't' => $t, 'x' => $x];
        }
        return ['rag_json' => json_encode($pack, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)];
    }

    private function mapTestTypesToTables(array $testTypes): array
    {
        $out = [];
        foreach ($testTypes as $tt) {
            $cfg = $this->fieldGuide['test_type_logic'][$tt] ?? null;
            if ($cfg && !empty($cfg['table'])) $out[] = $cfg['table'];
        }
        // keep only known, non-view tables
        $out = array_values(array_unique(array_intersect($out, $this->allowedTables)));
        return $out;
    }



    /**
     * From local schema: emit essential columns that prevent guessing.
     * We include common date + lab join fields + human-readable labels.
     */
    private function schemaDerivedEssentials(array $tablesToUse): array
    {
        $out = [];
        $emitCol = function (string $table, string $name, ?string $sqlType = null, ?bool $nullable = null, string $extraText = '') use (&$out) {
            $id = "col:$table.$name";
            $txt = "$table.$name" . ($sqlType ? " ($sqlType" . ($nullable === null ? '' : (', ' . ($nullable ? 'NULL' : 'NOT NULL'))) . ")" : '');
            if ($extraText) $txt .= ": $extraText";
            $out[] = [
                'id' => $id,
                'type' => 'column',
                'text' => $txt,
                'meta' => ['table' => $table, 'name' => $name],
                'score' => 0.99
            ];
        };

        $needByTable = [];
        foreach ($tablesToUse as $t) {
            $needByTable[$t] = [
                'sample_tested_datetime',
                'sample_collection_date',
                'lab_id',
                'result_value_absolute',
                'result',
            ];
        }

        // If facility_details is involved, add human label + common geo fields (when present)
        if (in_array('facility_details', $tablesToUse, true)) {
            // always include human label + ids used for geo joins
            foreach (['facility_id', 'facility_name', 'facility_code', 'facility_state_id', 'facility_district_id'] as $col) {
                if ($this->tableHasColumn('facility_details', $col)) {
                    $emitCol('facility_details', $col);
                }
            }

            // if geo table exists, include key columns and best "name" column
            if (isset($this->schema['tables']['geographical_divisions'])) {
                foreach (['geo_id', 'geo_name'] as $col) {
                    if ($this->tableHasColumn('geographical_divisions', $col)) {
                        $emitCol('geographical_divisions', $col);
                    }
                }
                $geoName = $this->findNameLikeColumn('geographical_divisions');
                if ($geoName) {
                    $emitCol('geographical_divisions', $geoName);
                }
            }
        }


        foreach ($needByTable as $table => $fields) {
            $tableInfo = $this->schema['tables'][$table] ?? null;
            if (!$tableInfo) continue;
            $columns = $tableInfo['columns'] ?? [];
            foreach ($columns as $c) {
                $name = strtolower($c['name']);
                // match case-insensitively against requested list
                foreach ($fields as $f) {
                    if ($name === strtolower($f)) {
                        $emitCol(
                            $table,
                            $c['name'],
                            $c['type'] ?? null,
                            isset($c['nullable']) ? (bool)$c['nullable'] : null,
                            $c['comment'] ?? ''
                        );
                        break;
                    }
                }
            }
        }
        return $out;
    }

    // helper: find the best "name" column on a table
    private function findNameLikeColumn(string $table): ?string
    {
        $cands = ['geo_name', 'name', 'division_name', 'state_name', 'district_name', 'province_name', 'title', 'label'];
        $info = $this->schema['tables'][$table]['columns'] ?? [];
        foreach ($cands as $want) {
            foreach ($info as $c) {
                if (strcasecmp($c['name'] ?? '', $want) === 0) return $c['name'];
            }
        }
        // fallback: first col that contains "name"
        foreach ($info as $c) {
            if (stripos($c['name'] ?? '', 'name') !== false) return $c['name'];
        }
        return null;
    }

    /**
     * From local schema: emit relationship hints between selected tables.
     */
    private function schemaDerivedRelationships(array $tablesToUse): array
    {
        $out = [];
        $rel = $this->schema['relationships'] ?? [];
        if (!$rel) return $out;

        $allowed = array_flip($tablesToUse);
        foreach ($rel as $r) {
            $fromT = (string)($r['from_table'] ?? '');
            $toT   = (string)($r['to_table'] ?? '');
            if ($fromT === '' || $toT === '') continue;
            if (!isset($allowed[$fromT]) && !isset($allowed[$toT])) continue;

            $fromC = (string)($r['from_column'] ?? '');
            $toC   = (string)($r['to_column'] ?? '');
            $id    = "relationship:$fromT.$fromC->$toT.$toC";
            $txt   = "$fromT.$fromC -> $toT.$toC";
            $out[] = [
                'id'   => $id,
                'type' => 'relationship',
                'text' => $txt,
                'meta' => ['table' => $fromT],
                'score' => 1.0
            ];
        }

        // Convenience: if any selected table has a lab_id and facility_details is available,
        // ensure we include that canonical join even if not present in INFORMATION_SCHEMA FKs.
        $hasFacility = in_array('facility_details', $tablesToUse, true);
        if ($hasFacility) {
            foreach ($tablesToUse as $t) {
                if ($t === 'facility_details') continue;
                if ($this->tableHasColumn($t, 'lab_id')) {
                    $id  = "relationship:$t.lab_id->facility_details.facility_id";
                    $txt = "$t.lab_id -> facility_details.facility_id";
                    $out[$id] = [
                        'id' => $id,
                        'type' => 'relationship',
                        'text' => $txt,
                        'meta' => ['table' => $t],
                        'score' => 1.0
                    ];
                    // also ensure facility_name exists for human labels
                    if ($this->tableHasColumn('facility_details', 'facility_name')) {
                        $out["col:facility_details.facility_name"] = [
                            'id' => "col:facility_details.facility_name",
                            'type' => 'column',
                            'text' => 'facility_details.facility_name (label to display testing lab)',
                            'meta' => ['table' => 'facility_details', 'name' => 'facility_name'],
                            'score' => 0.98
                        ];
                    }
                }
            }
            $needsGeo = in_array('facility_details', $tablesToUse, true)
                && isset($this->schema['tables']['geographical_divisions']);

            if ($needsGeo) {
                if ($this->tableHasColumn('form_vl', 'lab_id')) {
                    $out["relationship:form_vl.lab_id->facility_details.facility_id"] = [
                        'id'   => "relationship:form_vl.lab_id->facility_details.facility_id",
                        'type' => 'relationship',
                        'text' => 'form_vl.lab_id -> facility_details.facility_id',
                        'meta' => ['table' => 'form_vl'],
                        'score' => 1.0
                    ];
                }
                if ($this->tableHasColumn('facility_details', 'facility_state_id') && $this->tableHasColumn('geographical_divisions', 'geo_id')) {
                    $out["relationship:facility_details.facility_state_id->geographical_divisions.geo_id"] = [
                        'id'   => "relationship:facility_details.facility_state_id->geographical_divisions.geo_id",
                        'type' => 'relationship',
                        'text' => 'facility_details.facility_state_id -> geographical_divisions.geo_id',
                        'meta' => ['table' => 'facility_details'],
                        'score' => 1.0
                    ];
                }
                if ($this->tableHasColumn('facility_details', 'facility_district_id') && $this->tableHasColumn('geographical_divisions', 'geo_id')) {
                    $out["relationship:facility_details.facility_district_id->geographical_divisions.geo_id"] = [
                        'id'   => "relationship:facility_details.facility_district_id->geographical_divisions.geo_id",
                        'type' => 'relationship',
                        'text' => 'facility_details.facility_district_id -> geographical_divisions.geo_id',
                        'meta' => ['table' => 'facility_details'],
                        'score' => 1.0
                    ];
                }
                $out["table:geographical_divisions"] = [
                    'id' => 'table:geographical_divisions',
                    'type' => 'table',
                    'text' => 'geographical_divisions (lookup of administrative units)',
                    'meta' => ['table' => 'geographical_divisions'],
                    'score' => 1.0
                ];
            }

            // normalize $out to list
            $out = array_values($out);
        }
        return $out;
    }


    private function buildStrictRagPack(array $contexts, array $tablesToUse): array
    {
        // 1) Filter retrieved contexts to allowed types *and* allowed tables
        $allowedTypes = ['table', 'column', 'relationship', 'validation', 'rule', 'exemplar', 'threshold'];
        $allowedTablesSet = array_flip($tablesToUse);

        $filtered = [];
        foreach ($contexts as $c) {
            $t = (string)($c['type'] ?? '');
            if (!in_array($t, $allowedTypes, true)) continue;

            // If a context is table/column/relationship, respect its meta.table
            $mt = strtolower((string)($c['meta']['table'] ?? ''));
            if ($mt !== '' && !isset($allowedTablesSet[$mt])) continue;

            // keep it, but trim text to keep prompt small
            $c['text'] = trim(preg_replace('/\s+/', ' ', (string)($c['text'] ?? '')));
            if (strlen($c['text']) > 220) $c['text'] = substr($c['text'], 0, 220) . '…';
            $filtered[] = $c;
        }

        // 2) Add schema-derived essentials for the selected tables
        $schemaEssentials = $this->schemaDerivedEssentials($tablesToUse);     // columns you will likely need
        $schemaRels       = $this->schemaDerivedRelationships($tablesToUse);  // FK join hints

        // De-dup by id
        $byId = [];
        $add = function (array $item) use (&$byId) {
            $id = (string)($item['id'] ?? '');
            if ($id === '') return;
            $byId[$id] = $item;
        };
        foreach (array_merge($filtered, $schemaEssentials, $schemaRels) as $item) {
            $add($item);
        }

        // 3) Ensure we at least include a table stub for each tableToUse
        foreach ($tablesToUse as $tbl) {
            $id = "table:$tbl";
            if (!isset($byId[$id])) {
                $byId[$id] = [
                    'id' => $id,
                    'type' => 'table',
                    'text' => "$tbl (base table)",
                    'meta' => ['table' => $tbl],
                    'score' => 1.0
                ];
            }
        }

        // 4) Compact to JSON pack expected by callLLM()
        //    - keep most relevant first: relationships > required columns > other
        $rank = function ($x) {
            $t = $x['type'] ?? '';
            return match ($t) {
                'relationship' => 100,
                'column'       => 90,
                'validation'   => 80,
                'rule'         => 70,
                'exemplar'     => 60,
                'threshold'    => 50,
                'table'        => 40,
                default        => 10
            };
        };

        $all = array_values($byId);
        usort($all, function ($a, $b) use ($rank) {
            $ra = $rank($a);
            $rb = $rank($b);
            if ($ra === $rb) return ($b['score'] ?? 0) <=> ($a['score'] ?? 0);
            return $rb <=> $ra;
        });

        // Keep a sane cap to avoid blowing prompt; 16–24 works well
        $all = array_slice($all, 0, 24);

        // Convert to the tiny shape the prompt expects
        $pack = [];
        foreach ($all as $c) {
            $pack[] = [
                'id' => (string)$c['id'],
                't'  => (string)$c['type'],
                'x'  => (string)$c['text'],
            ];
        }
        return ['rag_json' => json_encode($pack, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)];
    }




    // Use business rules structure
    private function callLLM(array $context, string $query): array
    {
        $usingRag = isset($context['rag_json']) && $context['rag_json'] !== '' && $context['rag_json'] !== '[]';

        if ($usingRag) {
            $system = <<<TXT
You are a strict MySQL SQL generator for a medical lab DB.

You MUST use only items in the ALLOWLIST JSON below. If anything you need is missing
(e.g., a column or join), respond with:
{"s":"","ok":false,"conf":0.0,"why":"<short reason>","cit":[]}

If any required column or relationship is missing from CONTEXT, set "ok": false and explain in "why". Do NOT guess.


ABSOLUTE CONSTRAINTS — NO EXCEPTIONS

- You must cite each table via table:...
- Cite columns via col:... when present; if a needed column isn’t listed but clearly belongs to an allowed table, you may still use it and cite the table id.
- Prefer human‑readable names (e.g., facility_details.facility_name) over raw IDs when grouping/reporting.
- Default date: for VL use form_vl.sample_tested_datetime unless the user asks for collection date.
- Use ONLY column names that appear in CONTEXT with their exact table qualifiers.
- Every table/column you use must have a matching context ID in cit.
- Follow all guidance in CONTEXT exactly (JOIN instructions, “never select” rules, etc.).
- Table aliases must match real table names from CONTEXT (e.g., fv for form_vl, fd for facility_details).
- If CONTEXT says JOIN facility_details, use exactly facility_details (never facility_data, labs, etc.).
- Privacy: never select patient identifiers; COUNT(DISTINCT ...) allowed for unique counts only.
- If CONTEXT says “never select X directly, always select Y”, you must select Y, not X.
- For lab breakdowns: select facility_details.facility_name (human‑readable), never lab_id (raw ID).
- Check JOIN conditions carefully — foreign keys link to primary keys.

CONTEXT contains ALL allowed tables, columns, and JOIN patterns. You cannot use anything not in CONTEXT.

ALLOWLIST:
{$context['rag_json']}

QUESTION: {$query}
Return ONLY one JSON object: {"s":"<SQL>","ok":true|false,"conf":0..1,"why":"<=120 chars","cit":["<ids>"]}
TXT;
        } else {
            $system = <<<TXT
You are a MySQL and medical database SQL expert. Generate a valid MySQL SELECT query and verify it matches the user's intent.

RESPOND WITH JSON:
{
  "sql": "SELECT ...",
  "verification": { "matches_intent": true, "confidence": 0.95, "reasoning": "..." },
  "concerns": ["optional"]
}

{$context['schema']}
{$context['relationships']}
{$context['reference_data']}
{$context['business_rules']}
{$context['field_guide']}
{$context['conversation_context']}
{$context['intent']}

USER QUESTION: {$query}
TXT;
        }

        // In callLLM(), add this logging before the LLM call:
        error_log("=== FULL PROMPT DEBUG ===");
        error_log("System prompt length: " . strlen($system));
        error_log("System prompt:\n" . $system);
        error_log("Query: " . $query);
        error_log("Max tokens: 600");

        $client = $this->router->client('sql', $this->stepOverrides['sql'] ?? null);
        $raw = $client->generateJson($system, 600);

        // ADD THIS DEBUG LOGGING:
        error_log("=== RAG LLM DEBUG ===");
        error_log("Raw LLM response: " . substr($raw, 0, 1000));
        error_log("JSON decode error: " . json_last_error_msg());

        $out = json_decode(trim($raw), true);

        if ($usingRag) {
            if (!$out || (!array_key_exists('s', $out) && !array_key_exists('sql', $out))) {
                throw new RuntimeException('Model did not return JSON in strict RAG mode');
            }

            $sql = $out['s'] ?? $out['sql'] ?? '';
            $cit = array_values(array_filter($out['cit'] ?? $out['citations'] ?? []));
            $ok  = (bool)($out['ok'] ?? false);
            $conf = (float)($out['conf'] ?? 0.0);
            $why  = (string)($out['why'] ?? '');

            // Refuse if there is no citation for every table/column token
            if ($ok && (empty($cit))) {
                $ok = false;
                $conf = min($conf, 0.5);
                $why = $why ?: 'Missing citations for used items';
            }

            if (!$ok) {
                throw new RuntimeException('RAG refusal: ' . ($why ?: 'insufficient context') . ' SQL: ' . $sql);
            }

            return [
                'sql' => $sql,
                'verification' => [
                    'matches_intent' => true,
                    'confidence' => max(0.6, $conf),
                    'reasoning' => $why
                ],
                'concerns' => [],
                'citations' => $cit
            ];
        }



        // Legacy branch
        $result = $out;
        if (!$result || !isset($result['sql'])) {
            return [
                'sql' => $this->callLLMLegacy($context, $query),
                'verification' => [
                    'matches_intent' => false,
                    'confidence' => 0.3,
                    'reasoning' => 'LLM JSON parse failed; used legacy SQL generator'
                ],
                'concerns' => ['Used fallback SQL generation due to invalid/non-JSON response'],
                'citations' => []
            ];
        }
        return $result;
    }



    private function callLLMLegacy(array $context, string $query): string
    {
        $system = <<<TXT
You are a MySQL and medical database SQL expert. Generate a valid MySQL SELECT query based on the user's question and context.

CRITICAL: Return ONLY the raw SQL statement with NO formatting, explanations, or markdown. Just the SQL query on a single line.

{$context['schema']}
{$context['relationships']}
{$context['reference_data']}
{$context['business_rules']}
{$context['field_guide']}
{$context['conversation_context']}
{$context['intent']}

PRIVACY:
- You may use identifiers like patient_art_no ONLY inside COUNT(DISTINCT ...) to count unique patients.
- NEVER select, filter, group by, order by, or join on patient_art_no (or similar identifiers).
- When you count unique patients, alias as "unique_patients" (or a context-appropriate human label).

RULES:
- Return a COMPLETE VALID MySQL SELECT query
- Use exact column and table names from schema
- Use proper JOINs based on relationships
- Apply business rules for privacy and query scope
- Include WHERE clauses for filtering
- Use LIMIT for large result sets as per business rules
- Use aggregate functions if relevant to intent
- Apply DISTINCT if relevant to intent
- Use ORDER BY if relevant to intent
- Use aliases for tables and columns if needed for clarity
- Reference sample data for lookup values
- Follow all business rules and privacy requirements
- If conversation context suggests filters, include them unless the new query contradicts them
- No markdown code formatting, no code blocks, NO explanations, NO comments, No extra text
- No extraneous whitespace or line breaks
- IMPORTANT: If conversation context shows previous filters, COMBINE them with the new query unless they contradict
- When user says "these", "those", etc., apply ALL relevant filters from previous queries

Query: {$query}

MySQL SELECT Query:
TXT;

        return $this->llm->generateSql($system);
    }

    // Use business rules structure for validation
    private function validateSql(string $sql): string
    {
        if (!preg_match('/^\s*select\s/i', $sql)) {
            throw new RuntimeException('Non-SELECT SQL returned by LLM');
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

        // ---------- PRIVACY CHECKS ----------
        $privacyRules = $this->businessRules['global_rules']['privacy'] ?? [];
        $forbiddenColumns = $privacyRules['forbidden_columns'] ?? [];
        $allowAggDistinct = array_map('strtolower', $privacyRules['allow_aggregated_distinct'] ?? []);

        // Remove string literals so we don't match column names inside quotes
        $sqlNoStrings = $this->stripStringLiterals($sql);

        // For columns allowed only inside COUNT(DISTINCT ...), remove those safe occurrences first
        $sqlScrubbed = $sqlNoStrings;
        foreach ($allowAggDistinct as $col) {
            // Remove COUNT(DISTINCT ... col ...) with optional table/alias and backticks
            $pattern = '/count\s*\(\s*distinct\s+[^)]*\b' . preg_quote($col, '/') . '\b[^)]*\)/i';
            $sqlScrubbed = preg_replace($pattern, '/*__SAFE_AGG__*/', $sqlScrubbed);
        }

        // Now, if any forbidden column name still remains anywhere, it's a violation
        foreach ($forbiddenColumns as $column) {
            $col = strtolower($column);
            if (stripos($sqlScrubbed, $col) !== false) {
                throw new RuntimeException("Privacy violation: {$column} cannot be returned - $sql");
            }
        }
        // ---------- END PRIVACY CHECKS ----------

        // Extract tables used in SQL
        $tablesUsed = $this->extractTablesFromSQL($sql);

        // Build list of valid columns from schema for tables being used
        $validColumns = [];
        foreach ($tablesUsed as $table) {
            if (isset($this->schema['tables'][$table]['columns'])) {
                foreach ($this->schema['tables'][$table]['columns'] as $col) {
                    $validColumns[] = strtolower($table . '.' . $col['name']);
                    $validColumns[] = strtolower($col['name']); // unqualified
                }
            }
        }

        // Check for common lab-related mistakes across any table
        if (preg_match('/\b(testing_lab|lab_name|laboratory_name)\b/i', $sql)) {
            // Check if any table has lab_id
            $hasLabId = false;
            foreach ($tablesUsed as $table) {
                if ($this->tableHasColumn($table, 'lab_id')) {
                    $hasLabId = true;
                    break;
                }
            }

            if ($hasLabId) {
                throw new RuntimeException("For lab breakdown, use lab_id with JOIN to facility_details for lab names, not direct lab name columns");
            }
        }

        return $sql;
    }

    private function tableHasColumn(string $table, string $column): bool
    {
        $tableInfo = $this->schema['tables'][$table] ?? null;
        if (!$tableInfo || !isset($tableInfo['columns'])) {
            return false;
        }

        foreach ($tableInfo['columns'] as $col) {
            if (strtolower($col['name']) === strtolower($column)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Remove single- and double-quoted string literals to avoid false positives.
     */
    private function stripStringLiterals(string $sql): string
    {
        // remove '...'(escaped) and "..."(escaped)
        $sql = preg_replace("/'(?:''|\\\\'|[^'])*'/", "''", $sql);
        $sql = preg_replace('/"(?:\\"|[^"])*"/', '""', $sql);
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


    private function buildGroundingAllowlist(?array $ragContexts, ?string $ragJson, array $tablesToUse): array
    {
        $tables  = [];
        $columns = [];

        // 1) From rag_json pack (ids like "table:form_vl", "col:form_vl.sample_tested_datetime")
        if ($ragJson) {
            $pack = json_decode($ragJson, true);
            if (is_array($pack)) {
                foreach ($pack as $item) {
                    $id = (string)($item['id'] ?? '');
                    if (str_starts_with($id, 'table:')) {
                        $tables[] = strtolower(substr($id, 6));
                    } elseif (str_starts_with($id, 'col:')) {
                        $rest = substr($id, 4); // table.col
                        $parts = explode('.', $rest, 2);
                        if (count($parts) === 2) {
                            $tables[]  = strtolower($parts[0]);
                            $columns[] = strtolower($parts[0] . '.' . $parts[1]);
                        }
                    }
                }
            }
        }

        // 2) Also include any explicit table/column meta from raw retriever contexts (back-compat)
        foreach ($ragContexts ?? [] as $c) {
            $t = strtolower((string)($c['type'] ?? ''));
            if ($t === 'table') {
                $tbl = strtolower((string)($c['meta']['table'] ?? ''));
                if ($tbl) $tables[] = $tbl;
            } elseif ($t === 'column') {
                $tbl = strtolower((string)($c['meta']['table'] ?? ''));
                $col = strtolower((string)($c['meta']['name'] ?? ''));
                if ($tbl && $col) {
                    $tables[]  = $tbl;
                    $columns[] = "$tbl.$col";
                }
            }
        }

        // 3) Ensure selected tables & their schema columns are allowed (prevents false negatives)
        foreach ($tablesToUse as $t) {
            $lt = strtolower($t);
            $tables[] = $lt;
            $info = $this->schema['tables'][$t] ?? null;
            if ($info && !empty($info['columns'])) {
                foreach ($info['columns'] as $col) {
                    $columns[] = strtolower($t . '.' . $col['name']);
                }
            }
        }

        $tables  = array_values(array_unique($tables));
        $columns = array_values(array_unique($columns));

        return ['tables' => $tables, 'columns' => $columns];
    }

    private function enforceGrounding(string $sql, array $contexts): void
    {
        $allowedTables  = [];
        $allowedColumns = [];

        // If we were passed an explicit allowlist (from buildGroundingAllowlist)
        if (isset($contexts['tables']) || isset($contexts['columns'])) {
            $allowedTables  = array_map('strtolower', $contexts['tables']  ?? []);
            $allowedColumns = array_map('strtolower', $contexts['columns'] ?? []);
        } else {
            // Back-compat: derive from raw retriever contexts (meta.table/meta.name)
            foreach ($contexts as $c) {
                if (($c['type'] ?? '') === 'table') {
                    $tbl = $c['meta']['table'] ?? null;
                    if ($tbl) $allowedTables[] = strtolower($tbl);
                }
                if (($c['type'] ?? '') === 'column') {
                    $tbl = $c['meta']['table'] ?? null;
                    $col = $c['meta']['name'] ?? null;
                    if ($tbl && $col) $allowedColumns[] = strtolower("$tbl.$col");
                }
            }
            $allowedTables  = array_values(array_unique($allowedTables));
            $allowedColumns = array_values(array_unique($allowedColumns));
        }

        // Build alias → table map from FROM/JOIN
        preg_match_all(
            '/\b(?:FROM|JOIN)\s+`?([a-zA-Z0-9_]+)`?(?:\s+(?:AS\s+)?`?([a-zA-Z0-9_]+)`?)?/i',
            $sql,
            $am,
            PREG_SET_ORDER
        );
        $aliasToTable = [];
        foreach ($am as $m) {
            $table = strtolower($m[1]);
            $alias = strtolower($m[2] ?? $m[1]);
            $aliasToTable[$alias] = $table;
            // also include canonical table (no alias) for completeness
            $aliasToTable[$table] = $table;
        }

        // Normalize used tables to real table names
        $usedTables = array_values(array_unique(array_values($aliasToTable)));

        // Extract and normalize qualified columns (alias → table)
        preg_match_all('/`?([a-zA-Z0-9_]+)`?\.`?([a-zA-Z0-9_]+)`?/i', $sql, $cm, PREG_SET_ORDER);
        $usedColumns = [];
        foreach ($cm as $m) {
            $qual = strtolower($m[1]);  // alias or table
            $col  = strtolower($m[2]);
            $tbl  = $aliasToTable[$qual] ?? $qual;
            $usedColumns[] = "$tbl.$col";
        }
        $usedColumns = array_values(array_unique($usedColumns));

        foreach ($usedTables as $t) {
            if (!in_array($t, $allowedTables, true)) {
                throw new RuntimeException("Grounding: table '$t' not in contexts");
            }
        }
        foreach ($usedColumns as $tc) {
            if (!in_array($tc, $allowedColumns, true)) {
                throw new RuntimeException("Grounding: column '$tc' not in contexts");
            }
        }
    }
}
