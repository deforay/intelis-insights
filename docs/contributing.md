# Contributing

InteLIS Insights is FOSS under AGPLv3. Contributions of any kind — bug reports, fixes, features, docs, translations — are welcome from anyone who wants the project to be better.

## Quick start

```bash
git clone https://github.com/deforay/intelis-insights
cd intelis-insights
npm install
cp .env.example .env       # see .env.example for what to fill in
docker compose up -d postgres qdrant
npm run db:migrate
npm run dev
```

Open <http://localhost:3000>.

## Stack you should know

A single Next.js 16 app. Everything runs in one Node process — UI, HTTP API, and the LangGraph workflow that turns natural-language questions into SQL.

- **`app/`** — Next.js App Router. UI pages and API route handlers.
- **`lib/`** — server-side library code. Graph nodes, RAG client, LLM provider factory, validation, DB, config.
- **`components/`** — UI components (shadcn/ui plus custom).
- **`scripts/`** — schema export, corpus build, DB migrations, demo seed.
- **`tests/`** — unit (Vitest), integration (Testcontainers), e2e (Playwright).
- **`drizzle/`** — generated SQL migrations.

If you know TypeScript and SQL, you have everything you need.

## Development norms

- **TypeScript strict mode is on.** `npm run typecheck` must pass before a PR.
- **Format and lint.** `npm run lint` should pass.
- **Tests.** New behaviour needs a test. Vitest for unit/integration, Playwright for end-to-end.
- **Migrations.** Schema changes go through Drizzle: edit `lib/db/schema.ts`, run `npm run db:generate`, commit the generated SQL.
- **Secrets.** `.env` is git-ignored. Never commit credentials or API keys.
- **Privacy.** Before adding any code that touches the lab DB or sends data to an LLM, read the privacy invariants in [Privacy & RBAC](privacy-and-rbac.md). If you're unsure whether a change might leak PII, ask before merging.
- **No LLM-with-DB-access patterns.** See the [architecture doc](architecture.md#the-llm-is-a-text-transformer-not-a-database-client).

## What to work on

Issues are labelled by area:

| Label | Area |
|---|---|
| `area:graph` | LangGraph workflow nodes |
| `area:rag` | Qdrant ingestion and retrieval |
| `area:rbac` | Access control and SQL rewriting |
| `area:ui` | Frontend, components, accessibility |
| `area:i18n` | Localisation (French / Portuguese / Spanish translations especially welcome) |
| `area:docs` | Documentation, install guides, Ollama model matrix |

If you want to add something not on the list, open an issue first to discuss the design.

## Pull requests

- Branch from `main`.
- One topic per PR. Smaller is easier to review and merge.
- Describe **what** changed and **why**. Link the issue if there is one.
- If your change is user-facing, include a screenshot or a short clip.

## Code of conduct

Be kind, be specific, be patient. Disagreement is fine; condescension is not. If a maintainer is too curt, call it out.

## Licence

By contributing, you agree your contributions are licensed under AGPLv3 (the project's licence).
