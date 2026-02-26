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

## Documentation

- [Setup Guide](setup.md) — Installation and configuration
- [Query Pipeline](query-pipeline.md) — How questions become SQL and results
- [Seeding](seeding.md) — Building and indexing the RAG corpus
- [API Reference](api-reference.md) — REST endpoint documentation
