<?php
/**
 * Plugin Name: Vitesse — szkielet serwisu
 * Description: Zasoby, nagłówek z rozwijaną nawigacją, stopka 4-kolumnowa, pływające CTA.
 *
 * Zastępuje Elementor Pro Theme Builder — nagłówek i stopka są kodem, nie układem
 * klikanym w edytorze. Dzięki temu wersjonują się w gicie i nikt ich przypadkiem nie zepsuje.
 */

if (!defined('ABSPATH')) {
    exit;
}

/* ---------------------------------------------------------------- zasoby */

add_action('wp_enqueue_scripts', function () {
    $deps = [];

    foreach (['fonts', 'tokens', 'theme', 'power-search', 'fleet-calc', 'dyno'] as $handle) {
        $rel = "css/{$handle}.css";
        if (!file_exists(VTS_ASSETS_DIR . '/' . $rel)) {
            continue;
        }
        wp_enqueue_style("vts-{$handle}", VTS_ASSETS_URL . '/' . $rel, $deps, vts_asset_ver($rel));
        $deps[] = "vts-{$handle}";
    }

    wp_enqueue_script('vts-site', VTS_ASSETS_URL . '/js/site.js', [], vts_asset_ver('js/site.js'), true);

    foreach (['power-search', 'fleet-calc', 'dyno'] as $handle) {
        $rel = "js/{$handle}.js";
        if (file_exists(VTS_ASSETS_DIR . '/' . $rel)) {
            wp_enqueue_script("vts-{$handle}", VTS_ASSETS_URL . '/' . $rel, [], vts_asset_ver($rel), true);
        }
    }
}, 20);

/** hello-elementor dokłada własne style, które kolidują z naszym systemem. */
add_action('wp_enqueue_scripts', function () {
    wp_dequeue_style('hello-elementor-theme-style');
    wp_dequeue_style('hello-elementor-header-footer');
}, 30);

/**
 * Wyłączamy nagłówek, stopkę i tytuł strony z hello-elementor.
 * Nagłówek i stopkę rysujemy sami (niżej), a tytuł strony wchodzi razem
 * z okruszkami w vts-content.php — inaczej pojawiałby się dwa razy.
 */
add_filter('hello_elementor_header_footer', '__return_false');
add_filter('hello_elementor_page_title', '__return_false');

/* ------------------------------------------------------------- nagłówek */

function vts_render_brand(): string
{
    return sprintf(
        '<a class="vts-brand" href="%s" rel="home"><span>V</span>ITESSE<small>V-TECH ŁÓDŹ</small></a>',
        esc_url(home_url('/'))
    );
}

add_action('wp_body_open', 'vts_render_header');

function vts_render_header(): void
{
    $c = vts_company();
    ?>
    <div class="vts-topbar">
      <div class="vts-wrap">
        <span class="vts-topbar__where"><?= esc_html("{$c['street']}, {$c['postal_code']} {$c['city']}") ?> ·
          <?= esc_html($c['hours']['weekdays']['label']) ?>
          <?= esc_html($c['hours']['weekdays']['open'] . '–' . $c['hours']['weekdays']['close']) ?></span>
        <span class="vts-topbar__phones">
          <?php foreach (['tuning', 'fleet'] as $k) :
              $p = $c['phones'][$k]; ?>
            <span><?= esc_html($p['label']) ?>
              <a href="<?= esc_attr(vts_phone_href($p['number'])) ?>"><?= esc_html($p['number']) ?></a></span>
          <?php endforeach; ?>
        </span>
      </div>
    </div>

    <header class="vts-header">
      <div class="vts-wrap">
        <?= vts_render_brand() ?>
        <button class="vts-burger" aria-expanded="false" aria-controls="vts-nav">MENU</button>
        <nav class="vts-nav" id="vts-nav" aria-label="Nawigacja główna">
          <?php
          wp_nav_menu([
              'theme_location' => 'vts_main',
              'container'      => false,
              'depth'          => 2,
              'fallback_cb'    => '__return_empty_string',
          ]);
          ?>
        </nav>
        <a class="vts-btn vts-btn--primary vts-header__cta"
           href="<?= esc_url(home_url('/kontakt/')) ?>">Umów pomiar</a>
      </div>
    </header>
    <?php
}

