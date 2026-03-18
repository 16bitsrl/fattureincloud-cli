#!/usr/bin/env bash

# Verify that builds/fic reports the same version as the VERSION file.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"

PHAR_PATH="$PROJECT_DIR/builds/fic"
VERSION_FILE="$PROJECT_DIR/VERSION"

if [ ! -f "$PHAR_PATH" ]; then
    echo "ERROR: Missing PHAR at builds/fic"
    echo "Run: composer build"
    exit 1
fi

if [ ! -f "$VERSION_FILE" ]; then
    echo "ERROR: Missing VERSION file"
    exit 1
fi

source_version="$(cat "$VERSION_FILE" | tr -d '[:space:]')"
phar_version="$("$PHAR_PATH" --version)"
phar_version="${phar_version#fic }"
phar_version="${phar_version#v}"

if [ "$source_version" != "$phar_version" ]; then
    echo "ERROR: builds/fic is out of sync"
    echo "  VERSION file: $source_version"
    echo "  PHAR reports: $phar_version"
    echo ""
    echo "Rebuild with: composer build"
    exit 1
fi

echo "OK: builds/fic matches VERSION ($source_version)"
