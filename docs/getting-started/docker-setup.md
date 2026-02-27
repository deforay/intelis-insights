# Docker Setup

The fastest way to get running. Takes about 5 minutes (plus a one-time model download).

## 1. Clone the Repository

```bash
git clone --recurse-submodules https://github.com/deforay/intelis-insights.git
cd intelis-insights
```

!!! warning "Don't forget `--recurse-submodules`"
    The `llm-sidecar` folder is a [git submodule](https://git-scm.com/book/en/v2/Git-Tools-Submodules). Without `--recurse-submodules`, it will be an empty directory and the LLM sidecar won't build.

    **Already cloned without it?** Run:
    ```bash
    git submodule update --init --recursive
    ```

## 2. Configure Environment

```bash
cp .env.example .env
```

Open `.env` in your editor and fill in any values you need to change. Everything has sensible defaults for local development.

!!! tip "API keys can be added later via the Settings page"
    You don't need to put LLM API keys in `.env`. Once the app is running, go to **Settings → API Keys** in the browser to add or change keys for Anthropic, OpenAI, DeepSeek, Google, and Groq. Keys entered in the Settings page are stored in the database and pushed to the sidecar at runtime — no restart required.

    If you prefer `.env`, that still works:
    ```bash
    # Pick one (or more):
    ANTHROPIC_API_KEY=sk-ant-your-key-here
    OPENAI_API_KEY=sk-your-key-here
    DEEPSEEK_API_KEY=your-key-here
    ```

See [Environment Variables](../guides/environment-variables.md) for the full list.

## 3. Start All Services

```bash
make up
```

This starts five services:

| Service | Port | What it does |
|---------|------|-------------|
| `app` | [localhost:8080](http://localhost:8080) | PHP application (dashboard, chat, API) |
| `mysql` | localhost:3306 | MySQL 8.0 database |
| `qdrant` | [localhost:6333](http://localhost:6333/dashboard) | Qdrant vector database |
| `rag-api` | localhost:8089 | RAG embedding + search service |
| `llm-sidecar` | localhost:3100 | LLM gateway (routes to Claude, GPT, etc.) |

!!! info "First run takes longer"
    On the first run, Docker builds images and the RAG API downloads an embedding model (~200 MB). This is cached for future starts — subsequent `make up` commands take only a few seconds.

## 4. Verify

```bash
make status
```

You should see all services as `running (healthy)`. If any show `starting`, wait a minute and check again.

```
=== Health Checks ===
App:          OK
RAG API:      OK
LLM Sidecar:  OK
Qdrant:       OK
```

Open [http://localhost:8080](http://localhost:8080) in your browser. You should see the dashboard.

## 5. (Optional) Connect InteLIS Data

The dashboard and chat are running, but without lab data the AI can't answer questions. See [Connecting InteLIS Data](../guides/connecting-intelis-data.md) for how to import a database dump or connect to an external InteLIS instance.

## Useful Commands

```bash
make logs           # follow all service logs
make logs-app       # follow only the PHP app logs
make shell          # open a shell in the PHP container
make db-shell       # open a MySQL CLI
make down           # stop all services
make clean          # stop + remove containers and volumes
```

See [Makefile Reference](../guides/makefile-reference.md) for the full list.

## Next Step

Run through the [First-Run Checklist](first-run-checklist.md) to verify everything works.
