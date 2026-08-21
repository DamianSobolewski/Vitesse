<?php
/**
 * Plugin Name: Vitesse — adresy katalogu
 * Description: Reguły przepisywania i szablony dla /chiptuning/{marka}/{model}/{generacja}/{silnik}/.
 *
 * Strony katalogu są wirtualne — powstają z tabel, nie z wp_posts. Przy 3200
 * wariantach silnikowych trzymanie ich jako wpisów oznaczałoby dziesiątki tysięcy
 * wierszy w wp_posts i wp_postmeta oraz nieużywalny wp-admin.
 */

if (!defined('ABSPATH')) {
    exit;
}

const VTS_CATALOG_BASE = 'chiptuning';

/** Slugi zarezerwowane dla realnych podstron — marka nie może ich przejąć. */
function vts_reserved_slugs(): array
{
    return ['chip-tuning', 'powerboxy', 'odblokowywanie-sterownikow', 'oferta-dla-flot',
            'dodatkowe-uslugi-ecu', 'samochody-osobowe', 'samochody-dostawcze',
            'ciezarowe-i-autobusy', 'kampery', 'ciagniki-i-maszyny', 'ev-i-hybrydy',
            'skrzynie-biegow-tcu', 'auta-na-gwarancji-i-w-leasingu', 'katalog'];
}

add_action('init', function () {
    $b = VTS_CATALOG_BASE;

    // 'top' — cała przestrzeń /chiptuning/ należy do katalogu. Strony redakcyjne
    // żyją pod /podnoszenie-mocy/, więc kolizja z regułą pagename jest niemożliwa.
    // Przy 'bottom' reguła pagename przechwytywała adresy jedno- i dwuczłonowe.
    add_rewrite_rule("^{$b}/?$",                              'index.php?vts_level=index', 'top');
    add_rewrite_rule("^{$b}/([^/]+)/?$",                      'index.php?vts_level=make&vts_make=$matches[1]', 'top');
    add_rewrite_rule("^{$b}/([^/]+)/([^/]+)/?$",              'index.php?vts_level=model&vts_make=$matches[1]&vts_model=$matches[2]', 'top');
    add_rewrite_rule("^{$b}/([^/]+)/([^/]+)/([^/]+)/?$",      'index.php?vts_level=gen&vts_make=$matches[1]&vts_model=$matches[2]&vts_gen=$matches[3]', 'top');
    add_rewrite_rule("^{$b}/([^/]+)/([^/]+)/([^/]+)/([^/]+)/?$", 'index.php?vts_level=engine&vts_make=$matches[1]&vts_model=$matches[2]&vts_gen=$matches[3]&vts_engine=$matches[4]', 'top');
});

add_filter('query_vars', function ($vars) {
    return array_merge($vars, ['vts_level', 'vts_make', 'vts_model', 'vts_gen', 'vts_engine']);
});

/* ---------------------------------------------------------- budowa URL-i */

function vts_catalog_url(...$parts): string
{
    return home_url('/' . VTS_CATALOG_BASE . '/' . implode('/', array_filter($parts)) . '/');
}

/* ------------------------------------------------------------- rozwiązanie */

/**
 * Zamienia slugi z adresu na rekordy. Zwraca null, jeśli którykolwiek poziom
 * nie istnieje albo jest ukryty — wtedy WordPress pokaże 404.
 */
