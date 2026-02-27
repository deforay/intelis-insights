# RAG Seeding

The RAG index is a collection of ~2,400 snippets stored in the Qdrant vector database. These snippets describe the InteLIS database schema, business rules, and clinical terminology — they give the LLM the context it needs to generate accurate SQL.

This page explains how to build, refresh, and reset the index.

## Quick Reference

| Command | What it does | When to use |
|---------|-------------|-------------|
| `make rag-refresh` | Re-exports schema, rebuilds snippets, uploads to Qdrant | Schema changed, business rules updated, field guide updated |
| `make rag-reset` | Deletes the Qdrant collection, then does a full `rag-refresh` | Switching embedding models, corrupt index, major schema overhaul |

!!! tip "Which one should I use?"
    Use `make rag-refresh` most of the time. It's incremental and faster. Use `make rag-reset` only when you need a clean slate (e.g. after changing the embedding model or if search results seem wrong).

## How the Pipeline Works

The seeding process has three steps:

```
┌──────────────────┐     ┌──────────────────────┐     ┌─────────────────┐
│  export-schema   │     │  build-rag-snippets   │     │   rag-upsert    │
│                  │     │                       │     │                 │
│  Reads MySQL     │────▶│  Schema + rules +     │────▶│  Uploads to     │
│  INFORMATION_    │     │  field guide →         │     │  Qdrant via     │
│  SCHEMA          │     │  JSONL snippets       │     │  RAG API        │
│                  │     │                       │     │                 │
│  → var/          │     │  → corpus/            │     │                 │
│    schema.json   │     │    snippets.jsonl     │     │                 │
└──────────────────┘     └──────────────────────┘     └─────────────────┘
```

### Step 1: Export Database Schema

```bash
php bin/export-schema.php
```

Queries `INFORMATION_SCHEMA` from the InteLIS database and writes `var/schema.json`. This captures:

- All tables and their columns (name, type, nullable, key, extra)
- Foreign key relationships
- Reference/lookup tables (tables with fewer than 50 rows)
- Sample values from reference columns

Output: `var/schema.json` (~125 tables typically)

### Step 2: Build RAG Snippets

```bash
php bin/build-rag-snippets.php
```

Combines the schema with business rules and field guide into JSONL snippets. Reads from three sources:

| Source | What it provides |
|--------|-----------------|
| `var/schema.json` | Database structure (tables, columns, foreign keys) |
| `config/business-rules.php` | Privacy rules, query constraints, validation |
| `config/field-guide.php` | Terminology, clinical thresholds, column semantics |

Snippet types generated:

| Type | Description | Count (~) |
|------|-------------|-----------|
| `column` | Column descriptions with types and semantics | ~1,500 |
| `table` | Table descriptions with column lists | ~125 |
| `syn` | Terminology synonyms and mappings | ~80 |
| `rule` | Business rules (privacy, defaults, formatting) | ~50 |
| `validation` | Field validation rules | ~40 |
| `exemplar` | Example query patterns | ~30 |
| `relationship` | Foreign key relationships between tables | ~23 |
| `threshold` | Clinical thresholds (VL suppression, etc.) | ~20 |
| `test_type` | Test type logic (VL, EID, COVID, TB) | ~15 |

Output: `corpus/snippets.jsonl` (~2,400 lines)

### Step 3: Upload to Qdrant

```bash
php bin/rag-upsert.php corpus/snippets.jsonl
```

Batch uploads the JSONL snippets to the RAG API, which embeds them and stores the vectors in Qdrant.

Options:

- Second argument is batch size (default: 500)

## All-in-One: `rag-refresh.sh`

The shell script `bin/rag-refresh.sh` runs all three steps plus health checks and verification:

```bash
bash bin/rag-refresh.sh
```

Options:

| Flag | Effect |
|------|--------|
| `--reset` | Delete and recreate the Qdrant collection before uploading |
| `--batch N` | Change the upload batch size (default: 500) |

```bash
# Full reset + rebuild
bash bin/rag-refresh.sh --reset
```

The Makefile targets are wrappers around this script:

- `make rag-refresh` → runs `rag-refresh.sh` inside the Docker container
- `make rag-reset` → runs `rag-refresh.sh --reset` inside the Docker container

## `rag-refresh` vs `rag-reset`

| | `make rag-refresh` | `make rag-reset` |
|--|---------------------|-------------------|
| **Deletes existing data?** | No — upserts (adds/updates) | Yes — drops the collection and recreates it |
| **Speed** | Faster (incremental) | Slower (rebuilds from scratch) |
| **Use when** | Schema changed, rules updated, new field guide entries | Switching embedding models, index seems corrupted, major schema overhaul |
| **Data loss risk** | None — old snippets stay unless overwritten by ID | All existing snippets are deleted first |
| **Command** | `make rag-refresh` | `make rag-reset` |

## Verification

After seeding, verify the index is working:

```bash
curl -X POST http://localhost:8089/v1/search \
  -H 'Content-Type: application/json' \
  -d '{
    "query": "viral load suppressed by lab",
    "k": 5,
    "filters": {"type": ["rule", "threshold", "column"]}
  }'
```

You should see relevant snippets about VL suppression thresholds, result categories, and facility columns.

## When to Re-seed

| Change | Action needed |
|--------|---------------|
| InteLIS schema changes (new tables, renamed columns) | `make rag-refresh` |
| Updated `config/business-rules.php` | `make rag-refresh` |
| Updated `config/field-guide.php` | `make rag-refresh` |
| Changed the embedding model (`EMBEDDING_MODEL` in `.env`) | `make rag-reset` |
| Search returns irrelevant or outdated results | `make rag-reset` |
| First setup after importing InteLIS data | `make rag-refresh` |
