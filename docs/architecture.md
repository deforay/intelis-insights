# Architecture

InteLIS Insights is a single Next.js application. The UI, the HTTP API, and the workflow that turns natural-language questions into SQL all run in one Node process.

For a step-by-step picture of what happens when a user asks a question, see [How a query flows](./query-flow.md).

## High-level

```mermaid
flowchart LR
  subgraph App["Next.js app (one Node process)"]
    UI["Web UI<br/>React Server Components"]
    API["HTTP API<br/>app/api/v1/**"]
    Graph["LangGraph workflow<br/>lib/graph/**"]
    UI --> API
    API --> Graph
  end

  Qdrant[("Qdrant<br/>vector DB")]
  Postgres[("Postgres<br/>users · sessions · audit")]
  MySQL[("InteLIS MySQL<br/>read-only")]
  LF[("LangFuse<br/>optional traces")]
  Provider[/"LLM provider<br/>(OpenAI · Anthropic · Google ·<br/>Mistral · DeepSeek · Groq · Ollama · …)"/]

  Graph --> Qdrant
  Graph --> Postgres
  Graph --> MySQL
  Graph -.-> LF
  Graph --> Provider
```

!!! info "External boundary"
    The InteLIS MySQL database is the country's live, operational lab system. We are a **read-only consumer**. The credentials in `LAB_DB_*` should be granted `SELECT` only. This service never bundles, replicates, replaces, or migrates lab data.

## The most important rule

**The LLM never holds a database connection.**

The LLM only sees text: the user's question, the database *schema* (table and column names), and the business rules. It emits text: a SQL string with structured metadata. The application — not the LLM — opens the database connection, validates the SQL, and runs it.

```mermaid
flowchart LR
  classDef trust fill:#0a7,stroke:#0a7,color:#fff
  classDef untrust fill:#a55,stroke:#a55,color:#fff
  classDef neutral fill:#888,stroke:#888,color:#fff

  User["User<br/>(signed in)"]:::untrust
  LLMSurface["LLM provider"]:::untrust
  App["App process<br/>(your environment)"]:::trust
  Lab[("Lab DB")]:::trust

  User -- "question text" --> App
  App -- "question + schema + rules<br/>no patient data" --> LLMSurface
  LLMSurface -- "SQL text" --> App
  App -- "validated SELECT" --> Lab
  Lab -- "rows" --> App
  App -- "answer" --> User
```

This is enforced by the code shape, not by the prompt. Patient data never leaves the application boundary. Every SQL the LLM emits goes through validation and access-control checks before it touches the lab DB. See [Privacy & RBAC](./privacy-and-rbac.md).

## The workflow

Every question follows the same seven steps. Each step is a function that reads the current state and returns updates. Branching happens between steps — failures route to the response builder; SQL-safety failures get one retry.

```mermaid
stateDiagram-v2
  direction LR
  [*] --> parse_question
  parse_question --> retrieve_context
  retrieve_context --> generate_sql
  generate_sql --> validate_access: sql ok
  generate_sql --> format_response: error / clarification
  validate_access --> validate_query: allowed
  validate_access --> format_response: denied
  validate_query --> execute_query: passed
  validate_query --> generate_sql: failed (retries < 1)
  validate_query --> format_response: failed (retries ≥ 1)
  execute_query --> format_response
  format_response --> [*]
```

| Step | What it does |
|---|---|
| **parse-question** | Looks at the question, picks the likely tables, detects "those" / "them" follow-ups. Pure pattern matching — no LLM call. |
| **retrieve-context** | Embeds the question, runs two parallel searches in Qdrant: one for general domain hints, one for table-specific facts. Builds a compact context bundle for the prompt. |
| **generate-sql** | Calls the LLM with the question + context + a schema listing. Gets back SQL plus the assumptions it applied (default time window, default test type, etc.). |
| **validate-access** | If the user is a district or province operator, injects a `WHERE` clause restricting results to their geographic scope. National users pass through. |
| **validate-query** | SELECT-only. Tables must be in the allowlist. No patient-identifier columns (with one carve-out: `COUNT(DISTINCT …)` is allowed for unique counts). |
| **execute-query** | Runs the SQL against the read-only lab DB. Enforces a hard `LIMIT 10000`. |
| **format-response** | Suggests a chart (table / line / bar / pie / scatter, etc.) based on the result shape. Writes the audit row. |

## What runs where

| Concern | Where it lives | Notes |
|---|---|---|
| Web UI | `app/(app)/**` | React Server Components, streamed to the browser. |
| HTTP API | `app/api/v1/**` | One streaming endpoint for queries (`/api/v1/query`); REST for sessions and admin. |
| Workflow | `lib/graph/**` | Nodes, state, routing, checkpointer. |
| Prompts + provider switch | `lib/llm/**` | OpenAI / Anthropic / Google / Mistral / DeepSeek / Groq / OpenAI-compatible / Ollama. |
| RAG client | `lib/rag/**` | Qdrant + embeddings + the schema loader. |
| Safety + RBAC | `lib/validation/**` | SQL validator + AST-based access control. |
| Auth | `auth.ts`, `lib/auth/**` | Auth.js v5 with email/password. |
| Domain knowledge | `lib/config/business-rules.ts`, `lib/config/field-guide.ts` | Ported from the retired PHP. The load-bearing IP. |
| Container bootstrap | `scripts/init.ts` | Runs once before the app starts: migrations, RAG corpus, admin seed. |

## Where state lives

| State | Storage |
|---|---|
| Users + access scopes | Postgres |
| Chat sessions + messages | Postgres |
| Audit log (every query) | Postgres |
| Conversation memory across turns | Postgres (LangGraph checkpointer) |
| Schema + business rules + terminology, embedded | Qdrant |
| Lab data (read-only) | InteLIS MySQL — external |

All four services run independently. Re-ingesting the corpus is a single command if the source MySQL schema changes; the init container does it automatically on first boot.

## Deployment shape

One Docker image, four services in compose:

```mermaid
flowchart LR
  init["init<br/>(one-shot: migrations,<br/>corpus, admin seed)"]
  app["app<br/>(Next.js)"]
  pg[("postgres")]
  qd[("qdrant")]
  init --> pg
  init --> qd
  app --> pg
  app --> qd
  app --> mysql[("InteLIS MySQL<br/>external")]
  init -. waits .-> pg
  init -. waits .-> qd
  app -. waits .-> init
```

`docker compose up -d` brings up Postgres + Qdrant, runs `init` to completion, then starts `app`. See [Getting started](./getting-started.md).

## Why a single app, not a service mesh

We considered splitting the LLM workflow into a dedicated backend service and keeping Next.js as a thin UI. We chose not to:

- One framework, one process, one Docker image. New contributors are productive on day one.
- Ministry IT teams see one service to operate.
- API and UI share types end-to-end with no shared-package step.
- React Server Components + a streaming `Response` handle the 10–15 second graph run with live progress updates.

Independent scaling isn't a real need at one-deployment-per-country. Operator simplicity is.

## See also

- [How a query flows](./query-flow.md) — visual walkthrough end to end.
- [Privacy & RBAC](./privacy-and-rbac.md) — the security model in depth.
- [Configuration](./configuration.md) — env vars and trade-offs.
- [Implementation plan](./plan.md) — current status and roadmap.
