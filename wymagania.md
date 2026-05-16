# Wymagania Projektowe

## [cite_start]TECHNOLOGIE [cite: 1]
* [cite_start]**Użyte języki/technologie:** Docker, GIT, HTML5, CSS, JavaScript (w tym FETCH API), PHP – obiektowy, baza danych PostgreSQL. [cite: 2]
* [cite_start]**Ograniczenia:** Bez użycia frameworka i gotowych szablonów. [cite: 3]

---

## [cite_start]ARCHITEKTURA APLIKACJI [cite: 4]
* [cite_start]Zastosowana architektura MVC, frontend-backend lub inna – zapewniająca bezpieczeństwo aplikacji. [cite: 5]

---

## [cite_start]DESIGN [cite: 6]
* [cite_start]Aplikacja estetyczna pod względem graficznym. [cite: 7]
* [cite_start]Responsywna, użycie CSS media queries. [cite: 7]

---

## [cite_start]ELEMENTY APLIKACJI [cite: 8]
* [cite_start]Proces logowania, utrzymanie sesji, uprawnienia użytkowników (weryfikowane przez system w trakcie działania/testowania), zarządzanie użytkownikami, wylogowanie + wybrana funkcjonalność w ramach założeń do projektu. [cite: 9]

---

## [cite_start]BAZA DANYCH [cite: 10]
* [cite_start]**Relacje:** Baza danych powinna zawierać relacje między tabelami, w tym wszystkie typy relacji (jeden-do-wielu, wiele-do-wielu, jeden-do-jednego). [cite: 11]
* [cite_start]**Wymagane obiekty bazy danych:** [cite: 12]
  * [cite_start]Minimum 2 widoki (użyte złączenia z kilkoma tabelami) [cite: 13]
  * [cite_start]Minimum 1 wyzwalacz [cite: 14]
  * [cite_start]Minimum 1 funkcja [cite: 15]
* **Transakcje i normalizacja:**
  * [cite_start]Transakcje na odpowiednim poziomie izolacji [cite: 16]
  * [cite_start]Akcje na referencjach klucz główny - klucz obcy (użycie zapytań z JOIN) – spełnione 3 postacie normalne. [cite: 17]
* **Integralność danych:**
  * [cite_start]W bazie nie może występować redundancja danych, anomalia modyfikacji i usunięć. [cite: 18]
  * [cite_start]Należy zastosować odpowiednie typy danych dla przechowywanych danych w tabelach. [cite: 19]
* [cite_start]**Eksport:** Należy dołączyć kompletną bazę danych wraz z przykładowymi danymi wyeksportowaną do pliku SQL. [cite: 20]

---

## [cite_start]DOKUMENTACJA [cite: 21]
[cite_start]Należy dostarczyć dokumentację w pliku `readme.md`, zawierającą: [cite: 22]
* **Diagram ERD:** np. [cite_start]PNG/SVG w repozytorium i link do źródła (np. draw.io). [cite: 23]
* [cite_start]**Screeny aplikacji:** wersja webowa oraz mobilna. [cite: 24]
* [cite_start]**Architekturę:** krótki diagram warstwowy. [cite: 25]
* [cite_start]**Instrukcję uruchomienia:** polecenie `docker-compose up` oraz zmienne środowiskowe (`.env.example`). [cite: 26]
* [cite_start]**Scenariusz testowy:** opis krok po kroku (logowanie, role, CRUD, błąd 403/401, widoki/wyzwalacze). [cite: 27]
* [cite_start]**Checklistę:** z informacjami, co udało się zrobić. [cite: 28]

---

## [cite_start]WYMAGANIA KONIECZNE [cite: 29]
* [cite_start]**Standardy kodu:** Aplikację należy napisać zgodnie z filarami obiektowości i zasadami SOLID. [cite: 30]
* [cite_start]**Repozytorium:** Całość powinna być systematycznie dokumentowana za pomocą commitów na repozytorium GIT, które należy ustawić na publiczną widoczność. [cite: 31]
* [cite_start]> ⚠️ **Krytyczne ostrzeżenie:** Projekty napisane strukturalnie zostaną odrzucone – w przypadku próby oddania projektu napisanego strukturalnie zostaje wystawiona ocena 2.0. [cite: 32]
* [cite_start]**Dostęp i archiwizacja:** Kod aplikacji należy przechowywać na repozytorium git z dostępem publicznym lub udostępnionym prawem odczytu dla nauczyciela prowadzącego laboratoria. [cite: 33] [cite_start]Kod należy przechowywać w celach dokumentacji przez 5 lat. [cite: 34]
* [cite_start]**Modelowanie:** Należy dołączyć diagram ERD bazy danych. [cite: 35]
* **Testowanie:** Brak duplikacji kodu. [cite_start]Testy (choć symboliczne): PHPUnit (1-2 testy usług/repozytoriów) + testy integracyjne endpointów (np. prosty skrypt curl/bash). [cite: 36]
* [cite_start]**Obsługa błędów:** Obsługa błędów globalnie (strony 400/403/404/500). [cite: 37]