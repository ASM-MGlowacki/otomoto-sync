# Lista Kontrolna Testowania - Interfejs Administracyjny CMU Otomoto Integration

## Przygotowanie do testów

### Wymagania wstępne
- [x] WordPress zainstalowany i działający
- [x] Wtyczka CMU Otomoto Integration aktywna
- [x] Stałe API skonfigurowane w wp-config.php (OTOMOTO_CLIENT_ID, OTOMOTO_CLIENT_SECRET, etc.)
- [x] Co najmniej jeden post typu "maszyna-rolnicza" z danymi z Otomoto

### Przed rozpoczęciem
- [ ] Sprawdź logi wtyczki: `wp-content/plugins/otomoto-maszyny-integration/logs/otomoto_sync.log`
- [ ] Zweryfikuj że użytkownik testowy ma uprawnienia `manage_options`
- [ ] Oczyść cache przeglądarki

## I. Testowanie Kolumny "Status edycji"

### Lokalizacja: WordPress Admin → Posty → Maszyny Rolnicze

- [ ] **Test 1.1**: Kolumna "Status edycji" jest widoczna w tabeli postów
- [ ] **Test 1.2**: Posty niemodyfikowane pokazują "Synchronizowany z Otomoto" (zielony)
- [ ] **Test 1.3**: Edytuj dowolny post, zapisz → sprawdź zmianę na "Edytowany manualnie" (czerwony)
- [ ] **Test 1.4**: Kliknij nagłówek kolumny → sprawdź czy sortowanie działa
- [ ] **Test 1.5**: Filtruj posty przez różne statusy edycji

**Kryteria sukcesu**: Kolumna wyświetla się poprawnie, sortowanie działa, kolory są odpowiednie

## II. Testowanie Metaboxa

### Lokalizacja: WordPress Admin → Posty → Maszyny Rolnicze → [Edytuj Post]

#### A. Wyświetlanie danych
- [ ] **Test 2.1**: Metabox "Status Synchronizacji Otomoto" jest widoczny w prawej kolumnie
- [ ] **Test 2.2**: Wyświetla się status edycji (z odpowiednim kolorem)
- [ ] **Test 2.3**: ID Otomoto jest poprawnie wyświetlane
- [ ] **Test 2.4**: Link do ogłoszenia działa (otwiera w nowej karcie)
- [ ] **Test 2.5**: Data ostatniej synchronizacji jest czytelna

#### B. Przyciski akcji (tylko dla postów z ID Otomoto)
- [ ] **Test 2.6**: Przycisk "Odśwież dane z Otomoto" jest widoczny
- [ ] **Test 2.7**: Przycisk "Zresetuj flagę edycji manualnej" pojawia się tylko dla postów edytowanych manualnie

#### C. Funkcjonalność przycisków
- [ ] **Test 2.8**: "Odśwież dane z Otomoto"
  - [ ] Wyświetla confirm dialog
  - [ ] Po potwierdzeniu pokazuje loading spinner
  - [ ] Aktualizuje dane posta
  - [ ] Resetuje flagę edycji manualnej
  - [ ] Pokazuje komunikat sukcesu/błędu
  - [ ] Loguje operację

- [ ] **Test 2.9**: "Zresetuj flagę edycji manualnej"
  - [ ] Usuwa flagę `_otomoto_is_edited_manually`
  - [ ] Zmienia status na "Synchronizowany z Otomoto"
  - [ ] Pokazuje komunikat potwierdzający

**Kryteria sukcesu**: Wszystkie dane wyświetlają się poprawnie, przyciski działają, komunikaty są jasne

## III. Testowanie Strony Opcji

### Lokalizacja: WordPress Admin → Ustawienia → Otomoto Sync

#### A. Sekcja "Status Synchronizacji"
- [ ] **Test 3.1**: Status cyklu wsadowego wyświetla się z odpowiednim kolorem
- [ ] **Test 3.2**: Postęp cyklu (jeśli aktywny) pokazuje "Strona X z Y"
- [ ] **Test 3.3**: Daty są poprawnie sformatowane
- [ ] **Test 3.4**: Następna zaplanowana synchronizacja jest widoczna
- [ ] **Test 3.5**: Przycisk "↻ Odśwież Status" działa (auto-dodawany przez JS)

