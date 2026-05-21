# Codebase tour

A guided walk through this repo for new contributors. No tutorials — just orientation. Read it once before opening unfamiliar files; come back as needed.

## The 30-second mental model

```
Browser (chat UI)
   │  POST /api/v1/query  {question, sessionId}
   ▼
Next.js route handler  (app/api/v1/query/route.ts)
   │  authenticates, looks up the session, calls the workflow
   ▼
LangGraph workflow  (lib/graph/workflow.ts)
   │  threads a state object through 7 nodes,
   │  streaming an event after each one
   ▼
Browser
   │  draws the bento cards as events arrive
   ▼
Postgres (audit log, session, checkpoint) · Qdrant (RAG) · MySQL (lab data)
```

If you remember nothing else: **the route handler is the controller; the LangGraph workflow is the service**. Everything important happens in `lib/graph/`.

## Folder map (annotated)

```
app/                       ← Next.js: pages + API endpoints
  (app)/                     ← logged-in pages (chat, dashboard, reports)
  api/v1/
    query/route.ts             ← THE main endpoint — runs the workflow
    sessions/, reports/, admin/  ← REST endpoints for the rest
  login/, page.tsx, layout.tsx
  globals.css                ← theme tokens (CSS variables, light + dark)

components/                ← React UI. Like view partials in PHP.
  chat/                      ← chat thread + bento result cards
  chart/                     ← chart renderer (Recharts wrappers)
  ui/                        ← shadcn primitives (button, badge, …)

lib/                       ← all non-UI code. Like src/Service/ in Laravel.
  graph/                     ← LangGraph workflow (the heart of the app)
    workflow.ts                ← graph definition: nodes + edges
    state.ts                   ← the "row" that flows through the graph
    routing.ts                 ← branching rules between nodes
    nodes/                     ← one file per step (8 files)
    runner.ts                  ← streams events out of the graph
    checkpointer.ts            ← saves state to Postgres
    chart-heuristics.ts        ← "given these columns, suggest a chart"
    intent-regex.ts            ← regex-based intent detection (no LLM)
    events.ts, types.ts        ← shared types

  llm/                       ← prompts + provider switch
    prompts.ts                 ← every prompt the LLM sees
    providers.ts               ← OpenAI / Anthropic / Google / Ollama / …
    structured.ts              ← typed wrappers around the AI SDK
    scrub.ts                   ← PII scrub before anything leaves the box

  rag/                       ← Qdrant client + retrieval
    qdrant.ts, embeddings.ts, search.ts, schema-corpus.ts, snippets.ts

  validation/                ← the two safety layers
    safety.ts                  ← SELECT-only, no PII columns, allowlist
    access-control.ts          ← AST-based RBAC (district/province/national)

  db/                        ← Drizzle ORM
    app.ts                     ← Postgres (users, sessions, audit)
    lab.ts                     ← MySQL (read-only InteLIS)
    schema.ts                  ← table definitions for the app DB

  config/                    ← the load-bearing IP, ported from PHP
    business-rules.ts          ← lab domain rules (suppression cutoffs, etc.)
    field-guide.ts             ← which columns mean what
    tables.ts                  ← the table allowlist
    env.ts                     ← env-var parsing

  auth/                      ← Auth.js v5 integration + RBAC helpers
  chat/                      ← session/message/conversation helpers
  reports/                   ← saved-report CRUD (dashboard)
  audit/                     ← audit row writer
  observability/             ← LangFuse tracing

scripts/init.ts            ← one-shot container bootstrap
                             (migrations, RAG corpus ingest, admin seed)
```

## One request, file by file

A user types *"How many VL tests were done last month?"* and hits enter. Here's the path through the codebase:

1. **`components/chat/`** — the chat input dispatches a fetch to `/api/v1/query`. The bento cards are React components that subscribe to the streamed events and re-render as data arrives.
2. **`app/api/v1/query/route.ts`** — the controller. It:
   - calls `auth()` (from `auth.ts` at the repo root) to confirm the session,
   - turns the session row into a `UserContext` (district / province / national scope),
   - records the user message, opens a streaming `Response`,
   - calls `runQuery()` from `lib/graph/runner.ts` and pipes each event back to the browser as NDJSON.
