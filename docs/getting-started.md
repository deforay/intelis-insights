# Getting started

## What you need

- **Docker 24+** and Docker Compose v2.
- **An existing InteLIS deployment** with its MySQL database reachable on the network from the host that will run InteLIS Insights.
- **A read-only MySQL user** for the InteLIS DB. Provision one with `GRANT SELECT`.
- **One LLM provider key** (OpenAI, Anthropic, Google, Mistral, DeepSeek, Groq, or any OpenAI-compatible endpoint) — _or_ a host capable of running Ollama if you prefer fully offline.

## Install

```bash
git clone https://github.com/deforay/intelis-insights
cd intelis-insights
cp .env.example .env
```

Edit `.env`:

```dotenv
AUTH_SECRET=          # generate with: openssl rand -base64 32
POSTGRES_PASSWORD=    # any strong password

LAB_DB_HOST=db.your-intelis.example.org
LAB_DB_PORT=3306
LAB_DB_NAME=vlsm
LAB_DB_USER=insights_ro
LAB_DB_PASSWORD=...

LLM_PROVIDER=openai
OPENAI_API_KEY=sk-...
```

Bring it up:

```bash
docker compose up -d
```

Open <http://localhost:3000> and sign in with an admin user created by the install scripts.

!!! tip "Offline deployment"
    For air-gapped environments, set `LLM_PROVIDER=ollama` and `EMBEDDINGS_PROVIDER=ollama`, then start with:
    ```bash
    docker compose --profile offline up -d
    ```
    The bundled Ollama service has no external dependencies. See [LLM providers](llm-providers.md) for the tested model/hardware matrix.

## Local development

```bash
npm install
cp .env.example .env
docker compose up -d postgres qdrant
npm run db:migrate
npm run dev
```

Open <http://localhost:3000>.

## What's running

| Service | Port | Purpose |
|---|---|---|
| `app` | `3000` | Next.js — UI, API, LangGraph workflow |
| `postgres` | internal | App database (sessions, audit, users) |
| `qdrant` | internal | Vector database (RAG corpus) |
| `ollama` _(optional)_ | internal | Local LLM + embeddings (with `--profile offline`) |

Your existing **InteLIS MySQL** stays wherever it already runs. The `app` connects to it via `LAB_DB_HOST` — we never bundle, migrate, or touch its schema.

## Next steps

- [Configure your deployment](configuration.md) — every env var explained.
- [Understand the privacy and RBAC model](privacy-and-rbac.md).
- [Pick the right LLM provider](llm-providers.md).
