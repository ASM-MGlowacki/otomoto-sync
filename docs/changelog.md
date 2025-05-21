## [0.2.0] - RRRR-MM-DD
### Dodano
- Mechanizm zapobiegający duplikacji postów maszyn rolniczych. Posty są teraz aktualizowane, jeśli już istnieją dla danego ID Otomoto.
- Filtrowanie ogłoszeń: importowane i przetwarzane są wyłącznie ogłoszenia ze statusem `active` z API Otomoto.
- Pomijanie i logowanie ogłoszeń bez tytułu.
- Możliwość oznaczenia posta jako "edytowany manualnie" (`_otomoto_is_edited_manually`), co zapobiega automatycznej aktualizacji, chyba że wymuszono (`force_update`).
- Tymczasowe limity testowe: max 25 przetwarzanych aktywnych maszyn, max 1 zdjęcie na maszynę.

### Zmieniono
- Zrefaktoryzowano logikę tworzenia i aktualizacji postów w `CMU_Otomoto_Sync_Manager`.
- Usprawniono logikę porównywania danych przy aktualizacji postów (data modyfikacji, kluczowe pola).
- Poprawiono logikę obsługi i zapisu zdjęć, w tym usuwanie starych zdjęć przy aktualizacji.
- Zaktualizowano strukturę podsumowania synchronizacji w logach.

### Naprawiono
- Usunięto błąd, który mógł prowadzić do tworzenia wielu postów dla tego samego ogłoszenia z Otomoto.

## [Wersja bieżąca] - RRRR-MM-DD
### Naprawiono
- Poprawiono logikę tworzenia slugów dla kategorii maszyn w WordPress, aby zapobiec duplikatom z numerycznymi przyrostkami (np. `kombajny-1`) oraz aby zapewnić użycie myślników zamiast podkreślników w slugach (np. `przyczepy-rolnicze` zamiast `przyczepy_rolnicze`). Wprowadzono wewnętrzny cache dla przetwarzanych kategorii podczas pojedynczej sesji synchronizacji.
- Usunięto błąd powodujący tworzenie zduplikowanych terminów kategorii (np. `ciągniki`, `ciągniki-1`) dla tej samej kategorii Otomoto. Problem wynikał z braku zapisu meta danych `_otomoto_category_id` i `_otomoto_category_code` bezpośrednio po utworzeniu nowego terminu w metodzie `get_or_create_wp_term_for_otomoto_category`. Poprawka zapewnia poprawne identyfikowanie istniejących terminów i zapobiega ich duplikacji. 