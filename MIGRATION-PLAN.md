# Migration: Replace QueryCompiler with LLM-Generates-SQL (RAG approach)

## Context

The current `intelis-insights` system uses a deterministic pipeline: **IntentService → PlannerService → QueryCompiler → DatabaseService**. The LLM only produces structured "plans" (metric, dimensions, filters) that get compiled into SQL by a rigid QueryCompiler. This is too constraining — only answers questions about pre-defined metrics in `metrics/vl.yml`.

The old system (`old-intelis-insights`) used a more flexible approach: **schema + business-rules + field-guide → RAG → LLM generates SQL directly**. The LLM had full domain knowledge via 2,400+ RAG snippets and could handle arbitrary analytical questions.

**Goal:** Bring back the LLM-generates-SQL approach while keeping the new infrastructure (LLM sidecar, dashboard, reports, RAG API).

**Target database:** `vlsm` (raw tables: form_vl, form_eid, facility_details, etc.)

---

## Architecture Change

```
BEFORE (deterministic):
  Question → IntentService(LLM) → PlannerService(LLM) → QueryCompiler(code) → SQL

AFTER (LLM-generates-SQL):
  Question → QueryService(validate → intent → RAG context → LLM → SQL) → SQL
```

---

## Parallelizable Task Groups

### WAVE 1 — Independent file additions (all run in parallel)

#### Task 1A: Port config files
Copy and adapt configuration files from old codebase.

**Files to create:**
- `config/business-rules.php` — copy from `~/www/old-intelis-insights/config/business-rules.php` as-is (232 lines). Contains: privacy rules (forbidden columns, aggregated-only columns), default assumptions, intent-specific rules, validation rules, response formatting, contextual rules.
- `config/field-guide.php` — copy from `~/www/old-intelis-insights/config/field-guide.php` as-is (290 lines). Contains: terminology mapping, clinical thresholds, test type logic, column semantics, query patterns, generic patterns, field validation.

**Files to modify:**
- `config/app.php` — merge in these keys (keep existing `llm` and `rag` blocks):
  ```php
  'schema_path' => __DIR__ . '/../var/schema.json',
  'db_name' => 'vlsm',
  'default_limit' => 200,
  'rag_base_url' => $env('RAG_BASE_URL', 'http://127.0.0.1:8089'),
  'rag_enabled' => filter_var($env('RAG_ENABLED', 'true'), FILTER_VALIDATE_BOOLEAN),
  'cache' => [
      'driver' => $env('CACHE_DRIVER', 'file'),
      'namespace' => 'insights',
      'path' => __DIR__ . '/../var/cache',
      'ttl' => 300,
      'redis_dsn' => $env('REDIS_DSN', 'redis://127.0.0.1:6379'),
      'buster' => $env('CACHE_BUSTER', (string) @filemtime(__DIR__ . '/../corpus/snippets.jsonl')),
  ],
  ```
- `config/db.php` — add a second connection for vlsm:
  ```php
  return [
      // App DB (reports, etc.)
      'app' => [
          'dsn' => "mysql:host={$host};port={$port};dbname=intelis_insights;charset=utf8mb4",
          'user' => ..., 'password' => ..., 'options' => [...]
      ],
      // Query DB (vlsm — where LLM SQL executes)
      'query' => [
          'dsn' => "mysql:host={$host};port={$port};dbname=" . $env('QUERY_DB_NAME', 'vlsm') . ";charset=utf8mb4",
          'user' => ..., 'password' => ..., 'options' => [...]
      ],
  ];
  ```

---

#### Task 1B: Port bin scripts (RAG corpus pipeline)
Copy and adapt the schema export and corpus building scripts from old codebase.

