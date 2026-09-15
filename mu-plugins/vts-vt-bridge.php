<?php
/**
 * Plugin Name: Vitesse — most do wtyczki VT Konfigurator
 * Description: Drzewo pojazdów i przyrosty mocy z wtyczki VT Konfigurator (Signuply) trafiają do własnych tabel katalogu.
 *
 * Wtyczka jest źródłem prawdy: trzyma drzewo z konfiguratora V-techa (opcja vt_vehicle_tree),
 * pobiera wynik dla kombinacji marka/model/generacja/silnik/rocznik i cache'uje go. My nie
 * czytamy z niej przy każdym żądaniu — front, REST z tokenem, strony /chiptuning/ i przekierowania
 * pracują na tabelach wp_vts_*, które ten plik buduje z danych wtyczki:
 *
 *  - vts_vt_import_tree()   drzewo → marki, modele, generacje, silniki (upsert po legacy_key,
 *                           identyczne reguły slugów co dawny scraper, więc id się nie przesuwają),
 *  - vts_vt_sync_engine()   wynik wtyczki → wiersze wp_vts_gain (PowerChip, Chip Tuning),
 *  - vts_vt_ensure_fresh()  dociągnięcie przy pierwszym wyświetleniu wersji,
 *  - cron co godzinę        ogrzewanie paczki silników w tle,
 *  - WP-CLI `wp vts vt`     tree | import | sync.
 *
 * Kodu wtyczki nie modyfikujemy. Używamy jej publicznych metod statycznych:
 * VT_Storage::get_tree/get_settings/get_cached_result/set_cached_result/get_stale_result/log_error,
 * VT_Fetcher::refresh_tree/fetch_result. Klucz cache jest ten sam co w jej REST, więc panel
 * „Wyczyść cache wyników" i diagnostyka wtyczki działają też na nasze dane.
 */

if (!defined('ABSPATH')) {
    exit;
}

const VTS_VT_CRON   = 'vts_vt_sync_batch';
const VTS_VT_PLUGIN = 'vt-konfigurator/vt-konfigurator.php';

/** Wtyczka ładuje się po mu-plugins — sprawdzamy dopiero w chwili użycia, nie przy starcie. */
function vts_vt_available(): bool
{
    return class_exists('VT_Storage') && class_exists('VT_Fetcher');
}

function vts_vt_error(): WP_Error
{
    return new WP_Error('vts_vt_missing', 'Wtyczka VT Konfigurator nie jest aktywna. Aktywuj ją w Wtyczki → Zainstalowane wtyczki.');
}

add_action('admin_notices', function () {
    if (!current_user_can('manage_options') || vts_vt_available()) {
        return;
    }
    echo '<div class="notice notice-error"><p><strong>Katalog mocy:</strong> wtyczka VT Konfigurator nie jest aktywna. '
       . 'Bez niej katalog nie odświeży drzewa pojazdów ani przyrostów. '
       . '<a href="' . esc_url(admin_url('plugins.php')) . '">Aktywuj wtyczkę</a>.</p></div>';
});

/* ----------------------------------------------------------- drzewo → tabele */

/** Te same reguły co w dawnym tools/scrape/vtech-tree.py — slugi muszą zostać identyczne. */
function vts_vt_slugify(string $s): string
{
    $s = strtr($s, [
        'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z',
        'Ą' => 'A', 'Ć' => 'C', 'Ę' => 'E', 'Ł' => 'L', 'Ń' => 'N', 'Ó' => 'O', 'Ś' => 'S', 'Ź' => 'Z', 'Ż' => 'Z',
    ]);
    $s = strtolower($s);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    $s = preg_replace('/-{2,}/', '-', $s);

    return trim($s, '-') ?: 'x';
}

function vts_vt_fuel(string $label): string
{
    $low = strtolower($label);
    $fuel = 'diesel';
    foreach (['tsi', 'ecoboost', 'tfsi', 'benz', 'gti', 'mpi', 'fsi'] as $k) {
        if (str_contains($low, $k)) {
            $fuel = 'benzyna';
            break;
        }
    }
    if (str_contains($low, 'hybrid') || str_contains($low, 'hybryda')) {
        $fuel = 'hybryda';
    }
    if (str_contains($low, 'electric') || str_contains($low, ' ev')) {
        $fuel = 'elektryk';
    }

    return $fuel;
}

