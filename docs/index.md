# InteLIS Insights

**Natural-language analytics for laboratory data. Free, open source, self-hostable.**

Ask questions about lab results in plain English; get answers as charts and tables. Built for ministries of health, donor programs, and public-health labs that already run [InteLIS](https://github.com/deforay/vlsm) and want AI on top of their existing data — without paying for SaaS, without sending patient data to a vendor, and without their data leaving their infrastructure.

!!! info "Licence"
    InteLIS Insights is licensed under **AGPLv3**. Source code, audit, modify, self-host — all free. See [LICENSE on GitHub](https://github.com/deforay/intelis-insights/blob/main/LICENSE).

## What it does

You point it at your existing InteLIS MySQL database (read-only). Then:

1. A user signs in and asks a question — _"What's the VL suppression rate by district last quarter?"_
2. The app classifies intent, retrieves relevant schema context from a vector DB, and generates SQL using your chosen LLM (OpenAI, Anthropic, Google, Mistral, DeepSeek, Groq, or a local Ollama model).
3. The SQL goes through **access-control checks** (a district-level user can't query province-level data) and **safety validation** (SELECT-only, no PII columns, no patient identifiers).
4. The validated SQL runs against your lab DB. Results come back as a table plus an auto-suggested chart.
5. Every step is logged for audit.

!!! success "Privacy by architecture"
    **No patient identifiers are ever sent to the LLM.** The LLM never holds a database connection. Only the user's question and the database schema are transmitted; results stay in your infrastructure.

## Contents

- [Getting started](getting-started.md) — Docker Compose, an existing InteLIS database, one provider key.
- [Configuration](configuration.md) — Every environment variable, what it does, and which provider needs what.
- [Architecture](architecture.md) — The stack, the components, and how they fit together.
- [Query flow](query-flow.md) — How a question becomes a chart, step by step.
- [Privacy & RBAC](privacy-and-rbac.md) — How patient data stays safe; how district / province / national tiers are enforced.
- [LLM providers](llm-providers.md) — Supported providers (OpenAI, Anthropic, Google, Mistral, DeepSeek, Groq, Ollama) and how to pick.
- [Contributing](contributing.md) — All TypeScript / JavaScript. Built to be easy to read, easy to extend.
- [Implementation plan](plan.md) — Current progress, known gaps, v2 roadmap.
