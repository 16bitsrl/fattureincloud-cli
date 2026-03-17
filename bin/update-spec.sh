#!/usr/bin/env bash
#
# Update the OpenAPI spec from the official FattureInCloud repository.
#
# Usage:
#   ./bin/update-spec.sh           # update to latest from master
#   ./bin/update-spec.sh v2.1.8    # update to a specific tag
#

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"
SPEC_FILE="$PROJECT_DIR/resources/openapi/fattureincloud.yaml"

TAG="${1:-master}"
URL="https://raw.githubusercontent.com/fattureincloud/openapi-fattureincloud/${TAG}/openapi-enriched.yaml"

echo "Downloading OpenAPI spec (ref: ${TAG})..."
echo "  URL: ${URL}"
echo "  Target: ${SPEC_FILE}"
echo ""

HTTP_CODE=$(curl -sL -w "%{http_code}" -o "$SPEC_FILE.tmp" "$URL")

if [ "$HTTP_CODE" != "200" ]; then
    echo "ERROR: Download failed with HTTP ${HTTP_CODE}"
    rm -f "$SPEC_FILE.tmp"
    exit 1
fi

mv "$SPEC_FILE.tmp" "$SPEC_FILE"

# Count endpoints
ENDPOINT_COUNT=$(grep -c 'operationId:' "$SPEC_FILE" || true)
LINE_COUNT=$(wc -l < "$SPEC_FILE" | tr -d ' ')

echo "Spec updated successfully."
echo "  Endpoints: ${ENDPOINT_COUNT}"
echo "  Lines: ${LINE_COUNT}"
echo ""

# Extract version from spec
SPEC_VERSION=$(grep -m1 'version:' "$SPEC_FILE" | sed 's/.*version: *//' | tr -d "'\"")
echo "  API version: ${SPEC_VERSION}"
echo ""

# Remind to clear cache
echo "Remember to clear the CLI cache after updating:"
echo "  fic clear-cache"
