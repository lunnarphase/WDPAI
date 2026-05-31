#!/bin/bash

if [ -z "${BASE_URL:-}" ]; then
    if [ -f "/.dockerenv" ]; then
        BASE_URL="https://nginx"
    else
        BASE_URL="https://localhost:8443"
    fi
fi
PASSED=0
FAILED=0

echo "Running Integration Tests against ${BASE_URL}..."

get_status() {
    local uri="$1"
    local accept="$2"

    if [ -n "$accept" ]; then
        curl -k -o /dev/null -s -H "Accept: ${accept}" -w "%{http_code}\n" "$uri"
    else
        curl -k -o /dev/null -s -w "%{http_code}\n" "$uri"
    fi
}

assert_status() {
    local name="$1"
    local uri="$2"
    local expected="$3"
    local accept="${4:-}"
    local status

    status=$(get_status "$uri" "$accept")

    if [ "$status" -eq "$expected" ]; then
        PASSED=$((PASSED + 1))
        echo "[PASS] ${name} : Return code ${status}"
    else
        FAILED=$((FAILED + 1))
        echo "[FAIL] ${name} : Expected ${expected}, got ${status}"
    fi
}

assert_status "GET /login" "$BASE_URL/login" 200
assert_status "GET /dashboard" "$BASE_URL/dashboard" 302
assert_status "GET /api-search-doctors" "$BASE_URL/api-search-doctors?q=Jan" 401 "application/json"
assert_status "GET /api-get-notifications" "$BASE_URL/api-get-notifications" 401 "application/json"
assert_status "GET /api-get-notifications-unread" "$BASE_URL/api-get-notifications-unread" 401 "application/json"
assert_status "GET /api-mark-notifications-read" "$BASE_URL/api-mark-notifications-read" 401 "application/json"
assert_status "GET /invalid-route-123" "$BASE_URL/invalid-route-123" 404
assert_status "GET /api-route-that-does-not-exist" "$BASE_URL/api-route-that-does-not-exist" 404 "application/json"

echo "Summary: ${PASSED} passed, ${FAILED} failed"

if [ "$FAILED" -gt 0 ]; then
    exit 1
fi

exit 0