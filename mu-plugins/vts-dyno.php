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
    $data  = vts_dyno_query(['page' => 1]);
    $marki = get_terms(['taxonomy' => 'vts_dyno_marka', 'hide_empty' => true]);

    ob_start(); ?>
    <div class="vts-dyno" data-vts-dyno data-rest="<?= esc_attr(rest_url('vitesse/v1/dyno')) ?>">
      <?php if (!is_wp_error($marki) && $marki) : ?>
        <div class="vts-dyno__filters">
          <a href="<?= esc_url(get_permalink()) ?>" class="vts-dyno__chip is-active" data-filter="">Wszystkie</a>
          <?php foreach ($marki as $t) : ?>
            <a href="<?= esc_url(get_term_link($t)) ?>" class="vts-dyno__chip"
               data-filter="<?= esc_attr($t->slug) ?>"><?= esc_html($t->name) ?></a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <div class="vts-dyno__grid" data-grid>
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
