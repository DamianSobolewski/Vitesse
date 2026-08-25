#!/usr/bin/env bash
# Import katalogu mocy z content/catalog/*.json do bazy. Idempotentny.
#
#   ./bin/import-catalog.sh [--force]
#
set -euo pipefail
cd "$(dirname "$0")/.."

# Argumenty po "--" trafiają do skryptu, nie do wp-cli, które inaczej
# odrzuca nieznane flagi jak --force.
docker compose --profile cli run --rm wpcli eval-file /content/import-catalog.php -- "$@"
