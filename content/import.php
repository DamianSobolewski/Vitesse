<?php
/**
 * Import struktury serwisu: strony, treść, menu, SEO, ustawienia Elementora.
 * Uruchamiany przez bin/import.sh. Idempotentny — dopasowanie po slugu.
 *
 * To jedyna droga zmiany treści strukturalnych: edycja content/pages/*.html
 * i pages.json, potem ./bin/import.sh. Zmiany klikane w edytorze zostaną nadpisane.
 */

if (!defined('ABSPATH')) {
    exit(1);
}

const VTS_CONTENT = '/content';

function vts_log(string $m): void { echo $m . "\n"; }

/* ------------------------------------------------- formularz kontaktowy */

function vts_import_form(): void
{
    if (!post_type_exists('wpcf7_contact_form')) {
        vts_log('Contact Form 7 nieaktywny — pomijam formularz');
        return;
    }

    $body = file_get_contents(VTS_CONTENT . '/forms/kontakt.html');
    $c    = vts_company();
    $to   = vts_lead_inbox('retail');
    $from = 'no-reply@' . wp_parse_url(home_url(), PHP_URL_HOST);

    $existing = get_page_by_path('kontakt', OBJECT, 'wpcf7_contact_form');
    $id = $existing ? $existing->ID : wp_insert_post([
        'post_type'   => 'wpcf7_contact_form',
        'post_status' => 'publish',
        'post_title'  => 'Formularz kontaktowy',
        'post_name'   => 'kontakt',
    ]);

    update_post_meta($id, '_form', $body);
    update_post_meta($id, '_mail', [
        'active'          => true,
        'subject'         => '[Vitesse] Zapytanie ze strony: [vehicle]',
        'sender'          => $c['name'] . ' <' . $from . '>',
        'recipient'       => $to,
        'body'            => "Imię:     [your-name]\nE-mail:   [your-email]\nTelefon:  [your-phone]\n"
                           . "Pojazd:   [vehicle]\n\nTreść:\n[your-message]\n",
        'additional_headers' => 'Reply-To: [your-email]',
        'attachments'     => '',
        'use_html'        => false,
        'exclude_blank'   => false,
    ]);
    update_post_meta($id, '_messages', [
        'mail_sent_ok'     => 'Dziękujemy. Odezwiemy się w godzinach pracy warsztatu.',
        'mail_sent_ng'     => 'Nie udało się wysłać wiadomości. Zadzwońcie do nas — ' . $c['phones']['tuning']['number'] . '.',
        'validation_error' => 'Uzupełnijcie zaznaczone pola.',
        'accept_terms'     => 'Zaznaczcie zgodę na kontakt.',
        'invalid_email'    => 'Ten adres e-mail wygląda na niepoprawny.',
        'invalid_required' => 'To pole jest wymagane.',
    ]);
    update_post_meta($id, '_locale', 'pl_PL');

    vts_log('formularz kontaktowy: id ' . $id . ', odbiorca ' . $to);
}

$manifest = json_decode(file_get_contents(VTS_CONTENT . '/pages.json'), true);
$pages    = $manifest['pages'];

/* ------------------------------------------------ global kit Elementora
 * Tokeny wpisujemy do kitu, żeby edytor nie podstawiał własnych domyślnych
 * kolorów i czcionek tam, gdzie klient dołoży sekcję.
 */
function vts_configure_kit(): void
{
    $kit_id = (int) get_option('elementor_active_kit');
    if (!$kit_id) {
        return;
    }

    $settings = get_post_meta($kit_id, '_elementor_page_settings', true) ?: [];

    $settings['system_colors'] = [
        ['_id' => 'primary',   'title' => 'Pomarańcz',  'color' => '#FF7A00'],
        ['_id' => 'secondary', 'title' => 'Grafit',     'color' => '#171A21'],
        ['_id' => 'text',      'title' => 'Tekst',      'color' => '#F1F2F4'],
        ['_id' => 'accent',    'title' => 'Wyciszony',  'color' => '#8A919E'],
    ];
    $settings['system_typography'] = [
        ['_id' => 'primary',   'title' => 'Nagłówki',
         'typography_typography' => 'custom', 'typography_font_family' => 'Archivo',
         'typography_font_weight' => '700'],
        ['_id' => 'secondary', 'title' => 'Podtytuły',
         'typography_typography' => 'custom', 'typography_font_family' => 'Archivo',
         'typography_font_weight' => '600'],
        ['_id' => 'text',      'title' => 'Tekst',
         'typography_typography' => 'custom', 'typography_font_family' => 'IBM Plex Sans',
         'typography_font_weight' => '400'],
        ['_id' => 'accent',    'title' => 'Dane',
         'typography_typography' => 'custom', 'typography_font_family' => 'IBM Plex Mono',
         'typography_font_weight' => '500'],
    ];
    $settings['container_width']  = ['unit' => 'px', 'size' => 1240];
    $settings['body_background_background'] = 'classic';
    $settings['body_background_color']      = '#0F1116';

    update_post_meta($kit_id, '_elementor_page_settings', $settings);
    vts_log('kit Elementora: tokeny zapisane');
}

