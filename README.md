# Firmino Integration

Wtyczka WordPress integrująca WooCommerce z API systemu [Firmino](https://firmino.pl) — automatycznie tworzy dokumenty sprzedaży (faktury, paragony) dla zamówień oraz umożliwia import produktów jako artykułów w Firmino.

## Wymagania

- PHP 8.1 lub nowszy
- WordPress (aktualna wersja LTS)
- WooCommerce
- [Composer](https://getcomposer.org/)
- Aktywne konto Firmino z dostępem do API REST

## Instalacja

1. Sklonuj repozytorium do katalogu wtyczek WordPress:

   ```bash
   cd wp-content/plugins
   git clone <adres-repozytorium> firmino-integration
   ```

2. Zainstaluj zależności Composer:

   ```bash
   cd firmino-integration
   composer install --no-dev --optimize-autoloader
   ```

3. W panelu WordPress aktywuj wtyczkę **Firmino Integration**.

4. Przejdź do **Ustawienia → Firmino** i skonfiguruj dane połączenia z API.

## Konfiguracja

Wszystkie ustawienia dostępne są w panelu **Ustawienia → Firmino**.

| Pole | Opis | Wartość domyślna |
|---|---|---|
| Adres bazowy URL | Adres REST API Firmino | `https://app.firmino.pl/app/services/rest/api/` |
| Login | Login do konta Firmino | — |
| Hasło | Hasło do konta Firmino (HTTP Basic Auth) | — |
| Typ dokumentu | Symbol typu dokumentu sprzedaży, np. `fas` (faktura) | `fas` |
| Typ dokumentu paragonu | Symbol dla osoby fizycznej, np. `par` — pozostaw puste, aby używać głównego typu | — |
| Domyślna stawka VAT | Fallback stawki VAT, gdy WooCommerce zwraca wartość nieakceptowaną przez Firmino (np. `23`) | — |
| Domyślny kod kraju | Dwuliterowy kod ISO kraju klienta | `PL` |
| Domyślna miejscowość | Miejscowość używana, gdy klient jej nie podał | `Warszawa` |

Po zapisaniu ustawień użyj przycisku **Testuj połączenie**, aby sprawdzić poprawność konfiguracji.

## Funkcje

### Automatyczne generowanie dokumentów

Dokument w Firmino jest tworzony automatycznie, gdy zamówienie WooCommerce zmienia status na **W trakcie realizacji** (`processing` lub `w-trakcie-realizacji`). Jeśli zamówienie ma już przypisany dokument, ponowne generowanie jest pomijane.

### Ręczne wysyłanie zamówienia

W widoku szczegółów zamówienia dostępna jest akcja **Wyślij do Firmino** (panel *Akcje zamówienia*), która pozwala ręcznie wywołać generowanie dokumentu.

### Pobieranie dokumentu PDF

- **Admin** — po wygenerowaniu dokumentu w widoku zamówienia pojawia się przycisk *Pobierz dokument Firmino* z numerem dokumentu.
- **Lista zamówień** — ikona pobierania dostępna bezpośrednio z listy zamówień w panelu admina.
- **Klient** — zalogowany klient widzi przycisk pobierania na stronie szczegółów zamówienia oraz na liście zamówień w Moim koncie.

### Import produktów

Strona **WooCommerce → Import do Firmino** umożliwia masowy import produktów WooCommerce jako artykułów w Firmino. Obsługuje:

- import nowych produktów,
- aktualizację istniejących artykułów,
- import wariantów produktów,
- wybór źródła kodu artykułu (SKU produktu lub ID WooCommerce),
- paginację wsadową.

### Obsługa klientów

Wtyczka automatycznie wyszukuje klienta w Firmino po danych z zamówienia (NIP, e-mail, nazwa firmy). Jeśli klient nie istnieje, zostaje automatycznie utworzony. Identyfikator klienta Firmino jest cachowany w metadanych zamówienia WooCommerce.

## Struktura projektu

```
firmino-integration.php        # Punkt wejścia wtyczki
composer.json
src/
  Plugin.php                   # Bootstrap — inicjalizacja serwisów i hooków
  Admin/
    SettingsPage.php            # Strona ustawień
    ProductImportPage.php       # Strona importu produktów
  Contracts/
    HttpClientInterface.php     # Interfejs klienta HTTP
  Exceptions/
    ApiException.php
    FirminoException.php
    ValidationException.php
  Http/
    WordPressHttpClient.php     # Implementacja HTTP oparta na WP_Http
  Services/
    CustomerService.php         # Wyszukiwanie i tworzenie klientów w Firmino
    DocumentService.php         # Tworzenie dokumentów sprzedaży i pobieranie PDF
    ProductImportService.php    # Import produktów jako artykułów Firmino
  ValueObjects/
    CustomerData.php
    DocumentItem.php
    ImportOptions.php
    ImportResult.php
    Settings.php                # Immutable VO ustawień z wp_options
  WooCommerce/
    OrderHooks.php              # Hooki zamówień WooCommerce
```

## Licencja

GPL-2.0-or-later — szczegóły w pliku `LICENSE`.
