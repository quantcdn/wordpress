#!/usr/bin/env bash
# Build the distributable plugin zip: built assets, PHP sources, no dev files.
set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "$0")/.." && pwd)"
VERSION="$(grep -E '^ \* Version:' "$PLUGIN_DIR/quant.php" | awk '{print $3}')"
OUT_DIR="${1:-$PLUGIN_DIR/dist}"
STAGE="$(mktemp -d)/quant"

mkdir -p "$OUT_DIR" "$STAGE"
(cd "$PLUGIN_DIR" && npm run build >/dev/null)

rsync -a --exclude-from="$PLUGIN_DIR/.distignore" "$PLUGIN_DIR/" "$STAGE/"
ZIP="$OUT_DIR/quant-$VERSION.zip"
rm -f "$ZIP"   # zip -r would otherwise keep files deleted since the last build
(cd "$(dirname "$STAGE")" && zip -qr "$ZIP" quant)

echo "Built $OUT_DIR/quant-$VERSION.zip"