**Files to create:**
- `bin/export-schema.php` — copy from `~/www/old-intelis-insights/bin/export-schema.php` (176 lines). Queries INFORMATION_SCHEMA for all tables/columns/relationships/reference data, outputs `var/schema.json`. Update the config loading to use new app.php format.
- `bin/build-rag-snippets.php` — copy from `~/www/old-intelis-insights/bin/build-rag-snippets.php`. Reads business-rules.php + field-guide.php + var/schema.json, generates `corpus/snippets.jsonl` with 9 snippet types: rule, syn, column, table, relationship, exemplar, threshold, test_type, validation (~2,400 snippets).
- `bin/rag-upsert.php` — copy from `~/www/old-intelis-insights/bin/rag-upsert.php`. Batch uploads snippets.jsonl to RAG API `/v1/upsert`.
- `bin/rag-refresh.sh` — copy from `~/www/old-intelis-insights/bin/rag-refresh.sh`. Orchestrates full pipeline: health check → optional reset → build snippets → upsert → verify.

**Files to remove:**
- `bin/generate-corpus.php` — replaced by build-rag-snippets.php
- `bin/index-corpus.php` — replaced by rag-upsert.php

---

#### Task 1C: Upgrade ConversationContextService (longer context for chained questions)
The old ConversationContextService was session-based and only kept light summaries. We need **richer context** so chained questions work naturally:

```
Q1: "How many viral load tests done in last 12 months?"  → 45,000
Q2: "How many of these were in Littoral province?"        → must carry forward the VL + 12-month filter
Q3: "Break those down by facility"                        → must carry forward VL + 12-month + Littoral
```

**File to create:** `src/Services/ConversationContextService.php`

**Base:** `~/www/old-intelis-insights/src/Services/ConversationContextService.php` (368 lines), then **enhance**:

1. **Store full SQL + results per turn** — not just summaries. Each history entry gets:
   - `original_query` — the user question
   - `generated_sql` — the full SQL that ran
   - `intent` — detected intent
   - `tables_used` — tables in the query
   - `filters_applied` — extracted WHERE conditions (time, facility, province, VL category, etc.)
   - `columns_returned` — column names in result set
   - `row_count` — how many rows returned
   - `sample_rows` — first 5 rows of output (for LLM context)
   - `result_summary` — human-readable summary ("Found 45,000 VL tests")

2. **Smarter reference detection** — expand `seemsToReferencePrevious()` to also catch:
   - Pronouns: "these", "those", "them", "it", "they"
   - Continuations: "of those", "among them", "from those", "filter those"
   - Drill-downs: "break down", "by province", "by facility" (when no test type specified)
   - Refinements: "but only", "just the", "narrow to", "in Littoral"
   - Follow-ups: "what about", "how about", "and also", "what percentage"
   - Implicit: short questions without a table/test type that clearly depend on prior context

3. **Build rich LLM context string** — `buildContextForLlm()` returns a block included in the SQL generation prompt:
   ```
   CONVERSATION CONTEXT (use to resolve references like "those", "these", etc.):

   Q1: "How many viral load tests done in last 12 months?"
   SQL: SELECT COUNT(*) AS total_tests FROM form_vl WHERE sample_tested_datetime >= DATE_SUB(NOW(), INTERVAL 12 MONTH) AND IFNULL(is_sample_rejected, 'no') = 'no'
   Result: 45,000 tests
   Filters: time_period=12 MONTH, table=form_vl

   If the user says "these", "those", "of those" etc., they mean the results from the most recent query above.
   CARRY FORWARD all filters from the previous query and ADD the new conditions.
   ```

4. **Suggest continuation filters** — `suggestContinuationFilters()` extracts SQL WHERE fragments from recent queries that should carry forward (time ranges, facility filters, province filters, VL category, etc.)

5. **Configurable history depth** — keep last 10 queries (configurable), but only include last 3 in LLM prompt to avoid token bloat.

6. **Session storage** — use `$_SESSION` (same as old) for now. The `session_id` from the API request maps to the PHP session.

7. **Conversation management API** — expose endpoints for the user to control their conversation:
   - `POST /api/v1/chat/clear-context` — clears the conversation history for the current session. Returns `{"context_reset": true}`.
   - `GET /api/v1/chat/history` — returns the full conversation history for the current session (all Q&A pairs with SQL, results, timestamps). The dashboard can display this as a scrollable list of past conversations.
   - `GET /api/v1/chat/history/{index}` — returns a specific past conversation turn (question, SQL, result summary, filters). Allows the user to jump back to a previous question and re-run or drill down from it.
   - `POST /api/v1/chat/rewind/{index}` — truncates history to the given index, effectively "going back" to a prior conversation state. The next question will use that turn as the most recent context.

   Example flow in the dashboard:
   ```
   [Clear Context]  ← button calls POST /api/v1/chat/clear-context

   Conversation:
     1. "How many VL tests in last 12 months?" → 45,000   [Re-use ↩]
     2. "How many in Littoral province?" → 12,300           [Re-use ↩]
     3. "Break those down by facility" → table...           [Re-use ↩]

   [Re-use ↩] button calls POST /api/v1/chat/rewind/1
   → next question builds on Q1's context, ignoring Q2 and Q3
   ```

