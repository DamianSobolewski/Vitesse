# VT Konfigurator — Instrukcja obsługi

**Wersja wtyczki:** 1.0.0  
**Autor:** Signuply (signuply.io)  
**Wymagania:** WordPress 6.0+, PHP 8.0+

---

## Spis treści

1. [Instalacja](#1-instalacja)
2. [Pierwsze uruchomienie](#2-pierwsze-uruchomienie)
3. [Panel administracyjny](#3-panel-administracyjny)
4. [Osadzanie konfiguratora](#4-osadzanie-konfiguratora)
5. [Jak działa konfigurator](#5-jak-działa-konfigurator)
6. [Cache i wydajność](#6-cache-i-wydajność)
7. [Diagnostyka i błędy](#7-diagnostyka-i-błędy)
8. [Odinstalowanie](#8-odinstalowanie)

---

## 1. Instalacja

1. Skopiuj folder `vt-konfigurator/` do katalogu `/wp-content/plugins/` na serwerze.
2. Zaloguj się do panelu WordPress.
3. Przejdź do **Wtyczki → Zainstalowane wtyczki**.
4. Znajdź **VT Konfigurator** i kliknij **Aktywuj**.

> **Ważne:** Po aktywacji wtyczka nie będzie jeszcze wyświetlać danych — musisz najpierw odświeżyć drzewo pojazdów (krok poniżej).

---

## 2. Pierwsze uruchomienie

1. Przejdź do **Ustawienia → VT Konfigurator**.
2. Sprawdź pole **URL źródłowy** — domyślnie ustawiony jest adres:
   `https://sklep.vtech.pl/konfigurator-powerchip/`
   Zostaw go bez zmian, chyba że Vtech zmienił adres strony konfiguratora.
3. Kliknij przycisk **Odśwież dane pojazdów**.
4. Poczekaj kilka sekund — wtyczka pobierze i zapisze pełne drzewo marek, modeli, generacji, silników i roczników.
5. Po sukcesie pojawi się zielony komunikat z liczbą pobranych marek i kombinacji, np.:
   `Dane odświeżone pomyślnie. Marki: 42 · Kombinacje: 3847 · Rozmiar: 1,2 MB`

Dane pojazdów są przechowywane lokalnie w bazie danych WordPress i **nie muszą być pobierane przy każdym odwiedzeniu strony przez użytkownika**.

---

## 3. Panel administracyjny

Panel dostępny pod **Ustawienia → VT Konfigurator** podzielony jest na cztery sekcje.

### 3.1 Dane pojazdów

| Element | Opis |
|---|---|
| **Stan drzewa** | Pokazuje datę ostatniego odświeżenia, rozmiar danych, liczbę marek i kombinacji. |
| **Odśwież dane pojazdów** | Pobiera aktualne dane z vtech i zapisuje lokalnie. Jeśli nowe dane mają mniej niż 80% poprzednich kombinacji, zapis jest blokowany jako podejrzany (bezpiecznik przed uszkodzonymi danymi). |

**Kiedy odświeżać?** Odświeżaj ręcznie raz na kilka tygodni lub gdy zauważysz, że brakuje nowego pojazdu w konfiguratorze.

### 3.2 Wygląd

| Pole | Opis | Domyślnie |
|---|---|---|
| **URL źródłowy** | Adres strony vtech, z której pobierane jest drzewo pojazdów. | `https://sklep.vtech.pl/konfigurator-powerchip/` |
| **Kolor akcentu** | Kolor przycisków, obramowań i wartości KM. | `#e53e3e` (czerwony) |
| **Zaokrąglenie rogów (px)** | Promień zaokrąglenia elementów widżetu. | `8` |
| **Ładuj własny CSS** | Gdy odznaczone, wtyczka nie wczytuje własnego arkusza stylów — stylowanie spada na motyw. | Włączone |

### 3.3 Cache wyników

| Pole | Opis | Domyślnie |
|---|---|---|
| **Cache włączony** | Wyniki zapytań do vtech są zapamiętywane lokalnie, co przyspiesza kolejne wyświetlenia. | Włączony |
| **Czas życia cache (dni)** | Po ilu dniach zapisany wynik jest uznawany za przestarzały i pobierany ponownie. | `7` |
| **Wyczyść cache wyników** | Usuwa wszystkie zapisane wyniki — przydatne po zmianie oferty przez vtech. | — |

> **Stale cache (zapasowy):** Nawet po wygaśnięciu cache wtyczka przechowuje ostatni znany wynik jako kopię zapasową. Jeśli serwer vtech jest chwilowo niedostępny, użytkownik zobaczy poprzednie dane zamiast błędu.

### 3.4 Diagnostyka

Sekcja do testowania konkretnych kombinacji pojazd/silnik bezpośrednio z panelu.

1. Wybierz kolejno: **Marka → Model → Generacja → Silnik → Rocznik** (pola kaskadowe, każde aktywuje się po wyborze poprzedniego).
2. Kliknij **Testuj**.
3. Poniżej pojawi się surowa odpowiedź JSON z REST API, np.:
```json
{
  "powerchip": { "hp": 50, "nm": 80, "chart_url": "https://..." },
  "chip_tuning": { "hp": 29, "nm": 28 },
  "_source": "live"
}
```
Pole `_source` informuje skąd pochodzi odpowiedź: `live` (pobrano teraz), `cache` (z cache), `stale` (kopia zapasowa).

**Log błędów** wyświetla ostatnie 20 błędów z datą i treścią. Kliknij **Wyczyść log błędów**, aby go zresetować.

---

## 4. Osadzanie konfiguratora

Konfigurator wstawiasz za pomocą shortcode w dowolnym miejscu na stronie lub w poście — w edytorze klasycznym, Gutenbergu (blok „Shortcode") lub Elementorze (widget „Shortcode").

### Tryb inline (domyślny)

Konfigurator wyświetla się bezpośrednio w treści strony.

```
[vt_konfigurator]
```

### Tryb popup

Na stronie pojawia się tylko czerwony przycisk. Po jego kliknięciu otwiera się modal z konfiguratorem.

```
[vt_konfigurator mode="popup"]
```

### Tryb z wykresami mocy

Dodaje przycisk **„Zobacz wykres mocy"** przy każdym wyniku — otwiera pełnoekranowy wykres dynamometryczny pobrany z vtech.

```
[vt_konfigurator charts="1"]
```

Atrybuty można łączyć:

```
[vt_konfigurator mode="popup" charts="1"]
```

### Kilka instancji na jednej stronie

Na jednej stronie można umieścić dowolną liczbę shortcode'ów — każdy działa niezależnie. Drzewo pojazdów jest wczytywane tylko raz.

---

## 5. Jak działa konfigurator

1. **Użytkownik wybiera kolejno:** Markę → Model → Generację → Silnik → Rocznik.
   Każdy dropdown aktywuje się dopiero po wyborze poprzedniego. Niedostępne kombinacje są automatycznie ukrywane.

2. **Kliknięcie „Sprawdź tuning"** wysyła zapytanie do wewnętrznego REST API WordPress (`/wp-json/vt/v1/result`).

3. **Wyniki** pojawiają się w oknie modalnym:
   - **PowerChip** — przyrost mocy (+KM) i momentu (+Nm)
   - **Chip Tuning** — przyrost mocy (+KM) i momentu (+Nm)
   - Jeśli dana opcja nie jest dostępna dla wybranego pojazdu, karta w ogóle nie jest wyświetlana.
   - Przycisk **„Zobacz wykres mocy"** (gdy włączony atrybut `charts="1"`) otwiera pełnoekranowy wykres z serwisu vtech.

4. Zamknięcie okna wyników (przycisk ×, kliknięcie tła lub klawisz Escape) przywraca konfigurator.

---

## 6. Cache i wydajność

- **Drzewo pojazdów** (~1–3 MB JSON) jest pobierane z vtech raz ręcznie przez administratora i przesyłane do przeglądarki użytkownika przy załadowaniu strony. Nie obciąża to serwera vtech.
- **Wyniki kombinacji** są pobierane od vtech na żądanie (pierwsze kliknięcie „Sprawdź tuning"), a następnie zapisywane w cache na zdefiniowaną liczbę dni.
- **Rate limiting:** maksymalnie 30 zapytań na minutę z jednego adresu IP, aby chronić przed nadużyciami.

---

## 7. Diagnostyka i błędy

### Brak danych / szare dropdowny
Drzewo pojazdów nie zostało pobrane. Przejdź do **Ustawienia → VT Konfigurator** i kliknij **Odśwież dane pojazdów**.

### „Błąd pobierania wyników"
Serwer vtech był chwilowo niedostępny. Wtyczka spróbuje zwrócić ostatni znany wynik z kopii zapasowej. Sprawdź **Log błędów** w panelu, żeby poznać szczegóły.

### Bezpiecznik danych (dane nie zostały zapisane)
Pojawia się, gdy nowo pobrane drzewo zawiera mniej niż 80% poprzednich kombinacji — oznacza to prawdopodobną zmianę struktury strony vtech. Stare dane pozostają aktywne. Skontaktuj się z Signuply w celu aktualizacji wtyczki.

### Konfigurator nie działa po instalacji (404 REST API)
Sprawdź, czy WordPress ma ustawione przyjazne adresy URL. Przejdź do **Ustawienia → Bezpośrednie odnośniki**, wybierz dowolny format inny niż „Zwykłe" (np. „Nazwa wpisu") i kliknij **Zapisz zmiany**.

### Spinner (kółko ładowania) widoczny na stałe
Motyw lub Elementor nadpisują atrybut `hidden`. Odznacz opcję **Ładuj własny CSS** i dodaj do arkusza motywu:
```css
.vtk-widget [hidden],
.vtk-modal-overlay[hidden],
.vtk-result-overlay[hidden],
.vtk-dyno-overlay[hidden] { display: none !important; }
```

---

## 8. Niestandardowy CSS

Wszystkie klasy wtyczki mają prefiks `vtk-`, co eliminuje konflikty z motywem.

### 8.1 Zmienne CSS (najszybszy sposób)

Zmienne są zdefiniowane na elemencie `.vtk-widget` i dziedziczone przez wszystkie jego elementy potomne. Możesz je nadpisać w arkuszu motywu bez dotykania pliku wtyczki.

```css
.vtk-widget {
    --vtk-accent:      #e53e3e;   /* kolor główny: przyciski, wartość KM, obramowania hover */
    --vtk-accent-dark: #c53030;   /* ciemniejszy akcent na hover przycisku */
    --vtk-radius:      8px;       /* zaokrąglenie rogów wszystkich elementów */
    --vtk-font:        inherit;   /* czcionka — domyślnie dziedziczy z motywu */
    --vtk-bg:          #ffffff;   /* tło formularza i kart */
    --vtk-border:      #e2e8f0;   /* kolor obramowań */
    --vtk-text:        #1a202c;   /* kolor tekstu głównego */
    --vtk-muted:       #718096;   /* kolor tekstu pomocniczego (etykiety, jednostki) */
    --vtk-card-bg:     #f7fafc;   /* tło kart z wynikami */
    --vtk-shadow:      0 2px 12px rgba(0,0,0,0.08); /* cień formularza i modali */
}
```

> Kolor akcentu i zaokrąglenie rogów można też ustawić graficznie w panelu **Ustawienia → VT Konfigurator → Wygląd** — nie trzeba pisać CSS.

### 8.2 Klasy strukturalne

#### Formularz

| Klasa | Element |
|---|---|
| `.vtk-widget` | Korzeń całego widżetu (inline lub wewnątrz modala) |
| `.vtk-form` | Biały kontener z obramowaniem otaczający dropdowny i przycisk |
| `.vtk-dropdowns` | Siatka CSS Grid z listami rozwijalnymi |
| `.vtk-field` | Wrapper jednego pola (etykieta + select) |
| `.vtk-label` | Etykieta nad selectem (np. „MARKA") |
| `.vtk-select` | Element `<select>` |
| `.vtk-btn` | Bazowa klasa przycisków |
| `.vtk-btn--submit` | Przycisk „Sprawdź tuning" |
| `.vtk-loading` | Kontener spinnera ładowania |
| `.vtk-spinner` | Animowane kółko |
| `.vtk-error-msg` | Czerwony komunikat błędu |

#### Wyniki (okno modalne)

| Klasa | Element |
|---|---|
| `.vtk-result-overlay` | Ciemne tło (fixed, cały ekran) okna wyników |
| `.vtk-result-box` | Białe okno z wynikami (max 620 px) |
| `.vtk-result-close` | Przycisk × zamknięcia okna wyników |
| `.vtk-result-title` | Nagłówek „Wyniki tuningu dla Marka Model Rok" |
| `.vtk-cards` | Siatka kart produktów |
| `.vtk-cards--single` | Modyfikator gdy tylko jeden produkt — karta jest wyśrodkowana |
| `.vtk-card` | Pojedyncza karta (PowerChip lub Chip Tuning) |
| `.vtk-card-name` | Nazwa produktu w karcie (czerwona) |
| `.vtk-gains` | Kontener listy przyrostów |
| `.vtk-gain-wrap` | Wrapper jednego przyrostu (etykieta + wartość) |
| `.vtk-gain-wrap.vtk-gain--hp` | Modyfikator dla przyrostu mocy |
| `.vtk-gain-wrap.vtk-gain--nm` | Modyfikator dla przyrostu momentu |
| `.vtk-gain-label` | Etykieta (np. „PRZYROST MOCY") |
| `.vtk-gain` | Wiersz wartość + jednostka |
| `.vtk-gain-value` | Duża liczba (+50) |
| `.vtk-gain-unit` | Jednostka (KM lub Nm) |
| `.vtk-chart-btn` | Przycisk „Zobacz wykres mocy" (tylko przy `charts="1"`) |

#### Wykres dynamometryczny (pełny ekran)

| Klasa | Element |
|---|---|
| `.vtk-dyno-overlay` | Czarne tło pełnoekranowe wykresu |
| `.vtk-dyno-box` | Kontener obrazka wykresu |
| `.vtk-dyno-img` | Element `<img>` z wykresem |
| `.vtk-dyno-close` | Przycisk × zamknięcia wykresu |

#### Tryb popup (`mode="popup"`)

| Klasa | Element |
|---|---|
| `.vtk-popup-wrap` | Wrapper wokół przycisku wyzwalającego popup |
| `.vtk-popup-btn` | Czerwony przycisk „Sprawdź możliwości tuningu" |
| `.vtk-modal-overlay` | Ciemne tło modala z konfiguratorem |
| `.vtk-modal-box` | Białe okno modala (max 760 px) |
| `.vtk-modal-close` | Przycisk × zamknięcia modala |

### 8.3 Przykłady

**Zmiana koloru akcentu tylko na jednej stronie:**
```css
/* Np. zielony dla konkretnej strony */
.moja-strona .vtk-widget {
    --vtk-accent:      #2f855a;
    --vtk-accent-dark: #276749;
}
```

**Szerszy modal z wynikami:**
```css
.vtk-result-box {
    max-width: 860px;
}
```

**Większa czcionka wartości przyrostów:**
```css
.vtk-gain-value {
    font-size: 2.5rem;
}
```

**Kolor tła kart:**
```css
.vtk-widget {
    --vtk-card-bg: #1a202c; /* ciemna karta */
    --vtk-text:    #ffffff;
}
```

**Ukrycie etykiet nad selectami:**
```css
.vtk-label { display: none; }
```

---

## 9. Odinstalowanie

1. Dezaktywuj wtyczkę w **Wtyczki → Zainstalowane wtyczki**.
2. Kliknij **Usuń**.
3. Wtyczka automatycznie usunie wszystkie swoje dane z bazy danych WordPress (drzewo pojazdów, wyniki cache, ustawienia, logi błędów).
