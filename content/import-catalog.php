<?php
/**
 * Import katalogu mocy z content/catalog/*.json do własnych tabel.
 * Uruchamiany przez bin/import-catalog.sh. Idempotentny.
 *
 * Zasady:
 *  - kluczem tożsamości jest legacy_key, nie slug (slug jest generowany i bywa zmienny),
 *  - nie kasujemy wierszy — nieobecne w imporcie dostają visibility = 0,
 *  - silnik bez danych o przyroście nie jest publikowany (obrona przed pustymi stronami),
 *  - spadek liczby wierszy > 10% przerywa import (chroni przed zepsutym scrapem).
 */

if (!defined('ABSPATH')) {
    exit(1);
}

const VTS_DIR = '/content/catalog';

function vts_log(string $m): void
{
    echo $m . "\n";
}

function vts_read(string $name): array
{
    $f = VTS_DIR . "/{$name}.json";
    if (!file_exists($f)) {
        vts_log("BRAK pliku {$f}");
        exit(1);
    }
    return json_decode(file_get_contents($f), true) ?: [];
}

/** Wstawia paczkami — 3 tys. pojedynczych INSERT-ów trwałoby minuty. */
function vts_bulk(string $table, array $cols, array $rows, array $update, int $chunk = 400): int
{
    global $wpdb;
    if (!$rows) {
        return 0;
    }

    $done = 0;
    $collist = '`' . implode('`,`', $cols) . '`';
    $set = implode(',', array_map(fn($c) => "`{$c}`=VALUES(`{$c}`)", $update));

    foreach (array_chunk($rows, $chunk) as $part) {
        $ph = [];
        $vals = [];
        foreach ($part as $r) {
            $ph[] = '(' . implode(',', array_fill(0, count($cols), '%s')) . ')';
            foreach ($cols as $c) {
                $vals[] = $r[$c] ?? null;
            }
        }
        $sql = "INSERT INTO {$table} ({$collist}) VALUES " . implode(',', $ph)
             . " ON DUPLICATE KEY UPDATE {$set}";
        $wpdb->query($wpdb->prepare($sql, $vals));
        $done += count($part);
    }

    return $done;
}

/* ------------------------------------------------------------- kontrola */

$manifest = json_decode(file_get_contents(VTS_DIR . '/MANIFEST.json'), true);
$prev = get_option('vts_catalog_manifest');
$force = in_array('--force', (array) ($argv ?? []), true);

if ($prev && !$force) {
    foreach (['engines', 'makes'] as $k) {
        $old = (int) ($prev['counts'][$k] ?? 0);
        $new = (int) ($manifest['counts'][$k] ?? 0);
        if ($old > 0 && $new < $old * 0.9) {
            vts_log("PRZERWANO: liczba '{$k}' spadła z {$old} do {$new} (>10%). Użyj --force, jeśli to zamierzone.");
            exit(1);
        }
    }
}

global $wpdb;
$wpdb->query('SET foreign_key_checks = 0');

$now = current_time('mysql');
$T = fn($n) => vts_table($n);

/* --------------------------------------------------------------- marki */

$makes = vts_read('makes');
$rows = [];
foreach ($makes as $m) {
    $rows[] = [
        'slug'         => $m['slug'],
        'name'         => $m['name'],
        'legacy_key'   => $m['legacy_key'],
        'sort'         => $m['sort'],
        'is_truck'     => !empty($m['is_truck']) ? 1 : 0,
        // Jaguar i Land Rover mają chip tuning w ofercie — ukryta jest dopiero
        // przyszła linia serwisowa, a to osobna rzecz (patrz vts_feature).
        'visibility'   => 1,
        'feature_flag' => null,
        'updated_at'   => $now,
    ];
}
vts_bulk($T('make'), ['slug','name','legacy_key','sort','is_truck','visibility','feature_flag','updated_at'],
    $rows, ['name','sort','is_truck','visibility','updated_at']);
vts_log('marki: ' . count($rows));

$make_id = [];
foreach ($wpdb->get_results('SELECT id, slug FROM ' . $T('make'), ARRAY_A) as $r) {
    $make_id[$r['slug']] = (int) $r['id'];
}

/* -------------------------------------------------------------- modele */

$rows = [];
foreach (vts_read('models') as $m) {
    if (!isset($make_id[$m['make_slug']])) {
        continue;
    }
    $rows[] = [
        'make_id'       => $make_id[$m['make_slug']],
        'slug'          => $m['slug'],
        'name'          => $m['name'],
        'vehicle_class' => $m['vehicle_class'],
        'legacy_key'    => $m['legacy_key'],
        'sort'          => $m['sort'],
        'visibility'    => 1,
        'updated_at'    => $now,
    ];
}
vts_bulk($T('model'), ['make_id','slug','name','vehicle_class','legacy_key','sort','visibility','updated_at'],
    $rows, ['name','vehicle_class','sort','visibility','updated_at']);
vts_log('modele: ' . count($rows));