/**
 * Drzewo wtyczki → zestawy wierszy dla vts_catalog_apply().
 * Generacja i silnik siedzą w drzewie razem, w polu value = "generacja::silnik" pod każdym
 * rocznikiem; rozplatamy je, a rocznik zamieniamy na zakres lat generacji i jeden konkretny
 * rocznik (najnowszy) do zapytania o wynik.
 */
function vts_vt_build_sets(array $tree): array
{
    $truck_hint = ['truck', 'daf', 'iveco', 'man', 'scania', 'volvo-truck', 'mercedes-truck'];
    $makes = $models = $gens = $engines = [];

    $brands = array_keys($tree);
    usort($brands, 'strcmp');

    foreach ($brands as $bi => $bslug) {
        $brand = $tree[$bslug];
        $is_truck = false;
        foreach ($truck_hint as $h) {
            if (str_contains($bslug, $h)) {
                $is_truck = true;
                break;
            }
        }
        $makes[] = [
            'slug' => $bslug, 'name' => $brand['label'] ?? $bslug,
            'legacy_key' => $bslug, 'sort' => $bi, 'is_truck' => $is_truck,
        ];

        $mslugs = array_keys($brand['models'] ?? []);
        usort($mslugs, 'strcmp');

        foreach ($mslugs as $mi => $mslug) {
            $model = $brand['models'][$mslug];
            $models[] = [
                'make_slug' => $bslug, 'slug' => $mslug,
                'name' => $model['label'] ?? $mslug,
                'legacy_key' => "{$bslug}/{$mslug}", 'sort' => $mi,
                'vehicle_class' => $is_truck ? 'ciezarowe' : 'osobowe',
            ];

            // generacja::silnik → zakres roczników
            $span = [];
            foreach ($model['years'] ?? [] as $ykey => $ydata) {
                $year = null;
                if (preg_match('/\d{4}/', (string) ($ydata['label'] ?? $ykey), $m)) {
                    $year = (int) $m[0];
                }
                foreach ($ydata['engines'] ?? [] as $e) {
                    $value = (string) ($e['value'] ?? '');
                    if (!str_contains($value, '::')) {
                        continue;
                    }
                    [$g, $en] = explode('::', $value, 2);
                    $k = $g . '::' . $en;
                    if (!isset($span[$k])) {
                        $span[$k] = [
                            'gen' => $g, 'engine' => $en,
                            'gen_label' => $e['gen_label'] ?? $g,
                            'engine_label' => $e['engine'] ?? $en,
                            'years' => [], 'year_keys' => [],
                        ];
                    }
                    if ($year) {
                        $span[$k]['years'][] = $year;
                    }
                    $span[$k]['year_keys'][] = (string) $ykey;
                }
            }

            uasort($span, fn($a, $b) => strcmp($a['gen'], $b['gen']) ?: strcmp($a['engine'], $b['engine']));

            $seen_gen = [];
            $gi = 0;
            foreach ($span as $rec) {
                $g = $rec['gen'];
                if (!isset($seen_gen[$g])) {
                    $seen_gen[$g] = true;
                    $ys = [];
                    foreach ($span as $r2) {
                        if ($r2['gen'] === $g) {
                            array_push($ys, ...$r2['years']);
                        }
                    }
                    $gens[] = [
                        'make_slug' => $bslug, 'model_slug' => $mslug, 'slug' => $g,
                        'name' => $rec['gen_label'],
                        'legacy_key' => "{$bslug}/{$mslug}/{$g}",
                        'year_from' => $ys ? min($ys) : null,
                        'year_to'   => $ys ? max($ys) : null,
                        'sort' => $gi,
                    ];
                }

                $lbl = $rec['engine_label'];
                $kw = preg_match('/(\d+)\s*kW/i', $lbl, $m1) ? (int) $m1[1] : null;
                $hp = preg_match('/(\d+)\s*KM/i', $lbl, $m2) ? (int) $m2[1] : null;
                $year_keys = $rec['year_keys'];
                usort($year_keys, 'strcmp');

                $engines[] = [
                    'make_slug' => $bslug, 'model_slug' => $mslug, 'gen_slug' => $g,
                    'slug' => vts_vt_slugify($rec['engine']), 'name' => $lbl, 'fuel' => vts_vt_fuel($lbl),
                    'stock_kw' => $kw, 'stock_hp' => $hp, 'stock_nm' => null,
                    'legacy_key' => "{$bslug}/{$mslug}/{$g}/{$rec['engine']}",
                    'sort' => $gi,
                    // do pobrania wyniku wtyczka potrzebuje konkretnego rocznika
                    'vt_year' => $year_keys ? end($year_keys) : null,
                ];
                $gi++;
            }
        }
    }

    return ['makes' => $makes, 'models' => $models, 'generations' => $gens, 'engines' => $engines];
}

