# Intelis Insights

Governed analytics platform for the [InteLIS](https://github.com/deforay) Laboratory Information System. Transforms laboratory data into actionable program intelligence while maintaining strict privacy controls.

Intelis Insights sits on top of your InteLIS laboratory database and gives you two ways to explore your data:

1. **Dashboard** — Pre-built national indicators with interactive charts. No AI involved, fast and deterministic.
2. **Conversational Chat** — Ask questions in plain English (e.g. *"What is the VL suppression rate by district?"*). An LLM generates SQL, executes it safely, and returns results with charts.

Both modes enforce strict privacy controls — patient-level data is never exposed.

---

## Get Running

New here? Follow these steps in order:

1. **[Prerequisites](getting-started/prerequisites.md)** — What you need before you start
2. **[Docker Setup](getting-started/docker-setup.md)** — Fastest way to get running (~5 minutes)
3. **[First-Run Checklist](getting-started/first-run-checklist.md)** — Verify everything works

Don't want Docker? See [Manual Setup](getting-started/manual-setup.md) instead.

## Already Running?

Common next steps once the app is up:

- [Connect your InteLIS data](guides/connecting-intelis-data.md) — Required for the chat and dashboard to show real data
- [Configure API keys](guides/environment-variables.md#llm-provider-api-keys) — Add LLM provider keys via the Settings page or `.env`
- [Seed the RAG index](guides/rag-seeding.md) — Helps the AI generate better SQL queries
- [Deploy to production](guides/production-deployment.md) — Secure and optimize for real users

## Understand the System

- [Architecture Overview](concepts/architecture.md) — How the services fit together
- [Two Databases](concepts/two-databases.md) — Why there's an app DB and a query DB
- [RAG & Vector Search](concepts/rag.md) — How schema context improves AI accuracy
- [Glossary](concepts/glossary.md) — Terms used throughout the docs

## Reference

- [API Reference](reference/api.md) — All HTTP endpoints
- [Query Pipeline](reference/query-pipeline.md) — How a chat question becomes a chart
- [LLM Sidecar](reference/llm-sidecar.md) — Model aliases, configuration, endpoints
- [Environment Variables](guides/environment-variables.md) — Every `.env` setting explained
- [Makefile Reference](guides/makefile-reference.md) — All `make` commands

---

[:material-wrench: Troubleshooting](troubleshooting.md) — Stuck? Start here.

## Tech Stack

| Layer | Technology |
|-------|-----------|
| Backend | PHP 8.4+, Slim 4, Eloquent ORM |
| Frontend | Tailwind CSS, Alpine.js, Chart.js |
| Database | MySQL 8.0 (app + InteLIS query databases) |
| RAG | Python FastAPI sidecar, Qdrant vector DB |
| LLM | Configurable via LLM sidecar (Claude, DeepSeek, etc.) |
| Migrations | Phinx |
