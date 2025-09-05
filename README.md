# InteLIS Insights

Convert natural‑language questions into SQL for the InteLIS database. Runs a query plan through an LLM, validates the SQL, executes it, and returns results (with privacy rules enforced).

## Quick Start

1. **Install deps**

```bash
composer install
```

2. **Export the DB schema** (required)

```bash
php ./bin/export-schema.php
```

This generates `var/schema.json`. Re‑run it whenever the database schema changes.

## Use It

* **Web UI**: open `/chat` and ask a question.
* **API**: `POST /ask` with JSON

```json
{
  "q": "How many VL tests in the last 6 months?",
  "provider": "ollama|openai|anthropic",  
  "model": "optional-model-id"
}
```

**Response (minimal shape)**

```json
{
  "sql": "SELECT …",
  "rows": [ { "col": "val" } ],
  "timing": { "provider": "…", "model_used": "…", "total_ms": 0 }
}
```

## LLM Providers

Works with **Ollama**, **OpenAI**, and **Anthropic**. Pick a provider/model in the `/chat` settings or send `provider`/`model` in the `/ask` payload.

## Notes

* Privacy rules prevent returning disallowed columns.
* If you see “model not found”, use an explicit model id (e.g., for Anthropic use a dated id).
* If SQL generation looks off after schema changes, re‑export the schema (`export-schema.php`).
