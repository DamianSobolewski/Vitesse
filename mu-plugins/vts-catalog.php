<?php
/**
 * Plugin Name: Vitesse — katalog mocy
 * Description: Dostęp do własnych tabel katalogu, słownik usług, jeden punkt kontroli widoczności.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Jedyne miejsce, w którym powstaje warunek widoczności.
 *
 * Żadne zapytanie katalogowe nie buduje go ręcznie — dzięki temu ukrycie marki
 * (np. przyszłej linii serwisowej) działa wszędzie naraz i nie da się go pominąć.
 * Wartości: 0 = ukryte, 1 = publiczne, 2 = za flagą funkcji.
 */
function vts_visibility_sql(string $alias, ?string $feature = null): string
{
    $alias = preg_replace('/[^a-z0-9_]/i', '', $alias);

    if ($feature !== null && vts_feature($feature)) {
        return " AND {$alias}.visibility IN (1,2) ";
    }

    return " AND {$alias}.visibility = 1 ";
}

/**
 * Słownik usług. Konfiguracja, nie dane — mieszka w kodzie i wersjonuje się w gicie.
 */
function vts_services(): array
{
    return [
        // Poziomy PowerChip wg aktualnej oferty V-techa. Kolejność decyduje
        // o kolejności wyświetlania — od najtańszego do najmocniejszego.
        'powerchip-one' => [
            'label'      => 'PowerChip One',
            'short'      => 'One',
            'desc'       => 'Podstawowy moduł Plug&Play. Montaż bez ingerencji w oprogramowanie, zdejmowany w kilkanaście minut.',
            'show_price' => true,
        ],
        'powerchip-premium' => [
            'label'      => 'PowerChip Premium',
            'short'      => 'Premium',
            'desc'       => 'Mocniejszy wariant modułu, z szerszym zakresem korekt.',
            'show_price' => true,
        ],
        'powerchip-premium-ai' => [
            'label'      => 'PowerChip Premium + AI',
            'short'      => 'Premium + AI',
            'desc'       => 'Najwyższy poziom modułu, z adaptacją do stylu jazdy.',
            'show_price' => true,
        ],
        'chip' => [
            'label'      => 'Chip tuning',
            'short'      => 'Chip',
            'desc'       => 'Modyfikacja oprogramowania sterownika. Pełny zakres zmian, wynik potwierdzony pomiarem.',
            'show_price' => true,
        ],
        // Zostaje dla pojazdów spoza konfiguratora V-techa (MAN, maszyny rolnicze),
        // gdzie dane pochodzą z katalogu Vitesse.
        'powerbox' => [
            'label'      => 'PowerBox',
            'short'      => 'Box',
            'desc'       => 'Moduł montowany bez ingerencji w oprogramowanie. Odwracalny w kilkanaście minut.',
            'show_price' => true,
        ],
    ];
}

/** Kolejność wyświetlania wariantów usług. */
function vts_service_order(): array
{
    return array_keys(vts_services());
}

/* ----------------------------------------------------------- odczyt danych */

function vts_makes(array $args = []): array
{
    global $wpdb;
    $t = vts_table('make');
    $limit = isset($args['limit']) ? ' LIMIT ' . (int) $args['limit'] : '';

    return $wpdb->get_results(
        "SELECT id, slug, name, model_count, is_truck
           FROM {$t} m
          WHERE 1=1" . vts_visibility_sql('m', 'jlr_service') . "
          ORDER BY m.name ASC{$limit}",
        ARRAY_A
    ) ?: [];
}

function vts_models(string $make_slug): array
{
    global $wpdb;
    $tm = vts_table('model');
    $tk = vts_table('make');

    return $wpdb->get_results($wpdb->prepare(
        "SELECT o.id, o.slug, o.name, o.generation_count
           FROM {$tm} o
           JOIN {$tk} k ON k.id = o.make_id
          WHERE k.slug = %s" . vts_visibility_sql('o') . vts_visibility_sql('k', 'jlr_service') . "
          ORDER BY o.sort ASC, o.name ASC",
        $make_slug
    ), ARRAY_A) ?: [];
}

function vts_generations(int $model_id): array
{
    global $wpdb;
    $t = vts_table('generation');

    return $wpdb->get_results($wpdb->prepare(
        "SELECT id, slug, name, year_from, year_to, engine_count
           FROM {$t} g
          WHERE g.model_id = %d" . vts_visibility_sql('g') . "
          ORDER BY g.sort ASC, g.name ASC",
        $model_id
    ), ARRAY_A) ?: [];
}

