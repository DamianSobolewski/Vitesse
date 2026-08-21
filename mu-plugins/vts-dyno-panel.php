<?php
/**
 * Plugin Name: Vitesse — panel wykresów
 * Description: Rola vts_dyno_operator i okrojony wp-admin do wprowadzania wyników z hamowni.
 *
 * Wymaganie „z pominięciem kokpitu WordPressa" jest wymaganiem UX-owym, nie
 * architektonicznym. Spełniamy je przez usunięcie zbędnych elementów, a nie przez
 * budowę własnego panelu na froncie — upload, walidacja MIME, nonce i uprawnienia
 * są w wp-adminie sprawdzone, a frontowy uploader to najczęstszy wektor włamania.
 */

if (!defined('ABSPATH')) {
    exit;
}

const VTS_ROLE = 'vts_dyno_operator';

/* ------------------------------------------------------------------ rola */

add_action('init', function () {
    if (get_role(VTS_ROLE) && get_option('vts_role_version') === '2') {
        return;
    }

    remove_role(VTS_ROLE);
    add_role(VTS_ROLE, 'Obsługa hamowni', [
        'read'                      => true,
        'upload_files'              => true,
        'edit_vts_dynos'            => true,
        'edit_published_vts_dynos'  => true,
        'publish_vts_dynos'         => true,
        'delete_vts_dynos'          => true,
        'delete_published_vts_dynos'=> true,
    ]);

    // administrator musi widzieć to samo, co obsługa
    $admin = get_role('administrator');
    foreach (['edit_vts_dynos','edit_others_vts_dynos','edit_published_vts_dynos','publish_vts_dynos',
              'delete_vts_dynos','delete_others_vts_dynos','delete_published_vts_dynos','read_private_vts_dynos'] as $cap) {
        $admin && $admin->add_cap($cap);
    }

    update_option('vts_role_version', '2');
});

function vts_is_operator(): bool
{
    $u = wp_get_current_user();
    return $u && in_array(VTS_ROLE, (array) $u->roles, true);
}

/* --------------------------------------------------- uproszczenie panelu */

add_action('admin_menu', function () {
    if (!vts_is_operator()) {
        return;
    }
    global $menu;
    $keep = ['edit.php?post_type=vts_dyno', 'upload.php'];
    foreach ((array) $menu as $item) {
        $slug = $item[2] ?? '';
        if ($slug && !in_array($slug, $keep, true)) {
            remove_menu_page($slug);
        }
    }
}, 999);

add_filter('show_admin_bar', function ($show) {
    return vts_is_operator() ? false : $show;
});

add_action('wp_dashboard_setup', function () {
    if (!vts_is_operator()) {
        return;
    }
    global $wp_meta_boxes;
    $wp_meta_boxes['dashboard'] = [];
});

/** Po zalogowaniu obsługa trafia prosto do listy wykresów, nie na pulpit. */
add_filter('login_redirect', function ($to, $req, $user) {
    if ($user instanceof WP_User && in_array(VTS_ROLE, (array) $user->roles, true)) {
        return admin_url('edit.php?post_type=vts_dyno');
    }
    return $to;
}, 10, 3);

/**
 * Zamiast surowego komunikatu „brak uprawnień" operator wraca do swojego panelu.
 *
 * Hak musi być `admin_page_access_denied`, a nie `admin_init`: WordPress ładuje
 * wp-admin/menu.php (razem z kontrolą dostępu i wp_die 403) PRZED wywołaniem
 * admin_init, więc warunek na admin_init nigdy by się nie wykonał dla stron,
 * do których operator nie ma dostępu.
 *
 * To warstwa wygody — dostępu i tak pilnują uprawnienia roli.
 */
add_action('admin_page_access_denied', function () {
    if (!vts_is_operator()) {
        return;
    }
    wp_safe_redirect(admin_url('edit.php?post_type=vts_dyno'));
    exit;
});

