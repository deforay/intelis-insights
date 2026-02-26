<?php

declare(strict_types=1);

namespace App\Services;

use Throwable;
use RuntimeException;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Cache\Psr16Cache;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;

/**
 * Orchestrates the full question-to-SQL pipeline using LLM + RAG.
 *
 * Pipeline:  validate → detect intent → select tables → RAG context → LLM → validate SQL
 *
 * Adapted from old-intelis-insights to use the LLM sidecar (LlmClient) and
 * the existing RagClient instead of the former LlmRouter / RetrieverService.
 */
final class QueryService
{
    private array $appCfg;
    private array $businessRules;
    private array $fieldGuide;
    private array $schema;
    private array $allowedTables;

    private LlmClient $llm;
    private RagClient $rag;
    private ConversationContextService $contextService;

    private ?CacheInterface $cache = null;
    private string $cacheBuster = '0';

    public function __construct(
        array $appCfg,
        array $businessRules,
        array $fieldGuide,
        array $schema,
        LlmClient $llm,
        RagClient $rag,
        ?ConversationContextService $contextService = null,
        ?CacheInterface $cache = null,
    ) {
        $this->appCfg        = $appCfg;
        $this->businessRules = $businessRules;
        $this->fieldGuide    = $fieldGuide;
        $this->schema        = $schema;
        $this->allowedTables = $this->extractAllowedTables($schema);
        $this->llm           = $llm;
        $this->rag           = $rag;
        $this->contextService = $contextService ?? new ConversationContextService();

        $this->cacheBuster = (string) ($this->appCfg['cache']['buster'] ?? '0');
        $this->cache       = $cache ?? $this->createCacheFromConfig($appCfg);
    }

    // ── Cache ────────────────────────────────────────────────────────

    private function createCacheFromConfig(array $cfg): ?CacheInterface
    {
        $driver    = $cfg['cache']['driver'] ?? 'file';
        $namespace = $cfg['cache']['namespace'] ?? 'insights';

        try {
            if ($driver === 'file') {
                $path = $cfg['cache']['path'] ?? dirname(__DIR__, 2) . '/var/cache';
                @mkdir($path, 0775, true);
                $adapter = new FilesystemAdapter($namespace, 0, $path);
                return new Psr16Cache($adapter);
            }
            return null;
        } catch (Throwable) {
            return null;
        }
    }

    private function cacheGet(string $key, mixed $default = null): mixed
    {
        return $this->cache?->get($key, $default) ?? $default;
    }

    private function cacheSet(string $key, mixed $value, int $ttl = 300): void
    {
        $this->cache?->set($key, $value, $ttl);
    }

    // ── Main Pipeline ────────────────────────────────────────────────

    public function processQuery(string $query): array
    {
        $startTime = microtime(true);

        $this->validateQueryAgainstBusinessRules($query);

        $conversationContext = $this->contextService->getContextForNewQuery($query);

        try {
            $intentAnalysis = $this->detectQueryIntentWithBusinessRules($query, $conversationContext);

            if (!is_array($intentAnalysis) || !isset($intentAnalysis['type']) || !isset($intentAnalysis['intents'])) {
                $intentAnalysis = ['type' => 'single', 'intents' => ['general']];
            }

            $intentType = $intentAnalysis['type'];
            $intents    = $intentAnalysis['intents'];

            if (($intentAnalysis['domain_relevance'] ?? '') === 'low') {
                $issues = $intentAnalysis['issues'] ?? ['unrelated_to_domain'];
                throw new RuntimeException('Query appears unrelated to laboratory/medical domain: ' . implode(', ', $issues));
            }
        } catch (Throwable $e) {
            if (str_contains($e->getMessage(), 'unrelated to laboratory')) {
                throw $e;
            }
            $intentType     = 'single';
            $intents        = ['general'];
            $intentAnalysis = ['type' => $intentType, 'intents' => $intents];
        }

        $tablesToUse = $this->selectRelevantTablesWithBusinessRules($query, $intentType, $conversationContext);

        $ragContexts = [];
        if ($this->rag->isEnabled()) {
            $ragContexts = $this->retrieveIntentContexts($query, $tablesToUse, 15);
        }

        $context = empty($ragContexts)
            ? $this->buildPromptContext($intentType, $tablesToUse, $query, $conversationContext)
            : $this->buildStrictRagPack($ragContexts, $tablesToUse);

        // Inject conversation context into the prompt when user references prior turns
        if (!empty($conversationContext['has_context'])) {
            $llmBlock = $this->contextService->buildContextForLlm();
            if ($llmBlock !== '') {
                $context['conversation_llm_block'] = $llmBlock;
            }
        }

        $sqlResult = $this->callLLM($context, $query);
        $rawSql    = $sqlResult['sql'];

        // Soft domain hints (logged as concerns, never block the query)
        if (preg_match('/\blabs?\b/i', $query)) {
            if (!preg_match('/(^|[^a-z0-9_`])lab_id([^a-z0-9_`]|$)/i', $rawSql)) {
                $sqlResult['concerns'][] = "Hint: expected lab_id JOIN for 'by lab'.";
            }
        }
        if (!preg_match('/collect(ed|ion)|sample_collection_date/i', $query)) {
            if (!preg_match('/(^|[^a-z0-9_`])sample_tested_datetime([^a-z0-9_`]|$)/i', $rawSql)) {
                $sqlResult['concerns'][] = "Hint: prefer sample_tested_datetime for time filters.";
            }
        }

        $verification = $sqlResult['verification'];

        // RAG grounding check (soft — log warnings but don't block valid queries)
        if (!empty($ragContexts)) {
            try {
                $allowlist = $this->buildGroundingAllowlist($ragContexts, $context['rag_json'] ?? null, $tablesToUse);
                $this->enforceGrounding($rawSql, $allowlist);
            } catch (Throwable $g) {
                // Log grounding concern but don't block — the schema allowlist
                // already includes all columns from selected tables
                error_log('Grounding warning: ' . $g->getMessage());
                $sqlResult['concerns'][] = $g->getMessage();
            }
        }

        $finalSql        = $this->validateSql($rawSql);
        $actualTablesUsed = $this->extractTablesFromSQL($finalSql);

        return [
            'sql'                   => $finalSql,
            'raw_sql'               => $rawSql,
            'verification'          => $verification,
            'concerns'              => $sqlResult['concerns'] ?? [],
            'intent'                => $intentType,
            'intent_details'        => $intents,
            'intent_debug'          => $intentAnalysis,
            'tables_selected'       => $tablesToUse,
            'tables_used'           => $actualTablesUsed,
            'conversation_context'  => $conversationContext,
            'processing_time_ms'    => round((microtime(true) - $startTime) * 1000),
            'citations'             => $sqlResult['citations'] ?? [],
            'retrieved_context_ids' => array_map(fn($c) => $c['id'] ?? '', $ragContexts),
        ];
    }