#### B. Sekcja "Akcje Synchronizacji"
- [ ] **Test 3.6**: Trzy przyciski synchronizacji są widoczne
- [ ] **Test 3.7**: Opisy akcji są jasne i zrozumiałe
- [ ] **Test 3.8**: Przyciski mają odpowiednie kolory (primary, secondary, warning)

#### C. Sekcja "Informacje o konfiguracji"
- [ ] **Test 3.9**: Status API Client: "✓ Dostępny" (zielony)
- [ ] **Test 3.10**: Status Sync Manager: "✓ Dostępny" (zielony)
- [ ] **Test 3.11**: Kategoria nadrzędna pokazuje nazwę i ID
- [ ] **Test 3.12**: Liczba zarządzanych maszyn jest poprawna
- [ ] **Test 3.13**: Wersja wtyczki jest aktualna
- [ ] **Test 3.14**: Ścieżka do logów jest poprawna

**Kryteria sukcesu**: Wszystkie informacje są aktualne i poprawne

## IV. Testowanie Synchronizacji Manualnej

### A. Synchronizacja Wsadowa
- [ ] **Test 4.1**: Kliknij "Rozpocznij Synchronizację Wsadową"
- [ ] **Test 4.2**: Pojawia się confirm dialog
- [ ] **Test 4.3**: Po potwierdzeniu pokazuje loading spinner
- [ ] **Test 4.4**: Status zmienia się na "W trakcie"
- [ ] **Test 4.5**: Sprawdź logi - nowy cykl został zainicjowany
- [ ] **Test 4.6**: Sprawdź czy w tle uruchamiają się zadania CRON

### B. Synchronizacja Manualna
- [ ] **Test 4.7**: Kliknij "Synchronizuj Teraz (Manualna)"
- [ ] **Test 4.8**: Pojawia się ostrzeżenie o czasie wykonywania
- [ ] **Test 4.9**: Operacja wykonuje się natychmiast (może zająć kilka minut)
- [ ] **Test 4.10**: Pokazuje szczegółowe podsumowanie wyników
- [ ] **Test 4.11**: Aktualizuje "Ostatnia pomyślna synchronizacja"

### C. Wymuszone Odświeżenie
- [ ] **Test 4.12**: Kliknij "Wymuś Pełne Odświeżenie"
- [ ] **Test 4.13**: Pojawia się PODWÓJNE ostrzeżenie o nieodwracalności
- [ ] **Test 4.14**: Operacja nadpisuje flagi edycji manualnej
- [ ] **Test 4.15**: Pokazuje komunikat z liczbą zaktualizowanych postów

**Kryteria sukcesu**: Wszystkie typy synchronizacji działają, komunikaty są jasne

## V. Testowanie JavaScript i AJAX

### A. Auto-refresh
- [ ] **Test 5.1**: Status odświeża się automatycznie co 30 sekund
- [ ] **Test 5.2**: Przycisk "↻ Odśwież Status" jest dodawany dynamicznie
- [ ] **Test 5.3**: Manual refresh działa natychmiast
- [ ] **Test 5.4**: Auto-refresh zatrzymuje się gdy strona jest nieaktywna
- [ ] **Test 5.5**: Auto-refresh wznawia się gdy wrócisz na stronę

### B. Loading states
- [ ] **Test 5.6**: Formularze pokazują spinnery podczas submitu
- [ ] **Test 5.7**: Przyciski synchronizacji stają się nieaktywne podczas operacji
- [ ] **Test 5.8**: Loading states usuwają się po zakończeniu operacji

### C. Enhanced confirmations
- [ ] **Test 5.9**: Potwierdzenia dla różnych typów synchronizacji mają odpowiednie treści
- [ ] **Test 5.10**: Force sync ma podwójne potwierdzenie
- [ ] **Test 5.11**: Anulowanie potwierdzenia zatrzymuje operację

**Kryteria sukcesu**: JavaScript działa płynnie, UX jest intuicyjny

## VI. Testowanie Bezpieczeństwa

### A. Uprawnienia
- [ ] **Test 6.1**: Zaloguj się jako użytkownik bez uprawnień `manage_options`
- [ ] **Test 6.2**: Sprawdź czy metabox nie jest widoczny
- [ ] **Test 6.3**: Sprawdź czy strona opcji nie jest dostępna
- [ ] **Test 6.4**: Spróbuj wywołać URL-e akcji ręcznie → powinny być odrzucone