function vts_resolve_route(): ?array
{
    global $wpdb;

    $level = get_query_var('vts_level');
    if (!$level) {
        return null;
    }

    $out = ['level' => $level];

    if ($level === 'index') {
        return $out;
    }

    $k = vts_table('make'); $o = vts_table('model');
    $g = vts_table('generation'); $e = vts_table('engine');

    $out['make'] = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$k} m WHERE m.slug = %s" . vts_visibility_sql('m', 'jlr_service'),
        get_query_var('vts_make')
    ), ARRAY_A);
    if (!$out['make']) {
        return null;
    }
    if ($level === 'make') {
        return $out;
    }

    $out['model'] = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$o} x WHERE x.make_id = %d AND x.slug = %s" . vts_visibility_sql('x'),
        $out['make']['id'], get_query_var('vts_model')
    ), ARRAY_A);
    if (!$out['model']) {
        return null;
    }
    if ($level === 'model') {
        return $out;
    }

    $out['gen'] = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$g} x WHERE x.model_id = %d AND x.slug = %s" . vts_visibility_sql('x'),
        $out['model']['id'], get_query_var('vts_gen')
    ), ARRAY_A);
    if (!$out['gen']) {
        return null;
    }
    if ($level === 'gen') {
        return $out;
    }

    $out['engine'] = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$e} x WHERE x.generation_id = %d AND x.slug = %s" . vts_visibility_sql('x'),
        $out['gen']['id'], get_query_var('vts_engine')
    ), ARRAY_A);

    return $out['engine'] ? $out : null;
}

add_action('template_redirect', function () {
    if (!get_query_var('vts_level')) {
        return;
    }

    $route = vts_resolve_route();

    if (!$route) {
        // Adres pasuje do wzorca katalogu, ale rekordu nie ma. Bez jawnego 404
        // WordPress oddałby pustą stronę ze statusem 200 — przy tej liczbie
        // adresów oznaczałoby to masowe soft-404 w wynikach wyszukiwania.
        global $wp_query;
        $wp_query->set_404();
        status_header(404);
        nocache_headers();
        get_template_part('404');
        exit;
    }

    global $wp_query, $vts_route;
    $vts_route = $route;
    $wp_query->is_404 = false;
    status_header(200);

    add_filter('body_class', fn($c) => array_merge($c, ['vts', 'vts-catalog']));

    get_header();
    vts_render_catalog($route);
    get_footer();
    exit;
}, 5);

/* ------------------------------------------------------------- szablony */

function vts_catalog_crumbs(array $r): string
{
    $items = ['<a href="' . esc_url(home_url('/')) . '">Start</a>',
              '<a href="' . esc_url(vts_catalog_url()) . '">Katalog</a>'];

    if (!empty($r['make']) && $r['level'] !== 'make') {
        $items[] = '<a href="' . esc_url(vts_catalog_url($r['make']['slug'])) . '">'
                 . esc_html($r['make']['name']) . '</a>';
    }
    if (!empty($r['model']) && !in_array($r['level'], ['make', 'model'], true)) {
        $items[] = '<a href="' . esc_url(vts_catalog_url($r['make']['slug'], $r['model']['slug'])) . '">'
                 . esc_html($r['model']['name']) . '</a>';
    }
    if (!empty($r['gen']) && $r['level'] === 'engine') {
        $items[] = '<a href="' . esc_url(vts_catalog_url($r['make']['slug'], $r['model']['slug'], $r['gen']['slug'])) . '">'
                 . esc_html($r['gen']['name']) . '</a>';
    }

    return '<nav class="vts-crumbs" aria-label="Ścieżka">' . implode('<span>/</span>', $items) . '</nav>';
}

function vts_render_catalog(array $r): void
{
    echo '<section class="vts-hero" style="padding-block:var(--vts-gap-m) var(--vts-gap-s)"><div class="vts-wrap">';
    echo vts_catalog_crumbs($r);
    echo '<h1 style="font-size:var(--vts-step-3);font-style:normal;font-weight:700">'
       . esc_html(vts_catalog_title($r)) . '</h1>';
    echo '<p class="vts-lead">' . esc_html(vts_catalog_intro($r)) . '</p>';
    echo '</div></section>';

    echo '<div class="vts-section" style="padding-block:var(--vts-gap-m) var(--vts-gap-xl)"><div class="vts-wrap">';
    match ($r['level']) {
        'index'  => vts_view_index(),
        'make'   => vts_view_make($r),
        'model'  => vts_view_model($r),
        'gen'    => vts_view_gen($r),
        'engine' => vts_view_engine($r),
    };
    echo '</div></div>';
}

