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