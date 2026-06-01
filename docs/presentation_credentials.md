# Konta demonstracyjne

Plik zawiera konta do prezentacji projektu w lokalnym srodowisku developerskim.

## Wspolne hasla

- Admin: `AdminDemo!2026`
- Doctor: `DoctorDemo!2026`
- Patient: `PatientDemo!2026`

## Konto glowne do scenariusza 7-min

| Rola | Imie i nazwisko | Email | Haslo |
|---|---|---|---|
| patient | Adam Kaczmarek | demo.patient.01@medischedule.pl | PatientDemo!2026 |
| doctor | Jan Kowalski | kardiolog@medischedule.pl | DoctorDemo!2026 |
| admin | Administrator | admin@medischedule.pl | AdminDemo!2026 |

## Konta zapasowe

| Rola | Imie i nazwisko | Email | Haslo |
|---|---|---|---|
| patient | Marta Wysocka | demo.patient.02@medischedule.pl | PatientDemo!2026 |
| doctor | Anna Nowak | pediatra@medischedule.pl | DoctorDemo!2026 |
| admin | Paulina Maj | admin2@medischedule.pl | AdminDemo!2026 |

## Reset danych demo (opcjonalnie)

Windows PowerShell:

```powershell
Get-Content ./docker/db/presentation_seed.sql | docker compose exec -T db psql -U docker -d db
```

Linux/macOS:

```bash
docker compose exec -T db psql -U docker -d db < ./docker/db/presentation_seed.sql
```
