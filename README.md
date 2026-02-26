# Intelis Insights

Governed analytics platform for the [Intelis](https://github.com/deforay) Laboratory Information System. Transforms laboratory data into actionable program intelligence while maintaining strict privacy controls.

## Features

- **Smart Dashboard** — Predefined national indicators with interactive charts
- **Saved Reports** — Reusable structured analyses
- **Conversational Authoring** — AI-assisted query building via chat interface
- **Knowledge Assistance** — RAG-powered, privacy-safe document Q&A

## Tech Stack

| Layer | Technology |
|-------|-----------|
| Backend | PHP 8.2+, Slim 4, Eloquent ORM |
| Frontend | Tailwind CSS, Alpine.js, Chart.js |
| Database | MySQL (app + query databases) |
| RAG | Python FastAPI sidecar, Qdrant vector DB |
| LLM | Configurable via LLM sidecar (Claude, etc.) |
| Migrations | Phinx |

## Prerequisites

- PHP 8.2+
- Composer
- MySQL 8.0+
- Docker & Docker Compose (for Qdrant + RAG API)

## Getting Started

### 1. Clone & install dependencies

```bash
git clone <repo-url> && cd intelis-insights
composer install
```

### 2. Configure environment

```bash
cp .env.example .env
# Edit .env with your database credentials and sidecar URLs
```

### 3. Set up the database

```bash
mysql -u root -e "CREATE DATABASE intelis_insights;"
mysql -u root intelis_insights < database/001_create_schema.sql
mysql -u root intelis_insights < database/002_create_vl_aggregate_tables.sql
mysql -u root intelis_insights < database/003_refresh_vl_aggregates.sql
mysql -u root intelis_insights < database/004_system_tables.sql
mysql -u root intelis_insights < database/seed.sql
```

### 4. Start RAG services (Qdrant + RAG API)

```bash
docker compose up -d
```

### 5. Run the app

```bash
composer start
# → http://localhost:8080
```

## Project Structure

```
├── config/             # App, DB, and business-rule configuration
├── database/           # SQL schema & seed files
├── public/             # Web root (index.php, assets, views)
├── rag-api/            # Python RAG sidecar (FastAPI + Qdrant)
├── src/
│   ├── Bootstrap/      # Database bootstrapping
│   ├── Controllers/    # Chat & Report controllers
│   ├── Models/         # Eloquent models
│   └── Services/       # LLM, RAG, Query, Chart services
├── docker-compose.yml  # Qdrant + RAG API containers
├── composer.json
└── phinx.php           # Migration config
```

## License

AGPL-3.0 — see [LICENSE](LICENSE) for details.
