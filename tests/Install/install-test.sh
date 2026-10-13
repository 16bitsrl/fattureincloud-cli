#!/bin/sh
# End-to-end test for install.sh: downloads real release binaries from GitHub.

set -eu

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

fail() {
    echo "FAIL: $1" >&2
    exit 1
}

echo "== latest release, piped like curl | sh"
FIC_INSTALL_DIR="$WORK/latest" sh < "$ROOT/install.sh" > "$WORK/latest.log" 2>&1 || { cat "$WORK/latest.log"; fail "install of latest release failed"; }
[ -x "$WORK/latest/fic" ] || fail "binary not installed in FIC_INSTALL_DIR"
"$WORK/latest/fic" --version | grep -q '^fic ' || fail "installed binary does not run"

echo "== pinned version"
FIC_VERSION=1.0.2 FIC_INSTALL_DIR="$WORK/pinned" sh "$ROOT/install.sh" > "$WORK/pinned.log" 2>&1 || { cat "$WORK/pinned.log"; fail "install of pinned version failed"; }
"$WORK/pinned/fic" --version | grep -q '1.0.2' || fail "pinned version not installed"

echo "== missing version fails cleanly"
if FIC_VERSION=0.0.0 FIC_INSTALL_DIR="$WORK/missing" sh "$ROOT/install.sh" > "$WORK/missing.log" 2>&1; then
    fail "install of a missing version should fail"
fi
[ ! -e "$WORK/missing/fic" ] || fail "a failed install left a binary behind"

echo "OK"
