<changelog>

## [0.2.0] - 2025-01-23
### Dodano
- Interfejs administracyjny dla wtyczki CMU Otomoto Integration
- Metabox "Status Synchronizacji Otomoto" dla postów typu maszyna-rolnicza z informacjami o statusie, ID Otomoto, URL i dacie synchronizacji
- Przyciski akcji w metaboxie: "Odśwież dane z Otomoto" i "Zresetuj flagę edycji manualnej"
- Kolumnę "Status edycji" w listingu wszystkich maszyn rolniczych (sortowalna)
- Stronę opcji wtyczki z trzema sekcjami: Status Synchronizacji, Akcje Synchronizacji, Informacje o konfiguracji
- Trzy typy synchronizacji manualnej: wsadowa (przez CRON), manualna (bezpośrednia), wymuszona (force update)
- System powiadomień email wykorzystujący istniejącą infrastrukturę CRON
- Auto-refresh statusu synchronizacji w czasie rzeczywistym (AJAX)
- Loading spinnery i ulepszone potwierdzenia akcji
- Responsywny design interfejsu administracyjnego
- Automatyczne wykrywanie i powiadamianie o krytycznych błędach konfiguracji

### Zmieniono
- Przeniesiono style CSS do osobnego pliku assets/admin.css
- Dodano JavaScript dla lepszego UX w pliku assets/admin.js
- Ulepszone komunikaty zwrotne dla użytkownika z szczegółowymi informacjami
- Wzmocnione zabezpieczenia (nonce, uprawnienia, walidacja danych)

### Naprawiono
- Poprawione wywołania metod sync managera z odpowiednimi parametrami
- Zabezpieczenie przed nieskończonymi pętlami przy operacjach synchronizacji
- Odpowiednie resetowanie flag edycji manualnej po udanych aktualizacjach

## [0.1.0] - 2025-01-20

## [0.3.0] - YYYY-MM-DD
### Dodano
- Mechanizm automatycznego przenoszenia do kosza wpisów `maszyna-rolnicza`, których odpowiedniki w API Otomoto nie są już aktywne lub zostały zamknięte. Proces ten obejmuje również wpisy, które były edytowane manualnie.
- Dodano nowy klucz `posts_deleted_as_inactive_in_otomoto` do tablicy podsumowania synchronizacji (`$sync_summary`), aby śledzić liczbę usuniętych wpisów.

### Zmieniono
- Zmodyfikowano metodę `CMU_Otomoto_Sync_Manager::sync_adverts` w celu implementacji logiki identyfikacji i usuwania nieaktywnych ogłoszeń po zakończeniu pętli synchronizacji z API. 

## [0.4.0] - YYYY-MM-DD
### Dodano
- Pełna implementacja automatycznej synchronizacji wsadowej CRON: cykl dzienny, przetwarzanie partii, zarządzanie stanem cyklu w opcji, locki (transienty), cleanup nieaktywnych postów po zakończeniu cyklu.
- Mechanizm powiadomień e-mail administratora o krytycznych błędach synchronizacji (z throttlingiem).
- Możliwość konfiguracji adresu e-mail dla powiadomień technicznych za pomocą stałej `CMU_OTOMOTO_NOTIFICATION_EMAIL` w `wp-config.php`.
- Funkcje testowe do manualnego uruchamiania cyklu wsadowego i przetwarzania pojedynczej partii przez URL w panelu admina.
- Dokumentacja konfiguracji systemowego CRON-a w pliku `docs/CONFIGURATION_GUIDE.md`.

### Zmieniono
- Przeniesiono logikę wsadową do dedykowanej klasy `CMU_Otomoto_Cron`.
- Ujednolicono obsługę locków i stanu cyklu w całym procesie synchronizacji.
- Zcentralizowano logikę aktywacji/deaktywacji wtyczki w głównym pliku, zapewniając poprawną kolejność inicjalizacji CPT/Tax/Termów oraz zadań CRON.
- Ulepszono metodę `CMU_Otomoto_Sync_Manager::get_or_create_wp_term_for_otomoto_category` o zamianę podkreślników na myślniki w kodzie kategorii (jeśli używany jako baza sluga) przed utworzeniem sluga termu.

### Naprawiono
- Poprawiono odporność na błędy i przerwania cyklu (np. przez blokady, reset stanu, powiadomienia).
- Rozwiązano problem "niespodziewanej treści wyjściowej" podczas aktywacji wtyczki.
- Naprawiono błąd "Nieprawidłowa taksonomia" występujący podczas tworzenia termów początkowych przy aktywacji.
- Rozwiązano problem `PHP Fatal error: Cannot access private property CMU_Otomoto_Sync_Manager::$parent_wp_term_id` w `CMU_Otomoto_Cron`.
- Wyeliminowano ostrzeżenie `PHP Warning: Undefined array key "errors_encountered"` przez poprawne inicjowanie licznika w strukturze podsumowania cyklu.

## [0.4.1] - YYYY-MM-DD ([Data dzisiejsza])
### Dodano
- Zaimplementowano pełną logikę metody `CMU_Otomoto_Sync_Manager::process_api_page_for_batch` odpowiedzialnej za przetwarzanie pojedynczej strony wyników API w ramach cyklu wsadowego CRON.
- Dodano metodę `CMU_Otomoto_Api_Client::get_advert_details` do pobierania szczegółów pojedynczego ogłoszenia z API.
- Dodano tymczasową komendę WP-CLI `cmuotomoto debug_advert` do debugowania danych pojedynczego ogłoszenia z API (wykorzystującą nową metodę `get_advert_details`).

### Zmieniono
- Rozszerzono metodę `CMU_Otomoto_Sync_Manager::process_single_advert_data` o logikę generowania zastępczego tytułu posta z pól `make` i `model`, jeśli oryginalne pole `title` z API jest puste.
- Doprecyzowano logikę inkrementacji liczników w `CMU_Otomoto_Sync_Manager::process_single_advert_data` w kontekście tablicy podsumowania cyklu (w tym bezpieczne inkrementowanie `errors_encountered`).
- Ulepszono formatowanie i zawartość e-maili z powiadomieniami.

### Naprawiono
- Poprawiono sygnaturę metody `process_api_page_for_batch` w `CMU_Otomoto_Sync_Manager`, aby tablice podsumowania cyklu i ID aktywnych ogłoszeń były przekazywane przez referencję (krytyczne dla poprawności wsadowej synchronizacji) - _potwierdzone jako już wcześniej zaimplementowane_.
- Dodano fallback do `error_log` w funkcji `cmu_otomoto_log` na wypadek niepowodzenia zapisu do pliku logu (lepsza diagnostyka błędów środowiskowych) - _potwierdzone jako już wcześniej zaimplementowane_.
