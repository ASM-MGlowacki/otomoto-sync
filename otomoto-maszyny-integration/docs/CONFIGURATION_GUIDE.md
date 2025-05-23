# Przewodnik Konfiguracji Synchronizacji Ogłoszeń Otomoto

Ten przewodnik opisuje, jak dostosować kluczowe parametry synchronizacji ogłoszeń we wtyczce CMU Otomoto Integration. Wszystkie wskazane modyfikacje dotyczą pliku:
`wp-content/plugins/otomoto-maszyny-integration/includes/class-cmu-otomoto-sync-manager.php`.

## 1. Limit Przetwarzanych Aktywnych Ogłoszeń

Ten limit kontroluje maksymalną liczbę **aktywnych** ogłoszeń, które zostaną przetworzone (stworzone lub zaktualizowane) podczas jednego cyklu synchronizacji. Jest to przydatne podczas testów, aby nie przetwarzać od razu wszystkich ogłoszeń.

- **Lokalizacja w kodzie:** Metoda `sync_adverts()`, zmienna `$dev_max_processed_active_adverts_limit`.
- **Przykład linii kodu:**
  ```php
  // DEV_NOTE_LIMIT_ADVERTS: Limit the number of *processed* (created/updated) active adverts.
  // Set to 0 or a very high number for production.
  // FINAL_GOAL: Remove or set to a very high number for production.
  $dev_max_processed_active_adverts_limit = 50; 
  ```
- **Tryb deweloperski/testowy:** Ustaw wartość np. `25` lub `50` (jak obecnie).
- **Tryb produkcyjny:** Aby przetwarzać wszystkie aktywne ogłoszenia, zmień wartość na `0` lub bardzo dużą liczbę, np. `PHP_INT_MAX`.
  ```php
  $dev_max_processed_active_adverts_limit = 0; // Przetwarzaj wszystkie aktywne
  ```

## 2. Ilość Próbek Ogłoszeń w Podsumowaniu

Ten parametr określa, ile surowych danych ogłoszeń z API zostanie dołączonych do logów oraz wyświetlonych w podsumowaniu testowym (jeśli jest aktywne).

- **Lokalizacja w kodzie:** Metoda `sync_adverts()`. Poszukaj fragmentów kodu używających `array_slice` na zmiennej przechowującej próbki (np. `$all_fetched_adverts_data_sample`) oraz warunku kontrolującego napełnianie tej tablicy.
- **Przykład linii kodu (fragmenty):**
  ```php
  // Przygotowanie próbki do zwrotu:
  $sync_summary['adverts_sample'] = array_slice($all_fetched_adverts_data_sample, 0, 50);
  // ... oraz ...
  // Zbieranie próbki:
  if (count($all_fetched_adverts_data_sample) < 50) {
       $needed_sample_items = 50 - count($all_fetched_adverts_data_sample);
       $all_fetched_adverts_data_sample = array_merge($all_fetched_adverts_data_sample, array_slice($adverts_page_data, 0, $needed_sample_items));
  }
  ```
- **Zmiana wartości:** Zmodyfikuj liczbę `50` w powyższych fragmentach na pożądaną liczbę próbek. Na przykład, dla 10 próbek:
  ```php
  $sync_summary['adverts_sample'] = array_slice($all_fetched_adverts_data_sample, 0, 10);
  // ... oraz ...
  if (count($all_fetched_adverts_data_sample) < 10) {
       $needed_sample_items = 10 - count($all_fetched_adverts_data_sample);
       // ...
  }
  ```

## 3. Limit Pobieranych Zdjęć na Ogłoszenie

Ten limit kontroluje maksymalną liczbę zdjęć pobieranych i dołączanych do każdego ogłoszenia podczas synchronizacji.

- **Lokalizacja w kodzie:** Metoda `handle_advert_images()`, zmienna `$max_images_to_sideload`.
- **Przykład linii kodu:**
  ```php
  // DEV_NOTE_LIMIT_IMAGES: Set to 1 for testing. For production, set to desired max (e.g., 5).
  // Set to 0 or a high number to download all available images from API.
  // FINAL_GOAL: Change to 5 or make configurable. For current tests, use 1.
  $max_images_to_sideload = 1; 
  ```
