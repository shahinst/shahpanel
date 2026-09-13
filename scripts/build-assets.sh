#!/usr/bin/env bash
# Build Vite assets (CSS/JS) for production.
# Run from project root: bash scripts/build-assets.sh

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

if ! command -v npm >/dev/null 2>&1; then
    echo "ERROR: npm not found. Install Node.js 18+ or upload public/build/ from your dev machine." >&2
    exit 1
fi

for required in vite.config.js package.json postcss.config.js; do
    if [[ ! -f "$ROOT/$required" ]]; then
        echo "ERROR: Missing $required in $ROOT" >&2
        echo "Upload the full project (or at least vite.config.js + resources/ + public/build/)." >&2
        exit 1
    fi
done

echo "Installing npm dependencies..."
if [[ -f package-lock.json ]]; then
    npm ci
else
    npm install
fi

echo "Building assets..."
npm run build

echo "Done. Output: public/build/"
