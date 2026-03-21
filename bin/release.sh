#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"

VERSION="${1:-}"
NO_PUSH="${2:-}"

if [ -z "$VERSION" ]; then
    echo "Usage: ./bin/release.sh X.Y.Z [--no-push]"
    exit 1
fi

if [ -n "$NO_PUSH" ] && [ "$NO_PUSH" != "--no-push" ]; then
    echo "ERROR: Unknown option $NO_PUSH"
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

echo "Updating skill version to $VERSION..."
sed -i.bak "s/^  version: .*/  version: $VERSION/" "$PROJECT_DIR/skills/fattureincloud/SKILL.md"
rm -f "$PROJECT_DIR/skills/fattureincloud/SKILL.md.bak"

echo "Building PHAR..."
php "$PROJECT_DIR/fic" app:build fic --build-version="$VERSION"

echo "Verifying PHAR..."
"$PROJECT_DIR/bin/check-phar-sync.sh"

git -C "$PROJECT_DIR" add VERSION builds/fic skills/fattureincloud/SKILL.md
git -C "$PROJECT_DIR" commit -m "Release $TAG"
git -C "$PROJECT_DIR" tag "$TAG"

if [ "$NO_PUSH" != "--no-push" ]; then
    git -C "$PROJECT_DIR" push origin main
    git -C "$PROJECT_DIR" push origin "$TAG"
fi

echo "Released: $TAG"