---

#### Task 1D: Update RAG API
Add `table` filter support needed by QueryService for table-scoped searches.

**File to modify:** `rag-api/app/main.py`

In the `search()` endpoint, after the existing `tag` filter block, add:
```python
if "table" in req.filters and req.filters["table"]:
    tables = req.filters["table"] if isinstance(req.filters["table"], list) else [req.filters["table"]]
    must.append(FieldCondition(key="meta.table", match=MatchAny(any=tables)))
```

Also support the `type_in` alias used by the old QueryService (maps to same `type` filter).

---

### WAVE 2 — Core service port (depends on Wave 1)

#### Task 2A: Port QueryService (the big one)
Create the new QueryService by adapting the old one to use the LLM sidecar.

**File to create:** `src/Services/QueryService.php`

**Source:** `~/www/old-intelis-insights/src/Services/QueryService.php` (1,590 lines)

**Key adaptation — LLM client mapping:**

The old system used `LlmRouter` → `AbstractLlmClient` (direct OpenAI/Anthropic/Ollama API). The new system uses `LlmClient` (HTTP wrapper for sidecar at :3100).

| Old method | New equivalent |
|---|---|
| `$this->llm->generateSql($prompt)` | `$this->llm->chat($systemPrompt, $userPrompt)` + SQL extraction logic (port `extractSql()` from old `AbstractLlmClient`) |
| `$this->llm->generateJson($prompt, $maxTokens)` | `$this->llm->chat($systemPrompt, $userPrompt, maxTokens: $maxTokens)` + JSON extraction, OR `$this->llm->structuredWithFallback(...)` for structured output |
| `$this->router->client('intent')` | Same `$this->llm` (sidecar handles routing) |
| `$this->router->client('sql')` | Same `$this->llm` |
| `$this->retriever->search(...)` | `$this->rag->search(...)` (existing `RagClient`, same API) |

**Constructor:**
```php
public function __construct(
    array $appCfg,
    array $businessRules,
    array $fieldGuide,
    array $schema,
    LlmClient $llm,          // new: sidecar client (replaces LlmRouter)
    RagClient $rag,           // new: existing RAG client (replaces RetrieverService)
    ?ConversationContextService $contextService = null,
)
```

**Methods to port (all from old QueryService):**
1. `processQuery()` (lines 170-292) — main pipeline
2. `validateQueryAgainstBusinessRules()` — pre-validation
3. `detectQueryIntentWithBusinessRules()` (lines 323-446) — intent classification
4. `selectRelevantTablesWithBusinessRules()` — table selection
5. `retrieveIntentContexts()` / `retrieveIntentFacts()` — RAG retrieval (adapt to use `RagClient` instead of `RetrieverService`)
6. `buildStrictRagPack()` (lines 1070-1157) — compact 24-item context for LLM
7. `buildPromptContext()` (lines 513-633) — legacy full-context mode
8. `callLLM()` (lines 1163-1293) — SQL generation call (**main refactor**: split single prompt into system+user for LlmClient, handle both RAG mode and legacy mode)
9. `validateSql()` (lines 1345-1420) — post-LLM SQL validation (privacy, allowed tables)
10. `enforceGrounding()` (lines 1523-1589) — RAG grounding check
11. `extractAllowedTables()` — helper
12. Cache helpers (`cacheGet`, `cacheSet`) — port with Symfony Cache

**Conversation context integration in `callLLM()`:**

When `ConversationContextService::getContextForNewQuery()` returns context (i.e. the question references prior turns), inject it into the LLM prompt:

