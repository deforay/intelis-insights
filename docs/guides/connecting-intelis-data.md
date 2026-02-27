# Connecting InteLIS Data

The InteLIS database is the laboratory data that the LLM queries against. Without it, the dashboard shows empty charts and the chat can't generate SQL queries.

There are two ways to provide InteLIS data:

- **Option A** — Import a database dump into the Docker MySQL container
- **Option B** — Connect to an external InteLIS instance

## Option A: Import a Database Dump

If you have a SQL dump of the InteLIS database (e.g. from `mysqldump`):

### 1. Import the dump

```bash
make db-import FILE=path/to/intelis-dump.sql
```

This loads the SQL file into the Docker MySQL container.

### 2. Update the database name (if needed)

If the dump creates a database with a name other than `vlsm`, update `.env`:

```bash
QUERY_DB_NAME=your_database_name
```

Then restart:

```bash
make down && make up
```

### 3. Export the schema for RAG

The RAG system needs to know what tables and columns exist:

```bash
docker compose exec app php /var/www/bin/export-schema.php
```

This reads the InteLIS database schema and writes it to `var/schema.json`.

### 4. Seed the RAG index

Build snippets from the schema + business rules + field guide, and upload them to Qdrant:

```bash
make rag-refresh
```

See [RAG Seeding](rag-seeding.md) for details on what this does.

### 5. Verify

Test that RAG can find relevant snippets:

```bash
curl -X POST http://localhost:8089/v1/search \
  -H 'Content-Type: application/json' \
  -d '{"query": "viral load suppression", "k": 3}'
```

Test an end-to-end query:

```bash
curl -X POST http://localhost:8080/api/v1/chat/ask \
  -H 'Content-Type: application/json' \
  -d '{"question": "How many viral load tests were done last month?"}'
```

## Option B: Connect to an External InteLIS Instance

If your InteLIS database is hosted elsewhere (e.g. a shared lab server):

### 1. Update `.env`

```bash
QUERY_DB_HOST=lab-db-server.example.com
QUERY_DB_NAME=vlsm
QUERY_DB_USER=readonly_user
QUERY_DB_PASSWORD=readonly_pass
```

!!! tip "Use a read-only database user"
    The application only runs SELECT queries against the InteLIS database. Using a read-only MySQL user is a good security practice.

### 2. Restart services

```bash
make down && make up
```

### 3. Export the schema and seed RAG

```bash
docker compose exec app php /var/www/bin/export-schema.php
make rag-refresh
```

### 4. Verify

Same as Option A, Step 5 above.

## What Changes After Connecting

| Feature | Without InteLIS | With InteLIS |
|---------|----------------|-------------|
| Dashboard | Loads, empty charts | Full national indicators |
| Chat | UI loads, AI can't query | Full conversational queries |
| Reports | Can be created, no data | Produce real results |
| RAG Index | Empty | ~2,400 snippets |

## Updating After Schema Changes

If the InteLIS database schema changes (new tables, renamed columns, new data):

```bash
# Re-export the schema
docker compose exec app php /var/www/bin/export-schema.php

# Re-seed RAG with updated schema
make rag-refresh
```

See [RAG Seeding](rag-seeding.md) for when to use `rag-refresh` vs `rag-reset`.
