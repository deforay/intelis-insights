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

## Choose your path

<div class="grid cards" markdown>

-   :material-rocket-launch:{ .lg .middle } **Get started in 5 minutes**

    ---

    Docker Compose, an existing InteLIS database, one provider key.

    [:octicons-arrow-right-24: Getting started](getting-started.md)

-   :material-cog:{ .lg .middle } **Configuration reference**

    ---

    Every environment variable, what it does, and which provider needs what.

    [:octicons-arrow-right-24: Configuration](configuration.md)

-   :material-shield-lock:{ .lg .middle } **Privacy & RBAC**

    ---

    How patient data stays safe; how district / province / national tiers are enforced in code.

    [:octicons-arrow-right-24: Privacy & RBAC](privacy-and-rbac.md)

-   :material-source-branch:{ .lg .middle } **Contribute**

    ---

    All TypeScript / JavaScript. Built to be easy to read, easy to extend.

    [:octicons-arrow-right-24: Contributing](contributing.md)

</div>

## The stack

Every component is recognisable, FOSS, and JavaScript / TypeScript.

| Concern | Choice |
|---|---|
| App framework | [Next.js 16](https://nextjs.org) (App Router) |
| Workflow engine | [LangGraph.js](https://langchain-ai.github.io/langgraphjs/) |
| LLM provider layer | [Vercel AI SDK](https://sdk.vercel.ai) |
| Vector DB | [Qdrant](https://qdrant.tech) |
| Auth | [Auth.js v5](https://authjs.dev) |
| App database | [PostgreSQL](https://www.postgresql.org) + [Drizzle ORM](https://orm.drizzle.team) |
| UI | [shadcn/ui](https://ui.shadcn.com) + [Tailwind CSS v4](https://tailwindcss.com) |
| Charts | [Recharts](https://recharts.org) |
| Observability | [LangFuse](https://langfuse.com) (self-hostable) |
| Runtime | Node 22 LTS |

## Project status

Early-stage rewrite consolidating an earlier multi-runtime InteLIS Insights prototype (PHP + Python + JS) into a single Next.js application. See the [implementation plan](plan.md) for current progress, the explicit list of known gaps, and the v2 roadmap.
