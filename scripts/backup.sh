#!/usr/bin/env bash
#
# Back up the stateful data of an InteLIS Insights deployment.
#
#   - Postgres (intelis-insights-postgres): app state — RBAC users, audit
#     log, sessions, LangGraph checkpoints. This is the IRREPLACEABLE data;
#     it has no other source.
#   - Bundled MySQL (intelis-insights-local-mysql): the imported InteLIS
#     dataset. Only present in the bundled-MySQL (docker-compose.local-lab)
#     deployment, and reconstructible from the original ./mysql-init dump —
#     so this dump is belt-and-suspenders. Skipped automatically when the
#     deployment connects to an external InteLIS server instead.
#
# Output: gzipped, timestamped dumps in $BACKUP_DIR (default ./backups),
# pruned after $BACKUP_RETENTION_DAYS (default 14).
#
# NOTE: backups contain real lab PII — keep $BACKUP_DIR off the public repo
# (./backups is gitignored) and ideally ship them to off-box storage.
#
# Usage:
#   ./scripts/backup.sh
#   BACKUP_DIR=/mnt/backups BACKUP_RETENTION_DAYS=30 ./scripts/backup.sh
#
# Cron (daily 02:30):
#   30 2 * * * cd /home/USER/intelis-insights && ./scripts/backup.sh >> backups/backup.log 2>&1
#
set -euo pipefail

# Run from the repo root regardless of where cron invokes us.
cd "$(dirname "$0")/.."

# Load .env for DB names + passwords.
set -a
[ -f .env ] && . ./.env
set +a

BACKUP_DIR="${BACKUP_DIR:-./backups}"
RETENTION_DAYS="${BACKUP_RETENTION_DAYS:-14}"
STAMP="$(date +%Y%m%d-%H%M%S)"
PG_CONTAINER="intelis-insights-postgres"
MYSQL_CONTAINER="intelis-insights-local-mysql"

mkdir -p "$BACKUP_DIR"
echo "[$(date -Is)] starting backup -> $BACKUP_DIR"

running() { docker ps --format '{{.Names}}' | grep -qx "$1"; }

# --- Postgres (always present) ---------------------------------------------
if running "$PG_CONTAINER"; then
  out="$BACKUP_DIR/postgres-${STAMP}.sql.gz"
  docker exec "$PG_CONTAINER" pg_dump -U intelis intelis_insights | gzip > "$out"
  echo "  postgres -> $out ($(du -h "$out" | cut -f1))"
else
  echo "  WARN: $PG_CONTAINER not running — skipping Postgres backup"
fi

# --- Bundled MySQL (only in local-lab deployments) -------------------------
if running "$MYSQL_CONTAINER"; then
  db="${LAB_DB_NAME:-intelis}"
  out="$BACKUP_DIR/mysql-${db}-${STAMP}.sql.gz"
  docker exec -e MYSQL_PWD="${LOCAL_LAB_MYSQL_ROOT_PASSWORD}" "$MYSQL_CONTAINER" \
    mysqldump -uroot --single-transaction --no-tablespaces "$db" | gzip > "$out"
  echo "  mysql -> $out ($(du -h "$out" | cut -f1))"
else
  echo "  (bundled MySQL not running — external-DB deployment, skipping)"
fi

# --- Rotate ----------------------------------------------------------------
find "$BACKUP_DIR" -maxdepth 1 -name '*.sql.gz' -type f -mtime +"$RETENTION_DAYS" -print -delete
echo "[$(date -Is)] backup complete; pruned dumps older than ${RETENTION_DAYS}d"
