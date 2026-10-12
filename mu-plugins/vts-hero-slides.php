<?php
/**
 * Plugin Name: Vitesse — slajdy hero
 * Description: Typ treści „Slajdy hero”: karuzela strony głównej sterowana z kokpitu.
 *
 * Każdy slajd to wpis: tytuł, nadtytuł, lead, obrazek wyróżniający, kolejność.
 * Opublikowany = widoczny w karuzeli, szkic = wyłączony. Bez własnego obrazka slajd
 * używa fabrycznego zdjęcia z assets/img (meta _vts_img), więc po imporcie strona
 * wygląda dokładnie tak jak przed wprowadzeniem tego typu treści.
 *
 * Rysowaniem zajmuje się vts_render_home_hero() w vts-content.php — tu tylko dane.
 */

if (!defined('ABSPATH')) {
    exit;
}

const VTS_SLIDE_CPT = 'vts_slajd';

/* ------------------------------------------------------------- rejestracja */

add_action('init', function () {
    register_post_type(VTS_SLIDE_CPT, [
        'label'               => 'Slajdy hero',
        'labels'              => [
            'name'               => 'Slajdy hero',
            'singular_name'      => 'Slajd',
            'menu_name'          => 'Slajdy hero',
            'add_new'            => 'Dodaj slajd',
            'add_new_item'       => 'Dodaj nowy slajd',
            'edit_item'          => 'Edytuj slajd',
            'new_item'           => 'Nowy slajd',
            'all_items'          => 'Wszystkie slajdy',
            'search_items'       => 'Szukaj slajdów',
            'not_found'          => 'Nie ma jeszcze żadnych slajdów. Strona główna pokaże slajd awaryjny.',
            'not_found_in_trash' => 'Kosz jest pusty.',
            'featured_image'     => 'Zdjęcie slajdu',
            'set_featured_image' => 'Ustaw zdjęcie slajdu',
            'remove_featured_image' => 'Usuń zdjęcie (wróci fabryczne)',
            'use_featured_image' => 'Użyj jako zdjęcia slajdu',
            'attributes'         => 'Kolejność',
        ],
        'description'         => 'Karuzela na górze strony głównej.',
        'public'              => false,
        'show_ui'             => true,
        'show_in_menu'        => true,
        'show_in_rest'        => false,
        'exclude_from_search' => true,
        'publicly_queryable'  => false,
        'rewrite'             => false,
        'query_var'           => false,
        'has_archive'         => false,
        'hierarchical'        => false,
        'menu_position'       => 21,
        'menu_icon'           => 'dashicons-images-alt2',
        'supports'            => ['title', 'thumbnail', 'page-attributes'],
        'capability_type'     => 'page',
        'map_meta_cap'        => true,
    ]);

    // Wariant tła na wąskie ekrany — odpowiednik plików *-sm.webp z assets/img.
    add_image_size('vts-hero-sm', 960, 0, false);
});

/* Formularz danych, nie strona: klasyczny edytor bez bloków i bez Elementora. */
add_filter('use_block_editor_for_post_type', function ($use, $type) {
    return $type === VTS_SLIDE_CPT ? false : $use;
}, 10, 2);

add_filter('elementor/utils/is_post_type_support', function ($supports) {
    return get_post_type() === VTS_SLIDE_CPT ? false : $supports;
});

/* ---------------------------------------------------------------- metabox */

/** Pola tekstowe slajdu: klucz meta => [etykieta, typ, podpowiedź]. */
function vts_slide_fields(): array
{
    return [
        '_vts_eyebrow' => ['Nadtytuł', 'text', 'Krótka etykieta nad hasłem, np. „Chip tuning · Łódź”.'],
        '_vts_lead'    => ['Lead', 'textarea', 'Jedno lub dwa zdania pod hasłem. Bez formatowania.'],
    ];
}

add_action('add_meta_boxes', function () {
    add_meta_box('vts_slide_text', 'Treść slajdu', 'vts_slide_metabox', VTS_SLIDE_CPT, 'normal', 'high');
    add_meta_box('vts_slide_img', 'Fabryczne zdjęcie', 'vts_slide_img_metabox', VTS_SLIDE_CPT, 'side', 'low');
    // Slajd nie ma własnego adresu, więc pole „Uproszczona nazwa" tylko myli.
    remove_meta_box('slugdiv', VTS_SLIDE_CPT, 'normal');
});

