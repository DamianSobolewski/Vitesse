# Vitesse V-tech Łódź — serwis firmowy

WordPress na Dockerze dla warsztatu chip tuningu w Łodzi. Cała logika serwisu siedzi we własnych
wtyczkach `mu-plugins/`, a treść stron jest kodem w `content/` — nic istotnego nie klika się w edytorze.

Trzy rzeczy, których nie ma w typowym WordPressie i o które w tym projekcie chodzi:

- **wyszukiwarka mocy** w nagłówku strony głównej — kaskada Marka → Model → Generacja → Silnik po
  katalogu ~4900 wersji silnikowych, z bramką na adres e-mail przed pokazaniem przyrostów,
- **kalkulator oszczędności** dla flot,
- **baza wykresów z hamowni** z uproszczonym panelem dla obsługi warsztatu.

Szczegóły architektury: **[PLAN-WDROZENIA.md](PLAN-WDROZENIA.md)**.

---

## Czego potrzebujesz

- **Docker** i **docker compose** (v2, czyli `docker compose`, nie `docker-compose`)
- **git**
- wolny port TCP na maszynie
- `openssl` do wygenerowania sekretów

Nic poza tym. PHP, WordPress, MariaDB i WP-CLI działają w kontenerach — **nie instaluj ich na serwerze**.

---

## Instalacja

### 1. Repozytorium i konfiguracja

```bash
git clone git@github.com:DamianSobolewski/Vitesse.git
cd Vitesse
cp .env.example .env
```

Otwórz `.env` i uzupełnij. **Puste pola sekretów to nie jest opcja** — poniżej co gdzie wpisać:

| Pole | Co wpisać |
|---|---|
| `DB_PASSWORD`, `DB_ROOT_PASSWORD` | dowolne mocne hasła, np. `openssl rand -hex 16` |
| `VTS_TOKEN_SECRET`, `VTS_LEAD_SALT` | `openssl rand -hex 32` — osobno dla każdego |
| `WP_ADMIN_PASSWORD` | hasło administratora WordPressa |
| `VTS_OPERATOR_PASSWORD` | hasło konta obsługi hamowni |
| `WP_PORT` | port wolny na tej maszynie (domyślnie `8090`) |
| `WP_BIND` | `127.0.0.1` gdy używasz reverse proxy, `0.0.0.0` gdy wystawiasz wprost |
| `VTS_LEAD_INBOX` | adres, na który mają trafiać zapytania z formularzy |

Szybkie wygenerowanie sekretów:

```bash
echo "VTS_TOKEN_SECRET=$(openssl rand -hex 32)"
echo "VTS_LEAD_SALT=$(openssl rand -hex 32)"
```

> Jeśli zostawisz `VTS_TOKEN_SECRET` pusty, bramka leadowa nadal działa, ale token podpisuje się
> kluczami WordPressa — ich rotacja unieważni wszystkie wydane tokeny. Na produkcji ustaw własny.

### 2. Uruchomienie kontenerów

```bash
docker compose up -d
```

### 3. Instalacja — pięć kroków, **kolejność obowiązkowa**

```bash
./bin/bootstrap.sh https://twoja-domena.pl   # rdzeń WordPressa, motyw, wtyczki, konta
./bin/migrate.sh                             # tabele katalogu mocy i leadów
./bin/import.sh                              # strony, treść, menu, SEO, formularz kontaktowy
./bin/import-catalog.sh                      # katalog mocy z plików w repozytorium
./bin/seed-dev.sh                            # przykładowe wykresy z hamowni (patrz ostrzeżenie niżej)
```

Co robi każdy krok i jak wygląda poprawny wynik:

| Krok | Efekt | Poprawny wynik |
|---|---|---|
| `bootstrap.sh` | instaluje WordPressa pod podanym adresem | `Gotowe. Adres serwisu: https://…` |
| `migrate.sh` | tworzy 7 własnych tabel | lista `Created table` albo `bez zmian` |
| `import.sh` | wgrywa 28 stron, menu i formularz | `strony: 28` |
| `import-catalog.sh` | wypełnia katalog | `OPUBLIKOWANE: 61 marek, … 4853 silników` |
| `seed-dev.sh` | dodaje 4 przykładowe wykresy | `wykresy demonstracyjne: 4` |

**Adres podany w `bootstrap.sh` zapisuje się do bazy.** Podanie złego oznacza, że serwis będzie
przekierowywał na niego z każdego innego hosta. Jeśli się pomylisz, uruchom `bootstrap.sh` ponownie
z właściwym adresem — skrypt jest idempotentny i sam poprawi wpis.

---

## Reverse proxy

Za proxy WordPress nie wie, że połączenie idzie po HTTPS — generuje adresy `http://`, co daje pętlę
przekierowań i mieszaną treść. Obsługa jest już w `docker-compose.yml`; po stronie proxy przekaż nagłówki:

