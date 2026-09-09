# VITESSE — dokumentacja serwisu

WordPress + Elementor FREE + Docker. Kierunek wizualny „Hamownia" (ciemny, warsztatowy).

---

## Uruchomienie

Instrukcja krok po kroku, także dla świeżego serwera: **[README.md](README.md)**.
W skrócie, na czystym środowisku:

```bash
cp .env.example .env                          # uzupełnij hasła i sekrety
docker compose up -d
./bin/bootstrap.sh https://adres-serwisu      # rdzeń WordPressa, motyw, wtyczki, konta
./bin/migrate.sh                              # tabele katalogu i leadów
./bin/import.sh                               # strony, treść, menu, SEO, formularz
./bin/import-catalog.sh                       # katalog mocy z content/catalog/*.json
./bin/seed-dev.sh                             # dane demonstracyjne (TYLKO nieprodukcyjne)
```

Kolejność jest obowiązkowa. `bootstrap.sh` przyjmuje adres serwisu jako parametr, bo instalacja
zapisuje go do bazy — serwis postawiony pod złym adresem będzie na niego przekierowywał z każdego
innego hosta. Skrypt jest idempotentny.

Logowanie: `/wp-admin`, dane w `.env` (`WP_ADMIN_USER`, `WP_ADMIN_PASSWORD`).
Konto obsługi hamowni: `VTS_OPERATOR_USER` / `VTS_OPERATOR_PASSWORD`.

`bootstrap.sh` włącza blokadę indeksowania (`blog_public = 0`). Obejmuje ona także strony
wirtualne katalogu — WordPress sam by ich nie objął, a jest ich blisko pięć tysięcy.
Zdjęcie blokady przed startem produkcyjnym:

```bash
docker compose --profile cli run --rm wpcli option update blog_public 1
```

## Struktura repozytorium

```
assets/          → wp-content/vts-assets (ro)   css, js, fonty woff2, obrazy
mu-plugins/      → wp-content/mu-plugins (ro)   cała logika serwisu
content/         → /content w kontenerze wpcli  treść jako kod + importery
  pages.json         manifest stron (slug → tytuł, rodzic, menu, SEO)
  pages/*.html       treść stron
  catalog/*.json     katalog mocy — źródło prawdy, wynik scrape'u
  redirects/         mapa starych adresów + inwentarz
tools/scrape/    scraper starego serwisu (Python)
tests/           Playwright: RWD, przekierowania, przepływ wyszukiwarki
uploads/         → wp-content/uploads (RW, poza gitem)
```

**Treść jako kod, jednokierunkowo.** Strony powstają z `content/pages/*.html`
i `pages.json`. Zmiany klikane w edytorze zostaną nadpisane przy następnym imporcie.

---

## Warstwy (mu-plugins)

| Plik | Odpowiada za |
|---|---|
| `vts-config.php` | dane firmy, flagi funkcji `vts_feature()`, sekrety z `getenv()` |
| `vts-schema.php` | 7 własnych tabel, `dbDelta`, klucze obce |
| `vts-catalog.php` | odczyt katalogu, słownik usług, `vts_visibility_sql()` |
| `vts-catalog-routes.php` | adresy `/chiptuning/{marka}/{model}/{generacja}/{silnik}/` |
| `vts-power-search.php` | REST kaskady, dekoder VIN, token HMAC, bramka leadowa |
| `vts-leads.php` | zapis leada, mail, autoresponder, retencja, podgląd w adminie |
| `vts-fleet-calc.php` | kalkulator oszczędności flotowych |
| `vts-dyno.php` | CPT wykresów, taksonomie, siatka z filtrem |
| `vts-dyno-panel.php` | rola `vts_dyno_operator`, okrojony wp-admin |
| `vts-redirects.php` | matryca 301 ze starego serwisu |
| `vts-site.php` | zasoby, nagłówek, stopka 4-kolumnowa, FAB |
| `vts-content.php` | hero (slajder + hero podstron), przewagi, proces, etapy, FAQ, kontakt, mapa, JSON-LD |
| `vts-dev-mail.php` | poczta → Mailpit, samowyłączenie poza localhostem |

