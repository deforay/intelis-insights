# InteLIS Insights — Next.js + LangGraph.js Rewrite Plan

## Context

InteLIS Insights is a natural-language-to-SQL system for laboratory data, deployed across 25+ countries. The retired implementation at `~/www/retired-intelis-insights` is split across three runtimes — PHP (Slim 4 orchestrator), Python (FastAPI + Qdrant RAG service), and Bun/JS (Vercel AI SDK sidecar). This rewrite consolidates everything into one all-JavaScript / Node 22 Next.js application released as **FOSS under AGPLv3** for public healthcare systems.

**Why a rewrite, framed by the actual goals:**

1. **FOSS for public healthcare.** This is a free, open-source solution under AGPLv3. Public-sector IT in low- and middle-income countries can adopt, audit, modify, and self-host without licensing concerns. Source code is the artifact; the deliverable is community-runnable software, not a hosted product.
2. **Single language, single ecosystem — TypeScript / JavaScript everywhere.** JS is the lowest common denominator developer skill globally. Junior devs in Africa, South Asia, and Latin America already know it. A FOSS project written in JS has the broadest possible contributor pool. Three runtimes (PHP + Python + Bun) is the opposite of that.
3. **Buyer-recognizable stack.** Decision-makers at Ministries of Health and donor programs recognize Next.js, Vercel AI SDK, LangChain / LangGraph, OpenAI, Anthropic, Qdrant, Postgres, Auth.js, LangFuse. Each line of the README is a name they have heard of. That matters for adoption and procurement conversations even when the software is free.
4. **Production-grade RBAC.** The retired system has no per-user data scoping (the PRD calls for district / multi-district / province / multi-province / national tiers but `config/business-rules.php` only lists it as TODO). The rewrite ships RBAC from day one, enforced in code.
5. **Preserve the domain IP.** Business rules, terminology mappings, privacy filters, and clinical thresholds in `config/business-rules.php` and `config/field-guide.php` are the load-bearing IP. They get ported faithfully into TypeScript.

**Demo posture.** Everything ships demo-ready: a working web UI, pre-seeded users at each access level, a representative MySQL fixture for the lab DB. Walking into a Ministry meeting, we open a browser, log in as a national-level user, ask a question in plain English, and show a chart — end to end.

**Data source — unchanged and external.** The existing InteLIS MySQL database remains the system of record for laboratory data. The new app connects to it **read-only**. No data migration, no schema changes. Per-country deployments point at that country's existing InteLIS MySQL instance via env vars (`LAB_DB_HOST`, `LAB_DB_NAME`, etc.). The Postgres database introduced by this rewrite stores only AI-service state (sessions, conversation checkpoints, audit log, user/RBAC records) — never lab data.

## Architecture

> **Important boundary:** the InteLIS MySQL database is **external infrastructure**, the country's live operational lab system. We are a read-only consumer of it — never bundle, replicate, replace, or migrate.

```
┌──────────────────────────────────────────────────────────────┐
│                  Next.js 15 App (Node runtime)               │
│                                                              │
│   app/(web)/             ── React Server Components UI       │
│   app/api/v1/*/route.ts  ── HTTP API (auth, query, sessions) │
│   lib/auth/              ── Auth.js (admin-provisioned)      │
│   lib/graph/             ── LangGraph workflow + nodes       │
│   lib/rag/, lib/llm/, lib/validation/                        │
│                                                              │
│   Single Docker image, single process                        │
└──┬──────────────┬─────────────────┬──────────────┬───────────┘
   │              │                 │              │
   ▼              ▼                 ▼              ▼
┌──────┐    ┌──────────┐    ┌──────────────┐  ┌────────────┐
│Qdrant│    │Vercel AI │    │  Existing    │  │ LangFuse   │
│ RAG  │    │SDK (LLM &│    │  InteLIS     │  │ (audit /   │
│      │    │embeddings│    │  MySQL DB    │  │  traces)   │
│      │    │)         │    │  (read-only) │  │ self-host  │
└──────┘    └──────────┘    └──────────────┘  └────────────┘
                ▲
                │
        ┌───────┴────────┐
        │ OpenAI         │
        │ Anthropic      │
        │ Google         │
        │ Ollama (local) │
        └────────────────┘
```

**Stack — every component is FOSS, all JavaScript, and recognizable:**

