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
 * Zamienia wartość ?auto=… na kanoniczny adres. Schodzi po poziomach: jeśli nie ma
 * silnika, próbuje generacji, potem modelu, potem marki — zamiast oddawać 404.
 */
function vts_resolve_legacy_catalog(string $auto): ?string
{
    global $wpdb;

    $auto = trim(str_replace("\xc2\xa0", ' ', $auto));
    if ($auto === '') {
        return null;
    }

    $e = vts_table('engine'); $g = vts_table('generation');
    $m = vts_table('model');  $k = vts_table('make');

    // silnik
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

    // generacja
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT k.slug mk, o.slug md, x.slug gn
           FROM {$g} x
           JOIN {$m} o ON o.id = x.model_id
           JOIN {$k} k ON k.id = o.make_id
          WHERE x.legacy_key = %s"
              . vts_visibility_sql('x') . vts_visibility_sql('o') . vts_visibility_sql('k'), $auto), ARRAY_A);
    if ($row) {
        return vts_catalog_url($row['mk'], $row['md'], $row['gn']);
    }

    // model
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT k.slug mk, x.slug md
           FROM {$m} x JOIN {$k} k ON k.id = x.make_id
          WHERE x.legacy_key = %s"
              . vts_visibility_sql('x') . vts_visibility_sql('k'), $auto), ARRAY_A);
    if ($row) {
        return vts_catalog_url($row['mk'], $row['md']);
    }

    // marka
    $slug = $wpdb->get_var($wpdb->prepare(
        "SELECT slug FROM {$k} x WHERE x.legacy_key = %s" . vts_visibility_sql('x'), $auto));
    if ($slug) {
        return vts_catalog_url($slug);
    }

    // nie znaleziono dokładnie — spróbuj przodka, obcinając od prawej
    if (str_contains($auto, '_')) {
        return vts_resolve_legacy_catalog(substr($auto, 0, strrpos($auto, '_')));
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
