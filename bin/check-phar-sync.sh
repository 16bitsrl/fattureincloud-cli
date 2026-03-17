#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"

SOURCE_ENTRYPOINT="$PROJECT_DIR/fic"
PHAR_PATH="$PROJECT_DIR/builds/fic"

normalize_version() {
    local raw="$1"

    raw="${raw#fic }"
    raw="${raw#v}"

    printf '%s' "$raw"
}

if [ ! -f "$PHAR_PATH" ]; then
    echo "ERROR: Missing PHAR at $PHAR_PATH"
    echo "Run: php fic app:build fic --build-version=X.Y.Z"
    exit 1
fi

source_version="${EXPECTED_VERSION:-}"

if [ -z "$source_version" ]; then
    source_version="$(php "$SOURCE_ENTRYPOINT" --version)"
fi

phar_version="$("$PHAR_PATH" --version)"

source_version="$(normalize_version "$source_version")"
phar_version="$(normalize_version "$phar_version")"

if [ "$source_version" != "$phar_version" ]; then
    echo "ERROR: builds/fic is out of sync"
    echo "  Source version: $source_version"
    echo "  PHAR version:   $phar_version"
    echo ""
    echo "Fix with: php fic app:build fic --build-version=$source_version"
    exit 1
fi

echo "OK: builds/fic matches source version ($source_version)"