```
{system prompt + RAG allowlist}

CONVERSATION CONTEXT (use to resolve references like "those", "these", etc.):

Q1: "How many viral load tests done in last 12 months?"
SQL: SELECT COUNT(*) AS total_tests FROM form_vl WHERE sample_tested_datetime >= DATE_SUB(NOW(), INTERVAL 12 MONTH)...
Result: 45,000 tests
Filters carried: time_period=12 MONTH, table=form_vl

The user's new question likely refers to the results above.
CARRY FORWARD all relevant filters from the previous query and ADD the new conditions.

QUESTION: "How many of these were in Littoral province?"

Return ONLY one JSON object: {"s":"<SQL>","ok":true|false,"conf":0..1,"why":"...","cit":["..."]}
```

This way the LLM sees the prior SQL and filters, and knows to build on them rather than starting from scratch. The `suggestContinuationFilters()` output is also included as explicit "carry forward" hints.

**Prompt refactoring for `callLLM()`:**

Old (single prompt string):
```php
$prompt = "You are a strict MySQL SQL generator...\nALLOWLIST:\n{$ragPack}\nQUESTION: {$query}\n...";
$result = $this->llm->generateJson($prompt, 1500);
```

New (split system/user):
```php
$system = "You are a strict MySQL SQL generator for a medical lab DB.\n...ABSOLUTE CONSTRAINTS:\n...";
$user = "ALLOWLIST:\n{$ragPack}\n\nQUESTION: {$query}\n\nReturn ONLY one JSON object: {\"s\":\"<SQL>\",\"ok\":true|false,...}";
$result = $this->llm->chat($system, $user, temperature: 0.0, maxTokens: 1500);
// Then parse JSON from response
```

**SQL extraction helper** — port from old `AbstractLlmClient::extractSql()`:
```php
private function extractSql(string $raw): string {
    // Handle ```sql ... ```, raw SELECT, etc.
}
```

---

### WAVE 3 — Integration wiring (depends on Wave 2)

#### Task 3A: Update ChatController
Rewire the controller to use QueryService instead of the old pipeline.

**File to modify:** `src/Controllers/ChatController.php`

**New constructor:**
```php
public function __construct(
    private QueryService $query,
    private DatabaseService $queryDb,  // vlsm
    private ChartService $chart,
) {}
```

**`ask()` method — new pipeline:**
```php
public function ask(Request $request, Response $response): Response
{
    $queryResult = $this->query->processQuery($question);
    $dbResult = $this->queryDb->execute($queryResult['sql']);

    // Store in conversation context
    $this->query->addToConversationHistory($question, $queryResult, $dbResult);

    // Chart suggestion
    $chartSuggestion = $this->chart->suggest($dbResult, $queryResult['intent'], $question);

    return $this->json($response, [
        'sql' => $queryResult['sql'],
        'verification' => $queryResult['verification'] ?? null,
        'citations' => $queryResult['citations'] ?? [],
        'data' => [
            'columns' => $dbResult['columns'],
            'rows' => $dbResult['rows'],
            'count' => $dbResult['count'],
        ],
        'chart' => $chartSuggestion,
        'meta' => [
            'execution_time_ms' => ...,
            'detected_intent' => $queryResult['intent'],
            'sql_execution_time_ms' => $dbResult['execution_time_ms'],
        ],
        'debug' => [
            'tables_used' => $queryResult['tables_used'],
            'conversation_context' => $queryResult['conversation_context'] ?? [],
        ],
    ]);
}
```

Remove methods: `validateIntent()`, `plan()` (no longer separate endpoints).

Keep: `suggestChart()` endpoint (adapt to new signature).

**Add conversation management methods:**
```php
// POST /api/v1/chat/clear-context — reset conversation
public function clearContext(Request $request, Response $response): Response

// GET /api/v1/chat/history — list all turns in current session
public function history(Request $request, Response $response): Response

// GET /api/v1/chat/history/{index} — get a specific turn
public function historyItem(Request $request, Response $response): Response

// POST /api/v1/chat/rewind/{index} — go back to a previous turn
public function rewind(Request $request, Response $response): Response
```

---

#### Task 3B: Update ChartService
Remove the `$plan` dependency from `suggest()`.