$model_id = [];
foreach ($wpdb->get_results('SELECT o.id, k.slug AS mk, o.slug FROM ' . $T('model') . ' o
                             JOIN ' . $T('make') . ' k ON k.id = o.make_id', ARRAY_A) as $r) {
    $model_id[$r['mk'] . '/' . $r['slug']] = (int) $r['id'];
}

/* ----------------------------------------------------------- generacje */

$rows = [];
foreach (vts_read('generations') as $g) {
    $key = $g['make_slug'] . '/' . $g['model_slug'];
    if (!isset($model_id[$key])) {
        continue;
    }
    $rows[] = [
        'model_id'   => $model_id[$key],
        'slug'       => $g['slug'],
        'name'       => $g['name'],
        'year_from'  => $g['year_from'],
        'year_to'    => $g['year_to'],
        'legacy_key' => $g['legacy_key'],
        'sort'       => $g['sort'],
        'visibility' => 1,
        'updated_at' => $now,
    ];
}
vts_bulk($T('generation'), ['model_id','slug','name','year_from','year_to','legacy_key','sort','visibility','updated_at'],
    $rows, ['name','year_from','year_to','sort','visibility','updated_at']);
vts_log('generacje: ' . count($rows));

$gen_id = [];
foreach ($wpdb->get_results('SELECT g.id, k.slug AS mk, o.slug AS md, g.slug FROM ' . $T('generation') . ' g
                             JOIN ' . $T('model') . ' o ON o.id = g.model_id
                             JOIN ' . $T('make') . ' k ON k.id = o.make_id', ARRAY_A) as $r) {
    $gen_id[$r['mk'] . '/' . $r['md'] . '/' . $r['slug']] = (int) $r['id'];
}

/* ------------------------------------------------------------- silniki */

$rows = [];
$name_by_slug = [];
foreach ($makes as $m) {
    $name_by_slug[$m['slug']] = $m['name'];
}

foreach (vts_read('engines') as $e) {
    $key = $e['make_slug'] . '/' . $e['model_slug'] . '/' . $e['gen_slug'];
    if (!isset($gen_id[$key])) {
        continue;
    }
    // po tym polu działa wyszukiwanie tekstowe w trzecim wierszu wyszukiwarki
    $blob = trim(($name_by_slug[$e['make_slug']] ?? '') . ' ' . $e['model_slug'] . ' '
                 . $e['gen_slug'] . ' ' . $e['name'] . ' ' . $e['fuel']);

    $rows[] = [
        'generation_id' => $gen_id[$key],
        'slug'          => $e['slug'],
        'name'          => $e['name'],
        'fuel'          => $e['fuel'],
        'stock_kw'      => $e['stock_kw'] ?: 0,
        'stock_hp'      => $e['stock_hp'] ?: 0,
        'stock_nm'      => $e['stock_nm'] ?: 0,
        'legacy_key'    => $e['legacy_key'],
        'search_blob'   => mb_substr($blob, 0, 255),
        'sort'          => $e['sort'],
        'visibility'    => 1,
        'updated_at'    => $now,
    ];
}
vts_bulk($T('engine'),
    ['generation_id','slug','name','fuel','stock_kw','stock_hp','stock_nm','legacy_key','search_blob','sort','visibility','updated_at'],
    $rows, ['name','fuel','stock_kw','stock_hp','stock_nm','search_blob','sort','visibility','updated_at']);
vts_log('silniki: ' . count($rows));

$engine_id = [];
foreach ($wpdb->get_results('SELECT id, legacy_key FROM ' . $T('engine'), ARRAY_A) as $r) {
    $engine_id[$r['legacy_key']] = (int) $r['id'];
}

/* ---------------------------------------------------------- przyrosty */

$rows = [];
foreach (vts_read('gains') as $g) {
    if (!isset($engine_id[$g['engine_legacy_key']])) {
        continue;
    }
    $rows[] = [
        'engine_id'    => $engine_id[$g['engine_legacy_key']],
        'service_code' => $g['service_code'],
        'tuned_hp'     => $g['tuned_hp'],
        'tuned_nm'     => $g['tuned_nm'],
        'visibility'   => 1,
        'updated_at'   => $now,
    ];
}
vts_bulk($T('gain'), ['engine_id','service_code','tuned_hp','tuned_nm','visibility','updated_at'],
    $rows, ['tuned_hp','tuned_nm','visibility','updated_at']);
vts_log('przyrosty: ' . count($rows));

/* ------------------------------------------- bramka jakości i liczniki */

// Silnik bez ani jednego przyrostu nie ma czego pokazać — nie publikujemy go.
$hidden = $wpdb->query(
    'UPDATE ' . $T('engine') . ' e
        LEFT JOIN ' . $T('gain') . ' g ON g.engine_id = e.id
        SET e.visibility = 0
      WHERE g.id IS NULL'
);
vts_log("ukryte silniki bez danych o przyroście: {$hidden}");

$wpdb->query('UPDATE ' . $T('generation') . ' g SET g.engine_count =
    (SELECT COUNT(*) FROM ' . $T('engine') . ' e WHERE e.generation_id = g.id AND e.visibility = 1)');
$wpdb->query('UPDATE ' . $T('generation') . ' SET visibility = 0 WHERE engine_count = 0');

$wpdb->query('UPDATE ' . $T('model') . ' o SET o.generation_count =
    (SELECT COUNT(*) FROM ' . $T('generation') . ' g WHERE g.model_id = o.id AND g.visibility = 1)');
$wpdb->query('UPDATE ' . $T('model') . ' SET visibility = 0 WHERE generation_count = 0');

$wpdb->query('UPDATE ' . $T('make') . ' k SET k.model_count =
    (SELECT COUNT(*) FROM ' . $T('model') . ' o WHERE o.make_id = k.id AND o.visibility = 1)');
$wpdb->query('UPDATE ' . $T('make') . ' SET visibility = 0 WHERE model_count = 0');

$wpdb->query('SET foreign_key_checks = 1');

update_option('vts_catalog_manifest', $manifest, false);
if (function_exists('vts_flush_catalog_cache')) {
    vts_flush_catalog_cache();
}

$c = vts_catalog_counts();
vts_log('');
vts_log("OPUBLIKOWANE: {$c['make']} marek, {$c['model']} modeli, {$c['generation']} generacji, {$c['engine']} silników");
