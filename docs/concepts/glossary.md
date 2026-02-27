# Glossary

Key terms and concepts used throughout Intelis Insights.

---

### Allowlist

A compact block of text sent to the LLM that lists exactly which tables, columns, rules, and query patterns it is allowed to use when generating SQL. Acts as a grounding mechanism to prevent hallucinated column names. Built from [RAG](#rag) snippets on each query. See [RAG & Vector Search](rag.md).

### App Database

The MySQL database (`intelis_insights` by default) that stores data created by Intelis Insights itself: reports, chat sessions, chat messages, and audit logs. Separate from the [Query Database](#query-database). See [Two Databases](two-databases.md).

### Business Rules

Hard-coded rules in `config/business-rules.php` that govern what the system can and cannot do. Examples: *"Never expose patient-level data"*, *"Always exclude rejected samples"*, *"VL suppression threshold is < 1000 copies/ml"*. These are enforced before and after SQL generation.

### Conversation Context

When a user asks follow-up questions (e.g. *"How many of those were in Littoral?"*), the system carries forward filters and context from previous turns. This allows natural multi-turn conversations without repeating conditions.

### EID

Early Infant Diagnosis — a type of lab test for HIV in infants. Stored in the `form_eid` table.

### Embedding

The process of converting text into a vector (a list of numbers). Texts with similar meanings produce vectors that are close together in vector space. Used by [RAG](#rag) to find relevant snippets. The embedding model used is `BAAI/bge-small-en-v1.5`.

### Field Guide

Configuration file (`config/field-guide.php`) that defines terminology, clinical thresholds, column semantics, and example query patterns. Combined with the database schema and business rules to build [RAG snippets](#snippet).

### Grounding

The process of validating that LLM-generated SQL only uses tables and columns that actually exist in the database schema. Prevents the LLM from hallucinating non-existent columns. Enforced via the [Allowlist](#allowlist).

### InteLIS

The [Integrated Laboratory Information System](https://github.com/deforay) — the source laboratory system whose data Intelis Insights analyzes. See [Query Database](#query-database).

### LLM

Large Language Model — an AI model (like Claude, GPT-4, or DeepSeek) that can understand and generate text. In Intelis Insights, the LLM generates SQL queries from natural language questions.

### LLM Sidecar

A lightweight gateway service (TypeScript/Bun, port 3100) that routes LLM requests to the configured provider. The PHP app never calls LLM APIs directly. See [LLM Sidecar Reference](../reference/llm-sidecar.md).

### Phinx

A PHP database migration tool used for managing schema changes. Configuration is in `phinx.php`.

### Qdrant

An open-source vector database (port 6333). Stores embedded [snippets](#snippet) and performs fast similarity searches. Has a web dashboard at `localhost:6333/dashboard`.

### Query Database

The MySQL database (`vlsm` by default) containing InteLIS laboratory data. This is the database that LLM-generated SQL queries execute against. **Read-only** from the application's perspective. See [Two Databases](two-databases.md).

### Query Pipeline

The 12-step process that transforms a natural language question into SQL, executes it, and returns results with a chart suggestion. See [Query Pipeline](../reference/query-pipeline.md).

### RAG

Retrieval-Augmented Generation — a technique that gives the LLM relevant context before asking it to generate SQL. Instead of sending the entire database schema, RAG finds only the relevant snippets via vector search. See [RAG & Vector Search](rag.md).

### RAG API

A Python FastAPI service (port 8089) that handles text [embedding](#embedding) and semantic search against [Qdrant](#qdrant). Provides `/v1/upsert` and `/v1/search` endpoints.

### Snippet

A small piece of text stored in the RAG index. Each snippet describes one thing: a table, a column, a business rule, a clinical threshold, etc. There are ~2,400 snippets in total. See [RAG Seeding](../guides/rag-seeding.md) for how they're generated.

### Vector

A list of numbers (384 dimensions in this system) that represents the meaning of a piece of text. Similar texts produce similar vectors, enabling semantic search.

### VL

Viral Load — a measure of HIV virus in the blood. The most common test type in the system. Stored in the `form_vl` table.

### VLSM

Viral Load and Sample Management — the name of the InteLIS database (`vlsm`). Contains lab test data for VL, EID, COVID, TB, and other test types.

### VL Suppression

When a patient's viral load result is below 1,000 copies/mL, they are considered "virally suppressed." This is a key clinical threshold used throughout the system: `result_value_absolute < 1000`.
