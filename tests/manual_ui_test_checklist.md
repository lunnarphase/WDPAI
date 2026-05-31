# Manualne Testy UI - Pelna Lista Na Oddanie

## Jak raportowac wynik kazdego testu
Uzyj ponizszego formatu do opisu wykonanego testu:

| Pole | Co wpisac |
|---|---|
| ID testu | np. A-01 |
| Rola | guest/patient/doctor/admin |
| Warunki wstepne | np. zalogowany jako doctor |
| Kroki | 1..n krokow odtworzenia |
| Oczekiwany rezultat | co powinno sie stac |
| Rzeczywisty rezultat | co faktycznie sie stalo |
| Status | PASS/FAIL |
| Dowod | screenshot + ewentualnie response z DevTools |

## Preconditions
- Aplikacja uruchomiona: docker-compose up -d --build
- Wejscie przez: https://localhost:8443
- Dostepne konta: patient, doctor, admin
- Otwarty DevTools: zakladki Network + Console

## Pakiet krytyczny na odbior (najpierw wykonaj)
Te testy zwykle prowadzacy sprawdza najszybciej:

- x A-01, A-04, A-06, A-08, A-09
- x R-01, R-02, R-03, R-07
- x P-01, P-03, P-05, P-08
- x N-01, N-03, N-05
- x D-01, D-03, D-06
- x M-01, M-03, M-04, M-08
- x E-01, E-02, E-03, E-05
- U-01, U-02
- S-01, S-02, S-05

## A. Logowanie / sesja / rejestracja
| ID | Rola | Kroki | Oczekiwany rezultat |
|---   |---    |---|---|
x | A-01 | guest   | Otworz /login | Formularz logowania widoczny, status 200 |
x | A-02 | guest   | Otworz /register | Formularz rejestracji widoczny, status 200 |
x | A-03 | guest   | Login: puste pola | Walidacja blokuje wyslanie |
x | A-04 | guest   | Login: bledny email lub haslo | Komunikat bledu, brak logowania |
x | A-05 | guest   | Login: niepoprawny format email | Komunikat walidacji |
x | A-06 | patient | Login poprawny | Przekierowanie na /dashboard |
x | A-07 | doctor  | Login poprawny | Przekierowanie na /doctor-dashboard |
x | A-08 | admin   | Login poprawny | Przekierowanie na /admin-dashboard |
x | A-09 | patient/doctor/admin | Kliknij Wyloguj | Sesja usunieta, powrot na /login |
x | A-10 | guest   | Otworz /dashboard bez logowania | Przekierowanie do /login (302) |
x | A-11 | guest   | Otworz /doctor-dashboard bez logowania | Brak dostepu, przekierowanie/401/403 zgodnie z flow |
x | A-12 | guest   | Otworz /admin-dashboard bez logowania | Brak dostepu, przekierowanie/401/403 zgodnie z flow |
x | A-13 | guest   | Rejestracja: slabe haslo | Komunikat o polityce hasla |
x | A-14 | guest   | Rejestracja: hasla niezgodne | Komunikat o niezgodnosci hasel |
x |  A-15 | guest   | Rejestracja: poprawne dane | Konto utworzone, mozliwe logowanie |

## R. Edge case dostepu i tras (RBAC)
| ID | Rola | Kroki | Oczekiwany rezultat |
|---|---|---|---|
x | R-01 | patient | Recznie wejdz na /admin-dashboard | 403 lub brak dostepu |
x | R-02 | patient | Recznie wejdz na /doctor-dashboard | 403 lub brak dostepu |
x | R-03 | doctor | Recznie wejdz na /admin-dashboard | 403 lub brak dostepu |
x |  R-04 | admin | Recznie wejdz na /doctor-dashboard | Brak dostepu jesli rola != doctor |
x | R-05 | admin | Recznie wejdz na /dashboard | Widok dziala tylko gdy logika na to pozwala, bez crasha |
| R-06 | guest | GET /api-search-doctors (Accept: application/json) | 401 JSON |
| R-07 | guest | GET /api-get-notifications-unread | 401 JSON |
| R-08 | guest | GET /api-get-notifications | 401 JSON |
| R-09 | guest | GET /api-mark-notifications-read | 401 JSON lub 405 zgodnie z kontrolerem |
| R-10 | doctor | Wywolaj endpoint admin API | 403/401, brak danych |
| R-11 | patient | Wywolaj endpoint doctor API | 403/401, brak danych |
| R-12 | guest | Otworz nieistniejaca trase /invalid-route-123 | Strona 404 |

