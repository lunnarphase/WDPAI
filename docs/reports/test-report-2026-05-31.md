# Test Report - 2026-05-31

## Scope

- Unit tests: PHPUnit
- Integration tests: endpoint smoke checks
- Coverage mode: Xdebug line coverage (scoped backend modules)

## Results

- Unit tests: PASS (61 tests, 145 assertions)
- Integration tests: PASS (13 passed, 0 failed)
- Coverage (scoped):
	- Classes: 40.00% (2/5)
	- Methods: 58.82% (40/68)
	- Lines: 44.95% (218/485)

## Commands used

```bash
# Unit tests (inside running PHP container)
docker exec wdpai-php-1 sh -lc "wget -q -O /tmp/phpunit.phar https://phar.phpunit.de/phpunit-11.phar; php /tmp/phpunit.phar --configuration /app/phpunit.xml"

# Unit tests + coverage (inside running PHP container)
docker exec -e XDEBUG_MODE=coverage wdpai-php-1 sh -lc "php /tmp/phpunit.phar --configuration /app/phpunit.xml --coverage-text --coverage-clover /app/tests/reports/coverage_scoped_2026-05-31_152602.xml --coverage-html /app/tests/reports/coverage_scoped_html_2026-05-31_152602"

# Integration tests (PowerShell)
./tests/integration_test.ps1
```

## Evidence files

- tests/reports/phpunit_coverage_scoped_2026-05-31_152602.txt
- tests/reports/coverage_scoped_2026-05-31_152602.xml
- tests/reports/coverage_scoped_html_2026-05-31_152602/index.html
- tests/reports/integration_2026-05-31_151433.txt

## Note

Coverage scope includes modules directly covered by the unit suite:
`AppController`, `AppointmentRepository`, `Repository`, `User`, `UsersRepository`.
