# How a Query Works: Step-by-Step Pipeline

When a user types a question like _"What is the VL suppression rate by district?"_ in the chat interface, here is exactly what happens end-to-end.

## Architecture

```
User Question
     │
     ▼
┌─────────────────────────────────────────────────────┐
│                  Slim PHP API                       │
│                                                     │
│  ChatController.ask()                               │
│       │                                             │
│       ▼                                             │
│  QueryService.processQuery()                        │
│       │                                             │
│       ├─ 1. Validate against business rules         │
│       ├─ 2. Check conversation context              │
│       ├─ 3. Detect intent (LLM)                     │
│       ├─ 4. Select relevant tables (LLM)            │
│       ├─ 5. Retrieve RAG context (Qdrant)           │
│       ├─ 6. Build strict RAG allowlist              │
│       ├─ 7. Generate SQL (LLM)                      │
│       ├─ 8. Validate SQL (privacy + grounding)      │
│       │                                             │
│       ▼                                             │
│  DatabaseService.execute()                          │
│       │  (runs SQL against vlsm)                    │
│       ▼                                             │
│  ChartService.suggest()                             │
│       │  (heuristic + optional LLM)                 │
│       ▼                                             │
│  JSON Response                                      │
└─────────────────────────────────────────────────────┘
```

## Step 1: Validate Against Business Rules

**File:** `QueryService::validateQueryAgainstBusinessRules()`

Before any LLM call, the system checks the question against hard-coded business rules in `config/business-rules.php`:

- **Privacy check** — Rejects questions asking for patient-level data (names, IDs, phone numbers, addresses). These are blocked before the LLM ever sees them.
- **Forbidden patterns** — Detects and blocks attempts to ask for individual patient records, DELETE/UPDATE/DROP statements, etc.
- **Length validation** — Rejects queries that are too short (< 3 chars) or too long.

If validation fails, an error is returned immediately without calling the LLM.

## Step 2: Check Conversation Context

**File:** `ConversationContextService::getContextForNewQuery()`

The system checks if this question references a previous conversation turn. It looks for:

- **Pronouns**: "these", "those", "them", "it"
- **Continuations**: "of those", "among them", "from those"
- **Drill-downs**: "break down by province", "by facility" (when no test type specified)
- **Refinements**: "but only", "just the", "narrow to", "in Littoral"
- **Follow-ups**: "what about", "how about", "what percentage"
- **Implicit**: short questions (< 6 words) without a table/test-type keyword

If context is detected, the system builds a rich context block containing previous queries, their SQL, result summaries, and extracted filters. This block is injected into the LLM prompt in Step 7.

**Example context block:**
```
CONVERSATION CONTEXT:
Q1: "How many viral load tests done in last 12 months?"
SQL: SELECT COUNT(*) AS total_tests FROM form_vl WHERE sample_tested_datetime >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
Result: Found 45,000 records
Filters: time_period=12 MONTH, table=form_vl

If the user says "these", "those", etc., they mean the results above.
CARRY FORWARD all filters and ADD the new conditions.
```

## Step 3: Detect Intent

**File:** `QueryService::detectQueryIntentWithBusinessRules()`

The system calls the **LLM** (via the sidecar) to classify the question's intent. The LLM is given:
- The user question
- Business rules context
- Conversation context (if any)

It returns a structured JSON with:
- **`intent`** — One of: `count`, `list`, `aggregate`, `trend`, `comparison`, `distribution`, `performance`, `correlation`, `geographic`, `metadata`, `general`
- **`intent_details`** — Specific dimensions and filters detected in the question
- **`confidence`** — How confident the classification is

This intent guides table selection and prompt construction in later steps.

## Step 4: Select Relevant Tables

**File:** `QueryService::selectRelevantTablesWithBusinessRules()`

Using the detected intent, the system asks the **LLM** to select which database tables are needed to answer the question. The LLM is given:
- The question
- The intent analysis
- A list of all available tables (from `var/schema.json`)

It returns 1-3 table names that should be used in the SQL query (e.g., `form_vl`, `facility_details`).

## Step 5: Retrieve RAG Context

**File:** `QueryService::retrieveIntentContexts()`