- **Tryb deweloperski/testowy:** Ustaw wartość np. `1` (jak obecnie).
- **Tryb produkcyjny:** Zmień wartość na docelową liczbę zdjęć, np. `5`, lub `0` aby próbować pobrać wszystkie (jeśli API na to pozwala i jest to pożądane).
  ```php
  $max_images_to_sideload = 5; // Pobieraj do 5 zdjęć
  // LUB
  // $max_images_to_sideload = 0; // Spróbuj pobrać wszystkie dostępne
  ```
  Pamiętaj, że pobieranie dużej liczby zdjęć może znacząco wydłużyć czas synchronizacji i zwiększyć zużycie zasobów.

## 4. Ustawienie Trybu Produkcyjnego (Ogólne Wskazówki)

Aby wtyczka działała w pełni produkcyjnie:

1.  **Przetwarzanie tylko aktywnych ogłoszeń (`status: 'active'`):**
    - Ta funkcjonalność jest już zaimplementowana jako podstawowy filtr w metodzie `process_single_advert_data()`. Nie wymaga dodatkowej konfiguracji do działania w trybie produkcyjnym – ogłoszenia nieaktywne są automatycznie pomijane.
    - Fragment kodu odpowiedzialny za filtrowanie (bez potrzeby zmian):
      ```php
      // Filter by status "active" - CRITICAL
      if (!(isset($advert_data['status']) && $advert_data['status'] === 'active')) {
          cmu_otomoto_log('Skipping advert ID ' . $otomoto_advert_id . ': Status is not "active". Status: ' . ($advert_data['status'] ?? 'N/A'), 'INFO', ['title' => $advert_data['title'] ?? 'N/A']);
          $sync_summary_ref['posts_skipped_inactive_status']++;
          return ['status' => 'skipped_inactive', 'otomoto_id' => $otomoto_advert_id];
      }
      ```

2.  **Usunięcie limitu przetwarzanych ogłoszeń:**
    - Zastosuj zmiany opisane w **Punkcie 1** tego przewodnika, ustawiając `$dev_max_processed_active_adverts_limit = 0;`.

3.  **Pobieranie docelowej liczby zdjęć:**
    - Zastosuj zmiany opisane w **Punkcie 3** tego przewodnika, ustawiając `$max_images_to_sideload` na pożądaną wartość produkcyjną (np. `5`).

Pamiętaj, aby po każdej zmianie konfiguracji dokładnie przetestować działanie wtyczki. 


## Lista URL

Oto lista URL-i i parametrów, które możesz wykorzystać, bazując na funkcji `cmu_otomoto_test_api_client` podpiętej do `admin_init`:

**Podstawowy URL Panelu Administracyjnego:**
Wszystkie te akcje będą uruchamiane przez dostęp do panelu administracyjnego. Bazowy URL to:
`https://twojadomena.pl/wp-admin/`
(lub `https://twojadomena.pl/wp-admin/admin.php` jeśli bezpośrednio)

---

1.  **Uruchomienie Podstawowej Synchronizacji (Testowej):**
    *   **Cel:** Uruchamia funkcję `cmu_otomoto_test_api_client`, która następnie wywołuje `$cmu_otomoto_sync_manager->sync_adverts(false);`. Oznacza to, że synchronizacja będzie próbowała tworzyć nowe posty dla aktywnych, używanych maszyn z tytułem i aktualizować istniejące, ale **nie będzie** wymuszać nadpisywania postów oznaczonych jako `_otomoto_is_edited_manually = true`. Zastosowane zostaną również limity deweloperskie (25 maszyn, 1 zdjęcie).
    *   **URL:**
        ```
        https://twojadomena.pl/wp-admin/admin.php?cmu_otomoto_test_api=1
        ```
        (lub po prostu `https://twojadomena.pl/wp-admin/?cmu_otomoto_test_api=1` - WordPress powinien to obsłużyć)
    *   **Kto może uruchomić:** Tylko zalogowany administrator (ze względu na `current_user_can('manage_options')`).
    *   **Co się stanie:** Rozpocznie się proces synchronizacji. Na końcu zostanie wyświetlone podsumowanie (`wp_die(...)`). Wyniki i szczegóły będą w logach wtyczki.

---

