# Setup Guide

## Quick Start (Docker)

The fastest way to get running. Requires only **Docker** and **Git**.

```bash
git clone --recurse-submodules https://github.com/deforay/intelis-insights.git
cd intelis-insights
cp .env.example .env
# Edit .env — add at least one LLM API key (ANTHROPIC_API_KEY, etc.)
make up
```

This starts all services:

| Service | Port | Description |
| --- | --- | --- |
| `app` | 8080 | PHP application (Slim 4) |
| `mysql` | 3306 | MySQL 8.0 (app database) |
| `qdrant` | 6333 | Qdrant vector database |
| `rag-api` | 8089 | FastAPI RAG service (embedding + search) |
| `llm-sidecar` | 3100 | LLM gateway (Claude, DeepSeek, etc.) |

Open <http://localhost:8080> once all services are healthy.

### Verify

```bash
make status         # check all service health
make logs           # follow logs
make logs-app       # follow only the PHP app logs
```

### Connect the InteLIS Database

LLM query features require the InteLIS database (the laboratory information system data where LLM-generated SQL queries execute). Without it, the dashboard and chat UI still load but the AI cannot generate SQL queries against lab data.

There are two ways to provide InteLIS data:

#### Option A: Import an InteLIS dump into the Docker MySQL container

If you have a SQL dump of the InteLIS database (e.g. from `mysqldump`):

```bash
# 1. Import the dump (creates the database and tables)
make db-import FILE=path/to/intelis-dump.sql

# 2. If the database name differs from the default (vlsm), update .env:
#    QUERY_DB_NAME=your_database_name
#    Then restart: make down && make up

# 3. Export the InteLIS schema so RAG knows about available tables/columns
docker compose exec app php /var/www/bin/export-schema.php

# 4. Build RAG snippets from the schema + business rules + field guide
#    and upload them to Qdrant for semantic search
make rag-refresh

# 5. Verify — should return matching snippets
curl -X POST http://localhost:8089/v1/search \
  -H 'Content-Type: application/json' \
  -d '{"query": "viral load suppression", "k": 3}'
```

#### Option B: Connect to an external InteLIS instance

If your InteLIS database is hosted elsewhere (e.g. a shared lab server):

```bash
# Edit .env with the external connection details:
#   QUERY_DB_HOST=lab-db-server.example.com
#   QUERY_DB_NAME=vlsm
#   QUERY_DB_USER=readonly_user
#   QUERY_DB_PASSWORD=readonly_pass

# Restart to pick up the new config
make down && make up

# Then seed RAG with the external schema
docker compose exec app php /var/www/bin/export-schema.php
make rag-refresh
```

#### After seeding: test a query

```bash
curl -X POST http://localhost:8080/api/v1/chat/ask \
  -H 'Content-Type: application/json' \
  -d '{"question": "How many viral load tests were done last month?"}'
```

#### Re-seeding RAG

If the InteLIS schema changes (new tables, renamed columns), re-seed RAG:

```bash
make rag-refresh          # incremental update
make rag-reset            # full reset + re-seed from scratch
```

---

## Manual Setup (without Docker)

If you prefer to run services natively.

### Prerequisites

- PHP 8.4+ with `pdo_mysql`, `mbstring` extensions
- Composer
- MySQL / MariaDB 8.0+
- Docker & Docker Compose (for Qdrant + RAG API)
- Node.js / Bun (for LLM Sidecar)

### 1. Clone & install dependencies

```bash
git clone --recurse-submodules https://github.com/deforay/intelis-insights.git
cd intelis-insights
composer install
```

### 2. Environment configuration

```bash
cp .env.example .env
```

Edit `.env` with your values. Key variables:

| Variable | Default | Description |
| --- | --- | --- |
| `DB_HOST` | `127.0.0.1` | MySQL host for the app database |
| `DB_NAME` | `intelis_insights` | App database name (reports, sessions) |
| `DB_PASSWORD` | — | MySQL root password |
| `QUERY_DB_NAME` | `vlsm` | InteLIS query database name (lab data) |
| `QUERY_DB_HOST` | falls back to `DB_HOST` | Query DB host (for external InteLIS) |
| `QUERY_DB_USER` | falls back to `DB_USER` | Query DB username |
| `QUERY_DB_PASSWORD` | falls back to `DB_PASSWORD` | Query DB password |
| `LLM_SIDECAR_URL` | `http://127.0.0.1:3100` | LLM sidecar base URL |
| `LLM_DEFAULT_MODEL` | `sonnet` | Default model for LLM calls |
| `RAG_BASE_URL` | `http://127.0.0.1:8089` | RAG API base URL |
| `RAG_ENABLED` | `true` | Enable/disable RAG retrieval |
| `ANTHROPIC_API_KEY` | — | Anthropic API key (at least one LLM key required) |

