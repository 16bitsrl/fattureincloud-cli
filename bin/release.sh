#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"

VERSION="${1:-}"

if [ -z "$VERSION" ]; then
    echo "Usage: ./bin/release.sh X.Y.Z"
    exit 1
fi

if ! [[ "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
    echo "ERROR: Version must be in X.Y.Z format"
    exit 1
fi

TAG="v$VERSION"

status_output="$(git -C "$PROJECT_DIR" status --short)"
if [ -n "$status_output" ]; then
    echo "ERROR: Working tree is not clean"
    printf '%s\n' "$status_output"
    exit 1
fi

if git -C "$PROJECT_DIR" rev-parse "$TAG" >/dev/null 2>&1; then
    echo "ERROR: Tag $TAG already exists"
    exit 1
fi

echo "Building PHAR $VERSION..."
php "$PROJECT_DIR/fic" app:build fic --build-version="$VERSION"

echo "Checking PHAR sync..."
EXPECTED_VERSION="$VERSION" "$PROJECT_DIR/bin/check-phar-sync.sh"

echo "Checking PHAR version..."
actual_phar_version="$("$PROJECT_DIR/builds/fic" --version)"
actual_phar_version="${actual_phar_version#fic }"
actual_phar_version="${actual_phar_version#v}"

if [ "$actual_phar_version" != "$VERSION" ]; then
    echo "ERROR: Built PHAR reports $actual_phar_version, expected $VERSION"
    exit 1
fi

git -C "$PROJECT_DIR" add builds/fic
git -C "$PROJECT_DIR" commit -m "Release $TAG"
git -C "$PROJECT_DIR" tag "$TAG"
git -C "$PROJECT_DIR" push origin main --follow-tags

echo "Release prepared and pushed: $TAG"
