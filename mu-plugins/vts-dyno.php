<?php
/**
 * Plugin Name: Vitesse — baza wykresów z hamowni
 * Description: CPT realizacji, taksonomie, siatka z filtrowaniem, shortcode [vts_dyno_grid].
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('init', function () {
    register_post_type('vts_dyno', [
        'label'         => 'Wykresy z hamowni',
        'labels'        => [
            'name'          => 'Wykresy z hamowni',
            'singular_name' => 'Wykres',
            'add_new'       => 'Dodaj wykres',
            'add_new_item'  => 'Dodaj nowy wykres',
            'edit_item'     => 'Edytuj wykres',
            'all_items'     => 'Wszystkie wykresy',
            'search_items'  => 'Szukaj wykresów',
            'not_found'     => 'Nie ma jeszcze żadnych wykresów.',
        ],
        'public'        => true,
        'has_archive'   => 'wykresy',
        'rewrite'       => ['slug' => 'wykres', 'with_front' => false],
        'menu_icon'     => 'dashicons-chart-area',
        'supports'      => ['title', 'editor', 'thumbnail'],
        'show_in_rest'  => true,
        'capability_type' => ['vts_dyno', 'vts_dynos'],
        'map_meta_cap'  => true,
    ]);

    $tax = [
        'vts_dyno_marka'  => ['Marka', 'marka'],
        'vts_dyno_paliwo' => ['Paliwo', 'paliwo'],
        'vts_dyno_usluga' => ['Rodzaj usługi', 'usluga'],
        'vts_dyno_klasa'  => ['Klasa pojazdu', 'klasa'],
    ];
    foreach ($tax as $slug => [$label, $rewrite]) {
        register_taxonomy($slug, 'vts_dyno', [
            'label'        => $label,
            'public'       => true,
            'hierarchical' => false,
            'show_in_rest' => true,
            'rewrite'      => ['slug' => 'wykresy/' . $rewrite, 'with_front' => false],
        ]);
    }
});

/** Pola opisujące pomiar. */
function vts_dyno_fields(): array
{
    return [
        '_vts_stock_hp' => ['Moc fabryczna [KM]', 'number'],
        '_vts_stock_nm' => ['Moment fabryczny [Nm]', 'number'],
        '_vts_tuned_hp' => ['Moc po modyfikacji [KM]', 'number'],
        '_vts_tuned_nm' => ['Moment po modyfikacji [Nm]', 'number'],
        '_vts_date'     => ['Data pomiaru', 'date'],
        '_vts_engine_id'=> ['Powiązany silnik z katalogu (ID)', 'number'],
    ];
}

function vts_dyno_meta(int $id): array
{
    $out = [];
    foreach (array_keys(vts_dyno_fields()) as $k) {
        $out[$k] = get_post_meta($id, $k, true);
    }
    $out['consent'] = (bool) get_post_meta($id, '_vts_consent', true);
    return $out;
}

/* ------------------------------------------------------------- REST */

add_action('rest_api_init', function () {
    register_rest_route('vitesse/v1', '/dyno', [
        'methods'  => 'GET',
        'permission_callback' => '__return_true',
        'callback' => function (WP_REST_Request $r) {
            return vts_dyno_query([
                'marka'  => sanitize_title($r->get_param('marka')),
                'paliwo' => sanitize_title($r->get_param('paliwo')),
                'usluga' => sanitize_title($r->get_param('usluga')),
                'page'   => max(1, (int) $r->get_param('page')),
            ]);
        },
    ]);
});

function vts_dyno_query(array $args): array
{
    $tax = [];
    foreach (['marka' => 'vts_dyno_marka', 'paliwo' => 'vts_dyno_paliwo', 'usluga' => 'vts_dyno_usluga'] as $k => $t) {
        if (!empty($args[$k])) {
            $tax[] = ['taxonomy' => $t, 'field' => 'slug', 'terms' => $args[$k]];
        }
    }

    $q = new WP_Query([
        'post_type'      => 'vts_dyno',
        'post_status'    => 'publish',
        'posts_per_page' => 12,
        'paged'          => $args['page'] ?? 1,
        'tax_query'      => $tax ?: null,
    ]);

    $items = [];
    foreach ($q->posts as $p) {
        $m = vts_dyno_meta($p->ID);
        $items[] = [
            'id'    => $p->ID,
            'title' => get_the_title($p),
            'url'   => get_permalink($p),
            'img'   => get_the_post_thumbnail_url($p, 'large') ?: '',
            'stock' => $m['_vts_stock_hp'] ? (int) $m['_vts_stock_hp'] : null,
            'tuned' => $m['_vts_tuned_hp'] ? (int) $m['_vts_tuned_hp'] : null,
            'nm_stock' => $m['_vts_stock_nm'] ? (int) $m['_vts_stock_nm'] : null,
            'nm_tuned' => $m['_vts_tuned_nm'] ? (int) $m['_vts_tuned_nm'] : null,
        ];
    }

    return ['items' => $items, 'pages' => (int) $q->max_num_pages, 'total' => (int) $q->found_posts];
}

