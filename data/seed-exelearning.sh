#!/bin/sh
# Seed script: Create test site, items and media with eXeLearning (.elpx) files
# Usage: ./data/seed-exelearning.sh [API_KEY_IDENTITY] [API_KEY_CREDENTIAL]
#
# If no API key is provided, it creates one automatically via omeka-s-cli.

set -e

OMEKA_URL="${OMEKA_URL:-http://localhost:8080}"
FIXTURE_DIR="$(dirname "$0")/fixtures"

# API key handling
KEY_IDENTITY="${1:-}"
KEY_CREDENTIAL="${2:-}"

if [ -z "$KEY_IDENTITY" ] || [ -z "$KEY_CREDENTIAL" ]; then
    echo "No API key provided, creating one..."

    # Determine how to run omeka-s-cli (inside container vs outside via docker)
    if command -v omeka-s-cli >/dev/null 2>&1; then
        CLI_CMD="omeka-s-cli"
    elif command -v docker >/dev/null 2>&1 && docker compose ps --services 2>/dev/null | grep -q omekas; then
        CLI_CMD="docker compose exec -T -w /var/www/html/volume omekas omeka-s-cli"
    else
        CLI_CMD=""
    fi

    if [ -n "$CLI_CMD" ]; then
        KEY_LABEL="seed-$(date +%s)"
        API_OUTPUT=$($CLI_CMD user:create-api-key admin@example.com "$KEY_LABEL" 2>&1)
        KEY_IDENTITY=$(echo "$API_OUTPUT" | grep '|' | grep -v '^+' | tail -1 | awk -F'|' '{gsub(/^[ \t]+|[ \t]+$/, "", $2); print $2}')
        KEY_CREDENTIAL=$(echo "$API_OUTPUT" | grep '|' | grep -v '^+' | tail -1 | awk -F'|' '{gsub(/^[ \t]+|[ \t]+$/, "", $3); print $3}')
    fi

    if [ -z "$KEY_IDENTITY" ] || [ -z "$KEY_CREDENTIAL" ]; then
        echo "Error: Could not create API key. Provide key_identity and key_credential as arguments."
        echo "Usage: $0 <key_identity> <key_credential>"
        exit 1
    fi
    echo "Created API key: $KEY_IDENTITY"
fi

API_AUTH="key_identity=${KEY_IDENTITY}&key_credential=${KEY_CREDENTIAL}"

# Check API connectivity
echo "Checking API connectivity at ${OMEKA_URL}..."
STATUS=$(curl -s -o /dev/null -w "%{http_code}" "${OMEKA_URL}/api/items?${API_AUTH}")
if [ "$STATUS" != "200" ]; then
    echo "Error: API returned HTTP $STATUS. Check OMEKA_URL and credentials."
    exit 1
fi
echo "API OK"

# Check if eXeLearning items already exist
EXISTING=$(curl -s "${OMEKA_URL}/api/items?${API_AUTH}&search=eXeLearning+Test+Project" | python3 -c "import sys,json; print(len(json.load(sys.stdin)))" 2>/dev/null || echo "0")
if [ "$EXISTING" -gt 0 ]; then
    echo "eXeLearning test items already exist ($EXISTING found). Skipping seed."
    exit 0
fi

echo ""
echo "=== Seeding eXeLearning test data ==="
echo ""

# --- Create item ---
echo "Creating item: eXeLearning Test Project"
ITEM_RESPONSE=$(curl -s -X POST "${OMEKA_URL}/api/items?${API_AUTH}" \
    -H "Content-Type: application/json" \
    -d '{
        "dcterms:title": [{"type": "literal", "property_id": 1, "@value": "eXeLearning Test Project"}],
        "dcterms:description": [{"type": "literal", "property_id": 4, "@value": "A simple test project created with eXeLearning. Contains basic text content for testing the plugin integration."}],
        "o:is_public": true
    }')
ITEM_ID=$(echo "$ITEM_RESPONSE" | python3 -c "import sys,json; print(json.load(sys.stdin).get('o:id',''))" 2>/dev/null || echo "")

if [ -z "$ITEM_ID" ]; then
    echo "  Error creating item."
    exit 1
fi
echo "  Created item #${ITEM_ID}"

# --- Upload media ---
FIXTURE_FILE="${FIXTURE_DIR}/really-simple-test-project.elpx"
if [ -f "$FIXTURE_FILE" ]; then
    echo "  Uploading: $(basename "$FIXTURE_FILE")"
    MEDIA_RESPONSE=$(curl -s -X POST "${OMEKA_URL}/api/media?${API_AUTH}" \
        -F "data={\"o:ingester\":\"upload\",\"file_index\":0,\"o:item\":{\"o:id\":${ITEM_ID}},\"dcterms:title\":[{\"type\":\"literal\",\"property_id\":1,\"@value\":\"eXeLearning Test Project\"}]}" \
        -F "file[0]=@${FIXTURE_FILE};type=application/zip")
    MEDIA_ID=$(echo "$MEDIA_RESPONSE" | python3 -c "import sys,json; print(json.load(sys.stdin).get('o:id',''))" 2>/dev/null || echo "")
    if [ -n "$MEDIA_ID" ]; then
        echo "  Uploaded media #${MEDIA_ID}"
    else
        echo "  Warning: Media upload may have failed"
    fi
