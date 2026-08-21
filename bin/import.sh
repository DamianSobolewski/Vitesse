#!/usr/bin/env bash
# Import struktury serwisu: strony, treść, menu, SEO. Idempotentny.
#
#   ./bin/import.sh
#
set -euo pipefail
cd "$(dirname "$0")/.."

docker compose --profile cli run --rm wpcli eval-file /content/import.php
