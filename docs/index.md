# Intelis Insights

Governed analytics platform for the [InteLIS](https://github.com/deforay) Laboratory Information System. Transforms laboratory data into actionable program intelligence while maintaining strict privacy controls.

## What It Does

Intelis Insights sits on top of your InteLIS laboratory database and gives you two ways to explore your data:

1. **Dashboard** — Pre-built national indicators with interactive charts. No AI involved, fast and deterministic.
2. **Conversational Chat** — Ask questions in plain English (e.g. *"What is the VL suppression rate by district?"*). An LLM generates SQL, executes it safely, and returns results with charts.

Both modes enforce strict privacy controls — patient-level data is never exposed.

## Features

- **Smart Dashboard** — Predefined national indicators with interactive charts
- **Saved Reports** — Reusable structured analyses
- **Conversational Authoring** — AI-assisted query building via chat interface
- **Knowledge Assistance** — RAG-powered, privacy-safe document Q&A

## Tech Stack

| Layer | Technology |
|-------|-----------|
| Backend | PHP 8.4+, Slim 4, Eloquent ORM |
| Frontend | Tailwind CSS, Alpine.js, Chart.js |
| Database | MySQL 8.0 (app + InteLIS query databases) |
| RAG | Python FastAPI sidecar, Qdrant vector DB |
| LLM | Configurable via LLM sidecar (Claude, DeepSeek, etc.) |
| Migrations | Phinx |

## Documentation

<div class="grid cards" markdown>

-   **Getting Started**

    ---

    Install and run Intelis Insights in minutes.

    [:octicons-arrow-right-24: Prerequisites](getting-started/prerequisites.md)
    [:octicons-arrow-right-24: Docker Setup](getting-started/docker-setup.md)
    [:octicons-arrow-right-24: Manual Setup](getting-started/manual-setup.md)
    [:octicons-arrow-right-24: First-Run Checklist](getting-started/first-run-checklist.md)

-   **Concepts**

    ---

    Understand how the system works before diving in.

    [:octicons-arrow-right-24: Architecture Overview](concepts/architecture.md)
    [:octicons-arrow-right-24: Two Databases](concepts/two-databases.md)
    [:octicons-arrow-right-24: RAG & Vector Search](concepts/rag.md)
    [:octicons-arrow-right-24: Glossary](concepts/glossary.md)

-   **Guides**

    ---

    Step-by-step instructions for common tasks.

    [:octicons-arrow-right-24: Connecting InteLIS Data](guides/connecting-intelis-data.md)
    [:octicons-arrow-right-24: RAG Seeding](guides/rag-seeding.md)
    [:octicons-arrow-right-24: Makefile Reference](guides/makefile-reference.md)
    [:octicons-arrow-right-24: Environment Variables](guides/environment-variables.md)
    [:octicons-arrow-right-24: Production Deployment](guides/production-deployment.md)

-   **Reference**

    ---

    Detailed technical documentation.

    [:octicons-arrow-right-24: API Reference](reference/api.md)
    [:octicons-arrow-right-24: Query Pipeline](reference/query-pipeline.md)
    [:octicons-arrow-right-24: LLM Sidecar](reference/llm-sidecar.md)

</div>

[:material-wrench: Troubleshooting](troubleshooting.md) — Common problems and how to fix them.