## P. Scenariusze pacjenta (UI)
| ID | Rola | Kroki | Oczekiwany rezultat |
|---|---|---|---|
x | P-01 | patient | Otworz /dashboard | Panel laduje sie poprawnie |
x | P-02 | patient | Wyszukaj lekarza po nazwie/specjalizacji | Lista filtrowana poprawnie |
x | P-03 | patient | Otworz modal profilu lekarza | Dane, opinie, podsumowanie wyswietlone |
x| P-04 | patient | Wyszukaj lekarza po frazie bez wynikow | Komunikat o braku wynikow |
x| P-05 | patient | Zarezerwuj wolny termin | Sukces + wizyta widoczna |
x| P-06 | patient | Probuj zarezerwowac bez daty/godziny | Komunikat bledu |
x| P-07 | patient | Otworz /my-appointments | Lista wizyt laduje sie |
x| P-08 | patient | Anuluj wizyte | Status zmienia sie na cancelled |
x| P-09 | patient | Edytuj profil (email + username) | Zmiany zapisane |
x| P-10 | patient | Edytuj profil z blednym emailem | Komunikat walidacji |
x| P-11 | patient | Zmien haslo na slabe | Komunikat walidacji hasla |
x| P-12 | patient | Zmien haslo na poprawne | Sukces i mozliwy ponowny login nowym haslem |
x| P-13 | patient | Dodaj opinie po zakonczonej wizycie | Opinia zapisana |
x| P-14 | patient | Sprobuj dodac druga opinie do tej samej wizyty | Blokada/komunikat 409 |

## N. Powiadomienia (UI + edge)
| ID | Rola | Kroki | Oczekiwany rezultat |
|---|---|---|---|
x| N-01 | patient | Otworz modal powiadomien | Lista powiadomien laduje sie |
x| N-02 | patient | Zamknij modal i odczekaj polling | Badge odswieza sie bez pelnej listy |
x| N-03 | patient | Uzyj "oznacz jako przeczytane" | Unread count maleje |
x| N-04 | patient | Usun jedno powiadomienie | Element znika z listy |
x| N-05 | patient | Uzyj "wyczysc wszystkie" | Lista pusta, unread=0 |
x| N-06 | patient | Odswiez strone po N-05 | Stan powiadomien pozostaje zgodny |
x| N-07 | patient | Usun to samo powiadomienie 2x (drugi raz przez UI/devtools) | Druga operacja nie psuje UI, blad kontrolowany |
x| N-08 | doctor | Otworz modal powiadomien | Lista i badge dzialaja |
x| N-09 | doctor | Mark as read w panelu lekarza | Badge aktualizuje sie |
x| N-10 | admin | Otworz modal notyfikacji admina | Lista + unread_count laduja sie |
x| N-11 | admin | Otworz szczegoly alertu ataku (jesli jest) | Modal szczegolow bez bledow JS |
x| N-12 | admin | Usun/wyczysc notyfikacje admina | Akcja trwa bez regresji UI |

## D. Scenariusze lekarza (UI)
| ID | Rola | Kroki | Oczekiwany rezultat |
|---|---|---|---|
x| D-01 | doctor | Otworz /doctor-dashboard | Panel lekarza laduje sie |
x| D-02 | doctor | Sprawdz sekcje stats/lista wizyt | Dane sa czytelne i spojne |
x| D-03 | doctor | Zapisz tygodniowa dostepnosc | Zakres zapisany i odtwarzalny po odswiezeniu |
x| D-04 | doctor | Dodaj zakres z start >= end | Walidacja odrzuca dane |
x| D-05 | doctor | Dodaj zakres dla dat z przeszlosci | UI/API nie psuje sie; przeszle daty pomijane |
x| D-06 | doctor | Utworz szablon grafiku | Szablon zapisany |
x| D-07 | doctor | Usun szablon grafiku | Szablon znika z listy |
x| D-08 | doctor | Zmien status wizyty na completed + zalecenia | Zmiany utrwalone |
x| D-09 | doctor | Zmien status wizyty bez appointment_id | Blad kontrolowany, brak crasha |
x| D-10 | doctor | Edytuj profil lekarza (bio/cena/czas) | Zmiany zapisane |
x| D-11 | doctor | Wyszukaj terminy i dostepne daty przez UI | Widoczne tylko dostepne sloty |
x| D-12 | doctor | Po D-08 zweryfikuj po stronie patient notyfikacje | Powiadomienie pojawia sie poprawnie |