/** Ekrany dostępne dla roli, ale dotyczące innego typu treści. */
add_action('admin_init', function () {
    if (!vts_is_operator() || wp_doing_ajax()) {
        return;
    }

    $path = wp_parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
    if (!in_array(basename($path), ['edit.php', 'post-new.php'], true)) {
        return;
    }

    if (sanitize_key($_GET['post_type'] ?? 'post') !== 'vts_dyno') {
        wp_safe_redirect(admin_url('edit.php?post_type=vts_dyno'));
        exit;
    }
});

/**
 * Wykresy edytujemy w edytorze klasycznym, nie blokowym.
 *
 * W edytorze blokowym formularz pomiaru ląduje w zwiniętym panelu „Metaboksy"
 * na dole ekranu, a obsługa dostaje kreator bloków, którego nie potrzebuje.
 * W klasycznym pola są od razu widoczne pod polem tytułu.
 */
add_filter('use_block_editor_for_post_type', function ($use, $type) {
    return $type === 'vts_dyno' ? false : $use;
}, 10, 2);

/** Elementor nie ma tu czego edytować — to formularz danych, nie strona. */
add_filter('elementor/utils/is_post_type_support', function ($supports) {
    return get_post_type() === 'vts_dyno' ? false : $supports;
});

add_action('admin_enqueue_scripts', function () {
    if (vts_is_operator()) {
        wp_enqueue_style('vts-admin-panel', VTS_ASSETS_URL . '/css/admin-panel.css',
            [], vts_asset_ver('css/admin-panel.css'));
    }
});

add_filter('admin_footer_text', function ($t) {
    return vts_is_operator() ? 'Panel wykresów Vitesse' : $t;
});

/**
 * Komunikaty administracyjne dla operatora są szumem — nie ma uprawnień, żeby
 * cokolwiek z nimi zrobić (aktualizacje, reklamy wtyczek, prośby o opinię).
 * Zostawiamy tylko nasze własne, bo dotyczą jego pracy.
 */
add_action('admin_head', function () {
    if (!vts_is_operator()) {
        return;
    }
    remove_all_actions('admin_notices');
    remove_all_actions('all_admin_notices');
    remove_action('admin_notices', 'update_nag', 3);

    // nasz komunikat o braku zgody musi przetrwać czyszczenie
    add_action('admin_notices', 'vts_consent_notice');
}, 1);

function vts_consent_notice(): void
{
    global $post;
    if ($post && $post->post_type === 'vts_dyno' && $post->post_status === 'draft'
        && !get_post_meta($post->ID, '_vts_consent', true)) {
        echo '<div class="notice notice-warning"><p>Wpis pozostaje szkicem, dopóki nie zaznaczysz
              zgody właściciela pojazdu na publikację.</p></div>';
    }
}

/** Pasek górny bez skrótów do rzeczy, których operator i tak nie otworzy. */
add_action('admin_bar_menu', function ($bar) {
    if (!vts_is_operator()) {
        return;
    }
    foreach (['new-content', 'wp-logo', 'comments', 'updates'] as $node) {
        $bar->remove_node($node);
    }
}, 999);

/* ----------------------------------------------------------- formularz */

add_action('add_meta_boxes', function () {
    add_meta_box('vts_dyno_data', 'Wyniki pomiaru', 'vts_dyno_metabox', 'vts_dyno', 'normal', 'high');
});

function vts_dyno_metabox(WP_Post $post): void
{
    wp_nonce_field('vts_dyno_save', 'vts_dyno_nonce');
    $m = vts_dyno_meta($post->ID);
    ?>
    <div class="vts-mb">
      <?php foreach (vts_dyno_fields() as $key => [$label, $type]) : ?>
        <p class="vts-mb__f">
          <label for="<?= esc_attr($key) ?>"><?= esc_html($label) ?></label>
          <input type="<?= esc_attr($type) ?>" id="<?= esc_attr($key) ?>" name="<?= esc_attr($key) ?>"
                 value="<?= esc_attr($m[$key]) ?>">
        </p>
      <?php endforeach; ?>

      <p class="vts-mb__consent">
        <label>
          <input type="checkbox" name="_vts_consent" value="1" <?php checked($m['consent']); ?>>
          <strong>Właściciel pojazdu zgodził się na publikację</strong> wykresu i zdjęć.
        </label>
        <span>Bez zaznaczenia wpis zostanie zapisany jako szkic. Widoczna tablica rejestracyjna
        sprawia, że pojazd i właściciel są możliwi do zidentyfikowania.</span>
      </p>
    </div>
    <?php
}

