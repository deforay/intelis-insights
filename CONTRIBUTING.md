# Contributing to InteLIS Insights

InteLIS Insights is FOSS under AGPLv3. Contributions of any kind — bug reports, fixes, features, docs, translations — are welcome from anyone who wants the project to be better.

## Quick start for contributors

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

This is a single Next.js 16 application. Everything runs in one Node process — UI, HTTP API, and the LangGraph workflow that turns natural-language questions into SQL.

- **`app/`** — Next.js App Router. UI pages and API route handlers.
- **`lib/`** — server-side library code. Graph nodes, RAG client, LLM provider factory, validation, DB, config.
- **`components/`** — UI components (shadcn/ui plus custom).
- **`scripts/`** — one-off scripts: schema export, corpus build, migrations, demo seed.
- **`tests/`** — unit (Vitest), integration (Testcontainers), e2e (Playwright).
- **`drizzle/`** — generated SQL migrations.

If you know TypeScript and SQL, you have everything you need.

## Development norms

- **TypeScript strict mode is on.** `npm run typecheck` must pass before opening a PR.
- **Format and lint.** `npm run lint` should pass.
- **Tests.** New behaviour needs a test. We use Vitest for unit/integration and Playwright for end-to-end flows.
- **Migrations.** Schema changes go through Drizzle: edit `lib/db/schema.ts`, run `npm run db:generate`, commit the generated SQL.
- **Secrets.** `.env` is git-ignored. Never commit credentials or API keys.
- **Privacy.** This project is built for healthcare data. Before adding any code that touches the lab database or sends data to an LLM, read the privacy invariants in `lib/validation/safety.ts`. If you're unsure whether a change might leak PII, ask before merging.

## What to work on

Open issues are labelled by area:
- `area:graph` — LangGraph workflow nodes
- `area:rag` — Qdrant ingestion and retrieval
- `area:rbac` — access control and SQL rewriting
- `area:ui` — frontend, components, accessibility
- `area:i18n` — localisation (French / Portuguese / Spanish translations especially welcome)
- `area:docs` — documentation, install guides, Ollama model matrix

If you want to add something not on the list, open an issue first to discuss the design.

## Pull requests

- Branch from `main`.
- One topic per PR. Smaller is easier to review and merge.
- Describe **what** changed and **why**. Link the issue if there is one.
- If your change is user-facing, include a screenshot or a short clip.

## Code of conduct

Be kind, be specific, be patient. Disagreement is fine; condescension is not. If a maintainer is too curt, call it out.

## License

By contributing, you agree your contributions are licensed under AGPLv3 (the project's license).
