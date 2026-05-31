$BaseUrl = if ($env:BASE_URL) { $env:BASE_URL } else { "https://localhost:8443" }
$Passed = 0
$Failed = 0

Write-Host "Running Integration Tests via PowerShell against $BaseUrl"

function Get-StatusCode {
    param(
        [Parameter(Mandatory = $true)] [string] $Uri,
        [string] $Accept = ""
    )

    $args = @("-k", "-s", "-o", "NUL", "-w", "%{http_code}")
    if ($Accept -ne "") {
        $args += @("-H", "Accept: $Accept")
    }
    $args += $Uri

    $status = & curl.exe @args
    if ($LASTEXITCODE -ne 0 -or -not ($status -match '^\d{3}$')) {
        return -1
    }

    return [int]$status
}

function Assert-StatusCode {
    param(
        [Parameter(Mandatory = $true)] [string] $Name,
        [Parameter(Mandatory = $true)] [string] $Uri,
        [Parameter(Mandatory = $true)] [int] $Expected,
        [string] $Accept = ""
    )

    $status = Get-StatusCode -Uri $Uri -Accept $Accept

    if ($status -eq $Expected) {
        $script:Passed++
        Write-Host "[PASS] $Name : Return code $status" -ForegroundColor Green
    } else {
        $script:Failed++
        Write-Host "[FAIL] $Name : Expected $Expected, got $status" -ForegroundColor Red
    }
}

Assert-StatusCode -Name "GET /login" -Uri "$BaseUrl/login" -Expected 200
Assert-StatusCode -Name "GET /dashboard" -Uri "$BaseUrl/dashboard" -Expected 302
Assert-StatusCode -Name "GET /api-search-doctors" -Uri "$BaseUrl/api-search-doctors?q=Jan" -Expected 401 -Accept "application/json"
Assert-StatusCode -Name "GET /api-get-notifications" -Uri "$BaseUrl/api-get-notifications" -Expected 401 -Accept "application/json"
Assert-StatusCode -Name "GET /api-get-notifications-unread" -Uri "$BaseUrl/api-get-notifications-unread" -Expected 401 -Accept "application/json"
Assert-StatusCode -Name "GET /api-mark-notifications-read" -Uri "$BaseUrl/api-mark-notifications-read" -Expected 401 -Accept "application/json"
Assert-StatusCode -Name "GET /invalid-route-123" -Uri "$BaseUrl/invalid-route-123" -Expected 404
Assert-StatusCode -Name "GET /api-route-that-does-not-exist" -Uri "$BaseUrl/api-route-that-does-not-exist" -Expected 404 -Accept "application/json"

Write-Host "Summary: $Passed passed, $Failed failed"

if ($Failed -gt 0) {
    exit 1
}

exit 0
