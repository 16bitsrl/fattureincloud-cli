#!/bin/sh
# Render the App Store screenshots (PNG 1280x720) into site/assets/store/.
# Needs python3 and playwright-cli.
set -eu
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PORT=8766
SESSION="fic-shots-$$"

python3 -m http.server "$PORT" --bind 127.0.0.1 --directory "$ROOT" >/dev/null 2>&1 &
SERVER=$!
trap 'kill $SERVER; playwright-cli -s="$SESSION" close >/dev/null 2>&1 || true' EXIT
sleep 1

playwright-cli -s="$SESSION" open "http://127.0.0.1:$PORT/marketing/screenshots.html" >/dev/null
mkdir -p "$ROOT/site/assets/store"
for i in 1 2 3 4; do
    playwright-cli -s="$SESSION" run-code "async page => { await page.locator('#shot-$i').screenshot({ path: '$ROOT/site/assets/store/screenshot-$i.png' }); }" >/dev/null
done
echo "Rendered: $(ls "$ROOT/site/assets/store" | tr '\n' ' ')"
