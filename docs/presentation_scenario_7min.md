# Scenariusz prezentacji (ok. 7 minut)

Ten scenariusz jest przygotowany pod szybka prezentacje na zajeciach: role patient + doctor + admin, logika wizyt, moderacja opinii i elementy bezpieczenstwa.

## 0. Przygotowanie (30-60 sekund przed startem)

1. Upewnij sie, ze kontenery dzialaja:

```bash
docker compose up -d --build
```

2. (Opcjonalnie) odswiez dane demo, zeby konta i statusy byly przewidywalne:

Windows PowerShell:

```powershell
Get-Content ./docker/db/presentation_seed.sql | docker compose exec -T db psql -U docker -d db
```

Linux/macOS:

```bash
docker compose exec -T db psql -U docker -d db < ./docker/db/presentation_seed.sql
```

3. Otworz aplikacje: `https://localhost:8443`.
4. Przygotuj 2 karty przegladarki:
- karta A: glowna prezentacja,
- karta B: szybkie testy 401/404 bez sesji (incognito lub wylogowane okno).

Konta: [presentation_credentials.md](presentation_credentials.md)

## 1. Minuta 0:00-1:30 - Pacjent: logowanie i rezerwacja

1. Zaloguj sie jako patient:
- email: `demo.patient.01@medischedule.pl`
- haslo: `PatientDemo!2026`
2. Pokaz dashboard pacjenta i liste wizyt.
3. Przejdz do wyszukiwarki lekarzy, filtruj po specjalizacji (np. Kardiologia).
4. Wybierz wolny termin i potwierdz rezerwacje.

Co podkreslic:
- walidacja terminu i konfliktow slotow,
- role-based dostep do dashboardu pacjenta.

## 2. Minuta 1:30-3:00 - Lekarz: obsluga wizyty

1. Wyloguj sie i zaloguj jako doctor:
- email: `kardiolog@medischedule.pl`
- haslo: `DoctorDemo!2026`
2. Otworz dashboard lekarza.
3. Dla wizyty pacjenta zmien status na `completed` i dodaj zalecenia.

Co podkreslic:
- workflow statusow (`confirmed` -> `completed`),
- zapis zalecen przez lekarza,
- uruchamianie powiadomien po zmianach statusu.

## 3. Minuta 3:00-4:30 - Pacjent: opinia i report

1. Wyloguj lekarza i zaloguj ponownie pacjenta (`demo.patient.01@medischedule.pl`).
2. Otworz zakonczona wizyte i dodaj opinie.
3. Zglos opinie do moderacji (report).

Co podkreslic:
- jedna opinia na jedna wizyte,
- obieg reportu do panelu admina,
- powiadomienia uzytkownika.

## 4. Minuta 4:30-6:00 - Admin: moderacja i bezpieczenstwo

1. Wyloguj pacjenta i zaloguj jako admin:
- email: `admin@medischedule.pl`
- haslo: `AdminDemo!2026`
2. Otworz panel administracyjny i sekcje reportow.
3. Pokaz obsluge reportu (`dismiss` albo `resolve`).
4. Pokaz panel bezpieczenstwa (proby logowan / alerty / blokady).

Co podkreslic:
- rola admina i autoryzacja endpointow,
- moderacja tresci,
- mechanizmy lockout i monitoring prob logowania.

## 5. Minuta 6:00-7:00 - Szybkie dowody bezpieczenstwa

1. W karcie bez sesji wywolaj endpoint API wymagajacy logowania (np. `/api-get-notifications`) i pokaz `401`.
2. Wejdz na nieistniejaca trase (np. `/invalid-route-123`) i pokaz `404`.
3. Podsumuj: MVC + role + bezpieczenstwo + testy automatyczne.

## 6. Plan awaryjny (gdy cos nie zadziala na zywo)

- Jesli nowa rezerwacja nie przejdzie: uzyj istniejacej wizyty pacjenta nr 1 lub konta zapasowego `demo.patient.02@medischedule.pl`.
- Jesli lekarz nie widzi oczekiwanej wizyty: przeloguj na konto zapasowe `pediatra@medischedule.pl`.
- Jesli dane sa niespojne: wykonaj ponownie seed prezentacyjny (sekcja 0).

## 7. Co warto miec otwarte obok prezentacji

- README: [../README.md](../README.md)
- Raport testow (aktualny): [reports/test-report-2026-06-01.md](reports/test-report-2026-06-01.md)
- Konta: [presentation_credentials.md](presentation_credentials.md)
