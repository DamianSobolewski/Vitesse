#!/usr/bin/env bash
# Pełne odświeżenie katalogu mocy z konfiguratora V-techa.
#
#   ./bin/refresh-catalog.sh          # drzewo + wyniki + scalenie + import
#   ./bin/refresh-catalog.sh --tree   # samo drzewo (szybkie, bez pobierania wyników)
#
# Pobieranie wyników jest wznawialne — cache leży w tools/scrape/raw-vtech/.
set -euo pipefail
cd "$(dirname "$0")/.."

echo "1/5  drzewo pojazdów z V-techa"
python3 tools/scrape/vtech-tree.py

if [ "${1:-}" = "--tree" ]; then
  echo "pominięto pobieranie wyników (--tree)"; exit 0
fi

echo
echo "2/5  wyniki dla kombinacji (wznawialne)"
python3 tools/scrape/vtech-gains.py

echo
echo "3/5  dołożenie marek spoza konfiguratora V-techa"
python3 tools/scrape/merge-catalog.py

echo
echo "4/5  mapa starych adresów na nowe ścieżki"
python3 tools/scrape/map-legacy.py
cp content/redirects/legacy-catalog.json mu-plugins/vts-data/

echo
echo "5/5  import do bazy"
./bin/import-catalog.sh "${@:2}"
