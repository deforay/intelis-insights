#!/usr/bin/env bash
set -euo pipefail

echo "==> Intelis Insights — Init Container"

# ── 1. Verify app database tables exist ──────────────────────
echo "==> Checking app database..."

TABLE_COUNT=$(mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" -p"$DB_PASSWORD" \
  -sNe "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${DB_NAME:-intelis_insights}';" 2>/dev/null || echo "0")

if [ "$TABLE_COUNT" -lt "5" ]; then
  echo "    App database has $TABLE_COUNT tables — expected more."
  echo "    MySQL init scripts may still be running. Waiting 10s..."
  sleep 10
  TABLE_COUNT=$(mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" -p"$DB_PASSWORD" \
    -sNe "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${DB_NAME:-intelis_insights}';" 2>/dev/null || echo "0")
  echo "    Now: $TABLE_COUNT tables."
fi

echo "    App database: $TABLE_COUNT tables found."

# ── 2. Check for query database (VLSM) ──────────────────────
QUERY_HOST="${QUERY_DB_HOST:-$DB_HOST}"
QUERY_PORT="${QUERY_DB_PORT:-$DB_PORT}"
QUERY_USER="${QUERY_DB_USER:-$DB_USER}"
QUERY_PASS="${QUERY_DB_PASSWORD:-$DB_PASSWORD}"
QUERY_NAME="${QUERY_DB_NAME:-vlsm}"

echo "==> Checking query database '$QUERY_NAME' on $QUERY_HOST..."

QUERY_DB_EXISTS=$(mysql -h"$QUERY_HOST" -P"$QUERY_PORT" -u"$QUERY_USER" -p"$QUERY_PASS" \
  -sNe "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='$QUERY_NAME';" 2>/dev/null || echo "")

if [ -z "$QUERY_DB_EXISTS" ]; then
  echo "    Query database '$QUERY_NAME' not found."
  echo "    LLM query features will be unavailable until VLSM data is imported."
  echo "    Import with: make db-import FILE=path/to/vlsm-dump.sql"
  echo "    Then run:    make rag-refresh"
  echo ""
  echo "==> Skipping schema export and RAG seeding."
  echo "==> Init container finished (partial — no query DB)."
  exit 0
fi

echo "    Query database '$QUERY_NAME' found."

# ── 3. Export schema from query DB ───────────────────────────
echo "==> Exporting schema from query database..."
php /var/www/bin/export-schema.php || {
  echo "WARNING: Schema export failed (non-fatal). RAG snippets may be incomplete."
}

# ── 4. RAG Seeding ───────────────────────────────────────────
echo "==> Checking RAG index..."

RAG_COUNT=$(curl -fsS -X POST "${RAG_BASE_URL}/v1/search" \
  -H 'Content-Type: application/json' \
  -d '{"query":"probe","k":1}' 2>/dev/null | \
  python3 -c "import sys,json; d=json.load(sys.stdin); print(len(d.get('contexts',[])))" 2>/dev/null || echo "0")

if [ "$RAG_COUNT" -eq "0" ] || [ "${FORCE_RAG_REFRESH:-0}" -eq "1" ]; then
  echo "==> Building and upserting RAG snippets..."

  php /var/www/bin/build-rag-snippets.php

  if [ -f /var/www/corpus/snippets.jsonl ]; then
    php /var/www/bin/rag-upsert.php /var/www/corpus/snippets.jsonl 500
    echo "==> RAG seeding complete."
  else
    echo "WARNING: snippets.jsonl not generated. RAG will have no data."
  fi
else
  echo "    RAG index already populated ($RAG_COUNT results). Skipping."
  echo "    Set FORCE_RAG_REFRESH=1 to force re-seeding."
fi

echo "==> Init container finished successfully."
