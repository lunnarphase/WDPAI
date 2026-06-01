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
    local accept="${2:-}"
    local method="${3:-GET}"
    local content_type="${4:-}"
    local body="${5:-}"

    set -- -k -o /dev/null -s -w "%{http_code}\n" -X "$method"

    if [ -n "$accept" ]; then
        set -- "$@" -H "Accept: ${accept}"
    fi

    if [ -n "$content_type" ]; then
        set -- "$@" -H "Content-Type: ${content_type}"
    fi

    if [ -n "$body" ]; then
        set -- "$@" --data "$body"
    fi

    curl "$@" "$uri"
}

assert_status() {
    local name="$1"
    local uri="$2"
    local expected="$3"
    local accept="${4:-}"
    local method="${5:-GET}"
    local content_type="${6:-}"
    local body="${7:-}"
    local status

    status=$(get_status "$uri" "$accept" "$method" "$content_type" "$body")

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
assert_status "GET /api-get-my-reviews" "$BASE_URL/api-get-my-reviews" 401 "application/json"
assert_status "GET /api-get-slots" "$BASE_URL/api-get-slots?doctor_id=1&date=2026-06-15" 401 "application/json"
assert_status "GET /api-get-available-dates" "$BASE_URL/api-get-available-dates?doctor_id=1&start_date=2026-06-15&end_date=2026-06-21" 401 "application/json"
assert_status "POST /doctor-availability" "$BASE_URL/doctor-availability" 401 "application/json" "POST" "application/json" "{}"
assert_status "GET /invalid-route-123" "$BASE_URL/invalid-route-123" 404
assert_status "GET /api-route-that-does-not-exist" "$BASE_URL/api-route-that-does-not-exist" 404 "application/json"
assert_status "POST /confirm-appointment" "$BASE_URL/confirm-appointment" 302 "" "POST"
assert_status "POST /api-update-profile" "$BASE_URL/api-update-profile" 401 "application/json" "POST" "application/json" "{}"
assert_status "POST /api-change-password" "$BASE_URL/api-change-password" 401 "application/json" "POST" "application/json" "{}"
assert_status "POST /submit-review" "$BASE_URL/submit-review" 401 "application/json" "POST" "application/json" "{}"
assert_status "POST /admin-delete-review" "$BASE_URL/admin-delete-review" 401 "application/json" "POST" "application/json" "{}"

echo "Summary: ${PASSED} passed, ${FAILED} failed"

if [ "$FAILED" -gt 0 ]; then
    exit 1
fi

exit 0