---

## Trzy zasady, na których stoi reszta

**1. Bramka leadowa jest po stronie serwera.**
`GET /wp-json/vitesse/v1/catalog/engines` zwraca wyłącznie dane fabryczne.
Wartości po modyfikacji wychodzą dopiero w odpowiedzi na `POST /lead`.
Bramkowanie w JavaScripcie da się obejść w narzędziach przeglądarki w kilka sekund.

**2. Token HMAC zamiast nonce'a WordPressa.**
Nonce jest wypalany w HTML i żyje 12–24 h, więc łamie się przy cache'owaniu całych
stron. Token generuje się przy nieskeszowanym XHR i żyje 30 minut.

**3. `wpautop` jest wyłączony dla stron z importera.**
Filtr służy do zamiany tekstu pisanego w edytorze na akapity. Treść naszych stron to gotowy HTML,
więc nie ma tu nic do roboty — a szkodzi na dwa sposoby: rozbijał wbudowane SVG w hero i wstawiał
`</p>` w środek `<a class="vts-card">`, przez co przeglądarka klonowała kotwicę i powstawały puste,
ale klikalne kafelki (28 sztuk na trzech stronach). Wyłącza go flaga `_vts_raw_html`, ustawiana przez
`content/import.php`; wpisy bloga zachowują domyślne zachowanie. Hero doklejamy dodatkowo
z priorytetem 99, żeby nie zależeć od kolejności filtrów.

**4. `vts_visibility_sql()` to jedyne miejsce, gdzie powstaje warunek widoczności.**
Dzięki temu ukrycie marki działa naraz w kaskadzie, katalogu, wyszukiwaniu,
przekierowaniach i sitemapie — i nie da się go przypadkiem pominąć.

---

## Katalog mocy

Własne tabele, nie CPT: tysiące wariantów silnikowych × kilka poziomów produktu to zbyt dużo,
żeby trzymać je w `wp_posts`. Kaskada byłaby wtedy `meta_query` z JOIN-ami po `LONGTEXT`.

### Źródło danych: konfigurator V-techa

Dane pochodzą z **konfiguratora PowerChip V-techa** (`sklep.vtech.pl`), czyli od producenta,
którego autoryzację Vitesse ma od 2008 roku. To lepsze źródło niż stary serwis klienta: zawsze
aktualne i z pełnym podziałem na poziomy produktu.

Mechanizm rozpoznaliśmy na podstawie wtyczki *VT Konfigurator* (Signuply), której **nie instalujemy** —
wzięliśmy z niej wiedzę, nie kod. Powody odrzucenia wtyczki:

* nie ma bramki leadowej — pokazuje przyrosty od razu, co przekreśla główne wymaganie klienta,
* wrzuca **całe drzewo (4,3 MB)** do HTML każdej strony przez `wp_localize_script`,
* jej parser zbiera wszystkie karty do dwóch worków i zostawia ostatnią, przez co przy czterech
  poziomach produktu pokazuje najwyżej dwa.

Nasz scraper zapisuje **każdy poziom osobno**: PowerChip One, PowerChip Premium,
PowerChip Premium + AI oraz Chip Tuning.

### Marki spoza konfiguratora

Konfigurator V-techa obejmuje pojazdy drogowe. Nie ma w nim **MAN-a** ani maszyn rolniczych
(Fendt, Case), a „Chip tuning ciągników i maszyn" jest w ofercie Vitesse. Te marki dokładamy
z katalogu starego serwisu — `tools/scrape/merge-catalog.py`. Marek występujących w obu źródłach
nie scalamy, żeby uniknąć sprzecznych wartości; V-tech jest zawsze nadrzędny.

### Przyrosty, nie wartości bezwzględne

