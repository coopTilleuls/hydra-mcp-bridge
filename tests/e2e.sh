#!/usr/bin/env bash
# E2E test for the Hydra MCP Gateway — lazy discovery model
# Requires: the gateway running on https://127.0.0.1:8000 and the demo API on https://localhost
set -euo pipefail

BASE="https://127.0.0.1:8000/mcp"
PASS=0
FAIL=0

ok() { echo "  PASS: $1"; PASS=$((PASS + 1)); }
fail() { echo "  FAIL: $1"; FAIL=$((FAIL + 1)); }
assert_contains() { echo "$1" | grep -q "$2" && ok "$3" || fail "$3 (expected '$2')"; }
assert_not_contains() { echo "$1" | grep -q "$2" && fail "$3 (unexpected '$2')" || ok "$3"; }

mcp() {
  local sid="$1" id="$2" body="$3"
  timeout 10 curl -sk -X POST "$BASE" \
    -H 'Content-Type: application/json' \
    -H "Mcp-Session-Id: $sid" \
    -d "$body" 2>&1
}

# Helper: read session data via jq from the MCP session file
session_get() {
  local sid="$1" path="$2"
  jq -r "$path" "var/cache/dev/mcp/$sid" 2>/dev/null
}

session_file_exists() {
  [ -f "var/cache/dev/mcp/$1" ]
}

echo "=== Hydra MCP Gateway E2E Tests (Lazy Discovery) ==="
echo ""

# 1. Initialize
echo "[1] Initialize"
INIT_RESP=$(timeout 5 curl -sk -D- -X POST "$BASE" \
  -H 'Content-Type: application/json' \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"e2e-test","version":"1.0"}}}' 2>&1)
SID=$(echo "$INIT_RESP" | grep -i '^mcp-session-id:' | sed 's/.*: //' | tr -d '\r\n')
[ -n "$SID" ] && ok "Session ID returned: ${SID:0:8}..." || fail "No session ID"
echo "$INIT_RESP" | grep -q '"serverInfo"' && ok "Server info present" || fail "Server info missing"
echo ""

# 2. tools/list (static only — should be exactly 1: read_api_resource)
echo "[2] tools/list (static tools)"
TOOLS=$(mcp "$SID" 2 '{"jsonrpc":"2.0","id":2,"method":"tools/list","params":{}}')
assert_contains "$TOOLS" "read_api_resource" "read_api_resource tool exists"
assert_not_contains "$TOOLS" "invoke_hydra_operation" "invoke_hydra_operation removed"
TOOL_COUNT=$(echo "$TOOLS" | python3 -c "import sys,json; print(len(json.load(sys.stdin)['result']['tools']))" 2>/dev/null)
[ "$TOOL_COUNT" = "1" ] && ok "Exactly 1 static tool" || fail "Expected 1 tool, got $TOOL_COUNT"
echo ""

# 3. read_api_resource("/") — entrypoint, Phase 1: awareness only, no tool registration
echo "[3] read_api_resource (/) - entrypoint (Phase 1: awareness only)"
ENTRY=$(mcp "$SID" 3 '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"read_api_resource","arguments":{"uri":"/"}}}')
# docs.jsonld URL should be cached in session, but NO affordances yet
DOCS_URL=$(session_get "$SID" '.hydra.docs_url // empty')
[ -n "$DOCS_URL" ] && ok "docs.jsonld URL cached: $DOCS_URL" || fail "docs.jsonld URL not cached"
AFFORDANCES_COUNT=$(session_get "$SID" '.hydra.affordances | length // 0')
[ "$AFFORDANCES_COUNT" = "0" ] || [ "$AFFORDANCES_COUNT" = "null" ] && ok "No affordances yet (lazy)" || fail "Affordances created too early ($AFFORDANCES_COUNT)"
echo ""

# 4. tools/list (after entrypoint — still only 1 static tool)
echo "[4] tools/list (after entrypoint - still no dynamic tools)"
TOOLS2=$(mcp "$SID" 4 '{"jsonrpc":"2.0","id":4,"method":"tools/list","params":{}}')
TOOL_COUNT2=$(echo "$TOOLS2" | python3 -c "import sys,json; print(len(json.load(sys.stdin)['result']['tools']))" 2>/dev/null)
[ "$TOOL_COUNT2" = "1" ] && ok "Still exactly 1 tool (no eager discovery)" || fail "Expected 1 tool, got $TOOL_COUNT2"
echo ""