2.  **Uruchomienie Synchronizacji z Wymuszoną Aktualizacją (Force Update):**
    *   **Cel:** Obecnie kod przekazuje `false` do `sync_adverts`. Aby umożliwić wymuszoną aktualizację przez URL, musimy lekko zmodyfikować funkcję `cmu_otomoto_test_api_client`, aby odczytywała dodatkowy parametr.
    *   **Proponowana Modyfikacja w `cmu24-otomoto-integration.php`:**
        W funkcji `cmu_otomoto_test_api_client` znajdź linię:
        ```php
        $sync_results = $cmu_otomoto_sync_manager->sync_adverts(false); // false = nie force_update_all
        ```
        Zastąp ją:
        ```php
        $force_sync = (isset($_GET['force_sync']) && $_GET['force_sync'] === '1');
        cmu_otomoto_log('Test: Force sync parameter is: ' . ($force_sync ? 'ENABLED' : 'DISABLED'), 'INFO');
        $sync_results = $cmu_otomoto_sync_manager->sync_adverts($force_sync);
        ```
    *   **URL (po modyfikacji):**
        ```
        https://twojadomena.pl/wp-admin/admin.php?cmu_otomoto_test_api=1&force_sync=1
        ```
    *   **Kto może uruchomić:** Administrator.
    *   **Co się stanie:** Rozpocznie się proces synchronizacji. Tym razem parametr `$force_update_all` w `sync_adverts` będzie ustawiony na `true`. Oznacza to, że wtyczka zignoruje flagę `_otomoto_is_edited_manually` i nadpisze wszystkie znalezione posty (oraz utworzy nowe), zgodnie z danymi z API (z uwzględnieniem limitów deweloperskich).

---

**Ważne Uwagi:**

*   **Dostępność URL-i:** Te URL-e działają, ponieważ funkcja `cmu_otomoto_test_api_client` jest podpięta do hooka `admin_init`, który jest wywoływany przy każdym ładowaniu strony w panelu administracyjnym. Sprawdzanie `isset($_GET['cmu_otomoto_test_api'])` decyduje, czy funkcja ma faktycznie coś zrobić.
*   **Brak Dedykowanych Endpointów API Wtyczki:** Warto podkreślić, że wtyczka w obecnej formie **nie tworzy** własnych endpointów REST API ani endpointów AJAX do uruchamiania tych operacji w bardziej "standardowy" sposób dla interakcji programistycznych. Używamy tutaj mechanizmu testowego.
*   **Logowanie:** Zawsze sprawdzaj logi (`wp-content/plugins/otomoto-maszyny-integration/logs/otomoto_sync.log`) po uruchomieniu tych URL-i, aby zobaczyć szczegółowy przebieg operacji.
*   **`wp_die()`:** Ponieważ funkcja testowa kończy się `wp_die()`, po jej wykonaniu zobaczysz tylko wiadomość wygenerowaną przez tę funkcję, a nie standardowy panel WordPress. Jest to typowe dla funkcji testowych uruchamianych w ten sposób.

Jeśli potrzebujesz bardziej rozbudowanego systemu URL-i (np. do konkretnych akcji, jak "tylko pobierz kategorie", "tylko zaktualizuj zdjęcia dla posta X"), wymagałoby to stworzenia dedykowanych obsług funkcji, być może z wykorzystaniem WordPress AJAX API lub REST API. Na razie jednak powyższe URL-e powinny wystarczyć do testowania głównych funkcjonalności synchronizacji.

## 5. Automatyczna synchronizacja – konfiguracja CRON

Aby zapewnić niezawodną, automatyczną synchronizację ogłoszeń, zalecamy skonfigurowanie systemowego CRON-a, który regularnie wywołuje WP-Cron WordPressa. Dzięki temu zadania wsadowe będą uruchamiane nawet jeśli nikt nie odwiedza strony.

### Przykładowa linia CRON (Linux)

Zalecane jest uruchamianie co 5 minut:

```
*/5 * * * * wget -q -O - https://twojadomena.pl/wp-cron.php?doing_wp_cron > /dev/null 2>&1
```

- Zamień `https://twojadomena.pl/` na adres swojej strony WordPress.
- Upewnij się, że Twój hosting pozwala na uruchamianie zadań CRON.
- Jeśli korzystasz z panelu hostingowego (np. cPanel, DirectAdmin), dodaj powyższą linię jako nowe zadanie CRON.

### Dodatkowe uwagi
- Domyślnie główny cykl synchronizacji uruchamiany jest raz dziennie (możesz to zmienić w kodzie, jeśli potrzebujesz częściej).
- Mechanizm wsadowy zadba o to, by nawet duża liczba ogłoszeń była przetwarzana bez przekraczania limitów czasu PHP.
- Szczegóły i postęp synchronizacji znajdziesz w logach: `wp-content/plugins/otomoto-maszyny-integration/logs/otomoto_sync.log`.
- W przypadku błędów lub braku synchronizacji sprawdź, czy CRON działa poprawnie oraz czy nie występują błędy w logach.

---