```nginx
location / {
    proxy_pass         http://127.0.0.1:8090;
    proxy_set_header   Host              $host;
    proxy_set_header   X-Real-IP         $remote_addr;
    proxy_set_header   X-Forwarded-For   $proxy_add_x_forwarded_for;
    proxy_set_header   X-Forwarded-Proto $scheme;
    proxy_set_header   X-Forwarded-Host  $host;
}
```

Przy `WP_BIND=127.0.0.1` (domyślnie) kontener nie jest dostępny z sieci wprost — tylko przez proxy.

---

## Weryfikacja po instalacji

```bash
# strona główna odpowiada pod właściwym adresem, bez przekierowania gdzie indziej
curl -sI https://twoja-domena.pl/ | head -3

# katalog mocy jest wypełniony — oczekiwane ok. 61 marek
curl -s https://twoja-domena.pl/wp-json/vitesse/v1/catalog/makes | head -c 200

# strona wewnątrz katalogu odpowiada 200
curl -s -o /dev/null -w '%{http_code}\n' https://twoja-domena.pl/chiptuning/ford/focus/

# stary adres ze starego serwisu przekierowuje 301 na nową ścieżkę
curl -s -o /dev/null -w '%{http_code} %{redirect_url}\n' \
  'https://twoja-domena.pl/chiptuning_lodz.php?auto=Ford'

# indeksowanie zablokowane — także na stronach katalogu
curl -s https://twoja-domena.pl/chiptuning/ford/ | grep -o '<meta name="robots"[^>]*>'
```

Ostatnie polecenie musi zwrócić `noindex`. Jeśli nie zwraca nic, blokada nie działa —
sprawdź `docker compose --profile cli run --rm wpcli option get blog_public` (ma być `0`).

Do zdjęcia blokady przed startem produkcyjnym:

```bash
docker compose --profile cli run --rm wpcli option update blog_public 1
```

---

## Gdy coś nie działa

| Objaw | Przyczyna | Co zrobić |
|---|---|---|
| Katalog i `/wp-json/…` zwracają **404** | brak przyjaznych odnośników | `docker compose --profile cli run --rm wpcli rewrite structure '/%postname%/' --hard` |
| Strony są, ale **kafelki puste albo brak menu** | nie przeszedł `import.sh` | uruchom `./bin/import.sh` i sprawdź, czy kończy się `strony: 28` |
| Wyszukiwarka w nagłówku ma **pustą listę marek** | nie przeszedł `import-catalog.sh` | uruchom go ponownie; sprawdź, że `content/catalog/*.json` istnieją |
| Panel **nie przyjmuje zdjęć** | złe uprawnienia katalogu | `docker compose exec -u root wordpress chown -R www-data:www-data /var/www/html/wp-content/uploads` |
| Serwis **przekierowuje na inny adres** | zły adres w bazie | `./bin/bootstrap.sh https://właściwy-adres` |
| Pętla przekierowań za proxy | brak nagłówka `X-Forwarded-Proto` | patrz sekcja *Reverse proxy* |

Logi: `docker compose logs -f wordpress`

---

## Czego NIE uruchamiać

- **`bin/refresh-catalog.sh`** — pobiera katalog na nowo z serwera V-techa, prawie 5000 zapytań.
  Dane jadą w repozytorium jako pliki JSON, więc na serwerze wystarczy `import-catalog.sh`.
  Skrypt jest do odświeżania danych, i to ze stanowiska deweloperskiego.
- **`bin/seed-dev.sh` na produkcji** — wgrywa zdjęcia zastępcze przeniesione ze starej strony,
  bez zgód właścicieli pojazdów na publikację. Na środowisku pokazowym są w porządku i sprawiają,
  że sekcja „Wykresy i osiągi" nie jest pusta. Przed startem produkcyjnym wymienić na archiwum klienta.

---

## Czego nie ma w repozytorium

Rdzeń WordPressa (instaluje `bootstrap.sh` do wolumenu Dockera), katalog `uploads/`, plik `.env`,
lustro starego serwisu oraz cache scrapera. Wszystko to odtwarza się z powyższych kroków albo
nie jest potrzebne do uruchomienia.

---

## Konta po instalacji

| Konto | Rola | Gdzie hasło |
|---|---|---|
| `WP_ADMIN_USER` | administrator | `.env` → `WP_ADMIN_PASSWORD` |
| `VTS_OPERATOR_USER` | obsługa hamowni — widzi wyłącznie panel wykresów | `.env` → `VTS_OPERATOR_PASSWORD` |

Panel administracyjny: `https://twoja-domena.pl/wp-admin`

---

## Odświeżenie katalogu mocy

Tylko ze stanowiska deweloperskiego, nie z serwera:

```bash
./bin/refresh-catalog.sh          # drzewo + wyniki + scalenie + mapa przekierowań + import
./bin/refresh-catalog.sh --tree   # samo drzewo, bez pobierania wyników
```

Pobieranie jest wznawialne. Po odświeżeniu zacommituj zmienione `content/catalog/*.json`
i `content/redirects/legacy-catalog.json` — serwer bierze dane właśnie stamtąd.
