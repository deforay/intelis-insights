#!/usr/bin/env bash
set -euo pipefail
which fswatch >/dev/null 2>&1 || { echo "Install fswatch: brew install fswatch"; exit 1; }
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
echo "Watching business rules + field guide. Press Ctrl-C to stop."
fswatch -o "$ROOT/config/business-rules.php" "$ROOT/config/field-guide.php" | while read _; do
  echo ">> Change detected @ $(date '+%H:%M:%S') — refreshing..."
  "$ROOT/bin/rag-refresh.sh" || echo "Refresh failed."
done
