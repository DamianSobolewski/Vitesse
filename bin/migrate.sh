#!/usr/bin/env bash
# Aplikuje schemat własnych tabel (katalog mocy, leady).
# Idempotentny — można uruchamiać wielokrotnie.
#
#   ./bin/migrate.sh
#
set -euo pipefail
cd "$(dirname "$0")/.."

docker compose --profile cli run --rm wpcli eval '
$r = vts_schema_migrate();
foreach ($r as $name => $changes) {
    if ($name === "foreign_keys") {
        echo "FK dodane: " . (empty($changes) ? "(wszystkie już istniały)" : implode(", ", $changes)) . "\n";
        continue;
    }
    $msg = empty($changes) ? "bez zmian" : implode("; ", $changes);
    printf("%-14s %s\n", $name, $msg);
}
echo "vts_db_version = " . get_option("vts_db_version") . "\n";
'