function vts_slide_metabox(WP_Post $post): void
{
    wp_nonce_field('vts_slide_save', 'vts_slide_nonce');
    ?>
    <p style="margin:0 0 14px;color:#50575e">Hasło slajdu wpisujesz w polu tytułu nad tym formularzem.
      Fragment ujęty w gwiazdki, np. <code>Odblokowywanie ECU *na miejscu*, od ręki.</code>,
      zostanie podświetlony na pomarańczowo. Pierwszy opublikowany slajd (najniższa kolejność) niesie nagłówek
      strony głównej.</p>
    <?php foreach (vts_slide_fields() as $key => [$label, $type, $hint]) :
        $val = (string) get_post_meta($post->ID, $key, true); ?>
      <p style="margin:0 0 12px">
        <label for="<?= esc_attr($key) ?>" style="display:block;font-weight:600;margin-bottom:4px"><?= esc_html($label) ?></label>
        <?php if ($type === 'textarea') : ?>
          <textarea id="<?= esc_attr($key) ?>" name="<?= esc_attr($key) ?>" rows="3" class="large-text"><?= esc_textarea($val) ?></textarea>
        <?php else : ?>
          <input type="text" id="<?= esc_attr($key) ?>" name="<?= esc_attr($key) ?>" class="large-text" value="<?= esc_attr($val) ?>">
        <?php endif; ?>
        <span class="description"><?= esc_html($hint) ?></span>
      </p>
    <?php endforeach;
}

function vts_slide_img_metabox(WP_Post $post): void
{
    $img = (string) get_post_meta($post->ID, '_vts_img', true);
    $has = has_post_thumbnail($post);
    ?>
    <p style="margin:0 0 8px;color:#50575e">
      <?php if ($has) : ?>
        Slajd używa zdjęcia z biblioteki mediów (pole „Zdjęcie slajdu” wyżej). Usuń je, żeby wrócić
        do fabrycznego kadru.
      <?php else : ?>
        Slajd używa fabrycznego zdjęcia serwisu<?= $img ? ' (<code>' . esc_html($img) . '.webp</code>)' : '' ?>.
        Żeby je podmienić, ustaw „Zdjęcie slajdu” wyżej: najlepiej poziomy kadr około 1800×1200 px,
        ciemniejszy po lewej, gdzie leży tekst.
      <?php endif; ?>
    </p>
    <?php if ($img === 'hero' && !$has) : ?>
      <p style="margin:0;color:#50575e">Na tym kadrze działa efekt reflektorów. Po podmianie zdjęcia efekt
        wyłącza się, bo jest dopasowany do tego konkretnego ujęcia.</p>
    <?php endif;
}

add_action('save_post_' . VTS_SLIDE_CPT, function ($post_id) {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }
    if (!isset($_POST['vts_slide_nonce']) || !wp_verify_nonce($_POST['vts_slide_nonce'], 'vts_slide_save')) {
        return;
    }
    if (!current_user_can('edit_post', $post_id)) {
        return;
    }
    foreach (vts_slide_fields() as $key => [, $type]) {
        $raw = (string) ($_POST[$key] ?? '');
        update_post_meta($post_id, $key, $type === 'textarea'
            ? sanitize_textarea_field($raw)
            : sanitize_text_field($raw));
    }
});

/* ---------------------------------------------------------- lista w kokpicie */

add_filter('manage_' . VTS_SLIDE_CPT . '_posts_columns', function ($cols) {
    $new = ['cb' => $cols['cb'] ?? '', 'vts_thumb' => 'Zdjęcie', 'title' => 'Hasło',
            'vts_eyebrow' => 'Nadtytuł', 'vts_order' => 'Kolejność', 'date' => $cols['date'] ?? 'Data'];
    return $new;
});