/* --------------------------------------------------------------- strony */

function vts_find_page(string $slug): ?WP_Post
{
    $q = new WP_Query([
        'post_type'      => 'page',
        'name'           => $slug,
        'post_status'    => ['publish', 'draft', 'private'],
        'posts_per_page' => 1,
        'no_found_rows'  => true,
    ]);
    return $q->have_posts() ? $q->posts[0] : null;
}

function vts_page_body(string $file): string
{
    $f = VTS_CONTENT . '/pages/' . $file . '.html';
    return file_exists($f) ? file_get_contents($f) : '';
}

$ids = [];

// Dwa przebiegi: najpierw wszystkie strony, potem relacje rodzic–dziecko.
foreach ($pages as $slug => $cfg) {
    $body = vts_page_body($cfg['file']);
    $post = vts_find_page($slug);

    $data = [
        'post_title'   => $cfg['title'],
        'post_name'    => $slug,
        'post_type'    => 'page',
        'post_status'  => 'publish',
        'post_content' => $body,
    ];

    if ($post) {
        $data['ID'] = $post->ID;
        $id = wp_update_post($data, true);
    } else {
        $id = wp_insert_post($data, true);
    }

    if (is_wp_error($id)) {
        vts_log('BŁĄD ' . $slug . ': ' . $id->get_error_message());
        continue;
    }

    $ids[$slug] = (int) $id;

    if (!empty($cfg['seo'])) {
        update_post_meta($id, 'rank_math_title', $cfg['seo']['title']);
        update_post_meta($id, 'rank_math_description', $cfg['seo']['desc']);
    }
    // Strony katalogowe i systemowe renderujemy własnym HTML, nie Elementorem.
    delete_post_meta($id, '_elementor_edit_mode');

    // Treść tych stron to ręcznie pisany HTML z jawnymi znacznikami. Flaga wyłącza
    // dla nich wpautop — patrz vts-content.php. Bez tego filtr wstawia znaczniki
    // akapitów w środek kotwic i przeglądarka klonuje je na puste kafelki.
    update_post_meta($id, '_vts_raw_html', 1);
}

foreach ($pages as $slug => $cfg) {
    if (!empty($cfg['parent']) && isset($ids[$slug], $ids[$cfg['parent']])) {
        wp_update_post(['ID' => $ids[$slug], 'post_parent' => $ids[$cfg['parent']]]);
    }
}
vts_log('strony: ' . count($ids));

/* ------------------------------------------------------------------ wpisy
 *
 * Ten sam wzorzec co strony: źródłem jest content/posts/*.html i posts.json,
 * dopasowanie po slugu, więc drugi przebieg niczego nie dubluje. Wpisy klikane
 * w edytorze zostaną nadpisane — tak samo jak strony.
 */

function vts_find_post(string $slug): ?WP_Post
{
    $q = new WP_Query([
        'post_type'      => 'post',
        'name'           => $slug,
        'post_status'    => ['publish', 'draft', 'private'],
        'posts_per_page' => 1,
        'no_found_rows'  => true,
    ]);
    return $q->have_posts() ? $q->posts[0] : null;
}

$plik_wpisow = VTS_CONTENT . '/posts.json';
$wpisy = file_exists($plik_wpisow)
    ? (array) json_decode(file_get_contents($plik_wpisow), true)
    : [];