The system performs **semantic search** against the Qdrant vector database via the RAG API. It sends multiple queries to find the most relevant snippets:

1. **Primary search** — The user question itself, filtered to snippet types relevant to the intent
2. **Table-specific search** — Search filtered to the selected tables, retrieving column definitions
3. **Rule search** — Search for business rules relevant to the question

The RAG API embeds each query using `BAAI/bge-small-en-v1.5` and finds the nearest neighbors in the vector space. Results are scored by cosine similarity.

Typically returns 15-24 snippets covering: table schemas, column semantics, business rules, clinical thresholds, and query patterns.

## Step 6: Build Strict RAG Allowlist

**File:** `QueryService::buildStrictRagPack()`

The raw RAG results are compressed into a compact 24-item "allowlist" — a structured block of text that tells the LLM exactly what tables, columns, rules, and patterns it is allowed to use.

**Format:**
```
ALLOWLIST (you may ONLY use items from this list):

[TABLE] form_vl — Viral load test results
  Columns: sample_tested_datetime, facility_name, result_value_absolute, ...
[TABLE] facility_details — Facility metadata
  Columns: facility_name, facility_state, facility_district, ...

[RULE] Suppressed VL: result_value_absolute < 1000 copies/ml
[RULE] Always exclude rejected samples: IFNULL(is_sample_rejected, 'no') = 'no'
[RULE] Never expose patient-level data

[PATTERN] VL suppression rate: COUNT(CASE WHEN result_value_absolute < 1000 ...) / COUNT(*)
```

This allowlist is the **grounding mechanism** — the LLM can only use tables, columns, and patterns listed here. Anything not in the allowlist is forbidden.

## Step 7: Generate SQL

**File:** `QueryService::callLLM()`

This is where the **LLM generates the actual SQL query**. It receives a two-part prompt:

**System prompt:**
```
You are a strict MySQL SQL generator for a medical laboratory database.
- Only use tables and columns from the ALLOWLIST
- Never return patient-identifying information
- Always exclude rejected samples unless asked
- Use aggregate functions, never return raw patient rows
- Respond ONLY with JSON: {"s":"<SQL>","ok":true,"conf":0.85,"why":"...","cit":["..."]}
```

**User prompt:**
```
ALLOWLIST:
[the compact RAG pack from Step 6]

CONVERSATION CONTEXT:
[context block from Step 2, if applicable]

QUESTION: "What is the VL suppression rate by district?"

Return ONLY one JSON object.
```

The LLM responds with JSON containing:
- `s` — The generated SQL query
- `ok` — Whether it was able to answer (true/false)
- `conf` — Confidence score (0-1)
- `why` — Human-readable explanation of what the query does
- `cit` — Citations (which RAG snippets were used)

**Example LLM output:**
```json
{
  "s": "SELECT fd.facility_state AS district, COUNT(*) AS total_tests, COUNT(CASE WHEN fv.result_value_absolute < 1000 THEN 1 END) AS suppressed, ROUND(COUNT(CASE WHEN fv.result_value_absolute < 1000 THEN 1 END) * 100.0 / COUNT(*), 1) AS suppression_rate FROM form_vl fv JOIN facility_details fd ON fv.facility_id = fd.facility_id WHERE fv.sample_tested_datetime >= DATE_SUB(NOW(), INTERVAL 12 MONTH) AND IFNULL(fv.is_sample_rejected, 'no') = 'no' GROUP BY fd.facility_state ORDER BY suppression_rate DESC",
  "ok": true,
  "conf": 0.92,
  "why": "Calculates VL suppression rate (< 1000 copies/ml) by district using form_vl joined with facility_details, excluding rejected samples, for the last 12 months.",
  "cit": ["VL suppression threshold", "form_vl columns", "facility_details columns"]
}
```

The LLM call goes through the **LLM Sidecar** (`LlmClient → HTTP → llm-sidecar:3100`) which routes to the configured model (e.g., Claude, GPT-4, DeepSeek).

## Step 8: Validate SQL

**File:** `QueryService::validateSql()` and `QueryService::enforceGrounding()`

Before executing, the generated SQL is validated:

### Privacy validation
- No forbidden columns (patient_name, phone_number, etc.) in SELECT
- No patient-level queries without aggregation
- No DELETE, UPDATE, DROP, ALTER statements

### Grounding validation
- Every table in the SQL must be in the allowed tables list
- Every column must exist in the schema
- The SQL must be syntactically valid

If validation fails, the query is rejected with an explanation. The system may retry with adjusted prompts (up to 2 retries).

## Step 9: Execute SQL

**File:** `DatabaseService::execute()`

The validated SQL is executed against the **vlsm** database using a raw PDO connection. This is intentionally separate from the Eloquent ORM used for app data.

Returns:
- `columns` — Column names from the result set
- `rows` — Array of result rows (associative arrays)
- `count` — Total row count
- `execution_time_ms` — How long the query took

## Step 10: Store Conversation Context

**File:** `ConversationContextService::addQuery()`

After execution, the full turn is stored in the session:
- Original question
- Generated SQL
- Detected intent
- Tables used
- Extracted filters (time range, facility, province, etc.)
- Result summary
- Sample rows (first 5)

This enables the next question to reference "those results" or "break that down by facility".

## Step 11: Suggest Chart

**File:** `ChartService::suggest()`

The system analyzes the result set to recommend a visualization:

### Heuristic pass (fast, no LLM)
- **Single row** → table/KPI
- **Temporal + numeric columns** → line/area chart
- **Single categorical + single numeric** → pie (few rows) or bar (many rows)
- **Two numeric columns** → scatter plot
- **Multiple categorical + numeric** → stacked bar
- **Many columns** → table

### LLM fallback
If heuristics are inconclusive, the LLM is asked via structured output to recommend a chart type with axis configuration.

## Step 12: Return Response

**File:** `ChatController::ask()`

The final JSON response is assembled:

```json
{
  "sql": "SELECT ...",
  "verification": {
    "ok": true,
    "conf": 0.92,
    "why": "Calculates VL suppression rate by district..."
  },
  "citations": ["VL suppression threshold", "form_vl columns"],
  "data": {
    "columns": ["district", "total_tests", "suppressed", "suppression_rate"],
    "rows": [...],
    "count": 10
  },
  "chart": {
    "recommended": "bar",
    "alternatives": ["horizontal_bar", "table"],
    "config": {"x_axis": "district", "y_axis": "suppression_rate"},
    "reasoning": "Single categorical dimension with many categories"
  },
  "meta": {
    "execution_time_ms": 3200,
    "detected_intent": "aggregate",
    "sql_execution_time_ms": 45,
    "session_id": "abc-123"
  },
  "debug": {
    "tables_used": ["form_vl", "facility_details"],
    "conversation_context": []
  }
}
```

The frontend renders this as bento cards: explanation, data table, chart, and query details.

## Multi-Turn Conversation Example

```
Q1: "How many VL tests in the last 12 months?"
    → SQL: SELECT COUNT(*) FROM form_vl WHERE sample_tested_datetime >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
    → Result: 45,000

Q2: "How many of those were in Littoral province?"
    → Context detected: "those" references Q1
    → SQL: SELECT COUNT(*) FROM form_vl fv JOIN facility_details fd ON ... WHERE ... AND fd.facility_state = 'Littoral'
    → Result: 12,300
    → Filters carried forward: 12 MONTH time range + form_vl table

Q3: "Break those down by facility"
    → Context detected: drill-down on Q2
    → SQL: SELECT fd.facility_name, COUNT(*) FROM form_vl fv JOIN ... WHERE ... AND fd.facility_state = 'Littoral' GROUP BY fd.facility_name
    → Result: 45 facilities listed
    → All filters carried forward: 12 MONTH + Littoral + form_vl
```

## Error Handling

- **Privacy violation** → Blocked at Step 1, user sees "This question asks for restricted patient-level data"
- **LLM refusal** → `ok: false` in Step 7, user sees the LLM's explanation of why it can't answer
- **SQL validation failure** → Blocked at Step 8, retried up to 2 times, then user sees validation error
- **Database error** → Caught in Step 9, user sees "Database error: ..."
- **RAG unavailable** → Step 5 returns empty results, LLM generates SQL without RAG context (less accurate)