add_action('manage_' . VTS_SLIDE_CPT . '_posts_custom_column', function ($col, $id) {
    if ($col === 'vts_thumb') {
        if (has_post_thumbnail($id)) {
            echo get_the_post_thumbnail($id, [120, 80], ['style' => 'border-radius:4px']);
        } else {
            $img = (string) get_post_meta($id, '_vts_img', true);
            $url = function_exists('vts_hero_slide_img') ? vts_hero_slide_img($img ?: 'hero', 'sm') : '';
            echo $url
                ? '<img src="' . esc_url($url) . '" alt="" style="width:120px;height:80px;object-fit:cover;border-radius:4px;opacity:.75">'
                  . '<br><span style="color:#787c82">fabryczne</span>'
                : '<span style="color:#787c82">fabryczne</span>';
        }
    }
    if ($col === 'vts_eyebrow') {
        echo esc_html((string) get_post_meta($id, '_vts_eyebrow', true)) ?: '—';
    }
    if ($col === 'vts_order') {
        echo (int) get_post_field('menu_order', $id);
    }
}, 10, 2);

add_filter('manage_edit-' . VTS_SLIDE_CPT . '_sortable_columns', function ($cols) {
    $cols['vts_order'] = 'menu_order';
    return $cols;
});

/** Lista w kokpicie w tej samej kolejności, co karuzela. */
add_action('pre_get_posts', function (WP_Query $q) {
    if (!is_admin() || !$q->is_main_query() || $q->get('post_type') !== VTS_SLIDE_CPT) {
        return;
    }
    if (!$q->get('orderby')) {
        $q->set('orderby', ['menu_order' => 'ASC', 'ID' => 'ASC']);
    }
});

add_filter('post_updated_messages', function ($m) {
    $m[VTS_SLIDE_CPT] = array_merge($m['post'] ?? [], [
        1 => 'Slajd zapisany. <a href="' . esc_url(home_url('/')) . '">Zobacz stronę główną</a>.',
        6 => 'Slajd opublikowany, jest już w karuzeli. <a href="' . esc_url(home_url('/')) . '">Zobacz stronę główną</a>.',
    ]);
    return $m;
});

/* -------------------------------------------------------------------- dane */

/**
 * Hasło z gwiazdkami → znacznik akcentu. Reszta HTML jest wycinana: tytuł wpisuje
 * klient, a trafia do <h1> strony głównej.
 */
function vts_slide_title_html(string $raw): string
{
    $txt = esc_html(wp_strip_all_tags($raw));
    return preg_replace('/\*([^*]+)\*/u', '<span class="vts-hero__accent">$1</span>', $txt) ?? $txt;
}

/**
 * Opublikowane slajdy w kształcie, jakiego oczekuje vts_render_home_hero():
 * img_url, img_sm_url, eyebrow, title (HTML), lead, light.
 *
 * Warstwa świetlna jedzie wyłącznie ze slajdem na pozycji 0 i tylko na fabrycznym
 * hero.webp — reflektory mają stałe współrzędne pod to zdjęcie.
 */
function vts_hero_slides_from_posts(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $posts = get_posts([
        'post_type'        => VTS_SLIDE_CPT,
        'post_status'      => 'publish',
        'posts_per_page'   => 10,
        'orderby'          => ['menu_order' => 'ASC', 'ID' => 'ASC'],
        'no_found_rows'    => true,
        'suppress_filters' => false,
    ]);

    $out = [];
    foreach ($posts as $p) {
        $name = (string) get_post_meta($p->ID, '_vts_img', true) ?: 'hero';
        $own  = has_post_thumbnail($p);

        if ($own) {
            $img = (string) get_the_post_thumbnail_url($p, 'full');
            $sm  = (string) get_the_post_thumbnail_url($p, 'vts-hero-sm') ?: $img;
        } else {
            $img = vts_hero_slide_img($name);
            $sm  = vts_hero_slide_img($name, 'sm') ?: $img;
        }
        if ($img === '') {
            continue;   // brak pliku i brak załącznika — slajd nie ma na czym stać
        }

        $out[] = [
            'img_url'    => $img,
            'img_sm_url' => $sm,
            'eyebrow'    => (string) get_post_meta($p->ID, '_vts_eyebrow', true),
            'title'      => vts_slide_title_html($p->post_title),
            'lead'       => (string) get_post_meta($p->ID, '_vts_lead', true),
            'light'      => !$own && $name === 'hero' && !$out,
        ];
    }

    /* Losowa kolejność (prośba agencji): slajd z <h1> zostaje pierwszy, reszta się miesza.
     * Flaga bez UI: wp option update vts_hero_shuffle 1 */
    if (count($out) > 2 && get_option('vts_hero_shuffle')) {
        $first = array_shift($out);
        shuffle($out);
        array_unshift($out, $first);
    }

    return $cache = $out;
}
