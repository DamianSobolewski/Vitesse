#!/usr/bin/env bash
# Import katalogu mocy z content/catalog/*.json do bazy. Idempotentny.
#
#   ./bin/import-catalog.sh [--force]
#
set -euo pipefail
cd "$(dirname "$0")/.."

docker compose --profile cli run --rm wpcli eval-file /content/import-catalog.php "$@"