add_action('save_post_vts_dyno', function ($post_id) {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }
    if (!isset($_POST['vts_dyno_nonce']) || !wp_verify_nonce($_POST['vts_dyno_nonce'], 'vts_dyno_save')) {
        return;
    }
    if (!current_user_can('edit_post', $post_id)) {
        return;
    }

    foreach (array_keys(vts_dyno_fields()) as $key) {
        $val = sanitize_text_field($_POST[$key] ?? '');
        // sanity: moc i moment w rozsądnym zakresie
        if (str_contains($key, '_hp') || str_contains($key, '_nm')) {
            $n = (int) $val;
            $val = ($n > 0 && $n < 5000) ? $n : '';
        }
        update_post_meta($post_id, $key, $val);
    }

    update_post_meta($post_id, '_vts_consent', isset($_POST['_vts_consent']) ? 1 : 0);
}, 10, 1);

/**
 * Bez zgody właściciela wpis nie może być publiczny — egzekwowane po stronie
 * serwera, nie tylko w interfejsie.
 */
add_filter('wp_insert_post_data', function ($data, $postarr) {
    if (($data['post_type'] ?? '') !== 'vts_dyno' || ($data['post_status'] ?? '') !== 'publish') {
        return $data;
    }
    $consent = isset($_POST['_vts_consent'])
        ? (bool) $_POST['_vts_consent']
        : (bool) get_post_meta($postarr['ID'] ?? 0, '_vts_consent', true);

    if (!$consent) {
        $data['post_status'] = 'draft';
    }
    return $data;
}, 10, 2);

/* --------------------------------------------------- bezpieczeństwo plików */

/** Obsługa wgrywa zdjęcia i wykresy — nic więcej nie jest jej potrzebne. */
add_filter('upload_mimes', function ($mimes) {
    if (!vts_is_operator()) {
        return $mimes;
    }
    return [
        'jpg|jpeg|jpe' => 'image/jpeg',
        'png'          => 'image/png',
        'webp'         => 'image/webp',
    ];
});

/**
 * Zdjęcie auta klienta potrafi nieść współrzędne miejsca, w którym stało.
 * Ponowny zapis przez bibliotekę GD usuwa EXIF, w tym GPS.
 */
add_filter('wp_handle_upload', function ($upload) {
    if (empty($upload['file']) || !function_exists('imagecreatefromjpeg')) {
        return $upload;
    }
    if (($upload['type'] ?? '') !== 'image/jpeg') {
        return $upload;
    }

    $img = @imagecreatefromjpeg($upload['file']);
    if ($img) {
        @imagejpeg($img, $upload['file'], 90);
        imagedestroy($img);
    }
    return $upload;
});

/* --------------------------------------------------------- kolumny listy */

add_filter('manage_vts_dyno_posts_columns', function ($cols) {
    $new = [];
    foreach ($cols as $k => $v) {
        $new[$k] = $v;
        if ($k === 'title') {
            $new['vts_result'] = 'Wynik';
            $new['vts_consent'] = 'Zgoda';
        }
    }
    return $new;
});

add_action('manage_vts_dyno_posts_custom_column', function ($col, $id) {
    $m = vts_dyno_meta($id);
    if ($col === 'vts_result') {
        echo $m['_vts_stock_hp'] && $m['_vts_tuned_hp']
            ? esc_html($m['_vts_stock_hp'] . ' → ' . $m['_vts_tuned_hp'] . ' KM')
            : '—';
    }
    if ($col === 'vts_consent') {
        echo $m['consent'] ? '✓' : '<span style="color:#b32d2e">brak</span>';
    }
}, 10, 2);
