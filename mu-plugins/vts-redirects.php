<?php
/**
 * Plugin Name: Vitesse — przekierowania 301
 * Description: Matryca ze starego serwisu .php na nową strukturę adresów.
 *
 * Dlaczego mu-plugin, a nie .htaccess: katalog WordPressa leży w wolumenie
 * Dockera, więc .htaccess nie jest wersjonowany w gicie, a deploy przez
 * `git reset --hard` go nie dotknie. Poza tym reguła katalogowa musi sięgnąć
 * do bazy po legacy_key, czego .htaccess nie potrafi.
 *
 * Wszystkie przekierowania muszą działać na NOWYM serwerze — po przełączeniu
 * DNS stary Apache nie zobaczy już żadnego żądania.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Mapa leży w podkatalogu — WordPress automatycznie ładuje tylko pliki .php
 * bezpośrednio w mu-plugins, więc podkatalog nie jest wykonywany jako wtyczka.
 */
function vts_static_redirects(): array
{
    static $map = null;
    if ($map === null) {
        $f = __DIR__ . '/vts-data/legacy-static.php';
        $map = file_exists($f) ? require $f : [];
    }
    return $map;
}

/**
 * Przechwytujemy tylko na ścieżce 404 — na zwykłym ruchu koszt jest zerowy.
 */
add_action('template_redirect', function () {
    if (!is_404()) {
        return;
    }

    $path  = trim(wp_parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '', '/');
    $path  = rawurldecode($path);
    $query = [];
    parse_str(wp_parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_QUERY) ?: '', $query);

    if ($path === '') {
        return;
    }

    // 1) katalog: chiptuning_lodz.php?auto=Ford_Focus_III_1.6 TDCi 70kW
    if (str_starts_with($path, 'chiptuning_lodz.php')) {
        $target = vts_resolve_legacy_catalog((string) ($query['auto'] ?? ''));
        vts_go($target ?: vts_catalog_url());
    }

    // 2) mapa statyczna
    $map = vts_static_redirects();
    if (isset($map[$path])) {
        vts_go(home_url($map[$path]));
    }

    // 3) wariant bez polskich znaków — stary serwis miał oba
    $ascii = strtr($path, ['ę' => 'e', 'ó' => 'o', 'ą' => 'a', 'ś' => 's', 'ł' => 'l',
                           'ż' => 'z', 'ź' => 'z', 'ć' => 'c', 'ń' => 'n']);
    if ($ascii !== $path && isset($map[$ascii])) {
        vts_go(home_url($map[$ascii]));
    }

    // 4) historia slugów katalogu — po ponownym imporcie slug mógł się zmienić
    if ($moved = vts_slug_history_target($path)) {
        vts_go($moved);
    }
}, 1);

/**
 * Mapa starych kluczy ?auto= na nowe ścieżki katalogu.
 *
 * Po przejściu na dane V-techa rekordy nie mają już kluczy ze starego serwisu,
 * więc wyszukiwanie po legacy_key przestało wystarczać. Mapę generuje
 * tools/scrape/map-legacy.py przez dopasowanie slugów i sygnatur silników.
 */
function vts_legacy_catalog_map(): array
{
    static $map = null;
    if ($map === null) {
        $f = __DIR__ . '/vts-data/legacy-catalog.json';
        $map = file_exists($f)
            ? (json_decode(file_get_contents($f), true) ?: [])
            : [];
    }
    return $map;
}

/**
 * Zamienia wartość ?auto=… na kanoniczny adres. Kolejność prób:
 * mapa → klucz w bazie (marki spoza konfiguratora V-techa zachowały stare klucze)
 * → przodek, przez obcinanie od prawej. Zamiast 404 użytkownik trafia wyżej w drzewie.
 */