function vts_catalog_title(array $r): string
{
    return match ($r['level']) {
        'index'  => 'Katalog osiągów — chip tuning',
        'make'   => 'Chip tuning ' . $r['make']['name'],
        'model'  => 'Chip tuning ' . $r['make']['name'] . ' ' . $r['model']['name'],
        'gen'    => $r['make']['name'] . ' ' . $r['model']['name'] . ' ' . $r['gen']['name'],
        'engine' => $r['make']['name'] . ' ' . $r['model']['name'] . ' ' . $r['gen']['name']
                  . ' — ' . $r['engine']['name'],
    };
}

function vts_catalog_intro(array $r): string
{
    $c = vts_catalog_counts();
    return match ($r['level']) {
        'index'  => "Wyniki dla {$c['engine']} wersji silnikowych z {$c['make']} marek. Wybierz markę, żeby zobaczyć modele.",
        'make'   => 'Modele ' . $r['make']['name'] . ', dla których mamy gotowe rozwiązania. Wybierz swój.',
        'model'  => 'Generacje modelu ' . $r['model']['name'] . '. Wybierz rocznik swojego auta.',
        'gen'    => 'Wersje silnikowe i przyrosty mocy. Wartości orientacyjne — wiążący jest pomiar na hamowni.',
        'engine' => 'Dane fabryczne i możliwy przyrost po modyfikacji.',
    };
}

function vts_view_index(): void
{
    echo '<div class="vts-cat-grid">';
    foreach (vts_makes() as $m) {
        printf('<a class="vts-cat-tile" href="%s"><b>%s</b><span>%d modeli</span></a>',
            esc_url(vts_catalog_url($m['slug'])), esc_html($m['name']), (int) $m['model_count']);
    }
    echo '</div>';
}

function vts_view_make(array $r): void
{
    echo '<div class="vts-cat-grid">';
    foreach (vts_models($r['make']['slug']) as $m) {
        printf('<a class="vts-cat-tile" href="%s"><b>%s</b><span>%d generacji</span></a>',
            esc_url(vts_catalog_url($r['make']['slug'], $m['slug'])),
            esc_html($m['name']), (int) $m['generation_count']);
    }
    echo '</div>';
}

function vts_view_model(array $r): void
{
    echo '<div class="vts-cat-grid">';
    foreach (vts_generations((int) $r['model']['id']) as $g) {
        $years = $g['year_from'] ? $g['year_from'] . '–' . ($g['year_to'] ?: '') : '';
        printf('<a class="vts-cat-tile" href="%s"><b>%s</b><span>%s%d wersji</span></a>',
            esc_url(vts_catalog_url($r['make']['slug'], $r['model']['slug'], $g['slug'])),
            esc_html($g['name']), $years ? esc_html($years) . ' · ' : '', (int) $g['engine_count']);
    }
    echo '</div>';
}

function vts_view_gen(array $r): void
{
    $engines = vts_engines((int) $r['gen']['id']);

    echo '<div class="vts-table-wrap"><table class="vts-table"><thead><tr>'
       . '<th>Wersja silnika</th><th>Paliwo</th><th>Moc</th><th>Moment</th><th></th>'
       . '</tr></thead><tbody>';

    foreach ($engines as $e) {
        printf('<tr><td><a href="%s">%s</a></td><td>%s</td><td>%d KM</td><td>%d Nm</td>'
             . '<td><a class="vts-table__go" href="%s">Sprawdź przyrost</a></td></tr>',
            esc_url(vts_catalog_url($r['make']['slug'], $r['model']['slug'], $r['gen']['slug'], $e['slug'])),
            esc_html($e['name']), esc_html($e['fuel']),
            (int) $e['stock_hp'], (int) $e['stock_nm'],
            esc_url(vts_catalog_url($r['make']['slug'], $r['model']['slug'], $r['gen']['slug'], $e['slug'])));
    }
    echo '</tbody></table></div>';
}

