<?php
/**
 * Plugin Name: Vitesse — treść i dane strukturalne
 * Description: Hero strony głównej, FAQ z jednego źródła, dane kontaktowe, okruszki, JSON-LD.
 */

if (!defined('ABSPATH')) {
    exit;
}

/* ------------------------------------------------- surowy HTML w treści stron
 *
 * wpautop służy do zamiany tekstu pisanego w edytorze na akapity. Treść naszych
 * stron to gotowy HTML z jawnymi znacznikami, więc filtr nie ma tu nic do roboty
 * — a szkodzi: wstawia `</p>` w środek `<a class="vts-card">`, przez co parser
 * przeglądarki klonuje kotwicę wokół każdego bloku i powstają puste, ale klikalne
 * kafelki. Flagę ustawia importer, więc obejmuje dokładnie strony z content/pages/.
 * Wpisy bloga, pisane w edytorze, zachowują domyślne zachowanie.
 */
add_action('wp', function () {
    if (!is_singular()) {
        return;
    }
    if (get_post_meta(get_queried_object_id(), '_vts_raw_html', true)) {
        remove_filter('the_content', 'wpautop');
        remove_filter('the_content', 'shortcode_unautop');
    }
});

/* ------------------------------------------------------------------ hero */

/**
 * Hero renderujemy przed treścią strony, a nie w niej — dzięki temu obrazek tła
 * i wyszukiwarka są jednym elementem szablonu, którego nikt nie skasuje przy edycji.
 */
add_filter('the_content', function ($content) {
    if (!in_the_loop() || !is_main_query() || is_admin()) {
        return $content;
    }

    if (is_front_page()) {
        return vts_capture('vts_render_home_hero') . $content;
    }

    if (is_page()) {
        return vts_capture('vts_render_page_header') . $content;
    }

    return $content;
    // Priorytet 99, nie 5: wpautop działa na 10 i zamienia puste linie w znaczniki
    // akapitów — w środku wbudowanego SVG rozbijało to grafikę na kawałki.
    // Doklejając hero po nim, przepuszczamy przez wpautop tylko treść strony.
}, 99);

/** Renderuje funkcję echo-ującą do stringa. */
function vts_capture(callable $fn): string
{
    ob_start();
    $fn();
    return (string) ob_get_clean();
}

/**
 * @param string $size '' dla pełnego kadru, 'sm' dla wariantu na wąskie ekrany
 */
function vts_hero_image_url(string $size = ''): string
{
    $custom = get_option('vts_hero_image');
    if ($custom && $size === '') {
        return $custom;
    }

    $file  = $size === 'sm' ? 'hero-sm.webp' : 'hero.webp';
    $local = VTS_ASSETS_DIR . '/img/' . $file;

    return file_exists($local) ? VTS_ASSETS_URL . '/img/' . $file : '';
}

/**
 * Warstwy świetlne hero — „zapłon".
 *
 * Zdjęcie pokazuje auto na wprost w ciemnym garażu. Reflektory na fotografii są
 * zapalone, więc nie da się ich zgasić wprost — zamiast tego kładziemy na nie
 * zasłonę w kolorze tła i ZDEJMUJEMY ją w trakcie sekwencji. Otoczenie jest
 * niemal czarne, więc szew jest niewidoczny.
 *
 * Geometria w układzie 1800×1200, czyli proporcje pliku hero.webp.
 * Reflektory: lewy x 35,3%, prawy x 78,7%, oba y 41%.
 */
