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

echo "Updating VERSION to $VERSION..."
printf '%s\n' "$VERSION" > "$PROJECT_DIR/VERSION"

echo "Building PHAR..."
php "$PROJECT_DIR/fic" app:build fic --build-version="$VERSION"

echo "Verifying PHAR..."
"$PROJECT_DIR/bin/check-phar-sync.sh"

git -C "$PROJECT_DIR" add VERSION builds/fic
git -C "$PROJECT_DIR" commit -m "Release $TAG"
git -C "$PROJECT_DIR" tag "$TAG"
git -C "$PROJECT_DIR" push origin main
git -C "$PROJECT_DIR" push origin "$TAG"

echo "Released: $TAG"