function vts_resolve_legacy_catalog(string $auto): ?string
{
    $auto = trim(str_replace("\xc2\xa0", ' ', $auto));
    if ($auto === '') {
        return null;
    }

    $map = vts_legacy_catalog_map();
    if (isset($map[$auto])) {
        $url = vts_catalog_path_url($map[$auto]);
        if ($url) {
            return $url;
        }
    }

    if ($url = vts_lookup_legacy_key($auto)) {
        return $url;
    }

    if (str_contains($auto, '_')) {
        return vts_resolve_legacy_catalog(substr($auto, 0, strrpos($auto, '_')));
    }

    return null;
}

/**
 * Buduje adres ze ścieżki "marka/model/generacja/silnik", ucinając poziomy,
 * których nie ma albo które są ukryte — dzięki temu nigdy nie kierujemy na 404.
 */
function vts_catalog_path_url(string $path): ?string
{
    global $wpdb;
    $parts = array_values(array_filter(explode('/', $path)));
    if (!$parts) {
        return null;
    }

    $k = vts_table('make'); $o = vts_table('model');
    $g = vts_table('generation'); $e = vts_table('engine');

    $make = $wpdb->get_row($wpdb->prepare(
        "SELECT id, slug FROM {$k} m WHERE m.slug = %s" . vts_visibility_sql('m', 'jlr_service'),
        $parts[0]), ARRAY_A);
    if (!$make) {
        return null;
    }
    $out = [$make['slug']];

    if (isset($parts[1])) {
        $model = $wpdb->get_row($wpdb->prepare(
            "SELECT id, slug FROM {$o} x WHERE x.make_id = %d AND x.slug = %s" . vts_visibility_sql('x'),
            $make['id'], $parts[1]), ARRAY_A);
        if ($model) {
            $out[] = $model['slug'];

            if (isset($parts[2])) {
                $gen = $wpdb->get_row($wpdb->prepare(
                    "SELECT id, slug FROM {$g} x WHERE x.model_id = %d AND x.slug = %s" . vts_visibility_sql('x'),
                    $model['id'], $parts[2]), ARRAY_A);
                if ($gen) {
                    $out[] = $gen['slug'];

                    if (isset($parts[3])) {
                        $eng = $wpdb->get_var($wpdb->prepare(
                            "SELECT slug FROM {$e} x WHERE x.generation_id = %d AND x.slug = %s" . vts_visibility_sql('x'),
                            $gen['id'], $parts[3]));
                        if ($eng) {
                            $out[] = $eng;
                        }
                    }
                }
            }
        }
    }

    return vts_catalog_url(...$out);
}

/** Marki spoza konfiguratora V-techa zachowały klucze ze starego serwisu. */
function vts_lookup_legacy_key(string $auto): ?string
{
    global $wpdb;
    $e = vts_table('engine'); $g = vts_table('generation');
    $m = vts_table('model');  $k = vts_table('make');

    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT k.slug mk, o.slug md, g.slug gn, x.slug en
           FROM {$e} x
           JOIN {$g} g ON g.id = x.generation_id
           JOIN {$m} o ON o.id = g.model_id
           JOIN {$k} k ON k.id = o.make_id
          WHERE x.legacy_key = %s"
          . vts_visibility_sql('x') . vts_visibility_sql('g')
          . vts_visibility_sql('o') . vts_visibility_sql('k'), $auto), ARRAY_A);
    if ($row) {
        return vts_catalog_url($row['mk'], $row['md'], $row['gn'], $row['en']);
    }

    foreach ([[$g, 'generation'], [$m, 'model'], [$k, 'make']] as [$table, $_]) {
        $slug = $wpdb->get_var($wpdb->prepare(
            "SELECT slug FROM {$table} x WHERE x.legacy_key = %s" . vts_visibility_sql('x'), $auto));
        if ($slug) {
            return vts_catalog_path_url($slug);
        }
    }

    return null;
}

function vts_slug_history_target(string $path): ?string
{
    global $wpdb;
    $t = vts_table('slug_history');

    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT entity, entity_id FROM {$t} WHERE old_path = %s", $path), ARRAY_A);

    return $row ? home_url('/') : null;
}

/** Zawsze jeden skok na finalny, absolutny adres — bez łańcuchów przekierowań. */
function vts_go(string $url): void
{
    wp_redirect($url, 301);
    exit;
}