add_action('after_setup_theme', function () {
    register_nav_menus([
        'vts_main'   => 'Nawigacja główna',
        'vts_footer' => 'Stopka — usługi',
        'vts_client' => 'Stopka — strefa klienta',
    ]);
    add_theme_support('post-thumbnails');
    add_theme_support('title-tag');
});

/* --------------------------------------------------------------- stopka */

add_action('wp_footer', 'vts_render_footer', 5);

function vts_render_footer(): void
{
    $c = vts_company();
    ?>
    <footer class="vts-footer">
      <div class="vts-wrap">
        <div class="vts-footer__cols">

          <div>
            <?= vts_render_brand() ?>
            <div class="vts-footer__meta" style="margin-top:18px">
              <span>Chip tuning, modyfikacje ECU i pomiary na hamowni.<br>
                Autoryzacja V-tech od 2008 roku.</span>
              <span><?= esc_html($c['street']) ?><br>
                <?= esc_html($c['postal_code'] . ' ' . $c['city']) ?></span>
              <?php if ($c['nip']) : ?><span>NIP <?= esc_html($c['nip']) ?></span><?php endif; ?>
              <?php if ($c['regon']) : ?><span>REGON <?= esc_html($c['regon']) ?></span><?php endif; ?>
            </div>
          </div>

          <div>
            <h4>Usługi</h4>
            <?php wp_nav_menu([
                'theme_location' => 'vts_footer',
                'container'      => false,
                'depth'          => 1,
                'fallback_cb'    => '__return_empty_string',
            ]); ?>
          </div>

          <div>
            <h4>Strefa klienta</h4>
            <?php wp_nav_menu([
                'theme_location' => 'vts_client',
                'container'      => false,
                'depth'          => 1,
                'fallback_cb'    => '__return_empty_string',
            ]); ?>
            <ul style="margin-top:9px">
              <li class="vts-footer__panel">
                <a rel="nofollow noopener"
                   href="<?= esc_url(wp_login_url(admin_url('edit.php?post_type=vts_dyno'))) ?>">Panel wykresów</a>
              </li>
            </ul>
          </div>

          <div>
            <h4>Kontakt</h4>
            <div class="vts-footer__meta">
              <?php foreach ($c['phones'] as $p) : ?>
                <span><?= esc_html($p['label']) ?>:
                  <a href="<?= esc_attr(vts_phone_href($p['number'])) ?>"><?= esc_html($p['number']) ?></a></span>
              <?php endforeach; ?>
              <span><a href="mailto:<?= esc_attr($c['email']) ?>"><?= esc_html($c['email']) ?></a></span>
              <span style="margin-top:6px">
                <?= esc_html($c['hours']['weekdays']['label']) ?>
                <?= esc_html($c['hours']['weekdays']['open'] . '–' . $c['hours']['weekdays']['close']) ?><br>
                <?= esc_html($c['hours']['saturday']['label']) ?>
                <?= esc_html($c['hours']['saturday']['open'] . '–' . $c['hours']['saturday']['close']) ?>
              </span>
            </div>
            <a class="vts-btn vts-btn--primary" style="margin-top:16px"
               href="<?= esc_url(home_url('/kontakt/')) ?>">Umów pomiar</a>
          </div>

        </div>

        <div class="vts-footer__bottom">
          <span>© <?= esc_html(date('Y')) ?> <?= esc_html($c['legal_name'] ?: $c['name']) ?></span>
          <span>Ceny zawierają pomiar na hamowni przed i po modyfikacji.</span>
        </div>
      </div>
    </footer>

    <div class="vts-fab">
      <a href="<?= esc_attr(vts_phone_href($c['phones']['tuning']['number'])) ?>">Zadzwoń</a>
      <a class="is-accent" href="<?= esc_url(home_url('/kontakt/')) ?>">Umów pomiar</a>
    </div>
    <?php
}

/* ----------------------------------------------------------- drobiazgi */

/** hello-elementor nie ma własnego układu treści — dajemy stronom kontener. */
add_filter('body_class', function ($classes) {
    $classes[] = 'vts';
    return $classes;
});

add_action('wp_head', function () {
    echo '<meta name="theme-color" content="#0F1116">' . "\n";
});