### B. Nonce i walidacja
- [ ] **Test 6.5**: Spróbuj wywołać akcje bez nonce → błąd bezpieczeństwa
- [ ] **Test 6.6**: Spróbuj wywołać akcje z nieprawidłowym nonce → błąd bezpieczeństwa
- [ ] **Test 6.7**: Sprawdź czy wszystkie dane wejściowe są sanitizowane

**Kryteria sukcesu**: Wszystkie zabezpieczenia działają prawidłowo

## VII. Testowanie Responsywności

### A. Różne rozmiary ekranu
- [ ] **Test 7.1**: Strona opcji wygląda dobrze na desktop (1920px+)
- [ ] **Test 7.2**: Strona opcji wygląda dobrze na tablet (768px-1024px)
- [ ] **Test 7.3**: Strona opcji wygląda dobrze na telefon (<768px)
- [ ] **Test 7.4**: Metabox jest czytelny na małych ekranach
- [ ] **Test 7.5**: Przyciski synchronizacji układają się pionowo na mobile

**Kryteria sukcesu**: Interface jest używalny na wszystkich urządzeniach

## VIII. Testowanie Powiadomień Email

### A. Konfiguracja
- [ ] **Test 8.1**: Sprawdź czy CMU_OTOMOTO_NOTIFICATION_EMAIL jest ustawione w wp-config
- [ ] **Test 8.2**: Spróbuj wywołać krytyczny błąd → sprawdź czy email został wysłany
- [ ] **Test 8.3**: Sprawdź throttling - drugi email w ciągu godziny nie powinien zostać wysłany

### B. Zawartość emaili
- [ ] **Test 8.4**: Email zawiera czytelny subject
- [ ] **Test 8.5**: Email zawiera szczegóły błędu
- [ ] **Test 8.6**: Email zawiera link do panelu admin
- [ ] **Test 8.7**: Email jest w formacie HTML z odpowiednim kodowaniem

**Kryteria sukcesu**: System powiadomień działa niezawodnie

## IX. Testowanie Logowania

### A. Operacje UI
- [ ] **Test 9.1**: Wszystkie akcje z metaboxa są logowane
- [ ] **Test 9.2**: Wszystkie akcje synchronizacji są logowane
- [ ] **Test 9.3**: Błędy są logowane z odpowiednim poziomem (ERROR/WARNING)
- [ ] **Test 9.4**: Logi zawierają wystarczające informacje do debugowania

### B. Jakość logów
- [ ] **Test 9.5**: Logi są czytelne i zrozumiałe
- [ ] **Test 9.6**: Znaczniki czasu są poprawne
- [ ] **Test 9.7**: Nie ma logów typu DEBUG w produkcji
- [ ] **Test 9.8**: Wielkość pliku logów jest rozsądna

**Kryteria sukcesu**: Logi pomagają w monitoringu i debugowaniu

## X. Testowanie Integracji

### A. Kompatybilność z istniejącym kodem
- [ ] **Test 10.1**: Cron jobs działają normalnie
- [ ] **Test 10.2**: API client funkcjonuje prawidłowo
- [ ] **Test 10.3**: Sync manager operuje bez problemów
- [ ] **Test 10.4**: Flagi edycji manualnej są respektowane

### B. Wydajność
- [ ] **Test 10.5**: Strona admina ładuje się szybko
- [ ] **Test 10.6**: AJAX requesty są responsywne
- [ ] **Test 10.7**: Brak wycieków pamięci przy długotrwałym użyciu
- [ ] **Test 10.8**: Auto-refresh nie obciąża serwera

**Kryteria sukcesu**: Interface nie wpływa negatywnie na wydajność

---

## Podsumowanie Testów

**Data testów**: _________________

**Tester**: _________________

**Środowisko**: _________________

### Wyniki
- **Testy zakończone sukcesem**: _____ / _____
- **Testy zakończone niepowodzeniem**: _____ / _____
- **Błędy krytyczne**: _________________
- **Uwagi**: _________________

### Rekomendacje
- [ ] Interface jest gotowy do produkcji
- [ ] Interface wymaga drobnych poprawek
- [ ] Interface wymaga znaczących zmian

**Podpis testera**: _________________ 