| Concern | Choice | License | Why |
|---|---|---|---|
| Runtime | **Node 22 (LTS)** | MIT | |
| Language | **TypeScript** | Apache 2.0 | |
| App framework | **Next.js 15 (App Router)** | MIT | All-in-one: UI + API in one app. Brand-name recognition for buyers. Largest JS contributor pool. |
| UI components | **shadcn/ui + Tailwind CSS** | MIT | Copy-paste-owned components, no runtime lib lock-in. FOSS-friendly. |
| Charts / dashboard | **Tremor** (built on Recharts) | Apache 2.0 | Purpose-built for analytics dashboards; React-native; recognizable Vercel-style aesthetic. |
| Auth | **Auth.js** (NextAuth v5) | ISC | Next.js-native, admin-provisioned credentials provider for v1; OIDC/SAML providers add later. |
| Workflow engine | **`@langchain/langgraph`** | MIT | Brand alignment; state machine with checkpointer for multi-turn conversation. |
| LLM orchestration | **`@langchain/core`** + **`@ai-sdk/langchain`** | MIT | LangChain primitives, Vercel AI SDK as provider layer. |
| LLM providers | **`ai` + `@ai-sdk/openai` + `@ai-sdk/anthropic` + `@ai-sdk/google`** | Apache 2.0 | `createOpenAI({ baseURL })` covers Ollama as the offline-first option. |
| Embeddings | **OpenAI `text-embedding-3-small`** default, **Ollama `nomic-embed-text`** for offline | — | Same Vercel AI SDK `embed()` interface either way. |
| Vector DB | **Qdrant** (via `@qdrant/js-client-rest`) | Apache 2.0 | FOSS vector DB, self-hostable, recognizable. |
| App database | **Postgres** + **Drizzle ORM** | PostgreSQL / Apache 2.0 | Sessions, checkpoints, audit, users. |
| Observability | **LangFuse** (self-hosted) | MIT | The known name in AI-app tracing; self-hostable for FOSS deployments. |
| Schema validation | **Zod** | MIT | |
| Testing | **Vitest** + **Playwright** | MIT | Unit/integration + UI e2e. |
| License | **AGPLv3** | — | Strong copyleft. Modifications by hosted-service deployers must be published — appropriate for FOSS public-health infrastructure. |

**Explicitly removed from the old design:**

- The separate Bun llm-sidecar HTTP service. Its provider factory and structured-output patterns get ported, but they run **in-process inside graph nodes** — no HTTP hop between LangGraph and the LLM.
- The separate Python FastAPI rag-api service. Qdrant is called directly from `lib/rag/`.
- PHP `$_SESSION` conversation state. Replaced by LangGraph's Postgres checkpointer keyed on `session_id`.
- Inngest. LangGraph itself is the orchestrator.
- Hono as a separate service. Next.js route handlers are the HTTP surface.

## Repository Scaffold

