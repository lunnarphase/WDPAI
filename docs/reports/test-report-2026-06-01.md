# Test Report - 2026-06-01

## Scope

Raport dokumentuje najnowszy rerun testow automatycznych po finalnych poprawkach dokumentacji i cleanupie kodu.

## Environment

- OS host: Windows
- Runtime app: Docker Compose (`nginx`, `php`, `db`, `pgadmin`)
- App URL: `https://localhost:8443`

## Executed Commands

PHPUnit (container `php`):

```bash
docker compose exec php sh -lc "wget -q -O /tmp/phpunit.phar https://phar.phpunit.de/phpunit-11.phar; php /tmp/phpunit.phar --configuration /app/phpunit.xml"
```

Integration smoke (PowerShell):

```powershell
./tests/integration_test.ps1
```

## Results Summary

- PHPUnit: **70 tests, 176 assertions, OK**
- Integration smoke: **17 passed, 0 failed**

## Raw Result Excerpts

### PHPUnit

```text
PHPUnit 11.5.55 by Sebastian Bergmann and contributors.
Runtime:       PHP 8.3.18RC1
Configuration: /app/phpunit.xml
...
Time: 00:04.851, Memory: 27.45 MB
OK (70 tests, 176 assertions)
```

### Integration smoke

```text
Running Integration Tests via PowerShell against https://localhost:8443
[PASS] GET /login : Return code 200
[PASS] GET /dashboard : Return code 302
[PASS] GET /api-search-doctors : Return code 401
[PASS] GET /api-get-notifications : Return code 401
[PASS] GET /api-get-notifications-unread : Return code 401
[PASS] GET /api-mark-notifications-read : Return code 401
[PASS] GET /api-get-my-reviews : Return code 401
[PASS] GET /api-get-slots : Return code 401
[PASS] GET /api-get-available-dates : Return code 401
[PASS] POST /doctor-availability : Return code 401
[PASS] GET /invalid-route-123 : Return code 404
[PASS] GET /api-route-that-does-not-exist : Return code 404
[PASS] POST /confirm-appointment : Return code 302
[PASS] POST /api-update-profile : Return code 401
[PASS] POST /api-change-password : Return code 401
[PASS] POST /submit-review : Return code 401
[PASS] POST /admin-delete-review : Return code 401
Summary: 17 passed, 0 failed
```

## Notes

- Wyniki 2026-06-01 sa aktualnym stanem referencyjnym do oddania projektu.
- Archiwalne raporty coverage z 2026-05-31 pozostaja dostepne w `tests/reports/`.