3. **`lib/graph/runner.ts`** — gets the compiled graph from `workflow.ts`, invokes it with the initial state, and emits `QueryEvent`s as each node finishes. This is the layer that adapts LangGraph's event stream into the NDJSON shape the UI expects.
4. **`lib/graph/workflow.ts`** — the graph definition. Wires the 8 nodes together with `addEdge` (always go to X) and `addConditionalEdges` (use a routing function). The Postgres checkpointer is attached here so a follow-up question can read the previous turn's state.
5. **`lib/graph/state.ts`** — the shape of the "row" that flows through the graph. Think of it as one record: `{ question, sessionId, userContext, intent, ragContext, sql, results, … }`. Each node returns a partial update; LangGraph merges it in.
6. **`lib/graph/nodes/`** — the actual work. Each node is a function `(state) => Promise<partialUpdate>`:
   - **parse-question.ts** — pattern-matches the question for likely tables, follow-up references ("those", "them"). No LLM call.
   - **retrieve-context.ts** — embeds the question and runs two parallel searches in Qdrant: domain hints + table-specific facts. Returns a context bundle for the prompt.
   - **generate-sql.ts** — builds the prompt (from `lib/llm/prompts.ts`), calls the chosen LLM via `lib/llm/providers.ts`, parses out SQL + metadata (assumptions, confidence, citations).
   - **validate-access.ts** — calls `lib/validation/access-control.ts`. If the user is district-level, injects a `WHERE province_id = …` clause. National users pass through.
   - **validate-query.ts** — calls `lib/validation/safety.ts`. Rejects anything that isn't `SELECT`; rejects PII columns; checks tables against the allowlist in `lib/config/tables.ts`.
   - **execute-query.ts** — opens a connection via `lib/db/lab.ts` (read-only MySQL), runs the SQL, returns rows. Enforces `LIMIT 10000`.
   - **narrate-result.ts** — small LLM call that writes a 1–3 sentence summary plus 2–3 follow-up suggestions.
   - **format-response.ts** — runs `chart-heuristics.ts` to suggest a chart shape (table / line / bar / pie) based on the columns. Writes the audit row.
7. Back in **`route.ts`** — once the stream finishes, the assistant message is persisted (`lib/chat/sessions.ts`), traces are flushed to LangFuse (`lib/observability/`), the response closes.

## `lib/graph/` in detail

Open it in this order, and the rest of the app will make sense:

| File | What you'll learn |
|---|---|
| `workflow.ts` | The whole flow on one page. Read the header comment, then read the `buildWorkflow` function — it's a fluent chain of `.addNode().addEdge()` calls that maps 1:1 to the diagram on the [Architecture](architecture.md) page. |
| `state.ts` | The shape of every piece of data that flows. If you ever wonder "where does this field come from?", trace it here. |
| `routing.ts` | The three branching rules. Tiny pure functions — each returns the name of the next node. |
| `nodes/parse-question.ts` | The simplest node — start here to see the "read state, return updates" pattern. |
| `nodes/generate-sql.ts` | The most important node. Shows how prompts (`lib/llm/prompts.ts`), retrieved context, and the chosen provider come together. |
| `nodes/execute-query.ts` | Shortest node — read this to see that the LLM does NOT touch the DB. The app opens the connection and runs the validated SQL. |
| `runner.ts` | The bridge between LangGraph's internal event stream and the NDJSON the browser consumes. |
| `checkpointer.ts` | A tiny singleton that initializes the Postgres-backed state store. |

## The other lib/ modules

### `lib/llm/`
- **prompts.ts** — every prompt is a plain TypeScript function returning a string. No magic, no chain composition. If the LLM is doing something weird, the prompt is here.
- **providers.ts** — a switch over the `LLM_PROVIDER` env var. Returns an AI SDK `LanguageModel`. Add a new provider here.
- **structured.ts** — typed helpers on top of `generateText` / `generateObject` from the Vercel AI SDK. Adds caching, retries, JSON parsing.
- **scrub.ts** — runs on conversation history before it goes into the prompt. Strips anything that might be a patient identifier.

### `lib/rag/`
- **qdrant.ts** — the Qdrant client.
- **embeddings.ts** — turns text into a vector using the configured embedding model.
- **search.ts** — runs the two parallel similarity searches used by `retrieve-context.ts`.
- **schema-corpus.ts** — the corpus ingest pipeline (run by `scripts/init.ts` on container start, or manually).
- **snippets.ts** — formats search results into compact prompt-ready snippets.

### `lib/validation/`
The two security layers the LLM cannot bypass. Read both — they're short.
- **safety.ts** — parses the SQL with an AST library, walks it, rejects anything that isn't `SELECT`, that touches a non-allowlisted table, or that references a PII column. ~250 lines including comments.
- **access-control.ts** — same AST approach. For non-national users, injects a `WHERE` clause restricting results to their geographic scope. Returns a `rewrittenSql` and an `AccessDecision`.

