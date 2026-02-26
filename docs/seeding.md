# Seeding the Vector Database

The RAG (Retrieval-Augmented Generation) system uses a Qdrant vector database populated with ~2,400 snippets derived from the database schema, business rules, and field guide. These snippets give the LLM the context it needs to generate accurate SQL.

## Prerequisites

- Docker services running (`docker compose up -d`)
- RAG API reachable at `http://127.0.0.1:8089`
- MySQL `vlsm` database accessible
- Composer dependencies installed

## Pipeline overview

```
┌──────────────────┐     ┌──────────────────────┐     ┌─────────────────┐
│ export-schema.php│────▶│ build-rag-snippets.php│────▶│ rag-upsert.php  │
│                  │     │                       │     │                 │
│ INFORMATION_     │     │ schema.json +         │     │ snippets.jsonl  │
│ SCHEMA → JSON    │     │ business-rules +      │     │ → Qdrant via    │
│                  │     │ field-guide →          │     │   RAG API       │
│ var/schema.json  │     │ corpus/snippets.jsonl  │     │                 │
└──────────────────┘     └──────────────────────┘     └─────────────────┘
```

## Step 1: Export database schema

Queries `INFORMATION_SCHEMA` from the vlsm database and produces `var/schema.json`:

```bash
php bin/export-schema.php
```

This captures:
- All tables and their columns (name, type, nullable, key, extra)
- Foreign key relationships
- Reference/lookup tables (tables with < 50 rows)
- Sample values from reference columns

Output: `var/schema.json` (~125 tables typically)

## Step 2: Build RAG snippets

Combines the schema with business rules and field guide into JSONL snippets:

```bash
php bin/build-rag-snippets.php
```

Sources:
- `var/schema.json` — database structure
- `config/business-rules.php` — privacy rules, query constraints, validation
- `config/field-guide.php` — terminology, clinical thresholds, column semantics

Snippet types generated:

| Type | Description | Count (~) |
|---|---|---|
| `rule` | Business rules (privacy, defaults, formatting) | ~50 |
| `syn` | Terminology synonyms and mappings | ~80 |
| `column` | Column descriptions with types and semantics | ~1,500 |
| `table` | Table descriptions with column lists | ~125 |
| `relationship` | Foreign key relationships between tables | ~23 |
| `exemplar` | Example query patterns | ~30 |
| `threshold` | Clinical thresholds (VL suppression, etc.) | ~20 |
| `test_type` | Test type logic (VL, EID, COVID, TB) | ~15 |
| `validation` | Field validation rules | ~40 |

Output: `corpus/snippets.jsonl` (~2,400 lines)

## Step 3: Upload to Qdrant

Batch uploads the JSONL snippets to the RAG API, which embeds them and stores in Qdrant:

```bash
php bin/rag-upsert.php corpus/snippets.jsonl
```

Options:
- Second argument is batch size (default: 500)

## All-in-one: rag-refresh.sh

Runs all three steps plus health checks and verification:

```bash
bash bin/rag-refresh.sh
```

Options:
- `--reset` — Recreate the Qdrant collection before upserting (use when schema has changed significantly)
- `--batch N` — Upsert batch size (default: 500)

```bash
# Full reset + rebuild
bash bin/rag-refresh.sh --reset
```

## Verification

After seeding, verify the index is working:

```bash
curl -X POST http://127.0.0.1:8089/v1/search \
  -H 'Content-Type: application/json' \
  -d '{
    "query": "viral load suppressed by lab",
    "k": 5,
    "filters": {"type": ["rule", "threshold", "column"]}
  }'
```

You should see relevant snippets about VL suppression thresholds, result categories, and facility columns.

## When to re-seed

Re-run the seeding pipeline when:
- The `vlsm` database schema changes (new tables, renamed columns)
- Business rules are updated (`config/business-rules.php`)
- Field guide is updated (`config/field-guide.php`)
- The embedding model changes (requires `--reset`)
