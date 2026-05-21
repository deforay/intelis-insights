# Configuration reference

Every setting is environment-variable driven. Copy `.env.example` to `.env` and fill in the values for your deployment.

## App

| Variable | Default | Description |
|---|---|---|
| `NODE_ENV` | `production` | `development` or `production`. |
| `APP_PORT` | `3000` | Host port the app binds to. |
| `AUTH_SECRET` | _(required)_ | Auth.js JWT signing secret. Generate with `openssl rand -base64 32`. |
| `AUTH_URL` | `http://localhost:3000` | Public URL the app is served from. |

## App database (Postgres)

The app's own state: users, sessions, conversation history (via LangGraph checkpoints), audit log.

| Variable | Default | Description |
|---|---|---|
| `APP_DB_URL` | _(required)_ | Postgres connection string. |
| `POSTGRES_PASSWORD` | _(required)_ | Used by the bundled Postgres container in `docker-compose.yml`. |

## Lab database — the existing InteLIS MySQL

!!! warning "Read-only access"
    The credentials below must be granted `SELECT` only. The application enforces SELECT-only at the SQL-validation layer as defence in depth, but the MySQL user itself should be read-only.

| Variable | Default | Description |
|---|---|---|
| `LAB_DB_HOST` | _(required)_ | InteLIS MySQL hostname or IP. |
| `LAB_DB_PORT` | `3306` | InteLIS MySQL port. |
| `LAB_DB_NAME` | _(required)_ | Database name. |
| `LAB_DB_USER` | _(required)_ | Read-only user. |
| `LAB_DB_PASSWORD` | _(required)_ | Password. |

## Vector database (Qdrant)

| Variable | Default | Description |
|---|---|---|
| `QDRANT_URL` | `http://qdrant:6333` | URL of the Qdrant server. |
| `QDRANT_API_KEY` | _(empty)_ | Optional API key if Qdrant is secured. |
| `QDRANT_COLLECTION` | `intelis_insights` | Collection name. |

## LLM provider

Choose one provider with `LLM_PROVIDER`. Provide only the key for the chosen one.

| Variable | Description |
|---|---|
| `LLM_PROVIDER` | `openai` \| `anthropic` \| `google` \| `mistral` \| `deepseek` \| `groq` \| `openai_compatible` \| `ollama` |
| `LLM_MODEL` | Model for SQL generation. Defaults to a reasonable per-provider choice; override to suit cost/quality preference. |
| `LLM_MODEL_INTENT` | Smaller/faster model for intent classification. |
| `OPENAI_API_KEY` | OpenAI key. |
| `ANTHROPIC_API_KEY` | Anthropic key. |
| `GOOGLE_GENERATIVE_AI_API_KEY` | Google AI Studio / Vertex AI key. |
| `MISTRAL_API_KEY` | Mistral key. |
| `DEEPSEEK_API_KEY` | DeepSeek key. |
| `GROQ_API_KEY` | Groq key. |
| `OPENAI_COMPATIBLE_BASE_URL` | Endpoint URL for any OpenAI-compatible provider (Together, Fireworks, OpenRouter, vLLM, LiteLLM, …). |
| `OPENAI_COMPATIBLE_API_KEY` | Auth token for the OpenAI-compatible endpoint. |
| `OLLAMA_BASE_URL` | `http://localhost:11434/v1` by default; in Docker offline mode this is auto-set to the bundled Ollama service. |

See [LLM providers](llm-providers.md) for selection guidance and the offline / cost-effective matrix.

## Embeddings

| Variable | Description |
|---|---|
| `EMBEDDINGS_PROVIDER` | `openai` \| `mistral` \| `openai_compatible` \| `ollama` |
| `EMBEDDINGS_MODEL` | Model name. Default: `text-embedding-3-small` (OpenAI). |

!!! warning "Re-ingest on switch"
    Switching embedding providers changes the vector dimension. The Qdrant collection is created at bootstrap with the configured embedder's dimension. To switch, drop the collection and re-run `scripts/upsert-corpus.ts`.

## Observability (optional)

[LangFuse](https://langfuse.com) traces every LLM call and graph node for audit and debugging. Use the bundled self-hosted profile, point at LangFuse Cloud, or leave disabled.

| Variable | Description |
|---|---|
| `LANGFUSE_PUBLIC_KEY` | LangFuse project public key. |
| `LANGFUSE_SECRET_KEY` | LangFuse project secret key. |
| `LANGFUSE_HOST` | Self-hosted LangFuse URL, or omit for cloud. |
