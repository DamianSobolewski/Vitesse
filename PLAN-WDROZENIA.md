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
./bin/import-catalog.sh                       # katalog mocy z wtyczki VT Konfigurator (drzewo V-techa)
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
plugins/         → wp-content/plugins/… (ro)    wtyczka VT Konfigurator od klienta, bez zmian w kodzie
content/         → /content w kontenerze wpcli  treść jako kod + importery
  pages.json         manifest stron (slug → tytuł, rodzic, menu, SEO)
  pages/*.html       treść stron
  redirects/         mapa starych adresów + inwentarz
tools/scrape/    scraper starego serwisu (Python) — treści, nie katalog
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
| `vts-catalog-import.php` | zapis katalogu do tabel (upsert po `legacy_key`), wygaszanie, liczniki |
| `vts-vt-bridge.php` | most do wtyczki VT Konfigurator: drzewo → tabele, wyniki → `vts_gain`, cron, `wp vts vt` |
| `vts-catalog-routes.php` | adresy `/chiptuning/{marka}/{model}/{generacja}/{silnik}/` |
| `vts-power-search.php` | REST kaskady i wyniku, dekoder VIN (pod flagą), token HMAC, `vts_engine_result()` |
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

**1. Wynik bez bramki, ale z tokenem i limitem.**
Klient zrezygnował z bramki e-mail (wrzesień 2026): wyszukiwarka ma pokazywać przyrosty od razu,
tak jak konfigurator V-techa. `GET /catalog/engines` zwraca dane fabryczne i token, a
`GET /catalog/result?engine&token` — przyrosty dla każdego poziomu programu. Token i limit 120
zapytań na godzinę z adresu zostają jako hamulec na zgarnianie katalogu skryptem.
`POST /lead` istnieje nadal (zapis leada z e-mailem), ale nic go dziś nie wywołuje.
Ta sama funkcja (`vts_engine_result()`) renderuje wynik na stronie wersji w katalogu.

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

### Źródło danych: wtyczka VT Konfigurator

Dane pochodzą z **konfiguratora PowerChip V-techa** (`sklep.vtech.pl`), czyli od producenta,
którego autoryzację Vitesse ma od 2008 roku. Pobiera je wtyczka **VT Konfigurator** (Signuply),
którą przysłał klient i której wdrożenia zażądał (IX 2026). Leży w `plugins/vt-konfigurator/`,
kod dostawcy bez zmian, aktywowana w `bootstrap.sh`; cała jej funkcjonalność (panel, REST
`/wp-json/vt/v1/result`, shortcode) zostaje włączona.

Wtyczka jest źródłem, ale nie warstwą prezentacji. Serwis pracuje na własnych tabelach,
a most `vts-vt-bridge.php` przepisuje do nich dane wtyczki:

* **drzewo pojazdów** (`wp_option vt_vehicle_tree`, ok. 4,5 MB, 60 marek, 33 tys. kombinacji
  rocznik×silnik) → marki, modele, generacje, silniki. Tymi samymi regułami slugów co dawny
  scraper, z tożsamością po `legacy_key` — id silników się nie przesuwają, więc hamownia,
  leady, przykłady na stronie głównej i mapa przekierowań zostają ważne;
* **wynik dla wersji** (`VT_Fetcher::fetch_result`, żywy request do sklepu V-techa) → wiersze
  `vts_gain`. Klucz cache jest ten sam co w REST wtyczki, więc oba wejścia dzielą jeden cache
  i jedno „Wyczyść cache wyników" w panelu.

Dlaczego tabele zostają: shortcode wtyczki wrzuca całe drzewo do HTML każdej strony, jej REST
nie ma tokenu ani naszego limitu, a strony `/chiptuning/…`, wyszukiwanie FULLTEXT i przekierowania
potrzebują id i slugów w bazie.

**Rocznik.** Wtyczka wymaga rocznika, nasza kaskada ma cztery pola. Serwer bierze najnowszy
rocznik, w którym dana generacja+silnik występuje w drzewie (`vts_engine.vt_year`).

**Dwie pozycje wyniku.** Parser wtyczki zbiera karty ze sklepu do dwóch worków i zostawia ostatnią
z każdego, więc wynik to „PowerChip" (w praktyce najwyższy poziom, Premium + AI) i „Chip Tuning".
Decyzja biznesowa: pokazujemy dokładnie to, co wtyczka. Słownik `vts_services()` ma więc dwa kody:
`powerchip` i `chip`.

**Kiedy dane trafiają do tabel.** Import drzewa jest natychmiastowy. Wyniki dociągają się trzema
drogami: cron co godzinę (paczka `vts_vt_batch`, domyślnie 150 silników, ok. 2 s każdy, bo strona
wyniku V-techa waży 4,66 MB), `wp vts vt sync` od ręki, oraz przy pierwszym wyświetleniu wersji
jeszcze niesprawdzonej (`vts_vt_ensure_fresh()` w `vts_engine_result()`). Wersja, dla której
wtyczka potwierdzi brak danych, schodzi z widoku (`vt_checked_at` ustawione, brak wierszy `vts_gain`);
niesprawdzona zostaje widoczna. Nieudane pobranie nie jest ponawiane przez 10 minut.

### Marki spoza konfiguratora

Nie ma ich. Katalog to wyłącznie drzewo V-techa (decyzja IX 2026); MAN, Fendt, Case i Great Wall
ze starego serwisu zostały wygaszone (`visibility = 0`, wiersze zostają dla przekierowań, które
prowadzą na przodka).

### Przyrosty, nie wartości bezwzględne

Wtyczka podaje **delty** (+KM / +Nm). Tabela `vts_gain` trzyma `gain_hp`/`gain_nm`, a
`vts_engine_result()` liczy z mocy fabrycznej wartość po modyfikacji. Moment fabryczny jest nieznany —
V-tech go nie podaje — i wtedy kolumna `stock_nm` ma 0, co kod traktuje jako brak danych, nie jako
zero. Adres wykresu z V-techa zapisujemy w `chart_url`, ale go nie pokazujemy (pytanie o zgodę
w `PYTANIA-DO-KLIENTA.md`).

### Przekierowania po podmianie katalogu

Nowe rekordy nie mają kluczy `?auto=` ze starego serwisu, więc wyszukiwanie po `legacy_key`
przestało wystarczać. Mapa `content/redirects/legacy-catalog.json` (kopia w `mu-plugins/vts-data/`)
powstała jednorazowo przez dopasowanie starych kluczy do nowych ścieżek (silniki po sygnaturze
pojemność/rodzina/kW) i jest statyczna — slugi katalogu biorą się z drzewa V-techa tymi samymi
regułami, więc pozostaje ważna. Klucze bez dopasowania trafiają na przodka, nigdy na 404.

### Odświeżenie katalogu

```bash
./bin/import-catalog.sh                                     # drzewo → wtyczka → tabele + 200 wersji
docker compose --profile cli run --rm wpcli vts vt sync     # reszta wersji od ręki
docker compose --profile cli run --rm wpcli vts vt tree     # samo drzewo (jak przycisk w panelu wtyczki)
docker compose --profile cli run --rm wpcli vts vt import   # drzewo z wtyczki → tabele
```

Bezpieczniki: drzewo z mniej niż 80 % poprzednich kombinacji nie zapisuje się (regułą wtyczki),
import ze spadkiem marek albo silników powyżej 10 % przerywa się; oba obchodzi `--force`.

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
- [ ] **Dowód społeczny** — realne opinie i ocena z profilu Google zamiast trzech opinii
      demonstracyjnych z `content/dyno/seed.json` (wgrywa je `bin/seed-dev.sh`):
      `wp option update vts_google_rating|vts_google_reviews_count|vts_google_reviews_url|vts_reviews`.
      Dopóki `vts_reviews` jest puste, sekcja „Co mówią klienci" **nie renderuje się wcale**.
- [ ] **Wykresy demonstracyjne** — 14 wpisów z flagą `_vts_seed` to wygenerowane wydruki
      (`tools/make-dyno-charts.py`), nie pomiary klientów. Przed startem usunąć z panelu
      (albo ponownie uruchomić seed z pustą listą) i wgrać archiwum hamowni.
- [ ] **Certyfikat V-tech** — skan lub zdjęcie do sekcji dowodu społecznego (brak materiału)
- [ ] Archiwum wykresów z hamowni + zgody właścicieli na publikację. Pojedynczy wykres ma
      własny widok (`vts-dyno.php`, filtr `the_content`): wydruk, liczby, etykiety, link do
      wersji w katalogu (pole „Powiązany silnik z katalogu" w panelu).
- [ ] **Akceptacja prawna treści DPF / EGR / SCR.** Flaga `vts_feature_emissions_pages`
      jest **włączona** na wyraźną decyzję klienta (mail o strukturze serwisu), a kafle
      tych układów stoją na `/podnoszenie-mocy/dodatkowe-uslugi-ecu/` i w bloku flotowym.
      Wyłączenie z powrotem to `wp option update vts_feature_emissions_pages 0`.
- [ ] Akceptacja prawna: regulamin, polityka prywatności
- [ ] **Dekoder VIN** — `vts_feature_vin_decoder` jest **wyłączony** (decyzja klienta,
      IX 2026: rząd VIN wydłużał wyszukiwarkę, a rozpoznaje tylko markę). Kod zostaje;
      włączenie to `wp option update vts_feature_vin_decoder 1`. Pełne dekodowanie
      (model + wersja) wymagałoby płatnego API — patrz `PYTANIA-DO-KLIENTA.md`.
- [ ] Zdjęcia własne zamiast stocku w `foto-*.webp` i `usp-*.webp` (kamper, hamownia,
      warsztat) — źródła i sposób podmiany w `assets/img/CREDITS.md`
- [ ] **Liczby na stronie głównej i „O nas"** (10 000+ modyfikacji, 60+ flot) podał klient
      w uwagach — do potwierdzenia przed startem; lata liczą się same od 2008
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
`[vts_usps]` (5 przewag ze zdjęciami), `[vts_proces]` (5 kroków), `[vts_pomiar]` (4 kroki
pomiaru), `[vts_stages]` (Stage 1/2), `[vts_liczby]`, `[vts_social_proof]`, `[vts_map]`,
`[vts_gauges]` (5 filarów), `[vts_photo img=… alt=…]` (duże zdjęcie 4:3 z `assets/img/foto-*.webp`, bez podpisu),
`[vts_jumpnav]` (kotwice sekcji z `pages.json → anchors`, te same co 3. poziom menu),
`[vts_dyno_latest]` (ostatni wykres z bazy albo przebieg poglądowy SVG). Proces stoi na stronie
głównej i na `/podnoszenie-mocy/` — z jednego źródła.

**2a. Układ sekcji z uwag klienta (IX 2026).** Nagłówek H2 zawsze nad tekstem, nie w środku;
sekcje z formularzem mają dane kontaktowe po lewej, formularz po prawej; ikony w kafelkach
i listach 50 px; tytuły kafelków to H3 i nie zmieniają koloru na hover; podstrony kategorii
chip tuningu i odblokowywania sterowników zdjęte (301 na strony nadrzędne, patrz
`legacy-static.php`); zakładka „Kontakt" zeszła z menu — zostaje przycisk „Umów pomiar".

**2b. Język treści.** Forma „Ty", zdania krótkie, bez myślników i konstrukcji „nie X, tylko Y",
korzyść i konkret na początku akapitu. Nagłówki: H1 z tytułu strony, H2 sekcje, H3 kafelki,
H4 pozycje list `.vts-ilist`.

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
wp option update vts_feature_vin_decoder 1      # włączenie rzędu VIN w wyszukiwarce (domyślnie wyłączony)
wp option update vts_feature_ai_agent 1         # asystent AI (etap 2)
wp option update vts_feature_emissions_pages 0  # ukrycie treści DPF/EGR/SCR (domyślnie włączone)
wp option update vts_catalog_index_level model  # cofnięcie indeksowania katalogu
```

`vts_catalog_index_level` to przełącznik odwrotu: gdyby Search Console zgłosiła
problem z cienką treścią przy 3200 stronach wariantów, schodzimy poziom wyżej
jedną komendą — adresy zostają, znika tylko indeksowanie.