    /**
     * After executing the SQL, call this to store the turn in conversation history.
     */
    public function addToConversationHistory(string $query, array $queryResult, array $dbResult): void
    {
        $this->contextService->addQuery($query, $queryResult, $dbResult);
    }

    public function clearConversationHistory(): void
    {
        $this->contextService->clearHistory();
    }

    public function getConversationHistory(): array
    {
        return $this->contextService->getHistory();
    }

    public function getConversationHistoryItem(int $index): ?array
    {
        return $this->contextService->getHistoryItem($index);
    }

    public function rewindConversation(int $index): void
    {
        $this->contextService->rewindTo($index);
    }

    // ── Validation ───────────────────────────────────────────────────

    private function validateQueryAgainstBusinessRules(string $query): void
    {
        $validationRules = $this->businessRules['validation_rules'] ?? [];

        $rejectPatterns = $validationRules['reject_patterns'] ?? [];
        foreach ($rejectPatterns as $pattern) {
            if (preg_match($pattern, $query)) {
                throw new RuntimeException('Query contains disallowed operations');
            }
        }

        $queryLower    = strtolower(trim($query));
        $broadPatterns = [
            '/^(show|list|get|select)?\s*(all|everything|\*)\s*$/i',
            '/^(dump|export)\s/i',
            '/^(select\s+\*|all\s+data)/i',
        ];

        foreach ($broadPatterns as $pattern) {
            if (preg_match($pattern, $queryLower)) {
                throw new RuntimeException('Query is too broad - please be more specific about what data you need');
            }
        }
    }

    // ── Intent Detection ─────────────────────────────────────────────

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

        $contextInfo = '';
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
        $intentContexts = $this->retrieveIntentFacts($query, 14);
        $intentPack     = $this->buildIntentRagPack($intentContexts);

        $system = <<<SYS
You classify a medical lab analytics question. Use ONLY the ALLOWLIST facts below
(synonyms, test types, validation/rules, exemplars). If unsure, set domain_relevance="low".

Return ONLY JSON:
{
  "type": "single" | "multi_part",
  "intents": string[],
  "test_types": string[],
  "tables": string[],
  "domain_relevance": "high" | "medium" | "low",
  "references_previous": true | false,
  "assumptions": string[]
}
SYS;

        $user = <<<USR
ALLOWLIST:
{$intentPack['rag_json']}

$businessContext
$contextInfo

QUESTION: {$query}
USR;

        try {
            $raw  = $this->llm->chat($system, $user, temperature: 0.0, maxTokens: 300);
            $resp = $this->extractJson($raw);

            if (is_array($resp)) {
                $resp['type']                = $resp['type'] ?? 'single';
                $resp['intents']             = array_values(array_filter($resp['intents'] ?? []));
                $resp['test_types']          = array_values(array_filter($resp['test_types'] ?? []));
                $resp['tables']              = array_values(array_filter($resp['tables'] ?? []));
                $resp['domain_relevance']    = $resp['domain_relevance'] ?? 'medium';
                $resp['references_previous'] = (bool) ($resp['references_previous'] ?? false);
                $resp['assumptions']         = array_values(array_filter($resp['assumptions'] ?? []));

                if (empty($resp['tables']) && !empty($resp['test_types'])) {
                    $resp['tables'] = $this->mapTestTypesToTables($resp['test_types']);
                }
                if (empty($resp['intents'])) {
                    $resp['intents'] = ['general'];
                }

                return $resp;
            }
        } catch (Throwable) {
            // fall through to regex fallback
        }

