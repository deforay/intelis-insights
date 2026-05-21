# Architecture

InteLIS Insights is a single Next.js application. UI, HTTP API, and the LangGraph workflow that turns natural-language questions into SQL all run in one Node process.

## High-level

```
┌──────────────────────────────────────────────────────────────┐
│                  Next.js 16 App (Node runtime)               │
│                                                              │
│   app/(web)/             React Server Components UI          │
│   app/api/v1/*/route.ts  HTTP API (auth, query, sessions)    │
│   lib/auth/              Auth.js (admin-provisioned)         │
│   lib/graph/             LangGraph workflow + nodes          │
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
        ┌───────┴────────────────────────────────┐
        │ OpenAI · Anthropic · Google · Mistral  │
        │ DeepSeek · Groq · openai-compatible    │
        │ Ollama (local / offline)               │
        └────────────────────────────────────────┘
```

!!! info "External boundary"
    The InteLIS MySQL database is **external infrastructure** — the country's live, operational lab system. We are a read-only consumer of it. It is never bundled, replicated, replaced, or migrated by this project.

## The LangGraph workflow

Every natural-language query passes through the same graph:

```
parse-question ──► retrieve-context ──► generate-sql ──┐
                                                       │
                ┌──────────────────────────────────────┘
                ▼
   validate-access ──► validate-query ──► execute-query ──► format-response
```

| Node | What it does |
|---|---|
| `parse-question` | Classifies the user's question (intent, candidate tables, references to prior turns) via a small/fast LLM. |
| `retrieve-context` | Two parallel Qdrant searches: intent facts (synonyms, rules) and strict schema context (columns, relationships). |
| `generate-sql` | Structured-output LLM call. Returns `{ s: sql, ok, conf, why, cit }` validated against a Zod schema. |
| `validate-access` | RBAC: parses the SQL AST and ensures geographic scope matches the user's `accessLevel`. Injects WHERE clauses or rejects. |
| `validate-query` | Safety: SELECT-only, table allow-list, no forbidden PII columns. |
| `execute-query` | Runs the validated SQL against the InteLIS MySQL (read-only pool, hard `LIMIT 10000`). |
| `format-response` | Suggests a chart (heuristic first, LLM fallback), assembles the audit record. |

Branching: `validate-access` and `validate-query` failures route to `format-response` with an explicit error reason. A failed `validate-query` retries `generate-sql` **once** with a corrective system message.

Conversation state is persisted by LangGraph's Postgres checkpointer, keyed on `sessionId`.

## The LLM is a text transformer, not a database client

This is a load-bearing security boundary. The LLM never holds a database connection:

- It receives **text** (question + schema context + business rules).
- It emits **text** (SQL string + structured metadata).
- The InteLIS Insights application opens the DB connection, validates the SQL, and runs it.

There is no tool-calling-with-DB-access pattern, no agentic-with-credentials loop. Every SQL execution goes through `validate-access` and `validate-query` regardless of how clever the LLM gets.

## Where state lives

| State | Storage |
|---|---|
| Users, RBAC scopes | Postgres (`users` table, via Drizzle) |
| Chat sessions, messages | Postgres (`chat_sessions`, `chat_messages`) |
| LangGraph checkpoints (multi-turn conversation context) | Postgres (managed by `@langchain/langgraph-checkpoint-postgres`) |
| Audit log | Postgres (`audit_log` — every question, generated SQL, scope decision, row count, errors, traceId) |
| Schema corpus + business rules + terminology | Qdrant (vector embeddings; rebuilt by `scripts/build-snippets.ts` + `scripts/upsert-corpus.ts`) |
| Lab data | InteLIS MySQL (the existing operational system — we only read) |

## Why a single Next.js app

- **Easy to read.** One framework, one process, one Docker image. New contributors can be productive on day one.
- **Operator simplicity.** Ministry IT teams running the deployment see one service to keep up, not two.
- **Type-safe end to end.** API contracts, server actions, and UI share types without a shared-package step.
- **Streaming UX.** React Server Components plus `Response` streams handle the 10–15 s graph run with progressive UI updates.

A separate API service was considered (Hono behind a Next.js front-end) and rejected for this product's scale — independent scaling isn't a real need, and the operational simplicity wins.

## See also

- [Implementation plan](plan.md) — the detailed roadmap and current status.
- [Privacy & RBAC](privacy-and-rbac.md) — the security model in depth.
- [Configuration](configuration.md) — env var reference.