/** Drzewo z opcji wtyczki → tabele katalogu. */
function vts_vt_import_tree(bool $force = false): array|WP_Error
{
    if (!vts_vt_available()) {
        return vts_vt_error();
    }
    $data = VT_Storage::get_tree();
    if (!$data || empty($data['tree']) || !is_array($data['tree'])) {
        return new WP_Error('vts_vt_no_tree', 'Wtyczka nie ma jeszcze drzewa pojazdów. Uruchom `wp vts vt tree` albo kliknij „Odśwież dane pojazdów" w Ustawienia → VT Konfigurator.');
    }

    $sets = vts_vt_build_sets($data['tree']);

    return vts_catalog_apply($sets, [
        'force'    => $force,
        'manifest' => ['source' => 'vt-konfigurator', 'tree_meta' => VT_Storage::get_tree_meta()],
    ]);
}

/** To samo, co przycisk „Odśwież dane pojazdów" w panelu wtyczki, łącznie z bezpiecznikiem 80 %. */
function vts_vt_refresh_tree(bool $force = false): array|WP_Error
{
    if (!vts_vt_available()) {
        return vts_vt_error();
    }
    $settings = VT_Storage::get_settings();
    $result = VT_Fetcher::refresh_tree(esc_url_raw($settings['source_url']));
    if (empty($result['success'])) {
        VT_Storage::log_error('refresh_tree (wp vts vt tree): ' . ($result['message'] ?? '?'));
        return new WP_Error('vts_vt_tree', 'Błąd odświeżania drzewa: ' . ($result['message'] ?? '?'));
    }

    $old = (int) (VT_Storage::get_tree_meta()['combinations_count'] ?? 0);
    $new = (int) $result['meta']['combinations_count'];
    if (!$force && $old > 0 && $new < (int) round($old * 0.8)) {
        VT_Storage::log_error(sprintf('Bezpiecznik: nowe kombinacje (%d) < 80%% poprzednich (%d). Drzewo nie zapisano.', $new, $old));
        return new WP_Error('vts_vt_guard', "Drzewo NIE zostało zapisane: {$new} kombinacji wobec {$old} poprzednio (poniżej 80%). Użyj --force, jeśli to zamierzone.");
    }

    VT_Storage::save_tree($result['json']);
    VT_Storage::save_tree_meta($result['meta']);

    return $result['meta'];
}

/* -------------------------------------------------------- wynik → wp_vts_gain */

/** Parametry zapytania do wtyczki z tego, co siedzi w wierszu silnika. */
function vts_vt_params(array $engine): ?array
{
    $parts = explode('/', (string) ($engine['legacy_key'] ?? ''));
    if (count($parts) !== 4 || empty($engine['vt_year'])) {
        return null; // silnik spoza drzewa V-techa albo stary rekord bez rocznika
    }
    [$brand, $model, $gen, $eng] = $parts;

    return ['brand' => $brand, 'model' => $model, 'gen' => $gen, 'engine' => $eng, 'year' => (string) $engine['vt_year']];
}

