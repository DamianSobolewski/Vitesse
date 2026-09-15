# Vitesse V-tech Łódź — serwis firmowy

WordPress na Dockerze dla warsztatu chip tuningu w Łodzi. Cała logika serwisu siedzi we własnych
wtyczkach `mu-plugins/`, a treść stron jest kodem w `content/` — nic istotnego nie klika się w edytorze.

Trzy rzeczy, których nie ma w typowym WordPressie i o które w tym projekcie chodzi:

- **wyszukiwarka mocy** pod hero strony głównej — kaskada Marka → Model → Generacja → Silnik po
  katalogu ~4700 wersji silnikowych, wynik (PowerChip i Chip Tuning) od razu po kliknięciu,
  bez bramki e-mail; dane pochodzą z wtyczki **VT Konfigurator** od klienta,
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

> Jeśli zostawisz `VTS_TOKEN_SECRET` pusty, wyszukiwarka nadal działa, ale token wyniku podpisuje się
> kluczami WordPressa — ich rotacja unieważni wszystkie wydane tokeny. Na produkcji ustaw własny.

### 2. Uruchomienie kontenerów

```bash
docker compose up -d
```

### 3. Instalacja — pięć kroków, **kolejność obowiązkowa**

```bash
./bin/bootstrap.sh https://twoja-domena.pl   # rdzeń WordPressa, motyw, wtyczki (w tym VT Konfigurator), konta
./bin/migrate.sh                             # tabele katalogu mocy i leadów
./bin/import.sh                              # strony, treść, menu, SEO, formularz kontaktowy
./bin/import-catalog.sh                      # katalog mocy: drzewo pojazdów z konfiguratora V-techa przez wtyczkę
./bin/seed-dev.sh                            # przykładowe wykresy z hamowni (patrz ostrzeżenie niżej)
```

Co robi każdy krok i jak wygląda poprawny wynik:

| Krok | Efekt | Poprawny wynik |
|---|---|---|
| `bootstrap.sh` | instaluje WordPressa pod podanym adresem | `Gotowe. Adres serwisu: https://…` |
| `migrate.sh` | tworzy 7 własnych tabel | lista `Created table` albo `bez zmian` |
| `import.sh` | wgrywa 19 stron, wpisy, menu i formularze | `strony: 19` |
| `import-catalog.sh` | pobiera drzewo ze sklepu V-techa do wtyczki, buduje katalog i ogrzewa pierwsze 200 wersji | `Drzewo zapisane: 60 marek…`, `OPUBLIKOWANE: 60 marek, … 4720 silników` |
| `seed-dev.sh` | dodaje 14 wykresów i 3 opinie demonstracyjne | `wykresy demonstracyjne: 14` |

`import-catalog.sh` potrzebuje dostępu z serwera do `sklep.vtech.pl` (wychodzące HTTPS).
Przyrosty dla pozostałych wersji dociąga cron WordPressa co godzinę (paczka 150 silników,
ok. 2 s każdy) albo od ręki `docker compose --profile cli run --rm wpcli vts vt sync`.
Wersja jeszcze nieogrzana dociąga wynik przy pierwszym wyświetleniu, więc wyszukiwarka
działa od razu po imporcie.

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

# katalog mocy jest wypełniony — oczekiwane 60 marek
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
| Strony są, ale **kafelki puste albo brak menu** | nie przeszedł `import.sh` | uruchom `./bin/import.sh` i sprawdź, czy kończy się `strony: 19` |
| Wyszukiwarka w nagłówku ma **pustą listę marek** | nie przeszedł `import-catalog.sh` | uruchom go ponownie; w `wp-admin` sprawdź, że wtyczka VT Konfigurator jest aktywna i w Ustawienia → VT Konfigurator widać stan drzewa |
| Wynik wyszukiwarki: **„Nie udało się pobrać”** albo długo się ładuje | serwer nie dosięga `sklep.vtech.pl` | sprawdź wychodzący ruch HTTPS z kontenera; log błędów w Ustawienia → VT Konfigurator |
| Panel **nie przyjmuje zdjęć** | złe uprawnienia katalogu | `docker compose exec -u root wordpress chown -R www-data:www-data /var/www/html/wp-content/uploads` |
| Serwis **przekierowuje na inny adres** | zły adres w bazie | `./bin/bootstrap.sh https://właściwy-adres` |
| Pętla przekierowań za proxy | brak nagłówka `X-Forwarded-Proto` | patrz sekcja *Reverse proxy* |

Logi: `docker compose logs -f wordpress`

---

## Czego NIE uruchamiać

- **`bin/import-catalog.sh --all`** w godzinach pracy — ściąga wyniki dla wszystkich ~4700 wersji
  naraz (ok. 3 godziny zapytań do sklepu V-techa). Domyślny tryb ogrzewa 200 wersji, resztę robi cron.
- **`bin/seed-dev.sh` na produkcji** — wgrywa 14 wykresów demonstracyjnych (wydruki wygenerowane
  z `content/dyno/seed.json`, pojazdy z katalogu V-tech, ale nie realne pomiary) i trzy opinie.
  Na środowisku pokazowym pokazują pełną stronę; przed startem produkcyjnym usunąć je z panelu
  i wgrać archiwum hamowni oraz opinie z profilu Google.

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

Źródłem jest wtyczka **VT Konfigurator** (`plugins/vt-konfigurator/`, kod od dostawcy bez zmian).
Trzyma drzewo pojazdów z konfiguratora V-techa i pobiera wyniki dla wersji; most
`mu-plugins/vts-vt-bridge.php` przepisuje to do tabel katalogu, na których pracuje serwis.

Bezpośrednio na serwerze, kiedy V-tech dołoży nowe pojazdy:

```bash
./bin/import-catalog.sh                                       # drzewo + tabele + 200 wersji
docker compose --profile cli run --rm wpcli vts vt sync       # reszta wersji od ręki (albo poczekaj na cron)
```

To samo klikiem: Ustawienia → VT Konfigurator → **Odśwież dane pojazdów** odświeża drzewo w wtyczce,
potem `docker compose --profile cli run --rm wpcli vts vt import` przenosi je do katalogu.
Wyniki starsze niż „Czas życia cache" z ustawień wtyczki (domyślnie 7 dni) cron odświeża sam.
Wielkość paczki cronu: `wp option update vts_vt_batch 300`.
