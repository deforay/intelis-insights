#!/usr/bin/env bash
# Writes the current git version to VERSION file in project root.
# Run this as part of your build/deploy pipeline.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
VERSION=$(git -C "$ROOT" describe --tags --always 2>/dev/null || echo "dev")
echo "$VERSION" > "$ROOT/VERSION"
echo "Wrote version: $VERSION"