/**
 * Wynik dla kombinacji — z cache wtyczki, a gdy go nie ma, z jej fetchera.
 * Powtarza logikę VT_Ajax::handle_result (cache → live → stale), żeby oba wejścia
 * zachowywały się tak samo i dzieliły jeden cache.
 */
function vts_vt_fetch(array $p): array|WP_Error
{
    $settings  = VT_Storage::get_settings();
    $use_cache = '1' === ($settings['cache_enabled'] ?? '1');
    $key       = md5("{$p['brand']}|{$p['model']}|{$p['gen']}|{$p['engine']}|{$p['year']}");

    if ($use_cache) {
        $cached = VT_Storage::get_cached_result($key);
        if ($cached !== null) {
            return $cached;
        }
    }

    $result = VT_Fetcher::fetch_result($p['brand'], $p['model'], $p['gen'], $p['engine'], $p['year']);

    if (is_wp_error($result)) {
        VT_Storage::log_error(sprintf('Błąd fetch_result [%s/%s/%s/%s/%s]: %s',
            $p['brand'], $p['model'], $p['gen'], $p['engine'], $p['year'], $result->get_error_message()));
        if ($use_cache) {
            $stale = VT_Storage::get_stale_result($key);
            if ($stale !== null) {
                return $stale;
            }
        }
        return $result;
    }

    if ($use_cache) {
        VT_Storage::set_cached_result($key, $result, (int) ($settings['cache_ttl'] ?? 7));
    }

    return $result;
}

/**
 * Wynik wtyczki → wiersze wp_vts_gain dla jednej wersji silnika.
 * Wtyczka zwraca dwie pozycje: „PowerChip" (ostatnia karta PowerChip ze sklepu V-techa)
 * i „Chip Tuning" — takie dwie pokazujemy, decyzja biznesowa z września 2026.
 *
 * @return true|WP_Error true także wtedy, gdy wtyczka potwierdziła brak danych (silnik znika)
 */
function vts_vt_sync_engine(int $engine_id): bool|WP_Error
{
    global $wpdb;

    if (!vts_vt_available()) {
        return vts_vt_error();
    }

    $engine = $wpdb->get_row($wpdb->prepare(
        'SELECT id, legacy_key, vt_year FROM ' . vts_table('engine') . ' WHERE id = %d', $engine_id
    ), ARRAY_A);
    if (!$engine) {
        return new WP_Error('vts_vt_engine', "Brak silnika #{$engine_id}.");
    }
    $p = vts_vt_params($engine);
    if (!$p) {
        return new WP_Error('vts_vt_params', "Silnik #{$engine_id} nie pochodzi z drzewa V-techa.");
    }

    $result = vts_vt_fetch($p);
    if (is_wp_error($result)) {
        // Nie ponawiamy przy każdym wyświetleniu — sklep V-techa bywa chwilowo niedostępny.
        set_transient("vts_vt_fail_{$engine_id}", 1, 10 * MINUTE_IN_SECONDS);
        return $result;
    }

    $now  = current_time('mysql');
    $rows = [];
    foreach ([['powerchip', 'powerchip', 'PowerChip'], ['chip_tuning', 'chip', 'Chip Tuning']] as [$src, $code, $label]) {
        $card = $result[$src] ?? null;
        if (!is_array($card)) {
            continue;
        }
        $hp = (int) ($card['hp'] ?? 0);
        $nm = (int) ($card['nm'] ?? 0);
        if ($hp <= 0 && $nm <= 0) {
            continue;
        }
        $rows[] = [
            'engine_id'    => $engine_id,
            'service_code' => $code,
            'label'        => $label,
            'gain_hp'      => $hp,
            'gain_nm'      => $nm,
            // wartości po modyfikacji liczy vts_engine_result() z mocy fabrycznej
            'tuned_hp'     => null,
            'tuned_nm'     => null,
            'chart_url'    => !empty($card['chart_url']) ? mb_substr((string) $card['chart_url'], 0, 255) : null,
            'visibility'   => 1,
            'updated_at'   => $now,
        ];
    }

    $wpdb->delete(vts_table('gain'), ['engine_id' => $engine_id], ['%d']);
    if ($rows) {
        vts_bulk(vts_table('gain'),
            ['engine_id','service_code','label','gain_hp','gain_nm','tuned_hp','tuned_nm','chart_url','visibility','updated_at'],
            $rows, ['label','gain_hp','gain_nm','tuned_hp','tuned_nm','chart_url','visibility','updated_at']);
    }

    $wpdb->update(
        vts_table('engine'),
        ['vt_checked_at' => $now, 'visibility' => $rows ? 1 : 0],
        ['id' => $engine_id],
        ['%s', '%d'],
        ['%d']
    );
    delete_transient("vts_vt_fail_{$engine_id}");

    return true;
}