/**
 * Warianty silnikowe danej generacji.
 *
 * UWAGA: celowo NIE zwraca wartości po tuningu ani ceny. Te ujawnia dopiero
 * vts_engine_gains() po przejściu bramki leadowej — inaczej wystarczy podejrzeć
 * odpowiedź w narzędziach przeglądarki, żeby ją ominąć.
 */
function vts_engines(int $generation_id): array
{
    global $wpdb;
    $t = vts_table('engine');

    return $wpdb->get_results($wpdb->prepare(
        "SELECT id, slug, name, fuel, stock_kw, stock_hp, stock_nm
           FROM {$t} e
          WHERE e.generation_id = %d" . vts_visibility_sql('e') . "
          ORDER BY e.fuel ASC, e.stock_hp ASC",
        $generation_id
    ), ARRAY_A) ?: [];
}

function vts_engine(int $id): ?array
{
    global $wpdb;
    $t = vts_table('engine');

    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$t} e WHERE e.id = %d" . vts_visibility_sql('e'),
        $id
    ), ARRAY_A);

    return $row ?: null;
}

/** Wartości po modyfikacji — tylko za bramką. */
function vts_engine_gains(int $engine_id): array
{
    global $wpdb;
    $t = vts_table('gain');

    $order = "'" . implode("','", array_map('esc_sql', vts_service_order())) . "'";

    return $wpdb->get_results($wpdb->prepare(
        "SELECT service_code, label, tuned_hp, tuned_nm, gain_hp, gain_nm, price_net, price_is_from
           FROM {$t} g
          WHERE g.engine_id = %d" . vts_visibility_sql('g') . "
          ORDER BY FIELD(g.service_code, {$order}), g.service_code",
        $engine_id
    ), ARRAY_A) ?: [];
}

/** Pełna ścieżka silnika — marka, model, generacja. Do nagłówków i maili. */
function vts_engine_path(int $engine_id): ?array
{
    global $wpdb;
    $e = vts_table('engine'); $g = vts_table('generation');
    $m = vts_table('model');  $k = vts_table('make');

    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT k.name AS make, k.slug AS make_slug,
                o.name AS model, o.slug AS model_slug,
                g.name AS generation, g.slug AS gen_slug,
                e.name AS engine, e.slug AS engine_slug,
                e.stock_hp, e.stock_nm, e.stock_kw, e.fuel
           FROM {$e} e
           JOIN {$g} g ON g.id = e.generation_id
           JOIN {$m} o ON o.id = g.model_id
           JOIN {$k} k ON k.id = o.make_id
          WHERE e.id = %d",
        $engine_id
    ), ARRAY_A);

    return $row ?: null;
}

/** Wyszukiwanie pełnotekstowe — w MVP obsługuje trzecie pole wyszukiwarki. */
function vts_search_engines(string $q, int $limit = 10): array
{
    global $wpdb;
    $q = trim($q);
    if (mb_strlen($q) < 3) {
        return [];
    }

    $e = vts_table('engine'); $g = vts_table('generation');
    $m = vts_table('model');  $k = vts_table('make');

    return $wpdb->get_results($wpdb->prepare(
        "SELECT e.id, e.name AS engine, e.stock_hp, e.fuel,
                k.name AS make, o.name AS model, g.name AS generation
           FROM {$e} e
           JOIN {$g} g ON g.id = e.generation_id
           JOIN {$m} o ON o.id = g.model_id
           JOIN {$k} k ON k.id = o.make_id
          WHERE MATCH(e.search_blob) AGAINST (%s IN NATURAL LANGUAGE MODE)"
              . vts_visibility_sql('e') . vts_visibility_sql('k', 'jlr_service') . "
          LIMIT %d",
        $q,
        $limit
    ), ARRAY_A) ?: [];
}

function vts_catalog_counts(): array
{
    global $wpdb;
    static $c = null;
    if ($c !== null) {
        return $c;
    }

    $c = [];
    foreach (['make', 'model', 'generation', 'engine'] as $t) {
        $table = vts_table($t);
        $c[$t] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} x WHERE 1=1" . vts_visibility_sql('x'));
    }

    return $c;
}
