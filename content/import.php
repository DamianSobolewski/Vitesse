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

/**
 * Definicje formularzy. Osobny formularz per podstrona zamiast jednego z ukrytym
 * polem tematu: każda strona pyta o co innego, a leady flotowe idą na inną skrzynkę.
 *
 * `fields` to wiersze treści maila do warsztatu — pola muszą istnieć w szablonie
 * z content/forms/{slug}.html, inaczej CF7 wstawi pusty znacznik.
 */
function vts_form_defs(): array
{
    return [
        'kontakt' => [
            'title'   => 'Formularz kontaktowy',
            'subject' => 'Zapytanie ze strony: [vehicle]',
            'inbox'   => 'retail',
            'fields'  => ['Imię' => 'your-name', 'E-mail' => 'your-email',
                          'Telefon' => 'your-phone', 'Pojazd' => 'vehicle'],
        ],
        'floty' => [
            'title'   => 'Zapytanie flotowe B2B',
            'subject' => 'Zapytanie flotowe: [company-name] ([fleet-size] szt.)',
            'inbox'   => 'fleet',
            'fields'  => ['Imię' => 'your-name', 'Firma' => 'company-name',
                          'E-mail' => 'your-email', 'Telefon' => 'your-phone',
                          'Liczba pojazdów' => 'fleet-size', 'Marki' => 'fleet-brands',
                          'Zakres' => 'scope'],
        ],
        'powerbox' => [
            'title'   => 'Zapytanie o PowerBox',
            'subject' => 'PowerBox: [vehicle]',
            'inbox'   => 'retail',
            'fields'  => ['Imię' => 'your-name', 'E-mail' => 'your-email',
                          'Telefon' => 'your-phone', 'Pojazd' => 'vehicle',
                          'Status' => 'status'],
        ],
        'ev' => [
            'title'   => 'Zapytanie EV / Hybryda',
            'subject' => 'EV / Hybryda: [vehicle]',
            'inbox'   => 'retail',
            'fields'  => ['Imię' => 'your-name', 'E-mail' => 'your-email',
                          'Telefon' => 'your-phone', 'Pojazd' => 'vehicle',
                          'Zakres' => 'scope', 'Przyłącze' => 'power-supply'],
        ],
        'hamownia' => [
            'title'   => 'Rezerwacja pomiaru na hamowni',
            'subject' => 'Hamownia: [vehicle] ([kind])',
            'inbox'   => 'retail',
            'fields'  => ['Imię' => 'your-name', 'E-mail' => 'your-email',
                          'Telefon' => 'your-phone', 'Pojazd' => 'vehicle',
                          'Rodzaj' => 'kind', 'Termin' => 'preferred-date'],
        ],
        'ecu' => [
            'title'   => 'Zgłoszenie — dodatkowe usługi ECU',
            'subject' => 'Usługi ECU: [vehicle]',
            'inbox'   => 'retail',
            'fields'  => ['Imię' => 'your-name', 'E-mail' => 'your-email',
                          'Telefon' => 'your-phone', 'Pojazd' => 'vehicle',
                          'Zakres' => 'scope'],
        ],
    ];
}