/** Ile dni wynik jest świeży — to samo ustawienie, którym wtyczka rządzi swoim cache. */
function vts_vt_ttl_days(): int
{
    if (!vts_vt_available()) {
        return 7;
    }

    return max(1, (int) (VT_Storage::get_settings()['cache_ttl'] ?? 7));
}

/**
 * Dociągnięcie przy pierwszym wyświetleniu: wersja jeszcze niesprawdzona albo
 * przeterminowana idzie do wtyczki, zanim odczytamy przyrosty. Cichy no-op, gdy wtyczka
 * nie jest aktywna, silnik nie jest z V-techa, albo ostatnia próba właśnie się nie udała.
 */
function vts_vt_ensure_fresh(int $engine_id): void
{
    global $wpdb;

    if (!vts_vt_available() || get_transient("vts_vt_fail_{$engine_id}")) {
        return;
    }

    $row = $wpdb->get_row($wpdb->prepare(
        'SELECT legacy_key, vt_year, vt_checked_at FROM ' . vts_table('engine') . ' WHERE id = %d', $engine_id
    ), ARRAY_A);
    if (!$row || !vts_vt_params($row)) {
        return;
    }

    $limit = time() - vts_vt_ttl_days() * DAY_IN_SECONDS;
    if ($row['vt_checked_at'] && strtotime($row['vt_checked_at']) >= $limit) {
        return;
    }

    vts_vt_sync_engine($engine_id);
}

/**
 * Paczka do ogrzania: najpierw nigdy niesprawdzone, potem najdawniej sprawdzone.
 *
 * @return array{done:int,failed:int,hidden:int}
 */
function vts_vt_sync_batch(int $limit = 150, float $sleep = 0.3): array
{
    global $wpdb;

    $out = ['done' => 0, 'failed' => 0, 'hidden' => 0];
    if (!vts_vt_available()) {
        return $out;
    }

    $stale = gmdate('Y-m-d H:i:s', (int) strtotime(current_time('mysql')) - vts_vt_ttl_days() * DAY_IN_SECONDS);
    $ids = $wpdb->get_col($wpdb->prepare(
        'SELECT id FROM ' . vts_table('engine') . "
          WHERE legacy_key LIKE '%%/%%/%%/%%' AND vt_year IS NOT NULL
            AND (vt_checked_at IS NULL OR vt_checked_at < %s)
          ORDER BY vt_checked_at IS NULL DESC, vt_checked_at ASC, id ASC
          LIMIT %d",
        $stale, $limit
    ));

    foreach ($ids as $i => $id) {
        $r = vts_vt_sync_engine((int) $id);
        if (is_wp_error($r)) {
            $out['failed']++;
        } else {
            $out['done']++;
        }
        if ($sleep > 0 && $i < count($ids) - 1) {
            usleep((int) ($sleep * 1_000_000));
        }
    }

    if ($ids) {
        $out['hidden'] = vts_catalog_recount();
        vts_flush_catalog_cache();
    }

    return $out;
}

/* --------------------------------------------------------------------- cron */

add_action('init', function () {
    if (!wp_next_scheduled(VTS_VT_CRON)) {
        wp_schedule_event(time() + 5 * MINUTE_IN_SECONDS, 'hourly', VTS_VT_CRON);
    }
});

add_action(VTS_VT_CRON, function () {
    if (get_transient('vts_vt_batch_lock')) {
        return; // poprzednia paczka jeszcze trwa
    }
    set_transient('vts_vt_batch_lock', 1, 50 * MINUTE_IN_SECONDS);
    vts_vt_sync_batch(max(1, (int) get_option('vts_vt_batch', 150)));
    delete_transient('vts_vt_batch_lock');
});