V-tech podaje **delty** (+KM / +Nm), stary katalog Vitesse podawał wartości po modyfikacji.
Tabela `vts_gain` trzyma oba pola: `gain_hp`/`gain_nm` oraz `tuned_hp`/`tuned_nm`, a warstwa
REST uzupełnia to, co da się policzyć. Moment fabryczny bywa nieznany — V-tech go nie podaje —
i wtedy kolumna `stock_nm` ma 0, co kod traktuje jako brak danych, nie jako zero.

### Przekierowania po podmianie katalogu

Nowe rekordy nie mają kluczy `?auto=` ze starego serwisu, więc wyszukiwanie po `legacy_key`
przestało wystarczać. `tools/scrape/map-legacy.py` dopasowuje stare klucze do nowych ścieżek
(silniki po sygnaturze pojemność/rodzina/kW) i zapisuje mapę do `content/redirects/legacy-catalog.json`.
Klucze bez dopasowania trafiają na przodka, nigdy na 404.

### Odświeżenie katalogu

```bash
./bin/refresh-catalog.sh            # drzewo + wyniki + scalenie + mapa + import
./bin/refresh-catalog.sh --tree     # samo drzewo, bez pobierania wyników
```

Pobieranie wyników jest wznawialne (cache w `tools/scrape/raw-vtech/`). Scraper prosi o kompresję
i zapisuje w cache tylko fragment z kartami produktów — pełna strona wyniku waży **4,66 MB**,
bo V-tech osadza w niej całe drzewo pojazdów.

## Weryfikacja

```bash
node tests/cards.mjs        # strażnik pustych kafelków
node tests/hero-anim.mjs
node tests/rwd.mjs          # 1440/768/390: brak poziomego scrolla, jeden H1
node tests/mobile.mjs       # menu na cały ekran, cele dotykowe, blokada tła
node tests/console.mjs      # panel w hero: kaskada, szczelność bramki, kontrast, LCP
node tests/visuals.mjs      # jedna lewa krawędź, jedna skala fontów, sieroty, licencje
node tests/redirects.mjs    # 71 starych adresów → 301 w jednym skoku na stronę 200
node tests/full-check.mjs   # kaskada, bramka, kalkulator, wykresy, LCP
```

Stan ostatniego przebiegu: wszystkie osiem zestawów przechodzi — RWD bez zastrzeżeń,
72/72 przekierowania, LCP ~330 ms, zero błędów JavaScriptu.

---

## Do produkcji

- [ ] Dane rejestrowe: `wp option update vts_legal_name|vts_nip|vts_regon`
- [ ] Potwierdzić przypisanie telefonów do działów (`vts-config.php`)
- [ ] Skrzynki leadowe w `.env`: `VTS_LEAD_INBOX`, `VTS_LEAD_INBOX_FLEET`
- [ ] SMTP produkcyjny + SPF/DKIM/DMARC (bez tego leady trafią do spamu)
- [ ] Zastąpić zdjęcia zastępcze materiałem klienta; usunąć dane z `bin/seed-dev.sh`
- [ ] **Dowód społeczny** — realne opinie i ocena z profilu Google zamiast danych
      demonstracyjnych z `bin/seed-dev.sh`:
      `wp option update vts_google_rating|vts_google_reviews_count|vts_google_reviews_url|vts_reviews`.
      Dopóki `vts_reviews` jest puste, sekcja „Co mówią klienci" **nie renderuje się wcale** —
      i tak ma zostać, dopóki nie ma czego pokazać.
- [ ] **Certyfikat V-tech** — skan lub zdjęcie do sekcji dowodu społecznego (brak materiału)
- [ ] Archiwum wykresów z hamowni + zgody właścicieli na publikację
- [ ] **Akceptacja prawna treści DPF / EGR / SCR.** Flaga `vts_feature_emissions_pages`
      jest **włączona** na wyraźną decyzję klienta (mail o strukturze serwisu), a kafle
      tych układów stoją na `/podnoszenie-mocy/dodatkowe-uslugi-ecu/` i w bloku flotowym.
      Wyłączenie z powrotem to `wp option update vts_feature_emissions_pages 0`.