function vts_view_engine(array $r): void
{
    $e = $r['engine'];
    ?>
    <div class="vts-split vts-split--wide-left">
      <div>
        <div class="vts-ps__res" style="margin-bottom:var(--vts-gap-m)">
          <div class="vts-ps__cell"><span>Moc fabryczna</span><b><?= (int) $e['stock_hp'] ?> KM</b></div>
          <div class="vts-ps__cell"><span>Moment fabryczny</span><b><?= (int) $e['stock_nm'] ?> Nm</b></div>
          <div class="vts-ps__cell"><span>Paliwo</span><b style="font-size:16px"><?= esc_html($e['fuel']) ?></b></div>
          <div class="vts-ps__cell"><span>Moc [kW]</span><b><?= (int) $e['stock_kw'] ?></b></div>
        </div>

        <h2>Co da się uzyskać</h2>
        <p>Dla tej wersji mamy przygotowane rozwiązania. Dokładne wartości po modyfikacji
        i orientacyjną wycenę pokażemy po podaniu adresu e-mail — poniżej, w wyszukiwarce.</p>
        <p>Wartości są orientacyjne i zależą od stanu technicznego pojazdu. Wiążący jest wynik
        pomiaru na hamowni, który wykonujemy przed modyfikacją i po niej.</p>

        <h2 style="margin-top:var(--vts-gap-m)">Inne wersje tej generacji</h2>
        <ul class="vts-cat-siblings">
          <?php foreach (vts_engines((int) $r['gen']['id']) as $s) :
              if ((int) $s['id'] === (int) $e['id']) { continue; } ?>
            <li><a href="<?= esc_url(vts_catalog_url($r['make']['slug'], $r['model']['slug'], $r['gen']['slug'], $s['slug'])) ?>">
              <?= esc_html($s['name']) ?></a> <span><?= (int) $s['stock_hp'] ?> KM</span></li>
          <?php endforeach; ?>
        </ul>
      </div>
      <div><?= do_shortcode('[vts_power_search title="Sprawdź przyrost dla tej wersji"]') ?></div>
    </div>
    <?php
}

/* ------------------------------------------------------------- meta, SEO */

add_filter('pre_get_document_title', function ($title) {
    global $vts_route;
    if (!$vts_route) {
        return $title;
    }
    return vts_catalog_title($vts_route) . ' | Vitesse V-tech Łódź';
}, 20);

add_action('wp_head', function () {
    global $vts_route;
    if (!$vts_route) {
        return;
    }

    $r = $vts_route;
    $canonical = vts_catalog_url(
        $r['make']['slug'] ?? null, $r['model']['slug'] ?? null,
        $r['gen']['slug'] ?? null, $r['engine']['slug'] ?? null
    );

    echo '<link rel="canonical" href="' . esc_url($canonical) . '">' . "\n";
    echo '<meta name="description" content="' . esc_attr(vts_catalog_intro($r)) . '">' . "\n";

    // Przełącznik odwrotu: gdyby Search Console zgłosiła problem z cienką treścią,
    // schodzimy poziom wyżej jedną komendą, bez zmian w adresach.
    $order = ['index' => 0, 'make' => 1, 'model' => 2, 'gen' => 3, 'engine' => 4];
    $limit = ['make' => 1, 'model' => 2, 'generation' => 3, 'engine' => 4][vts_catalog_index_level()];
    if (($order[$r['level']] ?? 0) > $limit) {
        echo '<meta name="robots" content="noindex,follow">' . "\n";
    }
}, 1);

/** Rank Math nie wie o stronach wirtualnych — wyłączamy jego meta na katalogu. */
add_filter('rank_math/frontend/disable_integration', function ($disable) {
    return get_query_var('vts_level') ? true : $disable;
});