/* -------------------------------------------------------- shortcode */

add_shortcode('vts_dyno_grid', function () {
    $data = vts_dyno_query(['page' => 1]);

    // Trzy osie filtrowania. Grupę pomijamy, gdy w bazie jest najwyżej jeden term —
    // rząd chipów z jedną pozycją niczego nie filtruje, a wygląda na zepsuty.
    $grupy = [
        ['marka',  'vts_dyno_marka',  'Marka'],
        ['paliwo', 'vts_dyno_paliwo', 'Paliwo'],
        ['usluga', 'vts_dyno_usluga', 'Rodzaj usługi'],
    ];

    ob_start(); ?>
    <div class="vts-dyno" data-vts-dyno data-rest="<?= esc_attr(rest_url('vitesse/v1/dyno')) ?>">

      <?php foreach ($grupy as [$klucz, $tax, $label]) :
          $terms = get_terms(['taxonomy' => $tax, 'hide_empty' => true]);
          if (is_wp_error($terms) || count($terms) < 2) {
              continue;
          } ?>
        <div class="vts-dyno__group">
          <span class="vts-dyno__glabel"><?= esc_html($label) ?></span>
          <div class="vts-dyno__filters" role="group" aria-label="<?= esc_attr($label) ?>">
            <a href="<?= esc_url(get_permalink()) ?>" class="vts-dyno__chip is-active"
               data-filter-group="<?= esc_attr($klucz) ?>" data-filter="">Wszystkie</a>
            <?php foreach ($terms as $t) : ?>
              <a href="<?= esc_url(get_term_link($t)) ?>" class="vts-dyno__chip"
                 data-filter-group="<?= esc_attr($klucz) ?>"
                 data-filter="<?= esc_attr($t->slug) ?>"><?= esc_html($t->name) ?></a>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>

      <div class="vts-grid vts-dyno__grid" data-grid>
        <?php if (!$data['items']) : ?>
          <p class="vts-dyno__empty">Nie ma jeszcze opublikowanych wykresów.
            Pierwsze pojawią się tu zaraz po wprowadzeniu ich do panelu.</p>
        <?php else : ?>
          <?= vts_dyno_cards($data['items']) ?>
        <?php endif; ?>
      </div>

      <?php if ($data['pages'] > 1) : ?>
        <button class="vts-btn vts-btn--ghost vts-dyno__more" data-more data-page="1">Pokaż więcej</button>
      <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
});

function vts_dyno_cards(array $items): string
{
    $out = '';
    foreach ($items as $i) {
        $gain = ($i['stock'] && $i['tuned']) ? $i['tuned'] - $i['stock'] : null;
        $out .= '<a class="vts-dyno__card" href="' . esc_url($i['url']) . '">';
        if ($i['img']) {
            $out .= '<span class="vts-dyno__img"><img src="' . esc_url($i['img']) . '" alt="'
                  . esc_attr($i['title']) . '" loading="lazy" width="600" height="400"></span>';
        }
        $out .= '<span class="vts-dyno__body"><span class="vts-dyno__t">' . esc_html($i['title']) . '</span>';
        if ($i['stock'] && $i['tuned']) {
            $out .= '<span class="vts-dyno__v">' . (int) $i['stock'] . ' → <b>' . (int) $i['tuned'] . ' KM</b>';
            if ($gain) {
                $out .= ' <em>+' . $gain . '</em>';
            }
            $out .= '</span>';
        }
        $out .= '</span></a>';
    }
    return $out;
}

/* ---------------------------------------------------------- pojedynczy wykres
 *
 * Motyw bazowy renderuje wpis CPT jako gołą treść bez tytułu i obrazka.
 * Doklejamy widok: wydruk na pełną szerokość kolumny, obok karta z liczbami,
 * datą i etykietami, pod spodem opis i przejście do pomiaru. Nagłówek (okruszki
 * i H1) dokłada vts-content.php tak jak stronom.
 */