### 3. Databases

The system uses **two MySQL databases**:

- **`intelis_insights`** — Application data: reports, chat sessions, chat messages, audit logs, VL aggregate tables.
- **InteLIS database** (default name: `vlsm`) — Laboratory data (Viral Load, EID, COVID, TB, etc.). This is the database that LLM-generated SQL queries run against. Read-only from the application's perspective.

```bash
mysql -u root -p -e "CREATE DATABASE IF NOT EXISTS intelis_insights CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p intelis_insights < database/001_create_schema.sql
mysql -u root -p intelis_insights < database/002_create_vl_aggregate_tables.sql
mysql -u root -p intelis_insights < database/003_refresh_vl_aggregates.sql
mysql -u root -p intelis_insights < database/004_system_tables.sql
mysql -u root -p intelis_insights < database/seed.sql
```

The InteLIS database should already exist (it's your LIMS database).

### 4. Start Docker services (Qdrant + RAG API)

```bash
docker compose up -d qdrant rag-api
```

Verify:

```bash
curl http://127.0.0.1:6333/healthz
curl http://127.0.0.1:8089/health
```

### 5. Seed the vector database

See the [Seeding Guide](seeding.md) for details.

```bash
bash bin/rag-refresh.sh
```

### 6. Start the LLM Sidecar

```bash
cd llm-sidecar
bun install
bun run start
# → http://localhost:3100
```

Or see the [LLM Sidecar README](https://github.com/deforay/llm-sidecar) for full configuration.

### 7. Run the application

```bash
composer start
# → http://localhost:8080
```

### 8. Verify

```bash
curl http://localhost:8080/health
curl http://localhost:8080/status

curl -X POST http://localhost:8080/api/v1/chat/ask \
  -H 'Content-Type: application/json' \
  -d '{"question": "How many viral load tests were done last month?"}'
```

---

## Makefile Reference

Run `make help` to see all available targets:

| Target | Description |
| --- | --- |
| `make up` | Start all services |
| `make down` | Stop all services |
| `make build` | Rebuild all images (no cache) |
| `make rebuild` | Rebuild and restart |
| `make prod` | Start in production mode (nginx + php-fpm) |
| `make logs` | Follow logs for all services |
| `make logs-<service>` | Follow logs for a specific service |
| `make status` | Show service status and health |
| `make shell` | Open a shell in the PHP app container |
| `make db-shell` | Open a MySQL shell |
| `make db-import FILE=...` | Import a SQL file into MySQL |
| `make rag-refresh` | Rebuild and re-seed RAG index |
| `make rag-reset` | Reset RAG collection and re-seed |
| `make clean` | Remove containers, volumes, and images |
| `make clean-data` | Remove all persistent data |

## Production Deployment

For production, use the override file which switches to nginx + php-fpm, disables debug mode, and hides internal service ports:

```bash
make prod
# — or —
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d
```

Ensure you set in `.env`:

- At least one `*_API_KEY` for LLM providers
- `ALLOW_INSECURE_NO_AUTH=false`
- `LLM_SIDECAR_SECRET=<a-strong-secret>`
- `APP_DEBUG=false`
- `DB_PASSWORD=<a-strong-password>`

## Architecture Overview

```
┌──────────────┐     ┌────────────────┐     ┌────────────────┐
│   Dashboard  │────▶│  Slim PHP API  │────▶│ MySQL InteLIS  │
│   (Frontend) │     │  (port 8080)   │     │  (lab data)    │
└──────────────┘     └───────┬────────┘     └────────────────┘
                             │
                    ┌────────┼────────┐
                    ▼        ▼        ▼
              ┌──────────┐ ┌─────┐ ┌──────────────┐
              │LLM Sidecar│ │ RAG │ │MySQL intelis │
              │(port 3100)│ │ API │ │  _insights   │
              └──────────┘ │:8089│ │ (app data)   │
                           └──┬──┘ └──────────────┘
                              │
                         ┌────┴────┐
                         │ Qdrant  │
                         │ (6333)  │
                         └─────────┘
```