/* ------------------------------------------------------------------- WP-CLI */

if (defined('WP_CLI') && WP_CLI) {
    /**
     * Katalog mocy z wtyczki VT Konfigurator.
     */
    class VTS_VT_CLI
    {
        /**
         * Pobiera drzewo pojazdów ze sklepu V-techa i zapisuje w wtyczce (jak przycisk w panelu).
         *
         * [--force]
         * : Zapisz nawet, gdy nowe drzewo ma mniej niż 80% poprzednich kombinacji.
         */
        public function tree(array $args, array $assoc): void
        {
            $meta = vts_vt_refresh_tree(isset($assoc['force']));
            if (is_wp_error($meta)) {
                WP_CLI::error($meta->get_error_message());
            }
            WP_CLI::success(sprintf('Drzewo zapisane: %d marek, %d kombinacji, %s.',
                $meta['brands_count'], $meta['combinations_count'], size_format($meta['size_bytes'])));
        }

        /**
         * Drzewo z wtyczki → tabele katalogu (marki, modele, generacje, silniki).
         *
         * [--force]
         * : Importuj nawet przy spadku liczby marek/silników powyżej 10%.
         */
        public function import(array $args, array $assoc): void
        {
            $r = vts_vt_import_tree(isset($assoc['force']));
            if (is_wp_error($r)) {
                WP_CLI::error($r->get_error_message());
            }
            foreach ($r['log'] as $line) {
                WP_CLI::log($line);
            }
            WP_CLI::success('Import zakończony. Przyrosty dociągnie `wp vts vt sync` albo cron.');
        }

        /**
         * Dociąga przyrosty z wtyczki dla silników niesprawdzonych lub przeterminowanych.
         *
         * [--limit=<n>]
         * : Ile silników w tym przebiegu. Domyślnie wszystkie zaległe.
         *
         * [--sleep=<s>]
         * : Przerwa między zapytaniami do sklepu V-techa w sekundach. Domyślnie 0.3.
         *
         * [--engine=<id>]
         * : Tylko jeden silnik (id z wp_vts_engine), niezależnie od świeżości.
         */
        public function sync(array $args, array $assoc): void
        {
            if (!vts_vt_available()) {
                WP_CLI::error(vts_vt_error()->get_error_message());
            }
            if (!empty($assoc['engine'])) {
                $r = vts_vt_sync_engine((int) $assoc['engine']);
                if (is_wp_error($r)) {
                    WP_CLI::error($r->get_error_message());
                }
                vts_catalog_recount();
                vts_flush_catalog_cache();
                WP_CLI::success("Silnik #{$assoc['engine']} zsynchronizowany.");
                return;
            }

            $limit = (int) ($assoc['limit'] ?? PHP_INT_MAX);
            $sleep = (float) ($assoc['sleep'] ?? 0.3);
            $total = ['done' => 0, 'failed' => 0, 'hidden' => 0];

            while ($limit > 0) {
                $r = vts_vt_sync_batch(min($limit, 200), $sleep);
                if ($r['done'] + $r['failed'] === 0) {
                    break;
                }
                $limit -= $r['done'] + $r['failed'];
                foreach ($total as $k => $v) {
                    $total[$k] += $r[$k];
                }
                WP_CLI::log(sprintf('  paczka: %d ok, %d błędów (razem %d)', $r['done'], $r['failed'], $total['done'] + $total['failed']));
                if ($r['failed'] > 0 && $r['done'] === 0) {
                    WP_CLI::warning('Sama paczka błędów — sklep V-techa nie odpowiada? Przerywam, log w Ustawienia → VT Konfigurator.');
                    break;
                }
            }

            WP_CLI::success(sprintf('Zsynchronizowano %d silników (%d błędów, %d ukrytych bez danych).',
                $total['done'], $total['failed'], $total['hidden']));
        }
    }

    WP_CLI::add_command('vts vt', 'VTS_VT_CLI');
}
