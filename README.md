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

2. Opcjonalnie zainstaluj autoloader Composer (wtyczka nie ma zależności i bez niego ładuje klasy z `src/`):

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
| Hasło | Hasło do konta Firmino (HTTP Basic Auth). Nie jest wyświetlane w formularzu; puste pole zachowuje zapisane hasło | — |
| Typ dokumentu | Symbol typu dokumentu sprzedaży, np. `fas` (faktura) | `fas` |
| Typ dokumentu paragonu | Symbol dla osoby fizycznej, np. `par` — pozostaw puste, aby używać głównego typu | — |
| Domyślna stawka VAT | Stawka używana, gdy WooCommerce nie nalicza podatku (stawka 0), np. `23`. Przy wyłączonych podatkach WooCommerce ceny są traktowane jako brutto | — |
| Domyślny kod kraju | Dwuliterowy kod ISO kraju klienta | `PL` |
| Domyślna miejscowość | Miejscowość używana, gdy klient jej nie podał | `Warszawa` |

Po zapisaniu ustawień użyj przycisku **Testuj połączenie**, aby sprawdzić poprawność konfiguracji.

## Funkcje

### Automatyczne generowanie dokumentów

Faktura w Firmino jest tworzona automatycznie po opłaceniu zamówienia. Integracja reaguje na standardowy hook płatności WooCommerce oraz na przejście do statusu `processing` lub `completed` i sprawdza `WC_Order::is_paid()`. Obsługuje także produkty wirtualne, które po płatności przechodzą bezpośrednio do `completed`. Dokument zostaje następnie oznaczony jako opłacony w Firmino. ID faktury zapisujemy w zamówieniu przed rozliczeniem, a ponowienie po błędzie najpierw odczytuje status dokumentu, aby nie zaksięgować płatności drugi raz. Nieopłaconego zamówienia nie można wysłać ręczną akcją.

### Ręczne wysyłanie zamówienia

W widoku szczegółów zamówienia dostępna jest akcja **Wystaw fakturę w Firmino** (panel *Akcje zamówienia*). Może służyć do ponowienia wystawienia faktury lub jej rozliczenia po błędzie API; jest dostępna dla już opłaconego zamówienia.

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

### Testy

Testy przepływu wystawiania i rozliczania faktur uruchomisz bez WooCommerce i bez dostępu do konta Firmino:

```bash
php tests/run.php
```

Testują opłacone i nieopłacone zamówienia, produkty wirtualne, ponowienia po błędzie API oraz pomijanie dokumentów już rozliczonych. Klient HTTP jest zastępowany atrapą; żaden test nie tworzy dokumentu w Firmino.

### Obsługa klientów

Dla każdego zamówienia wtyczka tworzy klienta w Firmino z danych rozliczeniowych (nazwa firmy lub imię i nazwisko, adres, e-mail, NIP). Nie wyszukuje istniejących klientów. Identyfikator klienta jest cachowany w metadanych zamówienia. NIP jest odczytywany z `_billing_nip` (checkout Biuro Natalia) lub z pól innych wtyczek; zamówienie bez NIP-u i nazwy firmy dostaje typ paragonu, jeśli został ustawiony.

### Korekty

API Firmino nie udostępnia wystawiania korekt. Wtyczka ich nie tworzy: korektę wystawia się ręcznie w panelu Firmino. Dlatego przed wystawieniem faktury zamówienie jest blokowane na czas jednego żądania, a zamówienie z ujemną opłatą zostaje odrzucone zamiast trafić na błędną fakturę.

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
