#!/usr/bin/env bash
# Katalog mocy z wtyczki VT Konfigurator: pobiera drzewo pojazdów ze sklepu V-techa
# (to samo, co przycisk „Odśwież dane pojazdów" w Ustawienia → VT Konfigurator),
# zapisuje je do tabel katalogu i ogrzewa pierwszą paczkę przyrostów.
# Resztę przyrostów dociąga cron co godzinę albo `wp vts vt sync`.
#
#   ./bin/import-catalog.sh            # drzewo + import + 200 silników
#   ./bin/import-catalog.sh --force    # mimo bezpieczników (spadek liczby rekordów)
#   ./bin/import-catalog.sh --all      # drzewo + import + wszystkie silniki (kilkadziesiąt minut)
#
set -euo pipefail
cd "$(dirname "$0")/.."

WPC() { docker compose --profile cli run --rm wpcli "$@"; }

FORCE=""; LIMIT="--limit=200"
for a in "$@"; do
  case "$a" in
    --force) FORCE="--force";;
    --all)   LIMIT="";;
  esac
done

echo "1/3  drzewo pojazdów ze sklepu V-techa → wtyczka"
WPC vts vt tree $FORCE
echo "2/3  drzewo → tabele katalogu"
WPC vts vt import $FORCE
echo "3/3  przyrosty (${LIMIT:-wszystkie})"
WPC vts vt sync $LIMIT
