#!/usr/bin/env bash
# Dane demonstracyjne dla środowiska deweloperskiego: trzy opinie i ocena
# (content/seed.json). NIE uruchamiać na produkcji — to nie są realne opinie klientów.
set -euo pipefail
cd "$(dirname "$0")/.."
docker compose --profile cli run --rm wpcli eval-file /content/seed-dev.php
