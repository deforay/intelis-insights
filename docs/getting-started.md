# Getting started

## What you need

- **Docker 24+** and Docker Compose v2.
- **An InteLIS MySQL source**, either:
  - an existing InteLIS deployment with its MySQL database reachable from the host that will run InteLIS Insights, or
  - an approved InteLIS SQL dump loaded into the optional local MySQL container.
- **A read-only MySQL user** for the InteLIS DB. For an external DB, provision one with `GRANT SELECT`; for the optional local container, the setup creates it for you.
- **One LLM provider key** (OpenAI, Anthropic, Google, Mistral, DeepSeek, Groq, or any OpenAI-compatible endpoint) — _or_ a host capable of running Ollama if you prefer fully offline.

## Install

```bash
git clone https://github.com/deforay/intelis-insights
cd intelis-insights
cp .env.example .env
```

Edit `.env` with your app secrets and choose one MySQL source.

### Option A: external InteLIS MySQL

Use this when the client already has InteLIS/MySQL running on a server or VM.

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

### Option B: optional Dockerized InteLIS MySQL

Use this when the client wants the app and a MySQL copy installed locally without installing MySQL directly on the machine.

```dotenv
AUTH_SECRET=          # generate with: openssl rand -base64 32
POSTGRES_PASSWORD=    # any strong password

LAB_DB_HOST=intelis-mysql
LAB_DB_PORT=3306
LAB_DB_NAME=intelis
LAB_DB_USER=intelis_reader
LAB_DB_PASSWORD=...

LOCAL_LAB_MYSQL_ROOT_PASSWORD=...
LOCAL_LAB_MYSQL_PORT=3307

LLM_PROVIDER=openai
OPENAI_API_KEY=sk-...
```

Place the approved dump in `mysql-init/` before the first startup:

```text
mysql-init/
  01-intelis-dump.sql.gz
  99-create-readonly-user.sh
```

Then start with the local-lab override:

```bash
docker compose -f docker-compose.yml -f docker-compose.local-lab.yml up -d
```

The override adds an `intelis-mysql` service, imports supported MySQL init files (`*.sql`, `*.sql.gz`, and executable `*.sh`) on first boot, creates the read-only user from `LAB_DB_USER` / `LAB_DB_PASSWORD`, and points `app` and `init` at that container. The import runs only when the MySQL volume is empty. To re-import, stop the stack, remove the Compose `intelis_mysql_data` volume, and start again.

Do not commit real lab dumps. The repo ignores `mysql-init/*.sql`, `*.sql.gz`, `*.dump`, and `*.dump.gz`.

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
| `intelis-mysql` _(optional)_ | host `3307`, internal `3306` | Local InteLIS MySQL copy, only with `docker-compose.local-lab.yml` |
| `ollama` _(optional)_ | internal | Local LLM + embeddings (with `--profile offline`) |

With the default compose command, your existing **InteLIS MySQL** stays wherever it already runs. The `app` connects to it via `LAB_DB_HOST`; we never bundle, migrate, or touch its schema. With the local-lab override, the app instead connects to the optional `intelis-mysql` container seeded from your approved dump.

## Next steps

- [Configure your deployment](configuration.md) — every env var explained.
- [Understand the privacy and RBAC model](privacy-and-rbac.md).
- [Pick the right LLM provider](llm-providers.md).