```
intelis-insights/
├── LICENSE                              # AGPLv3
├── README.md                            # FOSS positioning, install instructions
├── CONTRIBUTING.md                      # contributor onboarding
├── app/
│   ├── (web)/
│   │   ├── layout.tsx                   # shell, nav, auth gate
│   │   ├── page.tsx                     # landing → redirect to /chat
│   │   ├── chat/page.tsx                # NL query interface + result + chart
│   │   ├── chat/[sessionId]/page.tsx    # resume conversation
│   │   ├── sessions/page.tsx            # list past conversations
│   │   ├── admin/users/page.tsx         # admin: provision users + RBAC scopes
│   │   └── login/page.tsx               # Auth.js sign-in
│   └── api/
│       ├── v1/query/route.ts            # POST: NL question → SQL + results (streams)
│       ├── v1/sessions/[id]/route.ts    # GET / DELETE
│       ├── v1/admin/users/route.ts      # POST / PATCH / DELETE (admin only)
│       ├── auth/[...nextauth]/route.ts  # Auth.js handler
│       └── health/route.ts              # GET /api/health
├── lib/
│   ├── auth/
│   │   ├── config.ts                    # Auth.js config (credentials provider)
│   │   ├── rbac.ts                      # access-level, scope helpers
│   │   └── middleware.ts                # route protection helpers
│   ├── graph/
│   │   ├── state.ts                     # Annotation.Root state schema (Zod)
│   │   ├── workflow.ts                  # buildGraph() — node wiring + branching
│   │   └── nodes/
│   │       ├── parse-question.ts
│   │       ├── retrieve-context.ts
│   │       ├── generate-sql.ts
│   │       ├── validate-access.ts
│   │       ├── validate-query.ts
│   │       ├── execute-query.ts
│   │       └── format-response.ts
│   ├── rag/
│   │   ├── qdrant.ts                    # client + collection bootstrap
│   │   ├── embeddings.ts                # Vercel AI SDK embed() wrapper
│   │   └── snippets.ts                  # types: Snippet, SnippetType, filters
│   ├── llm/
│   │   ├── providers.ts                 # provider factory + model aliases (ported from sidecar)
│   │   ├── prompts.ts                   # all system / user prompt templates
│   │   └── structured.ts                # generateObject() wrapper with Zod schemas
│   ├── validation/
│   │   ├── access-control.ts            # RBAC scope check + AST WHERE injection
│   │   ├── safety.ts                    # SELECT-only, allowlist, forbidden-column guard
│   │   ├── terminology.ts               # ported from config/field-guide.php
│   │   └── business-rules.ts            # ported from config/business-rules.php
│   ├── db/
│   │   ├── lab.ts                       # mysql2 pool for InteLIS (read-only)
│   │   ├── app.ts                       # Drizzle for app Postgres
│   │   └── schema.ts                    # users, sessions, audit_log
│   ├── observability/
│   │   └── langfuse.ts                  # trace + generation wrappers
│   └── config/
│       ├── env.ts                       # Zod-validated env
│       ├── business-rules.ts            # ported config
│       ├── field-guide.ts               # ported config (terminology, thresholds, patterns)
│       └── schema.ts                    # exported InteLIS schema (generated by script)
├── components/                          # shadcn/ui + custom: chat UI, result table, charts
├── drizzle/                             # migrations
├── scripts/
│   ├── export-schema.ts                 # MySQL → schema.json
│   ├── build-snippets.ts                # schema + configs → corpus/snippets.jsonl
│   ├── upsert-corpus.ts                 # snippets.jsonl → Qdrant
│   └── seed-demo.ts                     # seed Postgres users + MySQL fixture (idempotent)
├── corpus/                              # generated artifacts (snippets.jsonl, schema.json)
├── tests/
│   ├── unit/                            # per-node tests with mocked LLM/Qdrant/MySQL
│   ├── integration/                     # full graph against Testcontainers
│   ├── e2e/                             # Playwright: login → query → chart
│   └── fixtures/queries.json            # NL → expected SQL fixtures
├── Dockerfile                           # multi-stage, Node 22 Alpine
├── docker-compose.yml                   # app + qdrant + postgres
├── docker-compose.offline.yml           # adds ollama profile
├── drizzle.config.ts
├── next.config.js
├── tailwind.config.ts
├── package.json
├── tsconfig.json
└── .env.example
```

## LangGraph Workflow

**State** (`lib/graph/state.ts`) — single object threaded through all nodes:

```ts
{
  question: string
  sessionId: string
  userContext: { userId, accessLevel, allowedProvinces[], allowedDistricts[] }

  intent: { type, intents[], testTypes[], tables[], referencesPrevious } | null
  ragContext: { ragJson, schemaBlock, citations[] } | null
  conversationBlock: string | null
  sql: string | null
  sqlMeta: { confidence, reasoning, citations[] } | null
  accessDecision: { allowed, rewrittenSql, reason } | null
  results: { columns[], rows[], count, executionMs } | null
  chart: ChartSuggestion | null
  error: { code, message, stage } | null

  traceId: string
  startedAt: number
}
```

**Nodes & edges:**

1. `parse-question` — LLM (small / fast model — `gpt-4o-mini` or `haiku`) classifies intent, test types, candidate tables. Falls back to regex on parse failure. Reads conversation checkpoint to detect references ("those", "of them").
2. `retrieve-context` — Two parallel Qdrant searches: (a) intent facts (k=14, type filter), (b) strict context (k=15, type+table filter).
3. `generate-sql` — `generateObject()` with Zod schema `{ s, ok, conf, why, cit }`. Uses the SQL-generation system prompt ported from `QueryService::callLLM` lines 903–1024.
4. `validate-access` — **NEW.** Inspects generated SQL for geographic columns; rewrites or rejects per `userContext.accessLevel`.
5. `validate-query` — SELECT-only, FROM required, every referenced table in the schema allowlist, no forbidden PII columns (with the `COUNT(DISTINCT …)` carve-out from the old system). Ports `QueryService::validateSql` lines 1028–1066.
6. `execute-query` — `mysql2` prepared statement against the read-only InteLIS pool. Hard `LIMIT 10000` if absent.
7. `format-response` — Chart suggestion (heuristic-first, LLM-fallback — ports `ChartService`) + audit metadata.

