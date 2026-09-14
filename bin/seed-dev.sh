#!/usr/bin/env bash
# Dane demonstracyjne dla środowiska deweloperskiego: 14 wykresów z hamowni
# (wydruki wygenerowane z content/dyno/seed.json) i opinie. NIE uruchamiać na
# produkcji — to nie są realne pomiary ani realne opinie klientów.
set -euo pipefail
cd "$(dirname "$0")/.."
docker compose --profile cli run --rm wpcli eval-file /content/seed-dyno.php
