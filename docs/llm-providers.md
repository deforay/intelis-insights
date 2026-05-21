# LLM providers

InteLIS Insights supports a range of providers so deployments can pick the right trade-off between cost, latency, accuracy, and data residency.

## Supported providers

| Provider | `LLM_PROVIDER` value | Cost profile | Notes |
|---|---|---|---|
| **OpenAI** | `openai` | Medium | Reliable, strong SQL generation, broad model lineup. The default. |
| **Anthropic** | `anthropic` | Medium | Claude family — strong on long-context reasoning. |
| **Google** | `google` | Medium | Gemini family. |
| **Mistral** | `mistral` | Low–Medium | First-class via `@ai-sdk/mistral`. European data residency. |
| **DeepSeek** | `deepseek` | Very low | OpenAI-compatible API. Strong quality-per-dollar. |
| **Groq** | `groq` | Low | OpenAI-compatible. Very fast inference. |
| **OpenAI-compatible** | `openai_compatible` | Varies | Together, Fireworks, OpenRouter, self-hosted vLLM / LiteLLM, etc. |
| **Ollama** | `ollama` | Free (your hardware) | Fully offline / air-gapped. |

## Choosing a provider

| You want… | Recommended |
|---|---|
| Production reliability, best-in-class SQL accuracy | OpenAI or Anthropic |
| Lowest cost while staying high quality | DeepSeek or Mistral |
| Fastest response times | Groq |
| Data must not leave the country | Ollama (offline) or `openai_compatible` pointed at a hosted regional endpoint |
| Air-gapped / no internet at all | Ollama with `docker compose --profile offline` |
| EU data residency | Mistral or a regional Together / Fireworks deployment |
| To switch between providers per query | `openai_compatible` pointed at OpenRouter |

## Configuration

Set `LLM_PROVIDER` and the matching API key. All other provider keys can be left empty.

```dotenv
LLM_PROVIDER=deepseek
DEEPSEEK_API_KEY=sk-...
LLM_MODEL=deepseek-chat
LLM_MODEL_INTENT=deepseek-chat
```

For `openai_compatible`:

```dotenv
LLM_PROVIDER=openai_compatible
OPENAI_COMPATIBLE_BASE_URL=https://api.together.xyz/v1
OPENAI_COMPATIBLE_API_KEY=...
LLM_MODEL=meta-llama/Llama-3.1-70B-Instruct-Turbo
```

## Offline / Ollama

For deployments without reliable internet access (some ministry data-centre environments, remote labs, sovereign-cloud requirements):

```dotenv
LLM_PROVIDER=ollama
EMBEDDINGS_PROVIDER=ollama
EMBEDDINGS_MODEL=nomic-embed-text
LLM_MODEL=llama3.1:70b
```

Start with the offline profile:

```bash
docker compose --profile offline up -d
```

The bundled `ollama` service runs locally, no external dependencies, no API keys.

!!! warning "Model quality matters"
    SQL-generation quality varies enormously across Ollama models and hardware classes:

    - **Llama 3.1 70B** — handles complex multi-join queries well on a workstation with ≥48 GB GPU RAM.
    - **Mixtral 8x7B / Llama 3.1 8B** — fine for simple queries, struggles with joins beyond two tables.
    - **Small (≤7B) models** — fast, often inaccurate on real-world InteLIS schemas. Use for `LLM_MODEL_INTENT` only.

    A tested model / hardware / accuracy matrix is published in `docs/ollama-matrix.md` _(coming soon)_.

## Embeddings

Embeddings are configured separately from the chat LLM:

| `EMBEDDINGS_PROVIDER` | Default model | Notes |
|---|---|---|
| `openai` | `text-embedding-3-small` (1536 dim) | Default. Cheap, high quality. |
| `mistral` | `mistral-embed` (1024 dim) | EU residency. |
| `openai_compatible` | _(provider-specific)_ | Use Together, Fireworks, etc. |
| `ollama` | `nomic-embed-text` (768 dim) | Offline. |

!!! warning "Re-ingest on switch"
    Switching embedding providers changes the vector dimension. The Qdrant collection is created at bootstrap with the configured embedder's dimension. To switch:
    ```bash
    # 1. drop the existing collection
    # 2. re-run the corpus build & upsert
    npm run rag:build
    npm run rag:upsert
    ```
