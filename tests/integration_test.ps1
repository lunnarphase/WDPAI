$BaseUrl = if ($env:BASE_URL) { $env:BASE_URL } else { "https://localhost:8443" }
$Passed = 0
$Failed = 0

Write-Host "Running Integration Tests via PowerShell against $BaseUrl"

function Get-StatusCode {
    param(
        [Parameter(Mandatory = $true)] [string] $Uri,
        [string] $Accept = "",
        [string] $Method = "GET",
        [string] $ContentType = "",
        [string] $Body = ""
    )

    $args = @("-k", "-s", "-o", "NUL", "-w", "%{http_code}", "-X", $Method)
    if ($Accept -ne "") {
        $args += @("-H", "Accept: $Accept")
    }

    if ($ContentType -ne "") {
        $args += @("-H", "Content-Type: $ContentType")
    }

    if ($Body -ne "") {
        $args += @("--data", $Body)
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
        [string] $Accept = "",
        [string] $Method = "GET",
        [string] $ContentType = "",
        [string] $Body = ""
    )

    $status = Get-StatusCode -Uri $Uri -Accept $Accept -Method $Method -ContentType $ContentType -Body $Body

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
Assert-StatusCode -Name "GET /api-get-my-reviews" -Uri "$BaseUrl/api-get-my-reviews" -Expected 401 -Accept "application/json"
Assert-StatusCode -Name "GET /api-get-slots" -Uri "$BaseUrl/api-get-slots?doctor_id=1&date=2026-06-15" -Expected 401 -Accept "application/json"
Assert-StatusCode -Name "GET /api-get-available-dates" -Uri "$BaseUrl/api-get-available-dates?doctor_id=1&start_date=2026-06-15&end_date=2026-06-21" -Expected 401 -Accept "application/json"
Assert-StatusCode -Name "POST /doctor-availability" -Uri "$BaseUrl/doctor-availability" -Expected 401 -Accept "application/json" -Method "POST" -ContentType "application/json" -Body "{}"
Assert-StatusCode -Name "GET /invalid-route-123" -Uri "$BaseUrl/invalid-route-123" -Expected 404
Assert-StatusCode -Name "GET /api-route-that-does-not-exist" -Uri "$BaseUrl/api-route-that-does-not-exist" -Expected 404 -Accept "application/json"
Assert-StatusCode -Name "POST /confirm-appointment" -Uri "$BaseUrl/confirm-appointment" -Expected 302 -Method "POST"
Assert-StatusCode -Name "POST /api-update-profile" -Uri "$BaseUrl/api-update-profile" -Expected 401 -Accept "application/json" -Method "POST" -ContentType "application/json" -Body "{}"
Assert-StatusCode -Name "POST /api-change-password" -Uri "$BaseUrl/api-change-password" -Expected 401 -Accept "application/json" -Method "POST" -ContentType "application/json" -Body "{}"
Assert-StatusCode -Name "POST /submit-review" -Uri "$BaseUrl/submit-review" -Expected 401 -Accept "application/json" -Method "POST" -ContentType "application/json" -Body "{}"
Assert-StatusCode -Name "POST /admin-delete-review" -Uri "$BaseUrl/admin-delete-review" -Expected 401 -Accept "application/json" -Method "POST" -ContentType "application/json" -Body "{}"

Write-Host "Summary: $Passed passed, $Failed failed"

if ($Failed -gt 0) {
    exit 1
}

exit 0