$ile_wpisow = 0;
foreach ($wpisy as $slug => $cfg) {
    $f = VTS_CONTENT . '/posts/' . $cfg['file'] . '.html';
    if (!file_exists($f)) {
        vts_log('BRAK PLIKU wpisu ' . $slug);
        continue;
    }

    $data = [
        'post_title'   => $cfg['title'],
        'post_name'    => $slug,
        'post_type'    => 'post',
        'post_status'  => 'publish',
        'post_content' => file_get_contents($f),
        'post_excerpt' => $cfg['excerpt'] ?? '',
        'post_date'    => $cfg['date'] . ' 09:00:00',
    ];

    $istnieje = vts_find_post($slug);
    if ($istnieje) {
        $data['ID'] = $istnieje->ID;
        $id = wp_update_post($data, true);
    } else {
        $id = wp_insert_post($data, true);
    }

    if (is_wp_error($id)) {
        vts_log('BŁĄD wpisu ' . $slug . ': ' . $id->get_error_message());
        continue;
    }

    if (!empty($cfg['seo'])) {
        update_post_meta($id, 'rank_math_title', $cfg['seo']['title']);
        update_post_meta($id, 'rank_math_description', $cfg['seo']['desc']);
    }
    delete_post_meta($id, '_elementor_edit_mode');
    update_post_meta($id, '_vts_raw_html', 1);
    if (!empty($cfg['icon'])) {
        update_post_meta($id, '_vts_icon', $cfg['icon']);
    }
    $ile_wpisow++;
}
vts_log('wpisy: ' . $ile_wpisow);

/* ----------------------------------------------- strona główna i wpisy */

foreach ($pages as $slug => $cfg) {
    if (!empty($cfg['front_page'])) {
        update_option('show_on_front', 'page');
        update_option('page_on_front', $ids[$slug]);
    }
    if (!empty($cfg['posts_page'])) {
        update_option('page_for_posts', $ids[$slug]);
    }
}

/* ----------------------------------------------------------------- menu */

function vts_build_menu(string $location, string $name, array $items, array $ids): void
{
    $menu = wp_get_nav_menu_object($name);
    if (!$menu) {
        $menu_id = wp_create_nav_menu($name);
    } else {
        $menu_id = (int) $menu->term_id;
        foreach (wp_get_nav_menu_items($menu_id) ?: [] as $it) {
            wp_delete_post($it->ID, true);
        }
    }

    $created = [];
    foreach ($items as $item) {
        $slug   = $item['slug'];
        $parent = $item['parent'] ?? null;
        if (!isset($ids[$slug])) {
            continue;
        }
        $created[$slug] = wp_update_nav_menu_item($menu_id, 0, [
            'menu-item-object-id' => $ids[$slug],
            'menu-item-object'    => 'page',
            'menu-item-type'      => 'post_type',
            'menu-item-status'    => 'publish',
            'menu-item-title'     => $item['label'],
            'menu-item-parent-id' => $parent && isset($created[$parent]) ? $created[$parent] : 0,
        ]);
    }

    $locations = get_theme_mod('nav_menu_locations', []);
    $locations[$location] = $menu_id;
    set_theme_mod('nav_menu_locations', $locations);
}

// główne: pozycje najwyższego poziomu z 'menu', plus ich dzieci
$main = [];
foreach ($pages as $slug => $cfg) {
    if (empty($cfg['menu']) || !empty($cfg['parent'])) {
        continue;
    }
    $main[] = ['slug' => $slug, 'label' => $cfg['menu']['label'], 'order' => $cfg['menu']['order']];
}
usort($main, fn($a, $b) => $a['order'] <=> $b['order']);

$with_children = [];
foreach ($main as $top) {
    $with_children[] = $top;
    $kids = [];
    foreach ($pages as $slug => $cfg) {
        if (($cfg['parent'] ?? null) === $top['slug'] && !empty($cfg['menu'])) {
            $kids[] = ['slug' => $slug, 'label' => $cfg['menu']['label'],
                       'order' => $cfg['menu']['order'], 'parent' => $top['slug']];
        }
    }
    usort($kids, fn($a, $b) => $a['order'] <=> $b['order']);
    array_push($with_children, ...$kids);
}
vts_build_menu('vts_main', 'Nawigacja główna', $with_children, $ids);

foreach (['vts_footer' => 'Stopka — usługi', 'vts_client' => 'Stopka — strefa klienta'] as $loc => $label) {
    $items = [];
    foreach ($manifest['menus'][$loc] as $slug) {
        $items[] = ['slug' => $slug, 'label' => $pages[$slug]['title']];
    }
    vts_build_menu($loc, $label, $items, $ids);
}
vts_log('menu: główne, stopka usługi, stopka strefa klienta');

/* ------------------------------------------------------------ ustawienia */

update_option('blogname', 'Vitesse V-tech Łódź');
update_option('blogdescription', 'Chip tuning, modyfikacje ECU i hamownia 4×4 w Łodzi');
update_option('permalink_structure', '/%postname%/');
update_option('rank_math_remove_category_base', true);

vts_import_form();
vts_configure_kit();
flush_rewrite_rules(false);

vts_log('');
vts_log('GOTOWE. Strona główna: ' . home_url('/'));
