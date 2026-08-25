#!/usr/bin/env bash
# Instalacja WordPressa od zera pod zadanym adresem.
#
#   ./bin/bootstrap.sh https://vitesse.przyklad.pl
#
# Uruchamiać RAZ, na świeżym środowisku, przed migrate.sh i importami.
# Jest idempotentny — powtórne uruchomienie nie duplikuje kont ani nie zmienia haseł.
#
# UWAGA: adres jest zapisywany do bazy. Serwis zainstalowany pod złym adresem
# będzie przekierowywał do niego z każdego innego hosta.
set -euo pipefail
cd "$(dirname "$0")/.."

SITE_URL="${1:-}"
if [ -z "$SITE_URL" ]; then
  echo "Użycie: ./bin/bootstrap.sh <adres-serwisu>"
  echo "Przykład: ./bin/bootstrap.sh https://vitesse.przyklad.pl"
  exit 1
fi
case "$SITE_URL" in
  http://*|https://*) ;;
  *) echo "BŁĄD: adres musi zaczynać się od http:// albo https://"; exit 1;;
esac

[ -f .env ] || { echo "BŁĄD: brak pliku .env — skopiuj .env.example i uzupełnij."; exit 1; }
set -a; . ./.env; set +a

for v in DB_NAME DB_USER DB_PASSWORD WP_ADMIN_USER WP_ADMIN_PASSWORD; do
  [ -n "${!v:-}" ] || { echo "BŁĄD: w .env brakuje $v"; exit 1; }
done

WPC() { docker compose --profile cli run --rm wpcli "$@"; }

echo "==> 1/7  czekam na bazę"
for i in $(seq 1 30); do
  WPC db check >/dev/null 2>&1 && break
  [ "$i" = 30 ] && { echo "BŁĄD: baza nie odpowiada. Czy 'docker compose up -d' wystartowało?"; exit 1; }
  sleep 2
done

echo "==> 2/7  rdzeń WordPressa ($SITE_URL)"
if WPC core is-installed >/dev/null 2>&1; then
  echo "    już zainstalowany — aktualizuję tylko adres"
  WPC option update home "$SITE_URL" >/dev/null
  WPC option update siteurl "$SITE_URL" >/dev/null
else
  WPC core install \
    --url="$SITE_URL" \
    --title="Vitesse V-tech Łódź" \
    --admin_user="$WP_ADMIN_USER" \
    --admin_password="$WP_ADMIN_PASSWORD" \
    --admin_email="${VTS_LEAD_INBOX:-biuro@vitesse.auto.pl}" \
    --skip-email
fi

echo "==> 3/7  język polski"
WPC language core install pl_PL --activate >/dev/null 2>&1 || true
WPC option update timezone_string 'Europe/Warsaw' >/dev/null
WPC option update date_format 'j F Y' >/dev/null

echo "==> 4/7  motyw i wtyczki"
WPC theme install hello-elementor --activate >/dev/null 2>&1 || WPC theme activate hello-elementor >/dev/null
WPC plugin install elementor contact-form-7 seo-by-rank-math webp-converter-for-media --activate >/dev/null 2>&1 || true

echo "==> 5/7  sprzątanie i odnośniki"
WPC post delete 1 2 3 --force >/dev/null 2>&1 || true
WPC plugin delete akismet hello >/dev/null 2>&1 || true
WPC theme delete twentytwentythree twentytwentyfour twentytwentyfive >/dev/null 2>&1 || true
# Bez przyjaznych odnośników REST API i adresy katalogu zwracają 404.
WPC rewrite structure '/%postname%/' --hard >/dev/null 2>&1 || true
WPC rewrite flush --hard >/dev/null 2>&1 || true

echo "==> 6/7  konto obsługi hamowni"
if [ -n "${VTS_OPERATOR_USER:-}" ] && [ -n "${VTS_OPERATOR_PASSWORD:-}" ]; then
  if WPC user get "$VTS_OPERATOR_USER" >/dev/null 2>&1; then
    echo "    konto '$VTS_OPERATOR_USER' już istnieje — pomijam"
  else
    WPC user create "$VTS_OPERATOR_USER" "${VTS_OPERATOR_USER}@vitesse.local" \
      --role=vts_dyno_operator --display_name="Obsługa hamowni" \
      --user_pass="$VTS_OPERATOR_PASSWORD" >/dev/null
  fi
else
  echo "    pominięto — brak VTS_OPERATOR_USER / VTS_OPERATOR_PASSWORD w .env"
fi

echo "==> 7/7  uprawnienia i blokada indeksowania"
docker compose exec -u root -T wordpress chown -R www-data:www-data /var/www/html/wp-content/uploads 2>/dev/null || true
# Podgląd dla klienta nie ma trafić do wyszukiwarek. Zdjąć dopiero przed startem:
#   docker compose --profile cli run --rm wpcli option update blog_public 1
WPC option update blog_public 0 >/dev/null

echo
echo "Gotowe. Adres serwisu: $(WPC option get home 2>/dev/null | tr -d '\r')"
echo "Indeksowanie: ZABLOKOWANE (blog_public = 0)"
echo
echo "Dalej po kolei:"
echo "  ./bin/migrate.sh          # tabele katalogu i leadów"
echo "  ./bin/import.sh           # strony, menu, SEO, formularz"
echo "  ./bin/import-catalog.sh   # katalog mocy z plików w repozytorium"
echo "  ./bin/seed-dev.sh         # przykładowe wykresy (tylko środowiska nieprodukcyjne)"
