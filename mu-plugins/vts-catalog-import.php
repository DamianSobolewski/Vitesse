<?php
/**
 * Plugin Name: Vitesse — zapis katalogu mocy
 * Description: Wspólny zapis marek, modeli, generacji i silników do własnych tabel (upsert po legacy_key).
 *
 * Zasady:
 *  - kluczem tożsamości jest legacy_key, nie slug (slug jest generowany i bywa zmienny),
 *  - nie kasujemy wierszy — nieobecne w imporcie dostają visibility = 0,
 *  - silnik, dla którego wtyczka potwierdziła brak przyrostu, nie jest publikowany;
 *    silnik jeszcze niesprawdzony zostaje widoczny (wynik dociąga się przy pierwszym wyświetleniu),
 *  - spadek liczby wierszy > 10% przerywa import (chroni przed uszkodzonym drzewem).
 */

if (!defined('ABSPATH')) {
    exit;
}

/** Wstawia paczkami — kilka tysięcy pojedynczych INSERT-ów trwałoby minuty. */
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

/**
 * Zapisuje komplet katalogu (bez przyrostów — te dociąga vts-vt-bridge) i wygasza
 * wszystko, czego bieżący import nie dotknął.
 *
 * @param array{makes:array,models:array,generations:array,engines:array} $sets
 *        makes:       {slug,name,legacy_key,sort,is_truck}
 *        models:      {make_slug,slug,name,legacy_key,sort,vehicle_class}
 *        generations: {make_slug,model_slug,slug,name,legacy_key,year_from,year_to,sort}
 *        engines:     {make_slug,model_slug,gen_slug,slug,name,fuel,stock_kw,stock_hp,stock_nm,legacy_key,sort,vt_year}
 * @param array{force?:bool,manifest?:array} $opts
 * @return array|WP_Error log linijek i liczniki
 */
