# Intelis Insights

Governed analytics platform for the [InteLIS](https://github.com/deforay) Laboratory Information System. Transforms laboratory data into actionable program intelligence while maintaining strict privacy controls.

## Features

- **Smart Dashboard** — Predefined national indicators with interactive charts
- **Saved Reports** — Reusable structured analyses
- **Conversational Authoring** — AI-assisted query building via chat interface
- **Knowledge Assistance** — RAG-powered, privacy-safe document Q&A

## Tech Stack

| Layer | Technology |
| ----- | ---------- |
| Backend | PHP 8.4+, Slim 4, Eloquent ORM |
| Frontend | Tailwind CSS, Alpine.js, Chart.js |
| Database | MySQL (app + InteLIS query databases) |
| RAG | Python FastAPI sidecar, Qdrant vector DB |
| LLM | Configurable via LLM sidecar (Claude, DeepSeek, etc.) |
| Migrations | Phinx |

## Quick Start (Docker)

Requires only **Docker** and **Git**.

```bash
git clone --recurse-submodules https://github.com/deforay/intelis-insights.git
cd intelis-insights
cp .env.example .env    # add at least one LLM API key
make up                 # starts all services
```

Open <http://localhost:8080> once services are healthy (`make status` to check).

### Connect the InteLIS Database

LLM query features require the InteLIS database. Import a dump or connect to an external instance:

```bash
# Import a dump into the Docker MySQL container
make db-import FILE=path/to/intelis-dump.sql
make rag-refresh

# — or connect to an external instance via .env —
# QUERY_DB_HOST=lab-db-server.example.com
# QUERY_DB_NAME=vlsm
# QUERY_DB_USER=readonly_user
# QUERY_DB_PASSWORD=readonly_pass
```

See the [full documentation](docs/index.md) for detailed guides, concepts, and troubleshooting.

## Documentation

- [Prerequisites](docs/getting-started/prerequisites.md) — What you need before starting
- [Docker Setup](docs/getting-started/docker-setup.md) — Step-by-step Docker installation
- [Manual Setup](docs/getting-started/manual-setup.md) — Running services natively
- [Architecture](docs/concepts/architecture.md) — How the system works
- [Glossary](docs/concepts/glossary.md) — Key terms explained
- [Troubleshooting](docs/troubleshooting.md) — Common problems and fixes

## Project Structure

```
├── config/             # App, DB, and business-rule configuration
├── database/           # SQL schema & seed files
├── docker/             # Docker support files (nginx, init scripts)
├── public/             # Web root (index.php, assets, views)
├── rag-api/            # Python RAG sidecar (FastAPI + Qdrant)
├── llm-sidecar/        # LLM gateway (git submodule)
├── src/
│   ├── Bootstrap/      # Database bootstrapping
│   ├── Controllers/    # Chat & Report controllers
│   ├── Models/         # Eloquent models
│   └── Services/       # LLM, RAG, Query, Chart services
├── docker-compose.yml  # Full stack (MySQL, Qdrant, RAG, LLM, App)
├── Makefile            # Developer convenience commands
├── composer.json
└── phinx.php           # Migration config
```

## Useful Commands

```bash
make help               # list all targets
make status             # check service health
make logs               # follow all service logs
make shell              # open shell in PHP container
make db-shell           # open MySQL CLI
make rag-refresh        # re-seed RAG index
make clean              # remove containers + volumes
```

## License

AGPL-3.0 — see [LICENSE](LICENSE) for details.
