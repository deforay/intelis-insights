# InteLIS Insights

**Natural-language analytics for laboratory data. Free, open source, self-hostable.**

Ask questions about lab results in plain English; get answers as charts and tables. Built for ministries of health, donor programs, and public-health labs that already run [InteLIS](https://github.com/deforay/vlsm) and want AI on top of their existing data — without paying for SaaS, without sending patient data to a vendor, and without their data leaving their infrastructure.

> Licensed under **AGPLv3**. Source code, audit, modify, self-host — all free. See [LICENSE](./LICENSE).

---

## What it does

You point it at your existing InteLIS MySQL database (read-only). Then:

1. A user signs in and asks a question — _"What's the VL suppression rate by district last quarter?"_
2. The app classifies intent, retrieves relevant schema context from a vector DB, and generates SQL using your chosen LLM (OpenAI, Anthropic, Google, or a local Ollama model).
3. The SQL goes through **access-control checks** (a district-level user can't query province-level data) and **safety validation** (SELECT-only, no PII columns, no patient identifiers).
4. The validated SQL runs against your lab DB. Results come back as a table plus an auto-suggested chart.
5. Every step is logged for audit.

**No patient identifiers are ever sent to the LLM.** Only the user's question and the database schema. Results stay in your infrastructure.

## The stack

Every component is recognizable, FOSS, and JavaScript / TypeScript.

| Concern | Choice | License |
|---|---|---|
| App framework | [Next.js 16](https://nextjs.org) (App Router) | MIT |
| Workflow engine | [LangGraph.js](https://langchain-ai.github.io/langgraphjs/) | MIT |
| LLM provider layer | [Vercel AI SDK](https://sdk.vercel.ai) | Apache 2.0 |
| Vector DB | [Qdrant](https://qdrant.tech) | Apache 2.0 |
| Auth | [Auth.js v5](https://authjs.dev) | ISC |
| App database | [PostgreSQL](https://www.postgresql.org) + [Drizzle ORM](https://orm.drizzle.team) | PostgreSQL / Apache 2.0 |
| UI | [shadcn/ui](https://ui.shadcn.com) + [Tailwind CSS v4](https://tailwindcss.com) | MIT |
| Charts | [Recharts](https://recharts.org) | MIT |
| Observability | [LangFuse](https://langfuse.com) (self-hostable) | MIT |
| Runtime | Node 22 LTS | MIT |

**LLM providers supported:** OpenAI, Anthropic, Google, Mistral, DeepSeek, Groq, any OpenAI-compatible endpoint (Together, Fireworks, OpenRouter, self-hosted vLLM / LiteLLM), and Ollama (local / offline). Pick the one that fits your budget and data-residency requirements.

## Quick start (Docker)

**Requirements:** Docker 24+, an existing InteLIS MySQL database with a read-only user.

```bash
git clone https://github.com/deforay/intelis-insights
cd intelis-insights

cp .env.example .env
```

Fill in these fields in `.env`:

| Field | Notes |
|---|---|
| `AUTH_SECRET` | `openssl rand -base64 32` |
| `POSTGRES_PASSWORD` | any strong password |
| `LAB_DB_HOST` | `host.docker.internal` if MySQL is on the same machine (Mac/Win); hostname/IP otherwise |
| `LAB_DB_NAME` / `LAB_DB_USER` / `LAB_DB_PASSWORD` | read-only credentials for your InteLIS MySQL DB |
| `OPENAI_API_KEY` (or another provider's key) | for embeddings (and SQL generation, if `LLM_PROVIDER=openai`) |
| `LLM_PROVIDER` / `DEEPSEEK_API_KEY` etc. | pick any supported provider |
| `SEED_ADMIN_EMAIL` / `SEED_ADMIN_PASSWORD` | optional — auto-creates an admin user on first boot |

Then:

```bash
docker compose up -d
```

This starts four services: `postgres`, `qdrant`, a one-shot `init` (waits for the data services, applies the app schema, exports the InteLIS schema, builds + embeds the RAG corpus, seeds an admin user if set), then `app`. The init step takes a couple of minutes on a fresh install — watch with `docker compose logs -f init`.

Once `init` exits cleanly, open <http://localhost:3000> and sign in with the credentials you provided in `SEED_ADMIN_*`. If you didn't set them, create a user later with `docker compose exec init npx tsx scripts/seed-admin.ts`.

### Re-running init

Init is idempotent: it skips steps that are already done. To force a rebuild of the corpus (e.g. after a MySQL schema change):

```bash
docker compose down
docker volume rm intelis-insights_corpus_data
docker compose up -d
```

### Fully offline / air-gapped deployment

For environments without cloud LLM access:

```bash
LLM_PROVIDER=ollama EMBEDDINGS_PROVIDER=ollama \
docker compose --profile offline up -d
```

The bundled `ollama` service runs locally with no external dependencies. You'll need to `docker compose exec ollama ollama pull llama3.1:8b nomic-embed-text` (or whichever models you choose) before the init service can use them.

## Local development

If you want to iterate on the code with hot reload, run the data services in Docker and the app on the host:

```bash
npm install
cp .env.example .env
docker compose up -d postgres qdrant
npm run db:push
SEED_ADMIN_EMAIL=you@example.org SEED_ADMIN_PASSWORD=changeme123 npm run seed:admin
npm run schema:export && npm run rag:build && npm run rag:upsert
npm run dev
```

Open <http://localhost:3000>. Changes to the code reload automatically. Switch `LAB_DB_HOST=127.0.0.1` in this mode (the app is on the host, not in Docker).

## Architecture

```
┌─────────────────────────────────────────────────────┐
│         Next.js 16 (UI + API + LangGraph)           │
│             Single Node process / image             │
└──┬───────────┬──────────────┬───────────┬───────────┘
   ▼           ▼              ▼           ▼
 Qdrant   Vercel AI SDK   InteLIS MySQL  LangFuse
 (vector)  (OpenAI/      (your existing  (audit /
           Anthropic/     lab DB —        traces —
           Google/        read-only)      optional)
           Ollama)
```

The existing InteLIS MySQL database is **external infrastructure** — your country's live lab system. This service connects to it read-only via env vars. We never bundle, migrate, or alter it. The Postgres DB introduced here stores only AI-service state (sessions, conversation history, audit log, users) — never lab data.

## Project status

Early-stage rewrite consolidating an earlier multi-runtime InteLIS Insights prototype (PHP + Python + JS) into a single Next.js application. Phase 1 (foundation) is in progress: scaffolding, auth, RBAC schema, Docker Compose deploy. See [docs/plan.md](./docs/plan.md) for the implementation plan, the explicit list of known gaps, and the v2 roadmap.

## Privacy and audit

- **The LLM never connects to your database.** It is strictly a text transformer: it sees the user's question plus schema context and emits SQL text. The InteLIS Insights app — not the LLM — opens the connection, validates the SQL, and runs it. No tool-calling-with-DB-access pattern; no agentic-with-credentials pattern.
- **No patient-level data is ever sent to any LLM provider.** Only the user's question text and the InteLIS schema (table names, column descriptions, business rules) are transmitted. Query results stay inside your infrastructure.
- **All generated SQL is validated** before execution: SELECT-only, schema allow-list enforced, PII columns rejected.
- **Access control is code-enforced**, not just LLM-prompted. A district-level user cannot retrieve another district's data even if the LLM is asked to.
- **Every query is audit-logged**: who, when, what NL question, what SQL, what scope was applied, how many rows returned.
- **Conversation history sanitisation:** prior-turn SQL replayed into the model goes through the same forbidden-column scrub as freshly generated SQL.

## Contributing

InteLIS Insights is built to be improved by anyone who needs it. The stack is all TypeScript/JavaScript — intentionally the lowest common denominator developer skill globally — so the contributor pool is as wide as possible. See [CONTRIBUTING.md](./CONTRIBUTING.md) for setup and norms.

## License

Copyright © 2026 Deforay Technical Services and contributors.

This program is free software: you can redistribute it and/or modify it under the terms of the GNU Affero General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version.

This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU Affero General Public License for more details.

You should have received a copy of the GNU Affero General Public License along with this program. If not, see <https://www.gnu.org/licenses/>.