**Branching:**

- `validate-access` reject → `format-response` (error path).
- `validate-query` reject → up to **one retry** of `generate-sql` with a corrective system message; second failure → `format-response` (error path).
- `execute-query` DB error → `format-response` (error path).
- Zero-result: `format-response` distinguishes "valid query, no rows" from query error, using intent + result shape.

**Checkpointer:** `PostgresSaver` keyed on `sessionId`. Conversation history materialized from the checkpoint.

**Streaming:** the `/api/v1/query` route handler returns a `Response` stream emitting node-level progress events (`{ stage: "retrieve-context", … }`) so the UI shows progress over the 10–15s graph run instead of staring at a spinner.

## RBAC Design (`lib/validation/access-control.ts`)

**API request includes user context** (derived server-side from the Auth.js session — never sent by the client):

```ts
userContext: {
  userId: string
  accessLevel: 'district' | 'multi_district' | 'province' | 'multi_province' | 'national'
  allowedProvinces: string[]
  allowedDistricts: string[]
}
```

**Enforcement:**

- **National** → no rewriting.
- **Province / district** → AST parse (`node-sql-parser`) ensures every reference to `facility_details` or `geographical_divisions` has a `WHERE` / `HAVING` constraint matching the allowed list. If absent, the node **injects** it. If injection isn't safely possible (subqueries, CTEs), **reject** with explicit reason.
- **Cross-scope** → if a query references geographic dimensions outside the user's allowed set, **reject**. Never silently rewrite a query the user clearly intended to violate scope.

## UI Pages (v1 demo-ready)

- **`/login`** — Auth.js credentials form. Pre-seeded demo users at each access level.
- **`/chat`** — main NL query interface. Chat-style transcript, streaming progress, formatted results table, Tremor chart, "show generated SQL" affordance for auditability.
- **`/chat/[sessionId]`** — resume a prior conversation.
- **`/sessions`** — list past conversations for the logged-in user.
- **`/admin/users`** — admin-only: create / edit users, set access level + allowed provinces / districts.

UI components from **shadcn/ui** (copy-into-repo, no lib runtime dep). Charts from **Tremor**. Tailwind for styling. Dark mode supported.

## Auth (`lib/auth/`)

- **Auth.js v5** with the credentials provider — email + bcrypt-hashed password.
- Users provisioned by an admin via `/admin/users` or the `scripts/seed-demo.ts` script.
- Session is a signed JWT cookie. RBAC fields (`accessLevel`, `allowedProvinces`, `allowedDistricts`) are stamped into the session callback so server actions / route handlers read them without an extra DB hit.
- No self-signup, no password reset email (v1) — admin re-provisions on lost password.
- OIDC / SAML providers can be added by editing `lib/auth/config.ts` when a real ministry deployment needs SSO.

## Key Files to Port from the Retired Project

| Old (read-only reference) | New |
|---|---|
| `config/business-rules.php` | `lib/config/business-rules.ts` |
| `config/field-guide.php` | `lib/config/field-guide.ts` |
| `src/Services/QueryService.php` 903–1024 (SQL-gen prompts) | `lib/llm/prompts.ts` |
| `src/Services/QueryService.php` 1028–1066 (SQL validation) | `lib/validation/safety.ts` |
| `src/Services/QueryService.php` 379–440 (table selection) | `lib/graph/nodes/parse-question.ts` |
| `src/Services/ConversationContextService.php` 319–465 (reference detection) | `lib/graph/nodes/parse-question.ts` |
| `src/Services/ChartService.php` 168–241 (heuristics) | `lib/graph/nodes/format-response.ts` |
| `bin/build-rag-snippets.php` | `scripts/build-snippets.ts` |
| `bin/rag-upsert.php` | `scripts/upsert-corpus.ts` |
| `llm-sidecar/src/providers.ts` (model aliases, provider factory) | `lib/llm/providers.ts` |