### `lib/db/`
- **app.ts** — Drizzle client for the Postgres app DB (users, sessions, audit, checkpointer).
- **lab.ts** — read-only `mysql2` pool for InteLIS. Uses `LAB_DB_*` env vars.
- **schema.ts** — Drizzle table definitions for the app DB.

If you've used an ORM in PHP (Eloquent, Doctrine), Drizzle will feel familiar — schema in code, type-safe queries. The lab DB does not use Drizzle because the InteLIS schema is external and we only read it.

### `lib/config/`
The lab domain logic — VL suppression cutoffs, what counts as a rejected sample, default time windows — was ported here from the earlier PHP implementation into plain TypeScript modules.
- **business-rules.ts** — VL suppression cutoffs, default time windows, "what counts as a rejected sample", etc.
- **field-guide.ts** — human-readable descriptions of important columns. Goes into the prompt as context.
- **tables.ts** — the allowlist that `validation/safety.ts` checks against.

If you change one of these, no SQL needs to be regenerated — the prompt picks up the new value on the next request. If you change embeddings or business-rule wording that's in the corpus, re-run the RAG ingest.

## The UI side, briefly

- **`app/(app)/chat/...`** — server components that render the chat shell. The `(app)` parens are Next.js syntax for a route group — they don't show up in the URL, they just group layouts.
- **`components/chat/bento-response.tsx`** — the result cards we've been styling. One file, all the cards. The "bento" is the asymmetric grid that recomposes based on what data has arrived (KPI vs chart vs table).
- **`components/chat/assistant-bubble.tsx`** — wraps `BentoResponse` with the streaming-state machinery.
- **`components/chart/chart-renderer.tsx`** — Recharts wrapper. Picks the chart component based on the recommendation from `chart-heuristics.ts`.

Two Next.js concepts worth knowing when reading these:
- **Server Component vs Client Component** — the default in this codebase is server (renders on the server, no JS shipped to the browser). Files with `"use client"` at the top are client components — needed when you use state, effects, or browser-only APIs.
- **Route Handler** — a function exported from `app/api/.../route.ts`. Like a single PHP file mapped to a URL.

## Cookbook: common changes

**Tweak a prompt** → `lib/llm/prompts.ts`. Re-test the question that was misbehaving.

**Change a business rule** (e.g. VL suppression cutoff) → `lib/config/business-rules.ts`. No corpus rebuild needed.

**Add a new column to the allowlist or block list** → `lib/validation/safety.ts` + `lib/config/tables.ts`.

**Add a new LLM provider** → add a case in `lib/llm/providers.ts`, add the env-var to `.env.example` and `lib/config/env.ts`.

**Add a new node to the workflow** → write the function in `lib/graph/nodes/`, wire it into `workflow.ts` with `.addNode()` and an edge, update `routing.ts` if it's behind a conditional edge, add any new fields to `state.ts`.

**Add a new RAG snippet type** → write the ingest in `lib/rag/schema-corpus.ts`, the retrieval shape in `lib/rag/search.ts`, the prompt formatting in `lib/rag/snippets.ts`. Re-run `scripts/init.ts` to re-ingest.

**Change the bento card layout** → `components/chat/bento-response.tsx`.

## Quick PHP / SQL analogue glossary

| New term | What it's like |
|---|---|
| Route handler (`route.ts`) | A PHP file that handles one URL. |
| Server Component | A PHP view rendered server-side. No client JS unless you mark `"use client"`. |
| Client Component | A piece of UI that needs to run in the browser (state, events). |
| Drizzle ORM | Eloquent / Doctrine. Schema in code, type-safe queries. |
| LangGraph node | One function in a pipeline. Reads state, returns partial updates. |
| LangGraph state | A row that flows through the pipeline and gets merged at each step. |
| Checkpointer | A table that saves state between steps so you can resume / read history. |
| Conditional edge | An `if/else` between two nodes. Implemented as a small routing function. |
| RAG (retrieval-augmented generation) | Looking up snippets from a vector DB and pasting them into the prompt. |
| Embedding | A vector representation of text. Two texts that mean similar things produce vectors that are close. |
| AST validator | A SQL parser that walks the parsed tree and applies rules. Way safer than regex. |

## Where to read next

- **[Architecture](architecture.md)** — the same content with diagrams, framed for an outside reader.
- **[Query flow](query-flow.md)** — the step-by-step picture of what happens when a user asks a question.
- **[Privacy & RBAC](privacy-and-rbac.md)** — deep dive on the two validators.
- **[Implementation plan](plan.md)** — what's done, what's open, what's next.
