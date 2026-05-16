$BaseUrl = "http://localhost:8080"

Write-Host "Running Integration Tests via PowerShell..."

# 1. Test the dashboard
try {
    $res = Invoke-WebRequest -Uri "$BaseUrl/dashboard" -MaximumRedirection 0 -ErrorAction SilentlyContinue
    $status = $res.StatusCode
} catch {
    $status = $_.Exception.Response.StatusCode.Value__
}
if ($status -eq 200 -or $status -eq 302) {
    Write-Host "✅ [PASS] GET /dashboard : Return code $status" -ForegroundColor Green
} else {
    Write-Host "❌ [FAIL] GET /dashboard : Unexpected code $status" -ForegroundColor Red
}

# 2. Test Fetch API endpoint
try {
    $res = Invoke-RestMethod -Uri "$BaseUrl/api-search-doctors?q=Jan" -Method Get -ErrorAction Stop
    Write-Host "✅ [PASS] GET /api-search-doctors : Data retrieved successfully" -ForegroundColor Green
} catch {
    Write-Host "❌ [FAIL] GET /api-search-doctors : Failed" -ForegroundColor Red
}

# 3. Test 404
try {
    $res = Invoke-WebRequest -Uri "$BaseUrl/invalid-route-123" -ErrorAction Stop
    Write-Host "❌ [FAIL] GET /invalid-route-123 : Expected 404 but got Success" -ForegroundColor Red
} catch {
    if ($_.Exception.Response.StatusCode.value__ -eq 404) {
        Write-Host "✅ [PASS] GET /invalid-route-123 : Return code 404 handled perfectly" -ForegroundColor Green
    } else {
        Write-Host "❌ [FAIL] GET /invalid-route-123 : Unexpected error" -ForegroundColor Red
    }
}
