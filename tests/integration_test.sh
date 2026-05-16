#!/bin/bash

# Simple integration test using cURL
# Test checking if the backend APIs are up and returning expected status codes.

BASE_URL="http://localhost"

echo "Running Integration Tests..."

# 1. Test the main dashboard (Should be 302 or 200)
STATUS=$(curl -o /dev/null -s -w "%{http_code}\n" $BASE_URL/dashboard)
if [ "$STATUS" -eq 200 ] || [ "$STATUS" -eq 302 ]; then
    echo "✅ [PASS] GET /dashboard : Return code $STATUS"
else
    echo "❌ [FAIL] GET /dashboard : Unexpected code $STATUS"
fi

# 2. Test Fetch API endpoint for missing auth or JSON
API_STATUS=$(curl -o /dev/null -s -w "%{http_code}\n" $BASE_URL/api-search-doctors?q=Jan)
if [ "$API_STATUS" -eq 200 ]; then
    echo "✅ [PASS] GET /api-search-doctors : Return code $API_STATUS"
else
    echo "❌ [FAIL] GET /api-search-doctors : Unexpected code $API_STATUS"
fi

# 3. Test a non-existent path (404 Not Found)
ERROR_STATUS=$(curl -o /dev/null -s -w "%{http_code}\n" $BASE_URL/invalid-route-123)
if [ "$ERROR_STATUS" -eq 404 ]; then
    echo "✅ [PASS] GET /invalid-route-123 : Return code 404 handled perfectly"
else
    echo "❌ [FAIL] GET /invalid-route-123 : Expected 404, got $ERROR_STATUS"
fi

echo "All tests finished!"