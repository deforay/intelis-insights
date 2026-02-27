# LLM Sidecar

The LLM sidecar is a lightweight gateway service that routes LLM requests from the PHP app to whichever provider is configured. The PHP app never calls LLM APIs directly — it goes through the sidecar.

**Source:** [github.com/deforay/llm-sidecar](https://github.com/deforay/llm-sidecar) (included as a git submodule in `llm-sidecar/`)

**Tech:** TypeScript, Bun, Hono framework

**Port:** 3100

## Why a Sidecar?

- **Provider abstraction** — Switch between Claude, GPT-4, DeepSeek, etc. by changing a config value, not code.
- **No PHP SDKs needed** — The PHP app sends simple HTTP requests; the sidecar handles provider-specific APIs.
- **Shared features** — Streaming, structured output, tool calling, and rate limiting are handled once in the sidecar.

## Model Aliases

Instead of using full `provider:model` identifiers, you can use shorthand aliases:

| Alias | Model |
|-------|-------|
| `sonnet` | `anthropic:claude-sonnet-4-20250514` |
| `opus` | `anthropic:claude-opus-4-20250514` |
| `haiku` | `anthropic:claude-haiku-4-20250514` |
| `gpt-4o` | `openai:gpt-4o` |
| `gpt-4o-mini` | `openai:gpt-4o-mini` |
| `gemini-pro` | `google:gemini-1.5-pro` |
| `gemini-flash` | `google:gemini-1.5-flash` |
| `llama-70b` | `groq:llama-3.3-70b-versatile` |
| `llama-8b` | `groq:llama-3.1-8b-instant` |
| `deepseek-chat` | `deepseek:deepseek-chat` |
| `deepseek-reasoner` | `deepseek:deepseek-reasoner` |

The default model is set via `LLM_DEFAULT_MODEL` in `.env` (default: `sonnet`).

## API Endpoints

All endpoints (except `/health`) require authentication when `ALLOW_INSECURE_NO_AUTH=false`:

```
Authorization: Bearer {LLM_SIDECAR_SECRET}
```

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/health` | GET | Health check (no auth required) |
| `/v1/info` | GET | Service info and capabilities |
| `/v1/models` | GET | List available models and aliases |
| `/v1/chat` | POST | Text completion |
| `/v1/chat/stream` | POST | Streaming completion (Server-Sent Events) |
| `/v1/structured` | POST | JSON schema-constrained output |
| `/v1/tools` | POST | Tool/function calling |
| `/v1/embeddings` | POST | Generate text embeddings |
| `/v1/image` | POST | Image generation (DALL-E) |
| `/v1/tokens` | POST | Token counting |

## How Intelis Insights Uses It

The PHP app makes three types of LLM calls through the sidecar during the [query pipeline](query-pipeline.md):

1. **Intent detection** (`/v1/structured`) — Classify the user's question intent (count, aggregate, trend, etc.)
2. **Table selection** (`/v1/structured`) — Choose which database tables are needed
3. **SQL generation** (`/v1/structured`) — Generate the actual SQL query with confidence score

All three use structured output (JSON schema) to get predictable, parseable responses.

## Configuration

The sidecar is configured via environment variables. In Docker, these are passed from your `.env` file through `docker-compose.yml`:

| Variable | Default | Description |
|----------|---------|-------------|
| `LLM_SIDECAR_URL` | `http://127.0.0.1:3100` | Base URL (set in PHP app) |
| `LLM_SIDECAR_SECRET` | *(empty)* | Auth secret (required in production) |
| `LLM_DEFAULT_MODEL` | `sonnet` | Default model alias |
| `ALLOW_INSECURE_NO_AUTH` | `true` | Skip auth in development |

Provider API keys can be set in two ways:

1. **Settings page (recommended)** — Go to **Settings → API Keys** in the browser. Keys are stored in the database and pushed to the sidecar at runtime via `POST /v1/config/keys`. No restart required.
2. **Environment variables** — Set in `.env` (passed through `docker-compose.yml`). Requires a container restart to take effect.

| Variable | Provider |
|----------|---------|
| `ANTHROPIC_API_KEY` | Anthropic (Claude) |
| `OPENAI_API_KEY` | OpenAI (GPT) |
| `DEEPSEEK_API_KEY` | DeepSeek |
| `GOOGLE_GENERATIVE_AI_API_KEY` | Google (Gemini) |
| `GROQ_API_KEY` | Groq |

Keys set via the Settings page take precedence — they overwrite the sidecar's in-memory keys on each save and on each Settings page load.

See the full [LLM Sidecar README](https://github.com/deforay/llm-sidecar) for all configuration options, usage examples, and deployment guides.

## Local Models with Ollama

The sidecar also supports local models via [Ollama](https://ollama.ai/). To use a local model:

1. Install and run Ollama
2. Pull a model: `ollama pull llama3.1:8b`
3. Set in `.env`: `LLM_DEFAULT_MODEL=ollama:llama3.1:8b`

No API key needed for local models.