**File to modify:** `src/Services/ChartService.php`

Change method signature:
```php
// Old: suggest(array $plan, array $result): array
// New: suggest(array $result, string $intent = '', string $query = ''): array
```

The heuristic logic stays the same (it already works on columns/rows, not plan). Just update the LLM fallback `askLlm()` to pass intent+query instead of plan.

---

#### Task 3C: Update public/index.php (DI wiring + routes)
Wire everything together.

**File to modify:** `public/index.php`

```php
// Load configs
$businessRules = require __DIR__ . '/../config/business-rules.php';
$fieldGuide = require __DIR__ . '/../config/field-guide.php';
$schema = is_file($appCfg['schema_path'])
    ? json_decode(file_get_contents($appCfg['schema_path']), true)
    : [];

// Two DB connections
$queryDb = new App\Services\DatabaseService($dbCfg['query']);  // vlsm
$appDb = new App\Services\DatabaseService($dbCfg['app']);       // intelis_insights

// Services
$llmClient = new App\Services\LlmClient($appCfg['llm']);
$ragClient = new App\Services\RagClient($appCfg['rag']);
$queryService = new App\Services\QueryService(
    $appCfg, $businessRules, $fieldGuide, $schema, $llmClient, $ragClient
);
$chartService = new App\Services\ChartService($llmClient);

// Controllers
$chatController = new App\Controllers\ChatController($queryService, $queryDb, $chartService);
$reportController = new App\Controllers\ReportController($appDb);
```

**Routes — remove:**
- `POST /api/v1/chat/validate-intent`
- `POST /api/v1/chat/plan`
- `POST /api/v1/query/execute`
- `GET /api/v1/metrics`

**Routes — add (conversation management):**
- `POST /api/v1/chat/clear-context` — reset conversation history
- `GET /api/v1/chat/history` — list all conversation turns
- `GET /api/v1/chat/history/{index}` — get specific turn details
- `POST /api/v1/chat/rewind/{index}` — go back to a previous turn

**Routes — keep:**
- `POST /api/v1/chat/ask` (main endpoint)
- `POST /api/v1/chart/suggest`
- Reports CRUD (`/api/v1/reports/*`)
- Health/status checks
- Landing page

---

### WAVE 4 — Cleanup (depends on Wave 3)

#### Task 4A: Remove unused files

**Delete:**
- `src/Services/IntentService.php`
- `src/Services/PlannerService.php`
- `src/Services/QueryCompiler.php`
- `src/Services/MetricRegistry.php`
- `src/Controllers/QueryController.php`
- `metrics/` directory (vl.yml and any other YAML files)
- `bin/generate-corpus.php`
- `bin/index-corpus.php`

---

## Execution Order

```
WAVE 1 (parallel — no dependencies):
  ├── Task 1A: Port config files
  ├── Task 1B: Port bin scripts
  ├── Task 1C: Port ConversationContextService
  └── Task 1D: Update RAG API

WAVE 2 (depends on Wave 1):
  └── Task 2A: Port QueryService

WAVE 3 (parallel — depends on Wave 2):
  ├── Task 3A: Update ChatController
  ├── Task 3B: Update ChartService
  └── Task 3C: Update public/index.php

WAVE 4 (depends on Wave 3):
  └── Task 4A: Remove unused files
```

---

## Verification

1. **Schema export:** `php bin/export-schema.php` → verify `var/schema.json` has vlsm tables
2. **Corpus build:** `php bin/build-rag-snippets.php` → verify ~2,400+ snippets in `corpus/snippets.jsonl`
3. **RAG index:** `php bin/rag-upsert.php corpus/snippets.jsonl` → snippets indexed
4. **RAG search:** `POST /v1/search` with "viral load suppressed by lab" → relevant snippets returned
5. **End-to-end:** `POST /api/v1/chat/ask` with `{"question": "how many VL tests last month by lab?"}` → SQL + data + chart
6. **Privacy:** Ask for patient names → rejected
7. **Multi-turn:** Follow-up with "what about those" → carries context
8. **Reports:** `/api/v1/reports` CRUD still works
9. **Dashboard:** UI renders results correctly
