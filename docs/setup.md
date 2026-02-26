# Setup Guide

## Prerequisites

- PHP 8.2+ with `pdo_mysql`, `mbstring`, `json` extensions
- Composer
- MySQL / MariaDB
- Docker & Docker Compose (for Qdrant + RAG API)
- LLM Sidecar running at `http://127.0.0.1:3100`

## 1. Clone & install dependencies

```bash
git clone https://github.com/deforay/intelis-insights.git
cd intelis-insights
composer install
```

## 2. Environment configuration

```bash
cp .env.example .env
```

Edit `.env` with your values:

```dotenv
# App database (reports, chat sessions, users)
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=intelis_insights
DB_USER=root
DB_PASSWORD=your_password

# Query database (VLSM — where LLM-generated SQL executes)
# Defaults to same host/credentials as app DB if not set
QUERY_DB_NAME=vlsm
# QUERY_DB_USER=root
# QUERY_DB_PASSWORD=your_password

# LLM Sidecar
LLM_SIDECAR_URL=http://127.0.0.1:3100
LLM_SIDECAR_SECRET=
LLM_DEFAULT_MODEL=sonnet

# RAG Sidecar (started via docker compose)
RAG_BASE_URL=http://127.0.0.1:8089
RAG_ENABLED=true
```

### Key environment variables

| Variable | Default | Description |
|---|---|---|
| `DB_HOST` | `127.0.0.1` | MySQL host for the app database |
| `DB_NAME` | `intelis_insights` | App database name (reports, sessions) |
| `QUERY_DB_NAME` | `vlsm` | Query database name (lab data) |
| `QUERY_DB_USER` | falls back to `DB_USER` | Query DB username |
| `QUERY_DB_PASSWORD` | falls back to `DB_PASSWORD` | Query DB password |
| `LLM_SIDECAR_URL` | `http://127.0.0.1:3100` | LLM sidecar base URL |
| `LLM_DEFAULT_MODEL` | `sonnet` | Default model for LLM calls |
| `RAG_BASE_URL` | `http://127.0.0.1:8089` | RAG API base URL |
| `RAG_ENABLED` | `true` | Enable/disable RAG retrieval |

## 3. Databases

The system uses **two MySQL databases**:

- **`intelis_insights`** — Application data: reports, chat sessions, chat messages, audit logs, and eventually users/roles/cached aggregates.
- **`vlsm`** — Laboratory data (Viral Load, EID, COVID, TB, etc.). This is the database that LLM-generated SQL queries run against. This database is read-only from the application's perspective.

### Create the app database & run migrations

```bash
mysql -u root -p -e "CREATE DATABASE IF NOT EXISTS intelis_insights CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# Run Phinx migrations
vendor/bin/phinx migrate
```

The `vlsm` database should already exist (it's your LIMS database).

## 4. Start Docker services

Qdrant (vector DB) and the RAG API run via Docker Compose:

```bash
docker compose up -d
```

This starts:

| Service | Port | Description |
|---|---|---|
| `qdrant` | 6333 | Qdrant vector database |
| `rag-api` | 8089 | FastAPI RAG service (embedding + search) |

Verify they're running:

```bash
# Qdrant health
curl http://127.0.0.1:6333/healthz

# RAG API health
curl -X POST http://127.0.0.1:8089/v1/search \
  -H 'Content-Type: application/json' \
  -d '{"query": "test", "k": 1}'
```

## 5. Seed the vector database

The RAG system needs to be populated with schema metadata, business rules, and field guide snippets. See the [Seeding Guide](seeding.md) for detailed instructions.

Quick version:

```bash
# Export schema from vlsm → var/schema.json
php bin/export-schema.php

# Build RAG snippets → corpus/snippets.jsonl
php bin/build-rag-snippets.php

# Upload to Qdrant via RAG API
php bin/rag-upsert.php corpus/snippets.jsonl

# Or do all three in one shot:
bash bin/rag-refresh.sh
```

## 6. Start the LLM Sidecar

The LLM sidecar must be running for the system to work. It's a separate project:

```bash
cd ~/www/llm-sidecar
# Follow its own setup instructions
```

The sidecar provides:
- `POST /v1/chat` — free-form text completion
- `POST /v1/structured` — JSON schema-validated output
- `POST /v1/embeddings` — text embeddings

## 7. Run the application

### Development

```bash
# PHP built-in server
composer start
# → http://localhost:8080
```

### Production (Apache/Nginx)

Point your web server's document root to the `public/` directory. Ensure all requests are routed to `public/index.php` (standard Slim 4 setup).

Apache `.htaccess` example (already in `public/`):

```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^ index.php [QSA,L]
```

## 8. Verify

```bash
# Health check
curl http://localhost:8080/health

# Database connectivity
curl http://localhost:8080/status

# Ask a question
curl -X POST http://localhost:8080/api/v1/chat/ask \
  -H 'Content-Type: application/json' \
  -d '{"question": "How many viral load tests were done last month?"}'
```

## Architecture overview

```
┌──────────────┐     ┌────────────────┐     ┌──────────────┐
│   Dashboard  │────▶│  Slim PHP API  │────▶│  MySQL vlsm  │
│   (Frontend) │     │  (port 8080)   │     │  (lab data)  │
└──────────────┘     └───────┬────────┘     └──────────────┘
                             │
                    ┌────────┼────────┐
                    ▼        ▼        ▼
              ┌──────────┐ ┌─────┐ ┌──────────────┐
              │LLM Sidecar│ │ RAG │ │MySQL intelis │
              │ (port 3100)│ │API  │ │  _insights   │
              └──────────┘ │:8089│ │ (app data)   │
                           └──┬──┘ └──────────────┘
                              │
                         ┌────┴────┐
                         │ Qdrant  │
                         │ (6333)  │
                         └─────────┘
```