function vts_catalog_apply(array $sets, array $opts = []): array|WP_Error
{
    global $wpdb;

    $log   = [];
    $force = !empty($opts['force']);
    $prev  = get_option('vts_catalog_manifest');
    $counts = [
        'makes'       => count($sets['makes'] ?? []),
        'models'      => count($sets['models'] ?? []),
        'generations' => count($sets['generations'] ?? []),
        'engines'     => count($sets['engines'] ?? []),
    ];

    if ($prev && !$force) {
        foreach (['engines', 'makes'] as $k) {
            $old = (int) ($prev['counts'][$k] ?? 0);
            $new = $counts[$k];
            if ($old > 0 && $new < $old * 0.9) {
                return new WP_Error(
                    'vts_import_guard',
                    "PRZERWANO: liczba '{$k}' spadła z {$old} do {$new} (>10%). Użyj --force, jeśli to zamierzone."
                );
            }
        }
    }

    $wpdb->query('SET foreign_key_checks = 0');

    $now = current_time('mysql');
    $T = fn($n) => vts_table($n);

    /* ------------------------------------------------------------ marki */

    $rows = [];
    $name_by_slug = [];
    foreach ($sets['makes'] as $m) {
        $name_by_slug[$m['slug']] = $m['name'];
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
    $log[] = 'marki: ' . count($rows);

    $make_id = [];
    foreach ($wpdb->get_results('SELECT id, slug FROM ' . $T('make'), ARRAY_A) as $r) {
        $make_id[$r['slug']] = (int) $r['id'];
    }

    /* ----------------------------------------------------------- modele */

    $rows = [];
    foreach ($sets['models'] as $m) {
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
    $log[] = 'modele: ' . count($rows);

    $model_id = [];
    foreach ($wpdb->get_results('SELECT o.id, k.slug AS mk, o.slug FROM ' . $T('model') . ' o
                                 JOIN ' . $T('make') . ' k ON k.id = o.make_id', ARRAY_A) as $r) {
        $model_id[$r['mk'] . '/' . $r['slug']] = (int) $r['id'];
    }

    /* -------------------------------------------------------- generacje */

    $rows = [];
    foreach ($sets['generations'] as $g) {
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
    $log[] = 'generacje: ' . count($rows);

    $gen_id = [];
    foreach ($wpdb->get_results('SELECT g.id, k.slug AS mk, o.slug AS md, g.slug FROM ' . $T('generation') . ' g
                                 JOIN ' . $T('model') . ' o ON o.id = g.model_id
                                 JOIN ' . $T('make') . ' k ON k.id = o.make_id', ARRAY_A) as $r) {
        $gen_id[$r['mk'] . '/' . $r['md'] . '/' . $r['slug']] = (int) $r['id'];
    }

    /* ---------------------------------------------------------- silniki */

    $rows = [];
    foreach ($sets['engines'] as $e) {
        $key = $e['make_slug'] . '/' . $e['model_slug'] . '/' . $e['gen_slug'];
        if (!isset($gen_id[$key])) {
            continue;
        }
        // po tym polu działa wyszukiwanie tekstowe
        $blob = trim(($name_by_slug[$e['make_slug']] ?? '') . ' ' . $e['model_slug'] . ' '
                     . $e['gen_slug'] . ' ' . $e['name'] . ' ' . $e['fuel']);

        $rows[] = [
            'generation_id' => $gen_id[$key],
            'slug'          => $e['slug'],
            'name'          => $e['name'],
            'fuel'          => $e['fuel'],
            'stock_kw'      => $e['stock_kw'] ?? 0,
            'stock_hp'      => $e['stock_hp'] ?? 0,
            // 0 znaczy „nieznany" — konfigurator V-techa nie podaje momentu fabrycznego
            'stock_nm'      => $e['stock_nm'] ?? 0,
            'legacy_key'    => $e['legacy_key'],
            'search_blob'   => mb_substr($blob, 0, 255),
            'vt_year'       => $e['vt_year'] ?? null,
            'sort'          => $e['sort'],
            'visibility'    => 1,
            'updated_at'    => $now,
        ];
    }
    vts_bulk($T('engine'),
        ['generation_id','slug','name','fuel','stock_kw','stock_hp','stock_nm','legacy_key','search_blob','vt_year','sort','visibility','updated_at'],
        $rows, ['name','fuel','stock_kw','stock_hp','stock_nm','search_blob','vt_year','sort','visibility','updated_at']);
    $log[] = 'silniki: ' . count($rows);

    /* ------------------------------------------ wygaszenie starych rekordów
     *
     * Upsert stemplem `updated_at` dotyka wyłącznie rekordów obecnych w bieżącym
     * imporcie. Wszystko, czego nie dotknął, pochodzi z poprzedniego źródła danych
     * i przestaje być widoczne. Nie kasujemy — wiersz musi zostać, żeby przekierowania
     * po starym kluczu miały dokąd prowadzić, a ponowny import mógł go przywrócić.
     *
     * Przyrosty nie są częścią importu (dociąga je most do wtyczki), więc wygaszamy
     * je osobno: zostają tylko te, które należą do silników z bieżącego importu i
     * mają kod z aktualnego słownika usług.
     */
    $stale = 0;
    foreach (['engine', 'generation', 'model', 'make'] as $t) {
        $stale += (int) $wpdb->query($wpdb->prepare(
            'UPDATE ' . $T($t) . ' SET visibility = 0 WHERE updated_at < %s AND visibility <> 0',
            $now
        ));
    }
    $codes = "'" . implode("','", array_map('esc_sql', vts_service_order())) . "'";
    $stale += (int) $wpdb->query(
        'UPDATE ' . $T('gain') . ' g
           LEFT JOIN ' . $T('engine') . ' e ON e.id = g.engine_id AND e.visibility = 1
            SET g.visibility = 0
          WHERE g.visibility <> 0 AND (e.id IS NULL OR g.service_code NOT IN (' . $codes . '))'
    );
    $log[] = "wygaszone rekordy z poprzedniego źródła: {$stale}";

    vts_catalog_recount();
    $wpdb->query('SET foreign_key_checks = 1');

    $manifest = array_merge($opts['manifest'] ?? [], [
        'imported_at' => $now,
        'counts'      => $counts,
    ]);
    update_option('vts_catalog_manifest', $manifest, false);

    if (function_exists('vts_flush_catalog_cache')) {
        vts_flush_catalog_cache();
    }

    $c = vts_catalog_counts();
    $log[] = "OPUBLIKOWANE: {$c['make']} marek, {$c['model']} modeli, {$c['generation']} generacji, {$c['engine']} silników";

    return ['log' => $log, 'counts' => $counts, 'published' => $c];
}

/**
 * Bramka jakości i liczniki: silnik, dla którego wtyczka potwierdziła brak danych,
 * nie ma czego pokazać — nie publikujemy go; puste generacje, modele i marki
 * schodzą razem z nim i wracają, gdy synchronizacja dociągnie dane. Widoczność 2
 * (za flagą funkcji) zostaje nietknięta. Wywoływane po imporcie i po każdej
 * paczce synchronizacji.
 */
function vts_catalog_recount(): int
{
    global $wpdb;
    $T = fn($n) => vts_table($n);

    $hidden = (int) $wpdb->query(
        'UPDATE ' . $T('engine') . ' e
            LEFT JOIN ' . $T('gain') . ' g ON g.engine_id = e.id AND g.visibility = 1
            SET e.visibility = 0
          WHERE e.visibility = 1 AND e.vt_checked_at IS NOT NULL AND g.id IS NULL'
    );

    $wpdb->query('UPDATE ' . $T('generation') . ' g SET g.engine_count =
        (SELECT COUNT(*) FROM ' . $T('engine') . ' e WHERE e.generation_id = g.id AND e.visibility = 1)');
    $wpdb->query('UPDATE ' . $T('generation') . ' SET visibility = IF(engine_count = 0, 0, IF(visibility = 0, 1, visibility))');

    $wpdb->query('UPDATE ' . $T('model') . ' o SET o.generation_count =
        (SELECT COUNT(*) FROM ' . $T('generation') . ' g WHERE g.model_id = o.id AND g.visibility = 1)');
    $wpdb->query('UPDATE ' . $T('model') . ' SET visibility = IF(generation_count = 0, 0, IF(visibility = 0, 1, visibility))');

    $wpdb->query('UPDATE ' . $T('make') . ' k SET k.model_count =
        (SELECT COUNT(*) FROM ' . $T('model') . ' o WHERE o.make_id = k.id AND o.visibility = 1)');
    $wpdb->query('UPDATE ' . $T('make') . ' SET visibility = IF(model_count = 0, 0, IF(visibility = 0, 1, visibility))');

    return $hidden;
}