function vts_import_form(): void
{
    if (!post_type_exists('wpcf7_contact_form')) {
        vts_log('Contact Form 7 nieaktywny — pomijam formularze');
        return;
    }

    $c    = vts_company();
    $from = 'no-reply@' . wp_parse_url(home_url(), PHP_URL_HOST);

    $messages = [
        'mail_sent_ok'     => 'Dziękujemy. Odezwiemy się w godzinach pracy warsztatu.',
        'mail_sent_ng'     => 'Nie udało się wysłać wiadomości. Zadzwońcie do nas — ' . $c['phones']['tuning']['number'] . '.',
        'validation_error' => 'Uzupełnijcie zaznaczone pola.',
        'accept_terms'     => 'Zaznaczcie zgodę na kontakt.',
        'invalid_email'    => 'Ten adres e-mail wygląda na niepoprawny.',
        'invalid_required' => 'To pole jest wymagane.',
    ];

    foreach (vts_form_defs() as $slug => $def) {
        $plik = VTS_CONTENT . '/forms/' . $slug . '.html';
        if (!is_readable($plik)) {
            vts_log('  brak szablonu formularza: ' . $slug . ' — pomijam');
            continue;
        }

        $to = vts_lead_inbox($def['inbox']);

        $existing = get_page_by_path($slug, OBJECT, 'wpcf7_contact_form');
        $id = $existing ? $existing->ID : wp_insert_post([
            'post_type'   => 'wpcf7_contact_form',
            'post_status' => 'publish',
            'post_title'  => $def['title'],
            'post_name'   => $slug,
        ]);

        // Wyrównanie etykiet w mailu — Krzysiek czyta to na telefonie, nie w kliencie
        // z proporcjonalną czcionką, więc kolumna wartości musi trzymać pion.
        $szer  = max(array_map('mb_strlen', array_keys($def['fields']))) + 2;
        $body  = '';
        foreach ($def['fields'] as $etykieta => $pole) {
            $body .= str_pad($etykieta . ':', $szer) . '[' . $pole . "]\n";
        }
        $body .= "\nTreść:\n[your-message]\n";

        update_post_meta($id, '_form', file_get_contents($plik));
        update_post_meta($id, '_mail', [
            'active'             => true,
            'subject'            => '[Vitesse] ' . $def['subject'],
            'sender'             => $c['name'] . ' <' . $from . '>',
            'recipient'          => $to,
            'body'               => $body,
            'additional_headers' => 'Reply-To: [your-email]',
            'attachments'        => '',
            'use_html'           => false,
            'exclude_blank'      => true,
        ]);
        update_post_meta($id, '_messages', $messages);
        update_post_meta($id, '_locale', 'pl_PL');

        vts_log('formularz ' . $slug . ': id ' . $id . ', odbiorca ' . $to);
    }
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
        ['_id' => 'primary',   'title' => 'Pomarańcz',  'color' => '#F46A00'],
        ['_id' => 'secondary', 'title' => 'Grafit',     'color' => '#171A21'],
        ['_id' => 'text',      'title' => 'Tekst',      'color' => '#F1F2F4'],
        ['_id' => 'accent',    'title' => 'Wyciszony',  'color' => '#8A919E'],
    ];
    // Jedna rodzina z księgi znaku — nagłówki i tekst różni tylko grubość.
    $settings['system_typography'] = [
        ['_id' => 'primary',   'title' => 'Nagłówki',
         'typography_typography' => 'custom', 'typography_font_family' => 'IBM Plex Sans',
         'typography_font_weight' => '700'],
        ['_id' => 'secondary', 'title' => 'Podtytuły',
         'typography_typography' => 'custom', 'typography_font_family' => 'IBM Plex Sans',
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

/* Strony, które wypadły z manifestu, idą do kosza. Rozpoznajemy je po fladze
 * _vts_raw_html, więc dotyczy to wyłącznie stron z importera — strona dodana
 * ręcznie w edytorze zostaje. Bez tego usunięte podstrony (np. dawne kategorie
 * chip tuningu) dalej odpowiadałyby 200 i wisiały w mapie witryny. */
$stare = get_posts([
    'post_type'      => 'page',
    'post_status'    => ['publish', 'draft', 'private'],
    'posts_per_page' => -1,
    'meta_key'       => '_vts_raw_html',
    'fields'         => 'ids',
]);
$do_kosza = 0;
foreach ($stare as $pid) {
    $slug = get_post_field('post_name', $pid);
    if (!isset($ids[$slug])) {
        wp_trash_post($pid);
        $do_kosza++;
    }
}
if ($do_kosza) {
    vts_log('strony spoza manifestu przeniesione do kosza: ' . $do_kosza);
}

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
    // Pozycja to slug albo {slug, label} — stopka ma własne, handlowe etykiety
    // („PowerBox Volvo VEA"), inne niż tytuły stron.
    $items = [];
    foreach ($manifest['menus'][$loc] as $poz) {
        $slug  = is_array($poz) ? $poz['slug'] : $poz;
        $label = is_array($poz) && !empty($poz['label']) ? $poz['label'] : $pages[$slug]['title'];
        $items[] = ['slug' => $slug, 'label' => $label];
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