- [ ] Akceptacja prawna: regulamin, polityka prywatności
- [ ] **Dekoder VIN** — `vts_feature_vin_decoder` jest włączony i rozpoznaje markę
      po WMI z własnego katalogu (100 kodów, `vts_vin_wmi_map()`). Do decyzji, czy
      dokładamy płatne API pełnego dekodowania (model + wersja silnika) — patrz
      `PYTANIA-DO-KLIENTA.md`. Bez niego użytkownik po rozpoznaniu marki i tak
      wybiera model i silnik ręcznie.
- [ ] Zdjęcia hero dla slajdów 2–4 i dla hero podstron — dziś slajder korzysta
      z pasów `pas-chip`, `pas-floty` i `pas-onas`, czyli materiału ilustracyjnego
- [ ] GA4 + Consent Mode v2 i baner zgody (jeszcze nie wdrożone)
- [ ] Google Search Console, sitemapa katalogu (provider jeszcze nie wdrożony)
- [ ] Przełączenie DNS; stary serwer zostawić działający ~30 dni jako siatka bezpieczeństwa

---

## Struktura sekcji

Układ podstron odpowiada zaktualizowanej strukturze serwisu przysłanej przez klienta.
Trzy rzeczy warto pamiętać przy dokładaniu kolejnych sekcji:

**1. Żadna sekcja nie może być samotną kolumną prozy.**
`--vts-content: 680px` (`assets/css/tokens.css`) to miara długości wiersza, nie szerokość
sekcji — i taka zostaje. Ale sekcja złożona z samego `.vts-narrow` renderuje się jako wąski
słupek w kontenerze 1240 px, z pustą prawą połową. Każdy blok tekstu dostaje więc towarzysza:
`.vts-split` z kartą, listą ikon, pasem zdjęciowym, wyszukiwarką albo formularzem.

**2. Sekcje wielokrotnego użytku są shortcode'ami, nie kopiowanym HTML-em.**
`[vts_usps]` (5 przewag), `[vts_proces]` (5 kroków), `[vts_stages]` (Stage 1/2),
`[vts_social_proof]`, `[vts_map]`, `[vts_gauges]` (5 filarów). Proces stoi na stronie
głównej i na `/podnoszenie-mocy/` — z jednego źródła.

**3. Hero podstrony konfiguruje `vts_page_hero($slug)` w `vts-content.php`.**
Slug bez wpisu dostaje wariant minimalny (okruszki + H1), więc strony prawne i katalog
nie wymagają niczego. Slajder strony głównej: `vts_hero_slides()` — nagłówek `<h1>` nosi
wyłącznie slajd 0, bo na stronie jest jeden nagłówek pierwszego poziomu.

**4. Formularze: osobny CF7 na podstronę.**
Definicje w `vts_form_defs()` (`content/import.php`), szablony pól w `content/forms/*.html`,
wstawianie przez `[vts_contact_form form="floty"]`. Zapytania flotowe idą na
`VTS_LEAD_INBOX_FLEET`, reszta na `VTS_LEAD_INBOX`.

---

## Flagi funkcji

```bash
wp option update vts_feature_jlr_service 1      # linia serwisowa Jaguar / Land Rover
wp option update vts_feature_vin_decoder 0      # wyłączenie pola VIN w wyszukiwarce
wp option update vts_feature_ai_agent 1         # asystent AI (etap 2)
wp option update vts_feature_emissions_pages 0  # ukrycie treści DPF/EGR/SCR (domyślnie włączone)
wp option update vts_catalog_index_level model  # cofnięcie indeksowania katalogu
```

`vts_catalog_index_level` to przełącznik odwrotu: gdyby Search Console zgłosiła
problem z cienką treścią przy 3200 stronach wariantów, schodzimy poziom wyżej
jedną komendą — adresy zostają, znika tylko indeksowanie.