**Privacy invariants preserved verbatim:**

- Forbidden-columns list (`patient_first_name`, `patient_id`, `child_id`, `mother_id`, `*_email`, `*_phone`, etc.).
- `allow_aggregated_distinct` carve-out — columns may appear inside `COUNT(DISTINCT …)` only.
- String-literal stripping before forbidden-column scanning.
- **Conversation context sanitization (new):** prior-turn SQL replayed into the LLM prompt also runs through the forbidden-column scrub, in case the user typed a literal patient ID earlier.

## Implementation Phases

**Phase 1 — Foundation.** Next.js 15 scaffold, Tailwind, shadcn/ui setup. Drizzle migrations for `users`, `sessions`, `audit_log`. Auth.js wired with credentials provider. `/login` and a stub `/chat`. Docker Compose with `app + qdrant + postgres`. `LICENSE` (AGPLv3) and `README.md`.

**Phase 2 — RAG corpus pipeline.** `scripts/export-schema.ts`. Port `business-rules.php` and `field-guide.php` to typed TS modules. `scripts/build-snippets.ts`. `scripts/upsert-corpus.ts` with provider-pluggable embeddings (OpenAI / Ollama).

**Phase 3 — Graph nodes.** Each node as a pure function `(state) → state'`. Unit tests with mocked LLM / Qdrant / MySQL. Order: `parse-question` → `retrieve-context` → `generate-sql` → `validate-access` → `validate-query` → `execute-query` → `format-response`.

**Phase 4 — Workflow assembly.** Wire nodes in `lib/graph/workflow.ts` with branching + single `generate-sql` retry. `PostgresSaver` checkpointer. Integration test using Testcontainers.

**Phase 5 — API + streaming.** `POST /api/v1/query` streams node-level progress. `GET /api/v1/sessions/:id`. Audit-log write per query.

**Phase 6 — UI.** `/chat` end to end: question input, streaming progress, result table, Tremor chart, SQL audit reveal, session list, `/admin/users`. Playwright e2e for the golden demo flow.

**Phase 7 — Demo seed.** `scripts/seed-demo.ts` creates pre-seeded users at each access level (`district_demo`, `province_demo`, `national_demo`) and seeds a representative MySQL fixture with realistic-looking-but-synthetic InteLIS data. Documented in README.

**Phase 8 — Observability.** LangFuse traces around each node + LLM call. Self-hosted LangFuse instructions in README. Prompt management via LangFuse optional.

## Distribution & Deployment — Docker Compose

The deployment story is: install Docker, drop in `.env`, run one command. Docker Compose nails this and "Docker" is recognizable to ministry procurement.

```yaml
services:
  app:        # Next.js (UI + API + LangGraph) — single image
  qdrant:     # vector DB
  postgres:   # AI-service state (sessions, checkpoints, audit, users)
  # Existing InteLIS MySQL is NOT in this file — it runs wherever the country's
  # InteLIS deployment already lives. The `app` connects via LAB_DB_HOST.
```

Profiles:
```yaml
  ollama:     # docker compose --profile offline up — local LLM + embeddings
  langfuse:   # docker compose --profile observability up — self-hosted tracing
```

**Operator flow:**
1. `cp .env.example .env`, fill in `LAB_DB_*` and `OPENAI_API_KEY` (or set `LLM_PROVIDER=ollama`).
2. `docker compose up -d`.
3. Open `http://<host>:3000`, log in as the admin user (password printed by seed), provision real users.

**Rejected alternatives:**

| Option | Verdict |
|---|---|
| Kubernetes / Helm | Overkill at single-instance per-country scale. Operator burden, no buyer win. |
| Standalone binary (Bun / pkg / nexe) | Brand-light; fragile with native deps. Worse install story than Docker. |
| Vercel-hosted SaaS | Not FOSS-aligned; defeats the self-host premise. (We may run a public demo instance on Vercel, but customers self-host.) |

**Container registry:** publish to GitHub Container Registry (`ghcr.io`) tagged per release.

## Verification