# 5. read_api_resource("/books") — collection visit triggers Phase 2
echo "[5] read_api_resource (/books) - collection visit (Phase 2: per-class discovery)"
BOOKS=$(mcp "$SID" 5 '{"jsonrpc":"2.0","id":5,"method":"tools/call","params":{"name":"read_api_resource","arguments":{"uri":"/books"}}}')
# Affordances should now exist in session with book-specific tools
AFFORDANCES_COUNT=$(session_get "$SID" '.hydra.affordances | length')
[ "$AFFORDANCES_COUNT" -ge "1" ] 2>/dev/null && ok "Affordances created at collection visit ($AFFORDANCES_COUNT)" || fail "No affordances after collection visit"
# docs.jsonld should be cached after first fetch
DOCS_CACHE=$(session_get "$SID" '.hydra.docs_cache | length')
[ "$DOCS_CACHE" -ge "1" ] 2>/dev/null && ok "docs.jsonld cached after first fetch" || fail "docs.jsonld not cached"
# Should have book CRUD tools
AFFORDANCES=$(session_get "$SID" '.hydra.affordances')
assert_contains "$AFFORDANCES" "post_" "POST operation discovered for books (tool name from hydra:title)"
assert_contains "$AFFORDANCES" "search_" "Search tool discovered from hydra:search"
echo ""

# 6. tools/list (after collection visit — should have dynamic tools for books)
echo "[6] tools/list (after collection visit - book tools present)"
TOOLS3=$(mcp "$SID" 6 '{"jsonrpc":"2.0","id":6,"method":"tools/list","params":{}}')
assert_contains "$TOOLS3" "search_" "Search tool present"
TOOL_COUNT3=$(echo "$TOOLS3" | python3 -c "import sys,json; print(len(json.load(sys.stdin)['result']['tools']))" 2>/dev/null)
[ "$TOOL_COUNT3" -ge "3" ] && ok "$TOOL_COUNT3 tools total (1 static + dynamic)" || fail "Expected >= 3 tools, got $TOOL_COUNT3"
echo ""

# 7. Verify create tool has typed input schema (not generic payload)
echo "[7] create tool input schema"
session_get "$SID" '.hydra.affordances' | python3 -c "
import sys, json
data = json.load(sys.stdin)
# Find any post_ tool (tool name from hydra:title, e.g. postBook -> post_book)
creates = [a for a in data if a['name'].startswith('post_')]
if creates:
    tool = creates[0]
    props = tool['inputSchema'].get('properties', {})
    no_payload = 'payload' not in props
    has_props = len(props) > 0
    print('TYPED_SCHEMA=' + ('yes' if no_payload and has_props else 'no'))
    print('TOOL=' + tool['name'])
else:
    print('TYPED_SCHEMA=no')
" 2>/dev/null | grep -q "TYPED_SCHEMA=yes" && ok "Create tool has typed schema (no generic payload)" || fail "Create tool missing typed schema"
echo ""

# 8. Test a typed dynamic mutation (create via dynamic tool instead of invoke_hydra_operation)
echo "[8] Typed dynamic mutation"
# Find the create tool name from affordances
CREATE_TOOL=$(session_get "$SID" '.hydra.affordances' | python3 -c "
import sys, json
data = json.load(sys.stdin)
creates = [a for a in data if a['name'].startswith('post_')]
print(creates[0]['name'] if creates else '')
" 2>/dev/null)
if [ -n "$CREATE_TOOL" ]; then
    ok "Found POST tool: $CREATE_TOOL"
    POST_RESULT=$(mcp "$SID" 8 "{\"jsonrpc\":\"2.0\",\"id\":8,\"method\":\"tools/call\",\"params\":{\"name\":\"$CREATE_TOOL\",\"arguments\":{\"title\":\"E2E Test Book\",\"author\":\"Test\"}}}")
    # Check it reaches the API (may get error depending on validation, but not a framework error)
    assert_not_contains "$POST_RESULT" "No affordance config" "Dynamic tool handler found the tool"
else
    fail "No POST tool found in affordances"
fi
echo ""

# 9. Persistence
echo "[9] Persistence"
session_file_exists "$SID" && ok "Session file persisted" || fail "Session file missing"
PERSISTED_AFFORDANCES=$(session_get "$SID" '.hydra.affordances')
assert_contains "$PERSISTED_AFFORDANCES" "search_" "search tool in persisted data"
echo ""

# 10. Visit another collection — discover new tools for that class only
echo "[10] read_api_resource (/parchments) - second collection (Phase 2 for different class)"
PARCHMENTS=$(mcp "$SID" 10 '{"jsonrpc":"2.0","id":10,"method":"tools/call","params":{"name":"read_api_resource","arguments":{"uri":"/parchments"}}}')
AFFORDANCES2=$(session_get "$SID" '.hydra.affordances')
assert_contains "$AFFORDANCES2" "parchment" "Parchment tools discovered from second collection"
echo ""

# Summary
echo "=== Results: $PASS passed, $FAIL failed ==="
[ "$FAIL" -eq 0 ] && exit 0 || exit 1
