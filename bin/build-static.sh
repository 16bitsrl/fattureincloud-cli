#!/usr/bin/env bash
#
# Build static self-contained binaries for all platforms.
# Uses pre-built micro.sfx from static-php.dev.
#
# Usage:
#   ./bin/build-static.sh                    # build for current platform
#   ./bin/build-static.sh all                # build for all platforms
#   ./bin/build-static.sh macos-aarch64      # build for specific platform
#
# Requires: builds/fic PHAR to exist (run `php fic app:build fic` first)
#

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"
PHAR="$PROJECT_DIR/builds/fic"
OUT_DIR="$PROJECT_DIR/builds/static"
COMMON_PHP_VERSION="${COMMON_PHP_VERSION:-8.4.5}"
WINDOWS_PHP_VERSION="${WINDOWS_PHP_VERSION:-8.4.18}"
COMMON_BASE_URL="https://dl.static-php.dev/static-php-cli/common"
WINDOWS_BASE_URL="https://dl.static-php.dev/static-php-cli/windows/spc-max"

PLATFORMS=(
    "macos-aarch64"
    "macos-x86_64"
    "linux-aarch64"
    "linux-x86_64"
    "windows-x86_64"
)

if [ ! -f "$PHAR" ]; then
    echo "ERROR: PHAR not found at $PHAR"
    echo "Run: php fic app:build fic --build-version=X.Y.Z"
    exit 1
fi

mkdir -p "$OUT_DIR"

build_platform() {
    local platform="$1"
    local url
    local archive
    local output="$OUT_DIR/fic-${platform}"

    if [ "$platform" = "windows-x86_64" ]; then
        url="${WINDOWS_BASE_URL}/php-${WINDOWS_PHP_VERSION}-micro-win.zip"
        archive="$OUT_DIR/micro-${platform}.zip"
        output="${output}.exe"
    else
        url="${COMMON_BASE_URL}/php-${COMMON_PHP_VERSION}-micro-${platform}.tar.gz"
        archive="$OUT_DIR/micro-${platform}.tar.gz"
    fi

    echo "Building fic-${platform}..."
    echo "  Downloading micro.sfx from ${url}"

    HTTP_CODE=$(curl -fsSL -w "%{http_code}" -o "$archive" "$url" 2>/dev/null || echo "000")

    if [ "$HTTP_CODE" != "200" ] && [ ! -f "$archive" ]; then
        echo "  WARNING: Download failed (HTTP ${HTTP_CODE}), skipping ${platform}"
        rm -f "$archive"
        return 1
    fi

    if [ "$platform" = "windows-x86_64" ]; then
        unzip -oq "$archive" -d "$OUT_DIR"
    else
        tar xzf "$archive" -C "$OUT_DIR"
    fi

    cat "$OUT_DIR/micro.sfx" "$PHAR" > "$output"
    chmod +x "$output"

    local size=$(ls -lh "$output" | awk '{print $5}')
    echo "  Built: ${output} (${size})"

    rm -f "$archive" "$OUT_DIR/micro.sfx"
}

TARGET="${1:-current}"

if [ "$TARGET" = "all" ]; then
    for platform in "${PLATFORMS[@]}"; do
        build_platform "$platform" || true
    done
elif [ "$TARGET" = "current" ]; then
    OS=$(uname -s | tr '[:upper:]' '[:lower:]')
    ARCH=$(uname -m)
    [ "$ARCH" = "arm64" ] && ARCH="aarch64"
    build_platform "${OS}-${ARCH}"
else
    build_platform "$TARGET"
fi

echo ""
echo "Done. Binaries in: $OUT_DIR/"
ls -lh "$OUT_DIR"/fic-* 2>/dev/null || true