## M. Scenariusze admina (UI)
| ID | Rola | Kroki | Oczekiwany rezultat |
|---|---|---|---|
x| M-01 | admin | Otworz /admin-dashboard | Panel admina laduje sie poprawnie |
x| M-02 | admin | Dodaj uzytkownika patient z PESEL | Uzytkownik dodany |
x| M-03 | admin | Dodaj usera z blednym emailem | Walidacja odrzuca |
x| M-04 | admin | Dodaj usera ze slabym haslem | Walidacja odrzuca |
x| M-05 | admin | Edytuj dane istniejacego usera | Zmiany zapisane |
x| M-06 | admin | Edytuj usera z blednym emailem | Odrzucenie danych |
x| M-07 | admin | Sprobuj usunac samego siebie | Operacja zablokowana |
x| M-08 | admin | Sprobuj usunac ostatniego admina | Operacja zablokowana |
x| M-09 | admin | Usun zwyklego usera | User usuniety z listy |
x| M-10 | admin | Zablokuj konto usera | Flaga blokady aktywna |
x| M-11 | admin | Odblokuj konto usera | Konto odblokowane + alerty wyczyszczone |
x| M-12 | admin | Usun opinie przez panel admina | Opinia usunieta |
x| M-13 | admin | Odrzuc zgloszenie opinii z uzasadnieniem | Status reportu zmieniony |
x| M-14 | admin | Sprobuj odrzucic report bez uzasadnienia | Walidacja odrzuca |
x | M-15 | admin | Otworz logi bezpieczenstwa i notyfikacje | Dane laduja sie bez bledow |

## E. Strony bledow i trasy
| ID | Rola | Kroki | Oczekiwany rezultat |
|---|---|---|---|
| E-01 | guest | Otworz /invalid-route-123 | Widok 404 |
| E-02 | guest | Wywolaj nieistniejace API /api-xyz (Accept JSON) | JSON 404 |
| E-03 | guest | Wejdz na chroniona strone HTML bez sesji | Redirect 302 do /login |
| E-04 | guest | Wywolaj chronione API bez sesji | JSON 401 |
| E-05 | patient | Wejdz na trase admina | 403 |
| E-06 | patient | Wejdz na trase lekarza | 403 |
| E-07 | doctor | Wejdz na trase admina | 403 |
| E-08 | admin | Uzyj nieprawidlowej metody dla endpointu API (np. GET zamiast POST) | 405 |
| E-09 | guest | Wywolaj endpoint oczekujacy JSON bez JSON body | 400 |
| E-10 | dowolna | Sprawdz brak bialych stron po bledzie | Kontrolowany komunikat/strona bledu |

## U. Responsywnosc i UX
| ID | Rola | Kroki | Oczekiwany rezultat |
|---|---|---|---|
| U-01 | dowolna | Widok 375px: login, dashboard, doctor-dashboard | Brak nachodzenia elementow |
| U-02 | dowolna | Widok 768px: admin-dashboard | Tabele i modale czytelne |
| U-03 | dowolna | Widok >=1280px: wszystkie glowne strony | Layout stabilny |
| U-04 | dowolna | Otwieranie/zamykanie modalow | Brak zawieszen i przeskokow |
| U-05 | dowolna | Nawigacja miedzy glow. widokami | Linki i przyciski dzialaja |
| U-06 | dowolna | Odswiez strone na aktywnej sesji | Stan zgodny, bez wylogowania |
| U-07 | dowolna | Odswiez strone po wylogowaniu | Brak dostepu do paneli |
| U-08 | dowolna | Kopiuj/wklej URL chronionej podstrony do nowej karty | Egzekwowanie sesji i roli |

## S. Security edge cases (manual z UI/DevTools)
| ID | Rola | Kroki | Oczekiwany rezultat |
|---|---|---|---|
x| S-01 | guest | 3-5 blednych loginow z jednego IP | Widoczna eskalacja komunikatow o bezpieczenstwie |
x| S-02 | admin | Po S-01 sprawdz panel security/logs | Widoczne wpisy podejrzanej aktywnosci |
| S-03 | guest | Sprobuj wykonac POST API bez wymaganych pol | 400/blad walidacji |
| S-04 | patient | Sprobuj usunac notyfikacje innego usera (devtools) | Brak usuniecia, kontrolowany blad |
x| S-05 | patient | Sprobuj wywolac endpoint admin block/unblock | 403/401 |
| S-06 | doctor | Sprobuj wywolac endpoint admin review resolve | 403/401 |
| S-07 | guest | Recznie ustaw Accept: application/json dla blednej trasy | Zwracany JSON, nie HTML |
| S-08 | dowolna | Sprawdz, czy po logout endpointy nadal sa chronione | Brak dostepu |
| S-09 | dowolna | Sprawdz Console podczas krytycznych akcji (save/delete) | Brak uncaught exception |
| S-10 | dowolna | Sprawdz Network: brak lawiny requestow po 2-3 min | Polling w kontrolowanych interwalach |

## Kryterium zaliczenia testow manualnych
- Must pass: wszystkie testy z "Pakiet krytyczny na odbior"
- Recommended: min. 85% PASS z pelnej listy
- Zero tolerancji: brak FAIL w testach A-06/A-08/R-01/R-03/E-01/E-02/M-07/M-08

## Miejsce na finalne podsumowanie (po wykonaniu)
- Liczba testow wykonanych: ___
- PASS: ___
- FAIL: ___
- BLOKERY: ___
- Decyzja: GOTOWE DO ODDANIA / WYMAGA POPRAWEK