- **Unit tests (Vitest):** every node, prompt assembly, SQL validator (with PII carve-out), terminology, RBAC scope injection (positive + negative).
- **Integration tests:** Testcontainers (Qdrant + Postgres + MySQL with seeded fixtures). Golden query fixtures.
- **RBAC tests:** for each access level — queries that succeed, queries that get WHERE-injected, queries that get rejected.
- **Multi-turn test:** 3+ follow-up turns ("those", "of those, in Littoral") preserved by the checkpointer.
- **Offline test:** full pipeline with `EMBEDDINGS_PROVIDER=ollama` and `LLM_PROVIDER=ollama` — no cloud calls escape.
- **e2e (Playwright):** the golden demo flow — log in → ask a question → see chart → drill down → check audit SQL.
- **Smoke test:** npm script hitting a running stack with canonical PRD sample queries.

## Scope Note — Build for InteLIS, Stay Open by Construction

Built for InteLIS, demoed for InteLIS, period. No abstraction layers, no driver interfaces, no dialect switches, no domain-neutral configs. Same MySQL, same lab vocabulary, same business rules — ported faithfully.

If a future prospect asks "can this work with our LIS?", the answer comes from the natural separability already in the design:

- Schema corpus is generated by one script. New schema → new script.
- Domain configs (`business-rules.ts`, `field-guide.ts`) are plain data files.
- The graph touches the lab DB in exactly one node (`execute-query`). Swap the driver there.

That's the whole forward-compatibility story. Costs us nothing today. Re-evaluate when a real prospect bites.

## Known Gaps / Out of v1 Scope (Honest List)

These are real concerns called out explicitly so they don't become surprises during demos or early adoption.

- **Multilingual.** UI, prompt templates, terminology mappings, and error messages are English-only in v1. The LLM understands questions in French / Portuguese / Spanish (it just translates internally), but the demo and docs are English. Localization is a v2 workstream.
- **Accuracy / golden-query coverage.** v1 ships with the integration-test fixture set (a few dozen queries). A larger curated NL → expected-SQL → expected-result regression set, run against each LLM upgrade, is a follow-up. Until then: every demo question goes through staging first.
- **Demo dataset.** `scripts/seed-demo.ts` produces a representative synthetic MySQL fixture for demos. It is not a substitute for real lab data — performance characteristics and edge cases will differ from production.
- **LLM cost controls.** No per-deployment monthly cap, no model-tier routing beyond "small model for intent, big model for SQL." For high-volume countries, this needs a budget layer.
- **Data freshness signaling.** Responses don't yet expose per-table last-upload timestamps, so "0 results" doesn't distinguish "no testing happened" from "no upload yet." Important for ministry trust — slated for early v1.x.
- **SSO.** Credentials-only in v1. OIDC / SAML providers add via Auth.js when a real ministry deployment requires them.
- **Ollama model matrix.** "It works on Ollama" is true for simple queries on Llama 3.1 70b. Complex multi-join queries on smaller models will fail. We will publish a tested model / hardware / accuracy matrix as part of the offline-deployment docs.
- **Prompt-injection hardening.** Corpus is build-time-only and write-locked at runtime (Qdrant runs without an admin token exposed). User questions go to the LLM verbatim — with SELECT-only enforcement, the blast radius is limited, but jailbreak resistance is not specifically tested. Worth a security pass before production rollout.

## Risks & Open Questions Worth Flagging Before Code

- **Schema parity.** The InteLIS MySQL schema isn't versioned with this repo. Phase 2 needs a live (or recently exported) schema to seed against. Fallback: the retired project's `var/schema.json`.
- **AST-based WHERE injection.** `node-sql-parser` handles MySQL well but has edge cases with nested subqueries / CTEs. Policy: when injection isn't safe, reject.
- **Embedding dimension mismatch.** OpenAI `text-embedding-3-small` is 1536-dim; Ollama `nomic-embed-text` is 768-dim. Collection is created with the configured embedder's dimension at bootstrap — **switching embedders requires re-ingestion**. Documented in README.
- **LangGraph PostgresSaver maturity.** Stable but if issues arise, fallback is `MemorySaver` + a Drizzle-backed history table.
- **Long-running route handlers in Next.js.** A 10–15s graph run is fine on a self-hosted Node server (`next start`), but would hit limits on Vercel serverless. Documented: this app is meant to run as a long-lived process, not on edge / serverless.
- **AGPLv3 contributor reach.** Some corporate contributors avoid AGPL. Mitigation: a contributor section in README explaining the FOSS / public-health rationale; no CLA in v1.
