# Environment Variables

All configuration is in the `.env` file at the project root. Copy `.env.example` to get started:

```bash
cp .env.example .env
```

## App Database

Connection settings for the application database (`intelis_insights`). See [Two Databases](../concepts/two-databases.md).

| Variable | Default | Description |
|----------|---------|-------------|
| `DB_HOST` | `127.0.0.1` | MySQL host |
| `DB_PORT` | `3306` | MySQL port |
| `DB_NAME` | `intelis_insights` | Application database name |
| `DB_USER` | `root` | MySQL username |
| `DB_PASSWORD` | `intelis_dev` | MySQL password |

!!! note "Docker overrides `DB_HOST`"
    In Docker, `DB_HOST` is automatically set to `mysql` (the container name). The `.env` value is used by host-side tools like MySQL GUIs and the `mysql` CLI.

## Query Database

Connection settings for the InteLIS laboratory database. See [Two Databases](../concepts/two-databases.md) and [Connecting InteLIS Data](connecting-intelis-data.md).

| Variable | Default | Description |
|----------|---------|-------------|
| `QUERY_DB_NAME` | `vlsm` | InteLIS database name |
| `QUERY_DB_HOST` | *(falls back to `DB_HOST`)* | InteLIS database host |
| `QUERY_DB_USER` | *(falls back to `DB_USER`)* | InteLIS database username |
| `QUERY_DB_PASSWORD` | *(falls back to `DB_PASSWORD`)* | InteLIS database password |

When `QUERY_DB_HOST` is empty, the query database is assumed to be on the same MySQL server as the app database.

## LLM Provider API Keys

At least one key is required for the chat features to work. See [Prerequisites](../getting-started/prerequisites.md#llm-api-key).

| Variable | Description |
|----------|-------------|
| `ANTHROPIC_API_KEY` | Anthropic (Claude) API key |
| `OPENAI_API_KEY` | OpenAI (GPT) API key |
| `DEEPSEEK_API_KEY` | DeepSeek API key |
| `GOOGLE_GENERATIVE_AI_API_KEY` | Google (Gemini) API key |
| `GROQ_API_KEY` | Groq API key |

!!! tip "Prefer the Settings page for API keys"
    Instead of editing `.env`, you can manage API keys from **Settings → API Keys** in the browser. Keys entered there are stored in the database and pushed to the LLM sidecar at runtime — no service restart required. This is the recommended approach after initial setup, especially in production where restarting containers to change a key is inconvenient.

## LLM Sidecar

| Variable | Default | Description |
|----------|---------|-------------|
| `LLM_SIDECAR_URL` | `http://127.0.0.1:3100` | LLM sidecar base URL |
| `LLM_SIDECAR_SECRET` | *(empty)* | Shared secret for authenticating with the sidecar. Required in production. |
| `LLM_DEFAULT_MODEL` | `sonnet` | Default model alias. See [LLM Sidecar](../reference/llm-sidecar.md) for available aliases. |
| `ALLOW_INSECURE_NO_AUTH` | `true` | Skip sidecar auth in development. **Must be `false` in production.** |

## RAG

| Variable | Default | Description |
|----------|---------|-------------|
| `RAG_BASE_URL` | `http://127.0.0.1:8089` | RAG API base URL |
| `RAG_ENABLED` | `true` | Enable/disable RAG retrieval. When `false`, the LLM generates SQL without schema context (less accurate). |
| `EMBEDDING_MODEL` | `BAAI/bge-small-en-v1.5` | Embedding model used by the RAG API. Changing this requires `make rag-reset`. |

## Qdrant

| Variable | Default | Description |
|----------|---------|-------------|
| `QDRANT_URL` | `http://127.0.0.1:6333` | Qdrant base URL |
| `QDRANT_COLLECTION` | `intelis_insights` | Qdrant collection name |

## Application

| Variable | Default | Description |
|----------|---------|-------------|
| `APP_ENV` | `development` | Environment name (`development` or `production`) |
| `APP_DEBUG` | `true` | Enable debug mode with verbose errors. **Must be `false` in production.** |
| `APP_TIMEZONE` | `UTC` | Application timezone |

## Init

| Variable | Default | Description |
|----------|---------|-------------|
| `FORCE_RAG_REFRESH` | `0` | Set to `1` to force RAG re-seeding on next container start |

## Production Checklist

When deploying to production, ensure these are set:

```bash
APP_ENV=production
APP_DEBUG=false
DB_PASSWORD=<strong-password>
ALLOW_INSECURE_NO_AUTH=false
LLM_SIDECAR_SECRET=<strong-secret>
```

See [Production Deployment](production-deployment.md) for the full guide.
