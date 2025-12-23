#!/usr/bin/env bash
set -euo pipefail

# bin/rag-refresh.sh
# --- config / defaults ---
BATCH=500
RESET=0

# Parse flags
while [[ $# -gt 0 ]]; do
  case "$1" in
    --reset) RESET=1; shift ;;
    --batch) BATCH="${2:-500}"; shift 2 ;;
    -h|--help)
      echo "Usage: $(basename "$0") [--reset] [--batch N]"
      echo "  --reset   Recreate the Qdrant collection before upserting"
      echo "  --batch   Upsert batch size (default: 500)"
      exit 0
      ;;
    *) echo "Unknown option: $1" >&2; exit 1 ;;
  esac
done

# Project root
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

# Resolve RAG base URL:
# 1) env RAG_BASE_URL, else
# 2) config/app.php['rag_base_url'], else
# 3) default http://127.0.0.1:8089
if [[ -n "${RAG_BASE_URL:-}" ]]; then
  RAG="$RAG_BASE_URL"
else
  RAG="$(php -r '
    $root=$argv[1];
    $cfg=@require $root."/config/app.php";
    echo is_array($cfg)&&isset($cfg["rag_base_url"]) ? $cfg["rag_base_url"] : "http://127.0.0.1:8089";
  ' "$ROOT")"
fi

SNIPS="$ROOT/corpus/snippets.jsonl"

echo "==> RAG base URL     : $RAG"
echo "==> Project root     : $ROOT"
echo "==> Batch size       : $BATCH"
echo "==> Reset collection : $RESET"

# Basic health check for sidecar (tolerate cold start)
echo "==> Probing sidecar..."
for i in {1..20}; do
  if curl -fsS -m 2 -X POST "$RAG/v1/search" \
      -H 'Content-Type: application/json' \
      -d '{"query":"probe","k":1}' >/dev/null 2>&1; then
    echo "   Sidecar is up."
    break
  fi
  echo "   Waiting for sidecar ($i/20)..."
  sleep 0.5
  if [[ $i -eq 20 ]]; then
    echo "ERROR: RAG sidecar not reachable at $RAG" >&2
    exit 1
  fi
done

# Optional: reset collection (handles deletions/renames)
if [[ "$RESET" -eq 1 ]]; then
  echo "==> Resetting collection..."
  curl -fsS -X POST "$RAG/v1/reset" >/dev/null || {
    echo "ERROR: /v1/reset failed. Ensure the endpoint exists in rag-api." >&2
    exit 1
  }
fi

# Ensure composer autoload exists for rag-upsert.php
if [[ ! -f "$ROOT/vendor/autoload.php" ]]; then
  echo "WARNING: vendor/autoload.php not found. rag-upsert.php needs Guzzle." >&2
fi

# Rebuild snippets.jsonl
echo "==> Building snippets.jsonl..."
php "$ROOT/bin/build-rag-snippets.php"

if [[ ! -f "$SNIPS" ]]; then
  echo "ERROR: $SNIPS not found after build." >&2
  exit 1
fi

# Upsert snippets into RAG
echo "==> Upserting snippets to RAG..."
php "$ROOT/bin/rag-upsert.php" "$SNIPS" "$BATCH"

# Quick verification search
echo "==> Verifying index..."
curl -fsS -X POST "$RAG/v1/search" \
  -H 'Content-Type: application/json' \
  -d '{"query":"viral load high suppressed", "k": 3, "filters": {"type":["rule","threshold","column"]}}' \
  | sed -e 's/{"contexts":/{"contexts":\n/' -e 's/,"debug":.*/\n}/' \
  | head -n 50

echo "==> Done."
