# InteLIS Insights

Convert natural‑language questions into SQL for the InteLIS database. Runs a query plan through an LLM, validates the SQL, executes it, and returns results (with privacy rules enforced).

## Quick Start

1. **Install deps**

```bash
composer install
```

1. **Export the DB schema** (required)

```bash
php ./bin/export-schema.php
```

This generates `var/schema.json`. Re‑run it whenever the database schema changes.

## Docker / Deploy

1. Copy `.env.example` to `.env` and fill in DB creds + LLM API keys.
2. Optional HTTPS + domain: set `ENABLE_TRAEFIK=true`, set `APP_DOMAIN` to your domain, set `TRAEFIK_ACME_EMAIL`, and point DNS A/AAAA to this host (ports 80/443 must be reachable for Let’s Encrypt).
3. Run `./deploy.sh` (auto-installs Docker on Ubuntu if needed, then builds/starts the stack).
3. Export the schema once the DB is reachable:
   ```bash
   docker compose exec app php bin/export-schema.php
   ```
4. Visit the app: `http://localhost:${APP_PORT:-8080}/chat` (API: `POST /ask`).

Services: `app` (PHP/Slim), `qdrant` (vector store on 6333), `rag-api` (RAG helper on 8089).

## Use It

- **Web UI**: open `/chat` and ask a question.

- **API**:

  - **Ask a question**

    ```http
    POST /ask
    Content-Type: application/json
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

  - **Clear conversation context** (reset the server-side context window)

    ```http
    POST /ask
    Content-Type: application/json
    {
      "clear_context": true
    }
    ```

    **Response**

    ```json
    { "message": "Conversation context cleared", "context_reset": true }
    ```

    *Note:* when `clear_context` is `true`, it is handled immediately and any `q` value (if present) is ignored for that request.

## Workflow

**High-level flow**
**High-level flow**
1. **User asks** a question (UI `/chat` or `POST /ask`).
2. **Context is built**: user query + conversation history + `var/schema.json` + business rules + field guide → prompt to the selected LLM.
3. **LLM generates SQL** (QueryService validates & enforces privacy rules).
4. **SQL is executed** against MySQL (DatabaseService).
5. **Charts are generated** based on the query output.
6. **Results are returned** to the caller (rows, counts, timing, debug info, charts) and conversation context is updated.

```mermaid
flowchart TD
  U["User Query in Natural Language (via UI or API)"] --> QS["<strong>QueryService generates Prompt for LLM.</strong> <br><br>User Query, Schema, Business rules, and Field Guides are attached to Prompt "]
  SCH["Database Schema - without any actual data"] --> QS
  BR["Predefined Business Rules"] --> QS
  FG["Predefined Field Guide"] --> QS
  CTX[Conversation context/history] --> QS

  QS --> LLM["LLM generates SQL based on Prompt"]
  LLM --> VAL["Validate SQL & enforce privacy"]
  VAL --> DB[(MySQL executes SQL)]
  DB --> CS["Chart generation based on Query Output"]
  CS --> RESP[Response JSON with Query Output + Charts]
  RESP --> CTX
```

## LLM Providers

Works with **Ollama**, **OpenAI**, and **Anthropic**. Pick a provider/model in the `/chat` settings or send `provider`/`model` in the `/ask` payload.

## Notes

- Privacy rules prevent returning disallowed columns.
- If you see “model not found”, use an explicit model id (e.g., for Anthropic use a dated id).
- If SQL generation looks off after schema changes, re‑export the schema (`export-schema.php`).
