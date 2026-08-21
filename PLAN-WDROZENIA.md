# VITESSE — dokumentacja serwisu

WordPress + Elementor FREE + Docker. Kierunek wizualny „Hamownia" (ciemny, warsztatowy).

---

## Uruchomienie

```bash
cp .env.example .env         # uzupełnij hasła i sekrety
docker compose up -d         # WordPress :8090, phpMyAdmin :8091
./bin/migrate.sh             # tabele katalogu i leadów
./bin/import.sh              # strony, treść, menu, SEO, formularz
./bin/import-catalog.sh      # katalog mocy z content/catalog/*.json
./bin/seed-dev.sh            # dane demonstracyjne wykresów (TYLKO dev)
```

Logowanie: `/wp-admin`, dane w `.env` (`WP_ADMIN_USER`, `WP_ADMIN_PASSWORD`).
Konto obsługi hamowni: `VTS_OPERATOR_USER` / `VTS_OPERATOR_PASSWORD`.

---

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
| `vts-power-search.php` | REST kaskady, token HMAC, bramka leadowa |
| `vts-leads.php` | zapis leada, mail, autoresponder, retencja, podgląd w adminie |
| `vts-fleet-calc.php` | kalkulator oszczędności flotowych |
| `vts-dyno.php` | CPT wykresów, taksonomie, siatka z filtrem |
| `vts-dyno-panel.php` | rola `vts_dyno_operator`, okrojony wp-admin |
| `vts-redirects.php` | matryca 301 ze starego serwisu |
| `vts-site.php` | zasoby, nagłówek, stopka 4-kolumnowa, FAB |
| `vts-content.php` | hero, FAQ, kontakt, okruszki, JSON-LD |
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

Własne tabele, nie CPT: 3 268 wariantów silnikowych × 2 usługi to ~60 tys. wpisów
w `wp_posts` i ~600 tys. w `wp_postmeta`. Kaskada byłaby wtedy `meta_query`
z JOIN-ami po `LONGTEXT`, trzy razy na jedną interakcję w Hero.

Opublikowane: **55 marek, 512 modeli, 1006 generacji, 3210 silników**.
Ukryte przez bramkę jakości: rekordy bez danych o przyroście.

Kluczem tożsamości jest `legacy_key` (wartość `?auto=` ze starego serwisu), nie slug —
slug jest generowany i bywa zmienny. Ten sam indeks obsługuje matrycę 301.

**Ponowny scrape:**
```bash
python3 tools/scrape/scrape-catalog.py     # 4 poziomy, wznawialny, cache w raw/
python3 tools/scrape/scrape-orphans.py     # modele bez poziomu generacji
./bin/import-catalog.sh                    # upsert; --force omija ochronę -10%
```

---

## Animacja hero („zapłon")

Zdjęcie pokazuje auto na wprost w ciemnym garażu. Reflektory na fotografii są zapalone, więc nie
da się ich zgasić wprost — zamiast tego kładziemy na nie **zasłonę w kolorze dobranym z samego
zdjęcia** i zdejmujemy ją z dwoma mrugnięciami (rozruch lampy). Potem zostaje delikatny oddech
poświaty. Kolor zasłony to `#020303`, a nie kolor tła strony: otoczenie lamp na zdjęciu jest
praktycznie czysto czarne i `#0F1116` zostawiał widoczne szare plamy.

Kadr jest **złożony**: auto zmniejszono do połowy szerokości i dosunięto do prawej krawędzi,
resztę płótna wypełniono czernią z miękkim wygaszeniem krawędzi. Bez tego całe auto nie mieści się
obok nagłówka i wyszukiwarki, które zajmują lewe dwie trzecie sekcji.

* Tło **nigdy nie jest animowane** — to element LCP. Animujemy wyłącznie warstwy nad nim.
* Bez JavaScriptu światła są zapalone od razu; przy `prefers-reduced-motion` również.
* Karty wjeżdżają przy przewijaniu, z przesunięciem 60 ms. Bloki tekstowe zostają nieruchome.
* Warianty tła: `hero.webp` (1800 px) i `hero-sm.webp` (900 px) przez zmienne CSS,
  z osobnymi wskazaniami `preload` per szerokość ekranu.

## Weryfikacja

```bash
node tests/cards.mjs        # strażnik pustych kafelków
node tests/hero-anim.mjs
node tests/rwd.mjs          # 1440/768/390: brak poziomego scrolla, jeden H1
node tests/redirects.mjs    # 71 starych adresów → 301 w jednym skoku na stronę 200
node tests/full-check.mjs   # kaskada, bramka, kalkulator, wykresy, LCP
```

Stan ostatniego przebiegu: RWD bez zastrzeżeń, 71/71 przekierowań, LCP ~250 ms,
zero błędów JavaScriptu.

---

## Do produkcji

- [ ] Dane rejestrowe: `wp option update vts_legal_name|vts_nip|vts_regon`
- [ ] Potwierdzić przypisanie telefonów do działów (`vts-config.php`)
- [ ] Skrzynki leadowe w `.env`: `VTS_LEAD_INBOX`, `VTS_LEAD_INBOX_FLEET`
- [ ] SMTP produkcyjny + SPF/DKIM/DMARC (bez tego leady trafią do spamu)
- [ ] Zastąpić zdjęcia zastępcze materiałem klienta; usunąć dane z `bin/seed-dev.sh`
- [ ] Archiwum wykresów z hamowni + zgody właścicieli na publikację
- [ ] Akceptacja prawna: regulamin, polityka prywatności, treści o układach spalin
- [ ] GA4 + Consent Mode v2 i baner zgody (jeszcze nie wdrożone)
- [ ] Google Search Console, sitemapa katalogu (provider jeszcze nie wdrożony)
- [ ] Przełączenie DNS; stary serwer zostawić działający ~30 dni jako siatka bezpieczeństwa

---

## Flagi funkcji

```bash
wp option update vts_feature_jlr_service 1      # linia serwisowa Jaguar / Land Rover
wp option update vts_feature_vin_decoder 1      # dekoder VIN (etap 2)
wp option update vts_feature_ai_agent 1         # asystent AI (etap 2)
wp option update vts_feature_emissions_pages 1  # treści DPF/EGR/SCR — po opinii prawnej
wp option update vts_catalog_index_level model  # cofnięcie indeksowania katalogu
```

`vts_catalog_index_level` to przełącznik odwrotu: gdyby Search Console zgłosiła
problem z cienką treścią przy 3200 stronach wariantów, schodzimy poziom wyżej
jedną komendą — adresy zostają, znika tylko indeksowanie.