function vts_render_hero_light(): void
{
    // Pozycje lamp w jednostkach viewBox, przeliczone po złożeniu kadru
    // (auto dosunięte do prawej, lewa część płótna to czerń strony).
    $lamps = [['x' => 1069, 'y' => 612], ['x' => 1516, 'y' => 604]];
    ?>
    <div class="vts-hero__veil" aria-hidden="true">
      <svg viewBox="0 0 1800 1240" preserveAspectRatio="xMidYMid slice" focusable="false">
        <defs>
          <filter id="vts-veil-blur" x="-40%" y="-40%" width="180%" height="180%">
            <feGaussianBlur stdDeviation="18"/>
          </filter>
        </defs>
        <!-- Kolor zasłony dobrany z samego zdjęcia: otoczenie lamp to praktycznie
           czysta czerń, więc kolor tła strony (#0F1116) zostawiał widoczne szare plamy. -->
        <g filter="url(#vts-veil-blur)" fill="#020303">
          <?php foreach ($lamps as $l) : ?>
            <ellipse cx="<?= $l['x'] ?>" cy="<?= $l['y'] + 40 ?>" rx="165" ry="125"/>
          <?php endforeach; ?>
        </g>
      </svg>
    </div>

    <div class="vts-hero__light" aria-hidden="true">
      <svg class="vts-hero__beams" viewBox="0 0 1800 1240"
           preserveAspectRatio="xMidYMid slice" focusable="false">
        <defs>
          <radialGradient id="vts-lamp-grad">
            <stop offset="0"   stop-color="#FFFFFF" stop-opacity=".95"/>
            <stop offset=".22" stop-color="#EAF2FF" stop-opacity=".55"/>
            <stop offset=".6"  stop-color="#BFD6FF" stop-opacity=".16"/>
            <stop offset="1"   stop-color="#BFD6FF" stop-opacity="0"/>
          </radialGradient>
          <radialGradient id="vts-floor-grad">
            <stop offset="0"   stop-color="#DCE8FF" stop-opacity=".30"/>
            <stop offset=".6"  stop-color="#BFD6FF" stop-opacity=".10"/>
            <stop offset="1"   stop-color="#BFD6FF" stop-opacity="0"/>
          </radialGradient>
        </defs>

        <?php foreach ($lamps as $l) : ?>
          <ellipse cx="<?= $l['x'] ?>" cy="<?= $l['y'] ?>" rx="140" ry="92" fill="url(#vts-lamp-grad)"/>
        <?php endforeach; ?>

        <!-- odbicie na posadzce przed autem -->
        <ellipse cx="1290" cy="880" rx="420" ry="110" fill="url(#vts-floor-grad)"/>
      </svg>

      <span class="vts-hero__glow"></span>
    </div>
    <?php
}

function vts_render_home_hero(): void
{
    $img = vts_hero_image_url();
    ?>
    <section class="vts-hero">
      <?php if ($img) : ?>
        <div class="vts-hero__bg"
             style="--vts-hero-img:url('<?= esc_url($img) ?>');--vts-hero-img-sm:url('<?= esc_url(vts_hero_image_url('sm') ?: $img) ?>')"></div>
        <?php vts_render_hero_light(); ?>
      <?php endif; ?>
      <div class="vts-hero__scrim"></div>
      <div class="vts-wrap">
        <div class="vts-hero__in">
          <p class="vts-eyebrow">Hamownia 4×4 · Łódź</p>
          <h1>Moc z pomiaru,<br>nie z <span style="color:var(--vts-accent)">folderu</span>.</h1>
          <p class="vts-lead">Chip tuning i modyfikacje sterowników. Każdą zmianę potwierdzamy
            pomiarem na hamowni — przed i po, na tym samym stanowisku.</p>

          <?= do_shortcode('[vts_power_search]') ?>

          <div class="vts-stats">
            <div class="vts-stat"><b>2008</b><span>autoryzacja V-tech</span></div>
            <div class="vts-stat"><b>4×4</b><span>hamownia i stanowisko moto</span></div>
            <div class="vts-stat"><b>W cenie</b><span>pomiar przed i po</span></div>
          </div>
        </div>
      </div>
    </section>
    <?php
}

function vts_render_page_header(): void
{
    ?>
    <section class="vts-hero" style="padding-block:var(--vts-gap-l) var(--vts-gap-m)">
      <div class="vts-hero__scrim"></div>
      <div class="vts-wrap">
        <?= vts_breadcrumbs() ?>
        <h1 style="max-width:20ch"><?= esc_html(get_the_title()) ?></h1>
      </div>
    </section>
    <?php
}

/* -------------------------------------------------------------- okruszki */

function vts_breadcrumbs(): string
{
    if (is_front_page()) {
        return '';
    }

    $crumbs = ['<a href="' . esc_url(home_url('/')) . '">Start</a>'];

    if (is_singular('page')) {
        foreach (array_reverse(get_post_ancestors(get_the_ID())) as $anc) {
            $crumbs[] = '<a href="' . esc_url(get_permalink($anc)) . '">'
                      . esc_html(get_the_title($anc)) . '</a>';
        }
    }

    $crumbs[] = '<span style="color:var(--vts-text);opacity:1;margin:0">'
              . esc_html(get_the_title()) . '</span>';

    return '<nav class="vts-crumbs" aria-label="Ścieżka">'
         . implode('<span>/</span>', $crumbs) . '</nav>';
}

/* ------------------------------------------------------------------- FAQ */

/** Jedno źródło pytań — używane na stronie głównej, w FAQ i w JSON-LD. */
function vts_faq_items(): array
{
    return [
        ['Czy chip tuning szkodzi silnikowi?',
         'Sam w sobie nie. Szkodzi modyfikacja wykonana bez pomiaru i bez znajomości konkretnej jednostki —
          na przykład plik pobrany z sieci i wgrany na chybił trafił. Pracujemy w granicach, które silnik
          i osprzęt realnie wytrzymują, a wynik sprawdzamy na hamowni. Jeśli pojazd jest w kiepskim stanie
          technicznym, widać to na pierwszym pomiarze i wtedy przerywamy pracę.'],
        ['Czy modyfikacja jest widoczna w serwisie?',
         'Tak, producenci mają narzędzia do wykrywania zmian w oprogramowaniu. Nie sprzedajemy obietnicy
          niewykrywalności. Jeśli to dla Was problem, lepszym rozwiązaniem jest PowerBox, który zdejmuje się
          przed wizytą w serwisie.'],
        ['Ile to trwa?',
         'Zwykle jeden dzień roboczy — łącznie z pomiarem przed i po. Sterowniki zamknięte otwieramy na miejscu,
          więc nie doliczajcie tygodnia na wysyłkę do firmy zewnętrznej.'],
        ['O ile spadnie spalanie?',
         'Przy niezmienionym stylu jazdy zwykle spada, bo rzadziej wchodzicie w wysokie obroty.
          Skala zależy od pojazdu i tras — przy flotach jeżdżących w trasie mówimy o kilku procentach.
          Konkretną wartość dla swojej floty policzycie w kalkulatorze.'],
        ['Czy da się wrócić do stanu fabrycznego?',
         'Tak. Kopię oryginalnego oprogramowania robimy przed każdą pracą, zostaje u nas i u Was.
          Przywrócenie to jedna wizyta.'],
        ['Czy robicie naprawy mechaniczne ciężarówek?',
         'Nie. Ten zakres zamknęliśmy — zajmujemy się elektroniką i pomiarami. Chip tuning ciężarówek
          i autobusów pozostaje w ofercie.'],
        ['Jak daleko jedziecie do klienta?',
         'Przy maszynach rolniczych i budowlanych zdarza się, że przyjeżdżamy na miejsce.
          Auta i pojazdy ciężarowe obsługujemy u nas, bo pomiar wymaga hamowni.'],
    ];
}

add_shortcode('vts_faq', function () {
    $out = '<div class="vts-faq">';
    foreach (vts_faq_items() as [$q, $a]) {
        $out .= '<details><summary>' . esc_html($q) . '</summary><div>'
              . esc_html(preg_replace('/\s+/', ' ', trim($a))) . '</div></details>';
    }
    return $out . '</div>';
});

/* ------------------------------------------------------------- kontakt */

add_shortcode('vts_contact_details', function () {
    $c = vts_company();
    ob_start(); ?>
    <div class="vts-card">
      <h3>Warsztat</h3>
      <p style="color:var(--vts-text)"><?= esc_html($c['street']) ?><br>
        <?= esc_html($c['postal_code'] . ' ' . $c['city']) ?></p>
      <h3 style="margin-top:var(--vts-gap-s)">Telefony</h3>
      <p>
        <?php foreach ($c['phones'] as $p) : ?>
          <?= esc_html($p['label']) ?>:
          <a href="<?= esc_attr(vts_phone_href($p['number'])) ?>"
             style="color:var(--vts-accent);text-decoration:none;font-weight:600"><?= esc_html($p['number']) ?></a><br>
        <?php endforeach; ?>
      </p>
      <h3 style="margin-top:var(--vts-gap-s)">E-mail</h3>
      <p><a href="mailto:<?= esc_attr($c['email']) ?>"
            style="color:var(--vts-accent);text-decoration:none"><?= esc_html($c['email']) ?></a></p>
      <h3 style="margin-top:var(--vts-gap-s)">Godziny</h3>
      <p><?= esc_html($c['hours']['weekdays']['label']) ?>
        <?= esc_html($c['hours']['weekdays']['open'] . '–' . $c['hours']['weekdays']['close']) ?><br>
        <?= esc_html($c['hours']['saturday']['label']) ?>
        <?= esc_html($c['hours']['saturday']['open'] . '–' . $c['hours']['saturday']['close']) ?></p>
    </div>
    <?php
    return ob_get_clean();
});

add_shortcode('vts_contact_form', function () {
    $form = get_page_by_path('kontakt', OBJECT, 'wpcf7_contact_form');
    if ($form) {
        return do_shortcode('[contact-form-7 id="' . $form->ID . '"]');
    }
    return '<p style="color:var(--vts-muted)">Formularz nie został jeszcze zaimportowany —
            uruchom <code>./bin/import.sh</code>.</p>';
});

/* ------------------------------------------------------------- JSON-LD */

add_action('wp_head', function () {
    if (!is_front_page()) {
        return;
    }

    $c = vts_company();
    $data = [
        '@context' => 'https://schema.org',
        '@type'    => 'AutoRepair',
        'name'     => $c['name'],
        'url'      => home_url('/'),
        'email'    => $c['email'],
        'telephone'=> $c['phones']['tuning']['number'],
        'address'  => [
            '@type'           => 'PostalAddress',
            'streetAddress'   => $c['street'],
            'postalCode'      => $c['postal_code'],
            'addressLocality' => $c['city'],
            'addressCountry'  => $c['country'],
        ],
        'openingHoursSpecification' => [
            ['@type' => 'OpeningHoursSpecification',
             'dayOfWeek' => ['Monday','Tuesday','Wednesday','Thursday','Friday'],
             'opens' => $c['hours']['weekdays']['open'], 'closes' => $c['hours']['weekdays']['close']],
            ['@type' => 'OpeningHoursSpecification', 'dayOfWeek' => ['Saturday'],
             'opens' => $c['hours']['saturday']['open'], 'closes' => $c['hours']['saturday']['close']],
        ],
    ];
    if ($c['nip']) {
        $data['vatID'] = $c['nip'];
    }

    $faq = ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => []];
    foreach (vts_faq_items() as [$q, $a]) {
        $faq['mainEntity'][] = [
            '@type' => 'Question', 'name' => $q,
            'acceptedAnswer' => ['@type' => 'Answer', 'text' => preg_replace('/\s+/', ' ', trim($a))],
        ];
    }

    foreach ([$data, $faq] as $block) {
        echo '<script type="application/ld+json">'
           . wp_json_encode($block, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
           . '</script>' . "\n";
    }
});
