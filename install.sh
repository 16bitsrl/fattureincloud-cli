#!/bin/sh
# Install the Fatture in Cloud CLI (fic) as a single static binary.
#
#   curl -fsSL https://raw.githubusercontent.com/16bitsrl/fattureincloud-cli/main/install.sh | sh
#
# Environment variables:
#   FIC_VERSION      version to install, e.g. 1.2.0 (default: latest release)
#   FIC_INSTALL_DIR  target directory (default: $HOME/.local/bin)

set -eu

REPO="16bitsrl/fattureincloud-cli"
INSTALL_DIR="${FIC_INSTALL_DIR:-$HOME/.local/bin}"
VERSION="${FIC_VERSION:-}"
VERSION="${VERSION#v}"

say() { printf '%s\n' "$*"; }
die() { printf 'error: %s\n' "$*" >&2; exit 1; }

case "$(uname -s)" in
    Linux) os=linux ;;
    Darwin) os=macos ;;
    MINGW* | MSYS* | CYGWIN*) die "on Windows use: irm https://raw.githubusercontent.com/$REPO/main/install.ps1 | iex" ;;
    *) die "unsupported operating system: $(uname -s)" ;;
esac

case "$(uname -m)" in
    x86_64 | amd64) arch=x86_64 ;;
    arm64 | aarch64) arch=aarch64 ;;
    *) die "unsupported architecture: $(uname -m)" ;;
esac

asset="fic-$os-$arch"

if [ -n "$VERSION" ]; then
    base_url="https://github.com/$REPO/releases/download/v$VERSION"
else
    base_url="https://github.com/$REPO/releases/latest/download"
fi

if command -v curl >/dev/null 2>&1; then
    download() { curl -fsSL --retry 3 -o "$2" "$1"; }
elif command -v wget >/dev/null 2>&1; then
    download() { wget -q -O "$2" "$1"; }
else
    die "curl or wget is required"
fi

if command -v sha256sum >/dev/null 2>&1; then
    sha256() { sha256sum "$1" | cut -d' ' -f1; }
elif command -v shasum >/dev/null 2>&1; then
    sha256() { shasum -a 256 "$1" | cut -d' ' -f1; }
else
    die "sha256sum or shasum is required to verify the download"
fi

tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT INT TERM

say "Downloading $asset ${VERSION:+v$VERSION }from github.com/$REPO..."
download "$base_url/$asset" "$tmp/fic" || die "download failed: $base_url/$asset"
download "$base_url/checksums.txt" "$tmp/checksums.txt" || die "download failed: $base_url/checksums.txt"

expected="$(awk -v f="$asset" '$2 == f || $2 == "*" f { print $1 }' "$tmp/checksums.txt")"
[ -n "$expected" ] || die "no checksum found for $asset"
[ "$(sha256 "$tmp/fic")" = "$expected" ] || die "checksum mismatch for $asset"

chmod +x "$tmp/fic"
mkdir -p "$INSTALL_DIR"
mv "$tmp/fic" "$INSTALL_DIR/fic"

say "Installed $("$INSTALL_DIR/fic" --version) to $INSTALL_DIR/fic"

case ":$PATH:" in
    *":$INSTALL_DIR:"*) ;;
    *)
        say ""
        say "$INSTALL_DIR is not in your PATH. Add it with:"
        say "  export PATH=\"$INSTALL_DIR:\$PATH\""
        ;;
esac

say ""
say "Next steps:"
say "  fic auth:login    # sign in with your Fatture in Cloud account"
say "  fic               # overview of commands and docs"
