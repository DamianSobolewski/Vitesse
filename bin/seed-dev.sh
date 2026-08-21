#!/usr/bin/env bash
# Dane demonstracyjne dla środowiska deweloperskiego (wykresy z hamowni).
# NIE uruchamiać na produkcji — zdjęcia są materiałem zastępczym bez zgód.
set -euo pipefail
cd "$(dirname "$0")/.."
docker compose --profile cli run --rm wpcli eval-file /content/seed-dyno.php