        return $this->fallbackIntentDetection($query);
    }

    private function fallbackIntentDetection(string $query): array
    {
        $q       = strtolower($query);
        $intents = [];
        $type    = 'single';

        if (preg_match('/\b(how many|count|number of|total)\b/', $q)) {
            $intents[] = 'count';
        }
        if (preg_match('/\b(list|show|display|all|get)\b/', $q)) {
            $intents[] = 'list';
        }
        if (preg_match('/\b(average|mean|sum|max|min)\b/', $q)) {
            $intents[] = 'aggregate';
        }

        if (preg_match('/\b(how many|count|number of).*\b(and|how many|what is|how much)\b/i', $q) || count($intents) > 1) {
            $type = 'multi_part';
        }

        return [
            'type'             => $type,
            'intents'          => !empty($intents) ? $intents : ['general'],
            'domain_relevance' => 'medium',
            'method'           => 'regex_fallback',
        ];
    }

    // ── Table Selection ──────────────────────────────────────────────

    private function selectRelevantTablesWithBusinessRules(string $query, string $intentType, array $conversationContext = []): array
    {
        $qLower         = strtolower($query);
        $selectedTables = [];
        $testTypeLogic  = $this->fieldGuide['test_type_logic'] ?? [];

        $tableGroups = [
            'vl|viral load|hiv|hiv vl|suppression|suppressed|turnaround|tat|test volume|rejection rate|sample' => [$testTypeLogic['vl']['table'] ?? 'form_vl'],
            'covid|coronavirus|covid19|covid-19'    => [$testTypeLogic['covid19']['table'] ?? 'form_covid19'],
            'eid|infant|early infant diagnosis'     => [$testTypeLogic['eid']['table'] ?? 'form_eid'],
            'tb|tuberculosis'                       => ['form_tb'],
            'hepatitis|hep'                         => ['form_hepatitis'],
            'facility|facilities|clinic|lab'        => ['facility_details'],
            'batch|batches'                         => ['batch_details'],
            'user|users|staff'                      => ['user_details'],
        ];

        foreach ($tableGroups as $pattern => $tables) {
            if (preg_match("/\\b($pattern)\\b/i", $qLower)) {
                $selectedTables = array_merge($selectedTables, $tables);
            }
        }

        if (!empty($conversationContext['intent_last']['tables'])) {
            $selectedTables = array_merge($selectedTables, (array) $conversationContext['intent_last']['tables']);
        }

        if (preg_match('/\blab(s)?\b/i', $qLower)) {
            $selectedTables[] = 'facility_details';
        }
        if (preg_match('/\b(province|state|district|county|region|zone)\b/i', $qLower)) {
            $selectedTables[] = 'geographical_divisions';
        }

        if (!empty($conversationContext['has_context']) && empty($selectedTables)) {
            $commonTables = $conversationContext['common_tables'] ?? [];
            if (!empty($commonTables)) {
                $selectedTables = array_merge($selectedTables, $commonTables);
            }
        }

        $selectedTables = array_unique($selectedTables);
        $selectedTables = array_intersect($selectedTables, $this->allowedTables);

        if (empty($selectedTables)) {
            if (preg_match('/\b(patient|test|testing|tests|sample|result|results)\b/i', $qLower)) {
                $selectedTables = [$testTypeLogic['vl']['table'] ?? 'form_vl'];
            } else {
                $selectedTables = ['facility_details'];
            }
        }

        // If facility_details is selected but no test form table, and the question
        // implies test data (turnaround, count, average, rate, etc.), add form_vl
        $hasTestForm = !empty(array_intersect($selectedTables, ['form_vl', 'form_eid', 'form_covid19', 'form_tb', 'form_hepatitis', 'form_cd4', 'form_generic']));
        if (!$hasTestForm && preg_match('/\b(turnaround|average|count|total|rate|volume|monthly|yearly|trend|how many|number of)\b/i', $qLower)) {
            array_unshift($selectedTables, $testTypeLogic['vl']['table'] ?? 'form_vl');
        }

        $maxTables = $this->businessRules['validation_rules']['scope_limits']['max_tables_per_query'] ?? 3;
        return array_slice($selectedTables, 0, $maxTables);
    }

    // ── RAG Retrieval ────────────────────────────────────────────────

    private function retrieveIntentContexts(string $query, array $tablesToUse, int $k = 15): array
    {
        $filters = [
            'type'  => ['column', 'rule', 'table', 'relationship', 'threshold', 'exemplar', 'validation'],
            'table' => array_values(array_unique($tablesToUse)),
        ];

        $ttl = (int) ($this->appCfg['cache']['ttl'] ?? 300);
        $key = $this->makeCacheKey('rag.search', $query, $filters, $tablesToUse, $k);

        $cached = $this->cacheGet($key);
        if (is_array($cached)) {
            return $cached;
        }

        $contexts = $this->rag->search($query, $k, $filters);
        $this->cacheSet($key, $contexts, $ttl);
        return $contexts;
    }

    private function retrieveIntentFacts(string $query, int $k = 14): array
    {
        $filters = [
            'type' => ['syn', 'test_type', 'rule', 'validation', 'exemplar', 'threshold'],
        ];

        $ttl = (int) ($this->appCfg['cache']['ttl'] ?? 300);
        $key = $this->makeCacheKey('rag.intent', $query, $filters, [], $k);

        $cached = $this->cacheGet($key);
        if (is_array($cached)) {
            return $cached;
        }

        $contexts = $this->rag->search($query, $k, $filters);
        $this->cacheSet($key, $contexts, $ttl);
        return $contexts;
    }

    private function buildIntentRagPack(array $contexts): array
    {
        $allowed = ['syn', 'test_type', 'rule', 'validation', 'exemplar', 'threshold'];

        usort($contexts, fn($a, $b) => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));
        $contexts = array_values(array_filter($contexts, fn($c) => in_array($c['type'] ?? '', $allowed, true)));
        $contexts = array_slice($contexts, 0, 20);

        $pack = [];
        foreach ($contexts as $c) {
            $x = trim(preg_replace('/\s+/', ' ', (string) ($c['text'] ?? '')));
            if (strlen($x) > 220) {
                $x = substr($x, 0, 220) . '…';
            }
            $pack[] = ['id' => (string) ($c['id'] ?? ''), 't' => (string) ($c['type'] ?? ''), 'x' => $x];
        }

        return ['rag_json' => json_encode($pack, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)];
    }

    // ── Strict RAG Pack ──────────────────────────────────────────────

    private function buildStrictRagPack(array $contexts, array $tablesToUse): array
    {
        $allowedTypes    = ['table', 'column', 'relationship', 'validation', 'rule', 'exemplar', 'threshold'];
        $allowedTableSet = array_flip($tablesToUse);

        $filtered = [];
        foreach ($contexts as $c) {
            $t = (string) ($c['type'] ?? '');
            if (!in_array($t, $allowedTypes, true)) {
                continue;
            }

            $mt = strtolower((string) ($c['meta']['table'] ?? ''));
            if ($mt !== '' && !isset($allowedTableSet[$mt])) {
                continue;
            }

            $c['text'] = trim(preg_replace('/\s+/', ' ', (string) ($c['text'] ?? '')));
            if (strlen($c['text']) > 220) {
                $c['text'] = substr($c['text'], 0, 220) . '…';
            }
            $filtered[] = $c;
        }

        $schemaEssentials = $this->schemaDerivedEssentials($tablesToUse);
        $schemaRels       = $this->schemaDerivedRelationships($tablesToUse);

        $byId = [];
        foreach (array_merge($filtered, $schemaEssentials, $schemaRels) as $item) {
            $id = (string) ($item['id'] ?? '');
            if ($id !== '') {
                $byId[$id] = $item;
            }
        }

        foreach ($tablesToUse as $tbl) {
            $id = "table:$tbl";
            if (!isset($byId[$id])) {
                $byId[$id] = [
                    'id' => $id, 'type' => 'table',
                    'text' => "$tbl (base table)", 'meta' => ['table' => $tbl], 'score' => 1.0,
                ];
            }
        }

        $rank = fn($x) => match ($x['type'] ?? '') {
            'relationship' => 100, 'column' => 90, 'validation' => 80,
            'rule' => 70, 'exemplar' => 60, 'threshold' => 50, 'table' => 40,
            default => 10,
        };

        $all = array_values($byId);
        usort($all, function ($a, $b) use ($rank) {
            $ra = $rank($a);
            $rb = $rank($b);
            return $ra === $rb ? (($b['score'] ?? 0) <=> ($a['score'] ?? 0)) : ($rb <=> $ra);
        });

        $all  = array_slice($all, 0, 24);
        $pack = [];
        foreach ($all as $c) {
            $pack[] = ['id' => (string) $c['id'], 't' => (string) $c['type'], 'x' => (string) $c['text']];
        }

        // Build compact schema listing for selected tables (all columns available)
        $schemaBlock = '';
        foreach ($tablesToUse as $tbl) {
            $tableInfo = $this->schema['tables'][$tbl] ?? null;
            if (!$tableInfo) continue;
            $cols = array_map(fn($c) => $c['name'], $tableInfo['columns'] ?? []);
            $schemaBlock .= "\n$tbl: " . implode(', ', $cols);
        }

        return [
            'rag_json' => json_encode($pack, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'schema_block' => $schemaBlock,
        ];
    }

    // ── Schema Derived Helpers ───────────────────────────────────────

    private function schemaDerivedEssentials(array $tablesToUse): array
    {
        $out     = [];
        $emitCol = function (string $table, string $name, ?string $sqlType = null, ?bool $nullable = null, string $extra = '') use (&$out) {
            $id  = "col:$table.$name";
            $txt = "$table.$name" . ($sqlType ? " ($sqlType" . ($nullable === null ? '' : ', ' . ($nullable ? 'NULL' : 'NOT NULL')) . ')' : '');
            if ($extra) {
                $txt .= ": $extra";
            }
            $out[] = ['id' => $id, 'type' => 'column', 'text' => $txt, 'meta' => ['table' => $table, 'name' => $name], 'score' => 0.99];
        };

        $needByTable = [];
        foreach ($tablesToUse as $t) {
            $needByTable[$t] = ['sample_tested_datetime', 'sample_collection_date', 'lab_id', 'result_value_absolute', 'result'];
        }

        if (in_array('facility_details', $tablesToUse, true)) {
            foreach (['facility_id', 'facility_name', 'facility_code', 'facility_state_id', 'facility_district_id'] as $col) {
                if ($this->tableHasColumn('facility_details', $col)) {
                    $emitCol('facility_details', $col);
                }
            }

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
            if (!$tableInfo) {
                continue;
            }
            foreach ($tableInfo['columns'] ?? [] as $c) {
                $name = strtolower($c['name']);
                foreach ($fields as $f) {
                    if ($name === strtolower($f)) {
                        $emitCol($table, $c['name'], $c['type'] ?? null, isset($c['nullable']) ? (bool) $c['nullable'] : null, $c['comment'] ?? '');
                        break;
                    }
                }
            }
        }

        return $out;
    }

    private function schemaDerivedRelationships(array $tablesToUse): array
    {
        $out     = [];
        $rel     = $this->schema['relationships'] ?? [];
        $allowed = array_flip($tablesToUse);

        foreach ($rel as $r) {
            $fromT = (string) ($r['from_table'] ?? '');
            $toT   = (string) ($r['to_table'] ?? '');
            if ($fromT === '' || $toT === '') {
                continue;
            }
            if (!isset($allowed[$fromT]) && !isset($allowed[$toT])) {
                continue;
            }

            $fromC = (string) ($r['from_column'] ?? '');
            $toC   = (string) ($r['to_column'] ?? '');
            $id    = "relationship:$fromT.$fromC->$toT.$toC";
            $out[] = ['id' => $id, 'type' => 'relationship', 'text' => "$fromT.$fromC -> $toT.$toC", 'meta' => ['table' => $fromT], 'score' => 1.0];
        }

        // Ensure canonical lab joins even without FK
        if (in_array('facility_details', $tablesToUse, true)) {
            foreach ($tablesToUse as $t) {
                if ($t === 'facility_details') {
                    continue;
                }
                if ($this->tableHasColumn($t, 'lab_id')) {
                    $id       = "relationship:$t.lab_id->facility_details.facility_id";
                    $out[$id] = ['id' => $id, 'type' => 'relationship', 'text' => "$t.lab_id -> facility_details.facility_id", 'meta' => ['table' => $t], 'score' => 1.0];

                    if ($this->tableHasColumn('facility_details', 'facility_name')) {
                        $cid            = 'col:facility_details.facility_name';
                        $out[$cid]      = ['id' => $cid, 'type' => 'column', 'text' => 'facility_details.facility_name (label to display testing lab)', 'meta' => ['table' => 'facility_details', 'name' => 'facility_name'], 'score' => 0.98];
                    }
                }
            }

            if (isset($this->schema['tables']['geographical_divisions'])) {
                if ($this->tableHasColumn('facility_details', 'facility_state_id') && $this->tableHasColumn('geographical_divisions', 'geo_id')) {
                    $id       = 'relationship:facility_details.facility_state_id->geographical_divisions.geo_id';
                    $out[$id] = ['id' => $id, 'type' => 'relationship', 'text' => 'facility_details.facility_state_id -> geographical_divisions.geo_id', 'meta' => ['table' => 'facility_details'], 'score' => 1.0];
                }
                if ($this->tableHasColumn('facility_details', 'facility_district_id') && $this->tableHasColumn('geographical_divisions', 'geo_id')) {
                    $id       = 'relationship:facility_details.facility_district_id->geographical_divisions.geo_id';
                    $out[$id] = ['id' => $id, 'type' => 'relationship', 'text' => 'facility_details.facility_district_id -> geographical_divisions.geo_id', 'meta' => ['table' => 'facility_details'], 'score' => 1.0];
                }
                $out['table:geographical_divisions'] = ['id' => 'table:geographical_divisions', 'type' => 'table', 'text' => 'geographical_divisions (lookup of administrative units)', 'meta' => ['table' => 'geographical_divisions'], 'score' => 1.0];
            }

            $out = array_values($out);
        }

        return $out;
    }

    // ── Legacy Prompt Context (fallback when RAG empty) ──────────────

    private function buildPromptContext(string $intent, array $tablesToUse, string $query, array $conversationContext = []): array
    {
        return [
            'schema'               => $this->buildSchemaInfo($tablesToUse),
            'relationships'        => $this->buildRelationshipsInfo($tablesToUse),
            'reference_data'       => $this->buildReferenceDataInfo($tablesToUse),
            'business_rules'       => $this->buildBusinessRulesContext($intent, $tablesToUse),
            'field_guide'          => $this->buildFieldGuideContext($tablesToUse),
            'conversation_context' => $this->buildConversationContext($conversationContext),
            'intent'               => $this->getIntentGuidance($intent),
        ];
    }

    private function buildConversationContext(array $conversationContext): string
    {
        if (empty($conversationContext['has_context'])) {
            return '';
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

    private function buildBusinessRulesContext(string $intent, array $tablesToUse): string
    {
        $context = "BUSINESS RULES:\n";

        $privacyRules = $this->businessRules['global_rules']['privacy'] ?? [];
        if (!empty($privacyRules)) {
            $context .= "Privacy Requirements:\n";
            $forbiddenCols = $privacyRules['forbidden_columns'] ?? [];
            $context .= "- NEVER add these in select query: " . implode(', ', array_slice($forbiddenCols, 0, 8)) . "\n";
        }

        $intentRules = $this->businessRules['intent_rules'][$intent] ?? [];
        if (!empty($intentRules['rules'])) {
            $context .= "\n{$intent} Query Rules:\n";
            foreach ($intentRules['rules'] as $rule) {
                $context .= "- $rule\n";
            }
        }

        $defaultAssumptions = $this->businessRules['global_rules']['default_assumptions']['rules'] ?? [];
        if (!empty($defaultAssumptions)) {
            $context .= "\nDefault Assumptions:\n";
            foreach (array_slice($defaultAssumptions, 0, 3) as $assumption) {
                $context .= "- $assumption\n";
            }
        }

        return $context;
    }

    private function buildFieldGuideContext(array $tablesToUse): string
    {
        $context = "TERMINOLOGY MAPPING:\n";
        foreach ($this->fieldGuide['terminology_mapping'] as $term => $column) {
            $context .= "- \"$term\" = $column\n";
        }

        foreach ($tablesToUse as $table) {
            $testType = null;
            foreach ($this->fieldGuide['test_type_logic'] ?? [] as $type => $config) {
                if ($config['table'] === $table) {
                    $testType = $type;
                    break;
                }
            }

            $clinicalThresholds = $this->fieldGuide['clinical_thresholds'] ?? [];
            if ($testType && isset($clinicalThresholds[$testType])) {
                $context .= "\n" . strtoupper($testType) . " CLINICAL THRESHOLDS:\n";
                foreach ($clinicalThresholds[$testType]['thresholds'] ?? [] as $name => $info) {
                    $context .= "- $name: {$info['condition']} // {$info['description']}\n";
                }
            }
        }

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
        $info = "TABLES AND COLUMNS:\n";

        foreach ($tablesToUse as $table) {
            $tableInfo = $this->schema['tables'][$table] ?? null;
            if (!$tableInfo) {
                continue;
            }

            $info .= "\n$table ({$tableInfo['type']}):\n";
            foreach (array_slice($tableInfo['columns'] ?? [], 0, 20) as $column) {
                $name     = $column['name'];
                $type     = $column['type'];
                $nullable = $column['nullable'] ? 'NULL' : 'NOT NULL';
                $key      = $column['key'] ? " [{$column['key']}]" : '';

                $info .= "  - $name ($type, $nullable)$key";
                if (strtolower($name) === 'lab_id') {
                    $info .= ' // JOIN facility_details ON lab_id for lab names';
                }
                if (!empty($column['comment'])) {
                    $info .= " // {$column['comment']}";
                }
                $info .= "\n";
            }
        }

        return $info;
    }

    private function buildRelationshipsInfo(array $tablesToUse): string
    {
        if (empty($this->schema['relationships'])) {
            return '';
        }

        $info    = "TABLE RELATIONSHIPS:\n";
        $relevant = [];

        foreach ($this->schema['relationships'] as $r) {
            if (in_array($r['from_table'], $tablesToUse) || in_array($r['to_table'], $tablesToUse)) {
                $relevant[] = $r;
            }
        }

        foreach ($relevant as $rel) {
            $info .= "- {$rel['from_table']}.{$rel['from_column']} -> {$rel['to_table']}.{$rel['to_column']}\n";
        }

        return $info;
    }

    private function buildReferenceDataInfo(array $tablesToUse): string
    {
        if (empty($this->schema['reference_data'])) {
            return '';
        }

        $info = "REFERENCE DATA (Sample values for lookup tables):\n";

        foreach ($tablesToUse as $table) {
            $refData = $this->schema['reference_data'][$table] ?? null;
            if (!$refData || empty($refData['data'])) {
                continue;
            }

            $info .= "\n$table (showing {$refData['sample_rows']} of {$refData['total_rows']} rows):\n";
            foreach (array_slice($refData['data'], 0, 5) as $row) {
                $values = [];
                foreach ($row as $key => $value) {
                    $values[] = "$key: " . (is_string($value) ? "'$value'" : $value);
                }
                $info .= '  - ' . implode(', ', array_slice($values, 0, 3)) . "\n";
            }
        }

        return $info;
    }

    private function getIntentGuidance(string $intent): string
    {
        $intentRules = $this->businessRules['intent_rules'][$intent] ?? [];
        $rules       = $intentRules['rules'] ?? [];

        $guidance = "QUERY TYPE GUIDANCE ($intent):\n";
        foreach ($rules as $rule) {
            $guidance .= "- $rule\n";
        }

        if (isset($intentRules['default_limit'])) {
            $guidance .= "- Default LIMIT: {$intentRules['default_limit']}\n";
        }
        if (isset($intentRules['essential_columns'])) {
            $guidance .= '- Essential columns: ' . implode(', ', $intentRules['essential_columns']) . "\n";
        }

        return $guidance;
    }

    // ── LLM SQL Generation ───────────────────────────────────────────

    private function callLLM(array $context, string $query): array
    {
        $usingRag = isset($context['rag_json']) && $context['rag_json'] !== '' && $context['rag_json'] !== '[]';

        // Inject conversation context block if present
        $conversationBlock = '';
        if (!empty($context['conversation_llm_block'])) {
            $conversationBlock = "\n\n" . $context['conversation_llm_block'];
        }

        if ($usingRag) {
            $system = <<<TXT
You are a strict MySQL SQL generator for a medical lab DB.

ABSOLUTE CONSTRAINTS:
- Use ONLY tables listed in AVAILABLE TABLES below. Never invent table names.
- You may use ANY column from the AVAILABLE TABLES schema listing below.
- The CONTEXT section provides domain-specific rules, thresholds, exemplars, and column semantics — follow them.
- Cite each table you use via "table:<name>" in the "cit" array.
- Cite relevant context items via their id in the "cit" array.
- Prefer human-readable names (e.g., facility_details.facility_name) over raw IDs when grouping/reporting.
- Default date: for VL use form_vl.sample_tested_datetime unless the user asks for collection date.
- Table aliases: use common abbreviations (fv for form_vl, fd for facility_details).
- Privacy: never select patient identifiers (names, phone numbers, addresses); COUNT(DISTINCT ...) allowed for unique counts only.
- For lab breakdowns: select facility_details.facility_name (human-readable), never lab_id (raw ID).
- Check JOIN conditions carefully — foreign keys link to primary keys.
- Always exclude rejected samples: add IFNULL(is_sample_rejected, 'no') = 'no' unless user asks for rejected.

If you truly cannot answer (e.g., the tables don't contain the needed data), respond with:
{"s":"","ok":false,"conf":0.0,"why":"<short reason>","cit":[]}
TXT;

            $schemaBlock = $context['schema_block'] ?? '';

            $user = <<<TXT
AVAILABLE TABLES (you may use any column listed here):
{$schemaBlock}

CONTEXT (rules, thresholds, patterns — follow these):
{$context['rag_json']}
{$conversationBlock}

QUESTION: {$query}

Return ONLY one JSON object: {"s":"<SQL>","ok":true|false,"conf":0..1,"why":"<=120 chars","cit":["<ids>"]}
TXT;

            $raw = $this->llm->chat($system, $user, temperature: 0.0, maxTokens: 1200);
            $out = $this->extractJson($raw);

            if (!$out || (!array_key_exists('s', $out) && !array_key_exists('sql', $out))) {
                throw new RuntimeException('Model did not return JSON in strict RAG mode');
            }

            $sql  = $out['s'] ?? $out['sql'] ?? '';
            $cit  = array_values(array_filter($out['cit'] ?? $out['citations'] ?? []));
            $ok   = (bool) ($out['ok'] ?? false);
            $conf = (float) ($out['conf'] ?? 0.0);
            $why  = (string) ($out['why'] ?? '');

            // If LLM said not ok but provided SQL, lower confidence but proceed
            if (!$ok && $sql !== '') {
                $conf = min($conf, 0.5);
                error_log("LLM set ok=false but provided SQL: $why");
            }

            // Only refuse if there's truly no SQL
            if ($sql === '') {
                throw new RuntimeException('Unable to generate SQL: ' . ($why ?: 'insufficient context'));
            }

            return [
                'sql'          => $sql,
                'verification' => ['matches_intent' => true, 'confidence' => max(0.6, $conf), 'reasoning' => $why],
                'concerns'     => [],
                'citations'    => $cit,
            ];
        }

        // Legacy (non-RAG) branch
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
TXT;

        $user = "{$conversationBlock}\n\nUSER QUESTION: {$query}";

        $raw = $this->llm->chat($system, $user, temperature: 0.0, maxTokens: 600);
        $out = $this->extractJson($raw);

        if (!$out || !isset($out['sql'])) {
            // Fallback: try to extract raw SQL
            $sql = $this->extractSql($raw);
            return [
                'sql'          => $sql,
                'verification' => ['matches_intent' => false, 'confidence' => 0.3, 'reasoning' => 'JSON parse failed; extracted raw SQL'],
                'concerns'     => ['Used fallback SQL extraction due to invalid JSON response'],
                'citations'    => [],
            ];
        }

        return [
            'sql'          => $out['sql'],
            'verification' => $out['verification'] ?? ['matches_intent' => true, 'confidence' => 0.7, 'reasoning' => ''],
            'concerns'     => $out['concerns'] ?? [],
            'citations'    => [],
        ];
    }

    // ── SQL Validation ───────────────────────────────────────────────

    private function validateSql(string $sql): string
    {
        if (!preg_match('/^\s*select\s/i', $sql)) {
            throw new RuntimeException('Non-SELECT SQL returned by LLM');
        }
        if (!preg_match('/\bFROM\s+([a-zA-Z0-9_]+)/i', $sql)) {
            throw new RuntimeException('Missing FROM clause in generated SQL: ' . $sql);
        }

        preg_match_all('/\bfrom\s+([a-zA-Z0-9_]+)|\bjoin\s+([a-zA-Z0-9_]+)/i', $sql, $m);
        $tables = array_filter(array_merge($m[1] ?? [], $m[2] ?? []));

        foreach ($tables as $table) {
            if (!in_array($table, $this->allowedTables, true)) {
                throw new RuntimeException("Disallowed table: {$table}");
            }
        }

        // Privacy checks
        $privacyRules     = $this->businessRules['global_rules']['privacy'] ?? [];
        $forbiddenColumns = $privacyRules['forbidden_columns'] ?? [];
        $allowAggDistinct = array_map('strtolower', $privacyRules['allow_aggregated_distinct'] ?? []);

        $sqlNoStrings = $this->stripStringLiterals($sql);
        $sqlScrubbed  = $sqlNoStrings;

        foreach ($allowAggDistinct as $col) {
            $pattern     = '/count\s*\(\s*distinct\s+[^)]*\b' . preg_quote($col, '/') . '\b[^)]*\)/i';
            $sqlScrubbed = preg_replace($pattern, '/*__SAFE_AGG__*/', $sqlScrubbed);
        }

        foreach ($forbiddenColumns as $column) {
            if (stripos($sqlScrubbed, strtolower($column)) !== false) {
                throw new RuntimeException("Privacy violation: {$column} cannot be returned - $sql");
            }
        }

        return $sql;
    }

    // ── Grounding ────────────────────────────────────────────────────

    private function buildGroundingAllowlist(?array $ragContexts, ?string $ragJson, array $tablesToUse): array
    {
        $tables  = [];
        $columns = [];

        if ($ragJson) {
            $pack = json_decode($ragJson, true);
            if (is_array($pack)) {
                foreach ($pack as $item) {
                    $id = (string) ($item['id'] ?? '');
                    if (str_starts_with($id, 'table:')) {
                        $tables[] = strtolower(substr($id, 6));
                    } elseif (str_starts_with($id, 'col:')) {
                        $parts = explode('.', substr($id, 4), 2);
                        if (count($parts) === 2) {
                            $tables[]  = strtolower($parts[0]);
                            $columns[] = strtolower($parts[0] . '.' . $parts[1]);
                        }
                    }
                }
            }
        }

        foreach ($ragContexts ?? [] as $c) {
            $t = strtolower((string) ($c['type'] ?? ''));
            if ($t === 'table') {
                $tbl = strtolower((string) ($c['meta']['table'] ?? ''));
                if ($tbl) {
                    $tables[] = $tbl;
                }
            } elseif ($t === 'column') {
                $tbl = strtolower((string) ($c['meta']['table'] ?? ''));
                $col = strtolower((string) ($c['meta']['name'] ?? ''));
                if ($tbl && $col) {
                    $tables[]  = $tbl;
                    $columns[] = "$tbl.$col";
                }
            }
        }

        foreach ($tablesToUse as $t) {
            $lt       = strtolower($t);
            $tables[] = $lt;
            $info     = $this->schema['tables'][$t] ?? null;
            if ($info && !empty($info['columns'])) {
                foreach ($info['columns'] as $col) {
                    $columns[] = strtolower($t . '.' . $col['name']);
                }
            }
        }

        return ['tables' => array_values(array_unique($tables)), 'columns' => array_values(array_unique($columns))];
    }

    private function enforceGrounding(string $sql, array $contexts): void
    {
        $allowedTables  = array_map('strtolower', $contexts['tables'] ?? []);
        $allowedColumns = array_map('strtolower', $contexts['columns'] ?? []);

        // Build alias → table map
        preg_match_all('/\b(?:FROM|JOIN)\s+`?([a-zA-Z0-9_]+)`?(?:\s+(?:AS\s+)?`?([a-zA-Z0-9_]+)`?)?/i', $sql, $am, PREG_SET_ORDER);
        $aliasToTable = [];
        foreach ($am as $m) {
            $table = strtolower($m[1]);
            $alias = strtolower($m[2] ?? $m[1]);
            $aliasToTable[$alias] = $table;
            $aliasToTable[$table] = $table;
        }

        $usedTables = array_values(array_unique(array_values($aliasToTable)));
        foreach ($usedTables as $t) {
            if (!in_array($t, $allowedTables, true)) {
                throw new RuntimeException("Grounding: table '$t' not in contexts");
            }
        }

        preg_match_all('/`?([a-zA-Z0-9_]+)`?\.`?([a-zA-Z0-9_]+)`?/i', $sql, $cm, PREG_SET_ORDER);
        $usedColumns = [];
        foreach ($cm as $m) {
            $qual = strtolower($m[1]);
            $col  = strtolower($m[2]);
            $tbl  = $aliasToTable[$qual] ?? $qual;
            $usedColumns[] = "$tbl.$col";
        }
        $usedColumns = array_values(array_unique($usedColumns));

        foreach ($usedColumns as $tc) {
            if (!in_array($tc, $allowedColumns, true)) {
                throw new RuntimeException("Grounding: column '$tc' not in contexts");
            }
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────

    private function extractAllowedTables(array $schema): array
    {
        if (isset($schema['tables']) && is_array($schema['tables'])) {
            $tables = array_keys($schema['tables']);
            if (isset($schema['version']) && version_compare($schema['version'], '2.0', '>=')) {
                return array_filter($tables, fn($t) => ($this->schema['tables'][$t]['type'] ?? 'base table') !== 'view');
            }
            return $tables;
        }
        return array_keys($schema['tables'] ?? $schema['views'] ?? []);
    }

    private function mapTestTypesToTables(array $testTypes): array
    {
        $out = [];
        foreach ($testTypes as $tt) {
            $cfg = $this->fieldGuide['test_type_logic'][$tt] ?? null;
            if ($cfg && !empty($cfg['table'])) {
                $out[] = $cfg['table'];
            }
        }
        return array_values(array_unique(array_intersect($out, $this->allowedTables)));
    }

    private function tableHasColumn(string $table, string $column): bool
    {
        foreach ($this->schema['tables'][$table]['columns'] ?? [] as $col) {
            if (strtolower($col['name']) === strtolower($column)) {
                return true;
            }
        }
        return false;
    }

    private function findNameLikeColumn(string $table): ?string
    {
        $candidates = ['geo_name', 'name', 'division_name', 'state_name', 'district_name', 'province_name', 'title', 'label'];
        $info       = $this->schema['tables'][$table]['columns'] ?? [];

        foreach ($candidates as $want) {
            foreach ($info as $c) {
                if (strcasecmp($c['name'] ?? '', $want) === 0) {
                    return $c['name'];
                }
            }
        }
        foreach ($info as $c) {
            if (stripos($c['name'] ?? '', 'name') !== false) {
                return $c['name'];
            }
        }
        return null;
    }

    private function extractTablesFromSQL(string $sql): array
    {
        $tables = [];
        if (preg_match_all('/\bFROM\s+(\w+)/i', $sql, $m)) {
            $tables = array_merge($tables, $m[1]);
        }
        if (preg_match_all('/\bJOIN\s+(\w+)/i', $sql, $m)) {
            $tables = array_merge($tables, $m[1]);
        }
        return array_unique($tables);
    }

    private function stripStringLiterals(string $sql): string
    {
        $sql = preg_replace("/'(?:''|\\\\'|[^'])*'/", "''", $sql);
        $sql = preg_replace('/"(?:\\\\"|[^"])*"/', '""', $sql);
        return $sql;
    }

    private function makeCacheKey(string $prefix, string $query, array $filters, array $tablesToUse, int $k): string
    {
        $finger = [
            'v' => $this->cacheBuster, 'db' => $this->schema['database'] ?? '',
            'tables' => array_values($tablesToUse), 'k' => $k, 'q' => $query, 'f' => $filters,
        ];
        $raw = $prefix . '.' . sha1(json_encode($finger, JSON_UNESCAPED_SLASHES));
        return $this->sanitizeCacheKey($raw);
    }

    private function sanitizeCacheKey(string $key): string
    {
        $key = preg_replace('/[{}()\/\\\\@:]+/', '.', $key);
        $key = preg_replace('/\.+/', '.', $key);
        return strlen($key) > 120 ? substr($key, 0, 120) : $key;
    }

    /**
     * Extract JSON from an LLM response that may include markdown fencing.
     */
    private function extractJson(string $raw): ?array
    {
        $raw = trim($raw);

        // Try direct parse first
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        // Extract from markdown code blocks
        if (preg_match('/```(?:json)?\s*\n?(.*?)\n?```/s', $raw, $m)) {
            $decoded = json_decode(trim($m[1]), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        // Try to find JSON object in the response
        if (preg_match('/\{[^{}]*(?:\{[^{}]*\}[^{}]*)*\}/s', $raw, $m)) {
            $decoded = json_decode($m[0], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * Extract SQL from an LLM response (fallback when JSON parsing fails).
     * Ported from old AbstractLlmClient::extractSql().
     */
    private function extractSql(string $response): string
    {
        $original = $response;

        // Step 1: Complete markdown code blocks
        if (preg_match('/```(?:sql)?\s*(SELECT\s+.*?)\s*```/is', $response, $m)) {
            return $this->cleanSql($m[1]);
        }

        // Step 2: Incomplete markdown (missing closing backticks)
        if (preg_match('/```(?:sql)?\s*(SELECT\s+.*)/is', $response, $m)) {
            return $this->cleanSql($m[1]);
        }

        // Step 3: Remove markdown and common prefixes
        $cleaned = preg_replace('/```(?:sql)?\s*([\s\S]*?)\s*```/i', '$1', $response);
        $cleaned = preg_replace('/^(MySQL compatible SELECT statement:?|SQL:?|Query:?)\s*/i', '', $cleaned);
        $cleaned = trim($cleaned);

        if (preg_match('/^\s*SELECT\s+.+?\s+FROM\s+/is', $cleaned)) {
            return $this->cleanSql($cleaned);
        }

        // Step 4: Find complete SELECT anywhere
        if (preg_match('/SELECT\s+.*?FROM\s+\w+(?:\s+(?:WHERE|GROUP BY|ORDER BY|LIMIT|HAVING)\s+.*?)*(?=\s*$|\s*[;.]|\s*```)/is', $response, $m)) {
            return $this->cleanSql($m[0]);
        }

        // Step 5: Simple fallback
        if (preg_match('/SELECT\s+.*?FROM\s+\w+/is', $response, $m)) {
            return $this->cleanSql($m[0]);
        }

        throw new RuntimeException('No valid SQL in LLM response: ' . substr($original, 0, 200));
    }

    private function cleanSql(string $sql): string
    {
        $sql = trim($sql);
        $sql = rtrim($sql, ';.,');
        $sql = preg_replace('/\s+/', ' ', $sql);
        return $sql;
    }
}