add_filter('the_content', function ($content) {
    if (!is_singular('vts_dyno') || !in_the_loop() || !is_main_query() || is_admin()) {
        return $content;
    }

    $id   = get_the_ID();
    $m    = vts_dyno_meta($id);
    $img  = get_the_post_thumbnail($id, 'large', ['loading' => 'eager', 'decoding' => 'async']);
    $full = get_the_post_thumbnail_url($id, 'full');
    $gain = ($m['_vts_stock_hp'] && $m['_vts_tuned_hp']) ? (int) $m['_vts_tuned_hp'] - (int) $m['_vts_stock_hp'] : null;
    $gnm  = ($m['_vts_stock_nm'] && $m['_vts_tuned_nm']) ? (int) $m['_vts_tuned_nm'] - (int) $m['_vts_stock_nm'] : null;

    $tagi = [];
    foreach (['vts_dyno_marka', 'vts_dyno_paliwo', 'vts_dyno_usluga', 'vts_dyno_klasa'] as $tax) {
        foreach (get_the_terms($id, $tax) ?: [] as $t) {
            $tagi[] = '<a class="vts-dyno__chip" href="' . esc_url(get_term_link($t)) . '">' . esc_html($t->name) . '</a>';
        }
    }

    $engine = (int) $m['_vts_engine_id'];
    $kat    = $engine && function_exists('vts_engine_path') ? vts_engine_path($engine) : null;
    $kat_url = $kat ? vts_catalog_url($kat['make_slug'], $kat['model_slug'], $kat['gen_slug'], $kat['engine_slug']) : '';

    ob_start(); ?>
    <div class="vts-section vts-dyno-single">
      <div class="vts-wrap vts-split vts-split--wide-left">
        <div>
          <?php if ($img) : ?>
            <figure class="vts-dyno-single__chart">
              <a href="<?= esc_url($full) ?>" target="_blank" rel="noopener"><?= $img ?></a>
              <figcaption>Kliknij, żeby otworzyć wydruk w pełnym rozmiarze.</figcaption>
            </figure>
          <?php endif; ?>
          <div class="vts-dyno-single__note"><?= $content ?></div>
        </div>
        <div>
          <div class="vts-card vts-dyno-single__data">
            <h2>Wynik pomiaru</h2>
            <dl>
              <?php if ($m['_vts_stock_hp']) : ?>
                <dt>Moc seryjna</dt><dd><?= (int) $m['_vts_stock_hp'] ?> KM<?= $m['_vts_stock_nm'] ? ' · ' . (int) $m['_vts_stock_nm'] . ' Nm' : '' ?></dd>
              <?php endif; ?>
              <?php if ($m['_vts_tuned_hp']) : ?>
                <dt>Po modyfikacji</dt><dd class="is-accent"><?= (int) $m['_vts_tuned_hp'] ?> KM<?= $m['_vts_tuned_nm'] ? ' · ' . (int) $m['_vts_tuned_nm'] . ' Nm' : '' ?></dd>
                <dt>Przyrost</dt><dd class="is-accent">+<?= $gain ?> KM<?= $gnm !== null ? ' · +' . $gnm . ' Nm' : '' ?></dd>
              <?php endif; ?>
              <?php if ($m['_vts_date']) : ?>
                <dt>Data pomiaru</dt><dd><?= esc_html(date_i18n('j F Y', strtotime($m['_vts_date']))) ?></dd>
              <?php endif; ?>
            </dl>
            <?php if ($tagi) : ?>
              <div class="vts-dyno__filters" style="margin:var(--vts-gap-s) 0 0"><?= implode('', $tagi) ?></div>
            <?php endif; ?>
            <div class="vts-dyno-single__cta">
              <a class="vts-btn vts-btn--primary" href="<?= esc_url(home_url('/hamownia/#rezerwacja')) ?>">Umów pomiar swojego auta</a>
              <?php if ($kat_url) : ?>
                <a class="vts-btn vts-btn--ghost" href="<?= esc_url($kat_url) ?>">Ta wersja w katalogu</a>
              <?php endif; ?>
            </div>
          </div>
          <p class="vts-note" style="margin-top:var(--vts-gap-s)">Wynik dotyczy tego egzemplarza, zmierzonego
            na naszym stanowisku przed pracą i po niej. Wartości dla innego auta tej samej wersji mogą się różnić.</p>
        </div>
      </div>
    </div>
    <?php
    return ob_get_clean();
}, 98);