else
    echo "  Warning: Fixture file not found: $FIXTURE_FILE"
fi

# --- Create site ---
echo ""
echo "Creating site: eXeLearning Demo"
SITE_RESPONSE=$(curl -s -X POST "${OMEKA_URL}/api/sites?${API_AUTH}" \
    -H "Content-Type: application/json" \
    -d '{
        "o:title": "eXeLearning Demo",
        "o:slug": "exelearning-demo",
        "o:theme": "default",
        "o:is_public": true,
        "o:navigation": []
    }')
SITE_ID=$(echo "$SITE_RESPONSE" | python3 -c "import sys,json; print(json.load(sys.stdin).get('o:id',''))" 2>/dev/null || echo "")

if [ -z "$SITE_ID" ]; then
    echo "  Warning: Site creation failed (may already exist)"
else
    echo "  Created site #${SITE_ID}"

    # Assign item to site
    curl -s -X PATCH "${OMEKA_URL}/api/items/${ITEM_ID}?${API_AUTH}" \
        -H "Content-Type: application/json" \
        -d "{\"o:site\": [{\"o:id\": ${SITE_ID}}]}" -o /dev/null

    # Also assign existing sample items to the site
    for id in 1 3 5; do
        curl -s -X PATCH "${OMEKA_URL}/api/items/${id}?${API_AUTH}" \
            -H "Content-Type: application/json" \
            -d "{\"o:site\": [{\"o:id\": ${SITE_ID}}]}" -o /dev/null 2>/dev/null
    done

    # Create welcome page
    PAGE_RESPONSE=$(curl -s -X POST "${OMEKA_URL}/api/site_pages?${API_AUTH}" \
        -H "Content-Type: application/json" \
        -d "{
            \"o:title\": \"Welcome\",
            \"o:slug\": \"home\",
            \"o:site\": {\"o:id\": ${SITE_ID}},
            \"o:is_public\": true,
            \"o:block\": [
                {\"o:layout\": \"html\", \"o:data\": {\"html\": \"<h2>eXeLearning Content Demo</h2><p>This site demonstrates the eXeLearning plugin for Omeka S.</p>\"}},
                {\"o:layout\": \"itemShowcase\", \"o:data\": {}, \"o:attachment\": [{\"o:item\": {\"o:id\": ${ITEM_ID}}}]}
            ]
        }")
    PAGE_ID=$(echo "$PAGE_RESPONSE" | python3 -c "import sys,json; print(json.load(sys.stdin).get('o:id',''))" 2>/dev/null || echo "")

    # Create browse items page
    BROWSE_RESPONSE=$(curl -s -X POST "${OMEKA_URL}/api/site_pages?${API_AUTH}" \
        -H "Content-Type: application/json" \
        -d "{
            \"o:title\": \"Browse Items\",
            \"o:slug\": \"items\",
            \"o:site\": {\"o:id\": ${SITE_ID}},
            \"o:is_public\": true,
            \"o:block\": [
                {\"o:layout\": \"browsePreview\", \"o:data\": {\"resource_type\": \"items\", \"query\": \"\", \"limit\": \"12\", \"link-text\": \"Browse all\", \"heading\": \"Items\"}}
            ]
        }")
    BROWSE_ID=$(echo "$BROWSE_RESPONSE" | python3 -c "import sys,json; print(json.load(sys.stdin).get('o:id',''))" 2>/dev/null || echo "")

    # Set navigation
    NAV="["
    if [ -n "$PAGE_ID" ]; then
        NAV="${NAV}{\"type\":\"page\",\"data\":{\"id\":${PAGE_ID},\"label\":\"Welcome\"}}"
    fi
    if [ -n "$BROWSE_ID" ]; then
        [ -n "$PAGE_ID" ] && NAV="${NAV},"
        NAV="${NAV}{\"type\":\"page\",\"data\":{\"id\":${BROWSE_ID},\"label\":\"Browse Items\"}}"
    fi
    NAV="${NAV}]"

    HOMEPAGE=""
    if [ -n "$PAGE_ID" ]; then
        HOMEPAGE=",\"o:homepage\":{\"o:id\":${PAGE_ID}}"
    fi

    curl -s -X PATCH "${OMEKA_URL}/api/sites/${SITE_ID}?${API_AUTH}" \
        -H "Content-Type: application/json" \
        -d "{\"o:navigation\":${NAV}${HOMEPAGE}}" -o /dev/null

    echo "  Site configured with navigation"
fi

echo ""
echo "=== Seed complete ==="
echo ""
echo "Admin:  ${OMEKA_URL}/admin"
echo "  admin@example.com / PLEASE_CHANGEME"
echo "  editor@example.com / 1234"
if [ -n "$SITE_ID" ]; then
    echo ""
    echo "Public site: ${OMEKA_URL}/s/exelearning-demo"
fi
if [ -n "$MEDIA_ID" ]; then
    echo "Media view:  ${OMEKA_URL}/admin/media/${MEDIA_ID}"
fi
