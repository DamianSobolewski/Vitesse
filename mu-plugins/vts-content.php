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

    // Wpisy dostają ten sam nagłówek co strony — okruszki i H1 z tytułu. Dzięki
    // temu żaden wpis nie musi sam dostarczać nagłówka, a te dwa typy treści
    // wyglądają spójnie.
    if (is_page() || is_singular('post')) {
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

    // Tylne światła są w kadrze pośrednio: nad dachem widać czerwoną łunę odbitą
    // od ściany. Środek i półosie wyznaczone z samego zdjęcia — piksele, w których
    // czerwień wyraźnie przewyższa pozostałe kanały (środek ciężkości 1298/451).
    $tail = ['x' => 1298, 'y' => 451, 'rx' => 172, 'ry' => 48];
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
            <ellipse cx="<?= $l['x'] ?>" cy="<?= $l['y'] + 40 ?>" rx="180" ry="135"/>
          <?php endforeach; ?>
          <!-- bez tej elipsy tylna łuna paliłaby się przez całą sekwencję, gdy
               przednie lampy są jeszcze zgaszone — i mrugnięcie by nie zagrało -->
          <ellipse cx="<?= $tail['x'] ?>" cy="<?= $tail['y'] ?>"
                   rx="<?= $tail['rx'] + 40 ?>" ry="<?= $tail['ry'] + 34 ?>"/>
        </g>
      </svg>
    </div>

    <div class="vts-hero__light" aria-hidden="true">
      <svg class="vts-hero__beams" viewBox="0 0 1800 1240"
           preserveAspectRatio="xMidYMid slice" focusable="false">
        <defs>
          <radialGradient id="vts-lamp-grad">
            <stop offset="0"   stop-color="#FFFFFF" stop-opacity="1"/>
            <stop offset=".14" stop-color="#FFFFFF" stop-opacity=".88"/>
            <stop offset=".32" stop-color="#EAF2FF" stop-opacity=".50"/>
            <stop offset=".64" stop-color="#BFD6FF" stop-opacity=".19"/>
            <stop offset="1"   stop-color="#BFD6FF" stop-opacity="0"/>
          </radialGradient>
          <!-- rozżarzony rdzeń żarówki — bez niego lampa jest plamą, a nie źródłem -->
          <radialGradient id="vts-core-grad">
            <stop offset="0"   stop-color="#FFFFFF" stop-opacity="1"/>
            <stop offset=".55" stop-color="#F4F9FF" stop-opacity=".72"/>
            <stop offset="1"   stop-color="#EAF2FF" stop-opacity="0"/>
          </radialGradient>
          <radialGradient id="vts-tail-grad">
            <stop offset="0"   stop-color="#FF5140" stop-opacity=".70"/>
            <stop offset=".34" stop-color="#E62A18" stop-opacity=".38"/>
            <stop offset=".72" stop-color="#B21A10" stop-opacity=".13"/>
            <stop offset="1"   stop-color="#B21A10" stop-opacity="0"/>
          </radialGradient>
          <radialGradient id="vts-floor-grad">
            <stop offset="0"   stop-color="#DCE8FF" stop-opacity=".38"/>
            <stop offset=".6"  stop-color="#BFD6FF" stop-opacity=".13"/>
            <stop offset="1"   stop-color="#BFD6FF" stop-opacity="0"/>
          </radialGradient>
        </defs>

        <!-- Tylne światła w tej samej warstwie co przednie: animacja zapłonu jest
             na całym SVG, więc łuna mruga co do klatki tak samo jak reflektory. -->
        <ellipse cx="<?= $tail['x'] ?>" cy="<?= $tail['y'] ?>"
                 rx="<?= $tail['rx'] + 58 ?>" ry="<?= $tail['ry'] + 40 ?>"
                 fill="url(#vts-tail-grad)"/>

        <?php foreach ($lamps as $l) : ?>
          <ellipse cx="<?= $l['x'] ?>" cy="<?= $l['y'] ?>" rx="176" ry="116" fill="url(#vts-lamp-grad)"/>
          <ellipse cx="<?= $l['x'] ?>" cy="<?= $l['y'] ?>" rx="54" ry="36" fill="url(#vts-core-grad)"/>
        <?php endforeach; ?>

        <!-- odbicie na posadzce przed autem -->
        <ellipse cx="1290" cy="880" rx="440" ry="118" fill="url(#vts-floor-grad)"/>
      </svg>

      <span class="vts-hero__glow"></span>

      <?php /* Światła awaryjne — zapala je przycisk na konsoli. Osobna warstwa,
               więc sekwencja zapłonu jej nie dotyczy: awaryjne działają
               niezależnie od reflektorów, tak jak w aucie. */ ?>
      <svg class="vts-hero__hazard" viewBox="0 0 1800 1240"
           preserveAspectRatio="xMidYMid slice" focusable="false" aria-hidden="true">
        <defs>
          <radialGradient id="vts-haz-grad">
            <stop offset="0"   stop-color="#FFB25A" stop-opacity=".95"/>
            <stop offset=".28" stop-color="#FF8A1E" stop-opacity=".60"/>
            <stop offset=".66" stop-color="#FF7A00" stop-opacity=".20"/>
            <stop offset="1"   stop-color="#FF7A00" stop-opacity="0"/>
          </radialGradient>
        </defs>
        <?php foreach ($lamps as $l) : ?>
          <ellipse cx="<?= $l['x'] ?>" cy="<?= $l['y'] ?>" rx="150" ry="100" fill="url(#vts-haz-grad)"/>
        <?php endforeach; ?>
        <ellipse cx="<?= $tail['x'] ?>" cy="<?= $tail['y'] ?>"
                 rx="<?= $tail['rx'] + 50 ?>" ry="<?= $tail['ry'] + 34 ?>"
                 fill="url(#vts-haz-grad)"/>
      </svg>
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

    // Wpis wisi pod blogiem — bez tego okruszki prowadziłyby prosto ze startu
    // do tekstu i nie dałoby się wrócić do listy.
    if (is_singular('post') && ($blog = (int) get_option('page_for_posts'))) {
        $crumbs[] = '<a href="' . esc_url(get_permalink($blog)) . '">'
                  . esc_html(get_the_title($blog)) . '</a>';
    }

    $crumbs[] = '<span style="color:var(--vts-text);opacity:1;margin:0">'
              . esc_html(get_the_title()) . '</span>';

    return '<nav class="vts-crumbs" aria-label="Ścieżka">'
         . implode('<span>/</span>', $crumbs) . '</nav>';
}

/* ------------------------------------------------------- zestaw wskaźników
 *
 * Sekcja „Cztery rzeczy" jako deska z czterema zegarami. Tarcze rysujemy
 * wstawionym SVG — bez obrazów, tak samo jak ikony.
 *
 * Wskazówki stoją na PRAWDZIWYCH wartościach, nie na ładnie wyglądających
 * położeniach. Dwie decyzje wynikające wprost z danych:
 *
 *  - PowerBox nie pokazuje przyrostu mocy. Średnia dla modułu to +36 KM wobec
 *    +28 KM dla chip tuningu, ale liczona z 50 wariantów wobec 4220 — próbka
 *    jest obciążona dużymi silnikami. Taki zegar sugerowałby, że moduł jest
 *    mocniejszy od zapisu w sterowniku, co nieprawda. Pokazuje więc czas
 *    montażu, który jest realną przewagą PowerBoxa.
 *  - Hamownia nie ma wielkości mierzalnej, więc jej tarcza działa jak wskaźnik
 *    trybu napędu, a nie udaje pomiaru.
 */

/** Średni przyrost mocy dla wariantu usługi — liczony z bazy, nie wpisany. */
function vts_sredni_przyrost(string $service_code): int
{
    global $wpdb;
    $klucz = 'vts_avg_gain_' . $service_code;
    $v = get_transient($klucz);
    if ($v !== false) {
        return (int) $v;
    }

    $t = vts_table('gain');
    $v = (int) round((float) $wpdb->get_var($wpdb->prepare(
        "SELECT AVG(NULLIF(gain_hp,0)) FROM {$t} WHERE service_code = %s AND visibility = 1",
        $service_code
    )));
    set_transient($klucz, $v, DAY_IN_SECONDS);

    return $v;
}

/**
 * Jedna tarcza. Wartość podajemy jako ułamek zakresu (0–1) — to on ustawia
 * wskazówkę; podpis w środku jest osobny, bo nie każdy zegar mierzy liczbę.
 */
function vts_gauge(array $g): string
{
    $ulamek = max(0.0, min(1.0, (float) $g['frac']));
    $kat    = 135 + $ulamek * 270;          // 0 na godz. 7:30, maksimum na 4:30

    $pkt = function (float $stopnie, float $r) {
        $rad = deg2rad($stopnie);
        return sprintf('%.2f %.2f', 100 + $r * cos($rad), 100 + $r * sin($rad));
    };

    // Kreski do wartości zapalają się na ciepło, reszta zostaje wygaszona —
    // tak jak podziałka pod wskazówką w podświetlonym zegarze.
    $kreski = '';
    for ($i = 0; $i <= 10; $i++) {
        $a   = 135 + $i * 27;
        $du  = $i % 5 === 0;
        $lit = ($i / 10) <= $ulamek + 0.001 ? ' class="is-lit"' : '';
        $kreski .= '<line' . $lit . ' x1="' . str_replace(' ', '" y1="', $pkt($a, $du ? 62 : 67))
                 . '" x2="' . str_replace(' ', '" y2="', $pkt($a, 74))
                 . '" stroke-width="' . ($du ? 2.4 : 1.2) . '"/>';
    }

    // Liczba do odliczenia — tylko jeśli odczyt jest liczbą. „4×4" nie jest.
    $liczba = null;
    if (preg_match('/^\+?(\d+(?:[.,]\d+)?)$/u', str_replace(' ', '', (string) $g['val']), $m)) {
        $liczba = $m[1];
    }

    ob_start(); ?>
    <a class="vts-gauge" href="<?= esc_url($g['href']) ?>"
       style="--vts-kat:<?= round($kat - 135, 1) ?>deg;--vts-pct:<?= round($ulamek * 100, 1) ?>">
      <span class="vts-gauge__dial">
        <span class="vts-gauge__glow" aria-hidden="true"></span>
        <svg viewBox="0 0 200 200" aria-hidden="true" focusable="false">
          <path class="vts-gauge__arc" d="M <?= $pkt(135, 74) ?> A 74 74 0 1 1 <?= $pkt(405, 74) ?>"
                fill="none" stroke-width="1.5"/>
          <?php /* Ten sam łuk drugi raz, na wierzchu — odsłaniany do wartości.
                   pathLength="100" normalizuje długość, więc kreskowanie liczy
                   się w procentach i nie zależy od promienia. */ ?>
          <path class="vts-gauge__arc-lit" pathLength="100"
                d="M <?= $pkt(135, 74) ?> A 74 74 0 1 1 <?= $pkt(405, 74) ?>"
                fill="none" stroke-width="2.5" stroke-linecap="round"/>
          <g class="vts-gauge__ticks" stroke-linecap="round"><?= $kreski ?></g>
          <g class="vts-gauge__needle">
            <line x1="100" y1="100" x2="<?= str_replace(' ', '" y2="', $pkt(135, 52)) ?>"
                  stroke-width="3" stroke-linecap="round"/>
          </g>
          <circle class="vts-gauge__hub" cx="100" cy="100" r="6"/>
        </svg>
        <span class="vts-gauge__val">
          <b<?= $liczba !== null ? ' data-vts-count="' . esc_attr($liczba) . '"' : '' ?>><?= esc_html($g['val']) ?></b>
          <?php if (!empty($g['unit'])) : ?><i><?= esc_html($g['unit']) ?></i><?php endif; ?>
        </span>
      </span>
      <span class="vts-gauge__body">
        <b><?= esc_html($g['title']) ?></b>
        <span class="vts-gauge__meta"><?= esc_html($g['meta']) ?></span>
        <span class="vts-gauge__desc"><?= esc_html($g['desc']) ?></span>
      </span>
    </a>
    <?php
    return ob_get_clean();
}

add_shortcode('vts_gauges', function () {
    $chip = vts_sredni_przyrost('chip');
    $eco  = (float) get_option('vts_eco_saving_pct', 6.5);

    $zegary = [
        [
            'href'  => home_url('/podnoszenie-mocy/chip-tuning/'),
            'val'   => '+' . $chip, 'unit' => 'KM',
            'frac'  => $chip / 100,                       // skala 0–100 KM
            'title' => 'Chip tuning',
            'meta'  => 'średni przyrost z katalogu',
            'desc'  => 'Modyfikacja oprogramowania sterownika. Osobowe, dostawcze, '
                     . 'ciężarowe, autobusy, kampery, ciągniki i maszyny.',
        ],
        [
            'href'  => home_url('/podnoszenie-mocy/powerboxy/'),
            'val'   => '15', 'unit' => 'min',
            'frac'  => 15 / 60,                           // skala 0–60 min
            'title' => 'PowerBoxy',
            'meta'  => 'montaż i demontaż',
            'desc'  => 'Moduł Plug&Play bez ingerencji w oprogramowanie. '
                     . 'Zdejmowany w kilkanaście minut, także dla Volvo VEA.',
        ],
        [
            'href'  => home_url('/podnoszenie-mocy/oferta-dla-flot/'),
            'val'   => number_format_i18n($eco, 1), 'unit' => '%',
            'frac'  => $eco / 10,                         // skala 0–10 %
            'title' => 'Eco-tuning dla flot',
            'meta'  => 'mniej paliwa, zakres 5–8%',
            'desc'  => 'Niższe spalanie, limitery prędkości i obrotów, '
                     . 'rozliczenie na fakturę. Policzcie zwrot w kalkulatorze.',
        ],
        [
            'href'  => home_url('/hamownia/'),
            'val'   => '4×4', 'unit' => '',
            'frac'  => 0.5,                               // wskaźnik trybu, nie pomiar
            'title' => 'Hamownia',
            'meta'  => 'napęd na obie osie + moto',
            'desc'  => 'Pomiar mocy i momentu przed i po modyfikacji. '
                     . 'Także jako samodzielna usługa diagnostyczna.',
        ],
    ];

    return '<div class="vts-gauges">' . implode('', array_map('vts_gauge', $zegary)) . '</div>';
});

/* Komentarze wyłączone. Warsztat nie ma kto moderować, a domyślny formularz
 * WordPressa i tak wychodzi poza nasz system stylów — z malinowym przyciskiem
 * z motywu bazowego włącznie. */
add_filter('comments_open', '__return_false', 20);
add_filter('pings_open', '__return_false', 20);

/* ----------------------------------------------------------- lista wpisów
 *
 * Szablon motywu renderuje archiwum wpisów jako gołe <article> poza naszym
 * kontenerem, a treść strony ustawionej jako „strona wpisów" pomija zupełnie.
 * Doklejamy jedno i drugie: nagłówek z wstępem przed pętlą i datę do zajawki.
 * Sam wygląd robi CSS — tu tylko dostarczamy brakujące elementy.
 */

/** Nagłówek listy wpisów: okruszki, tytuł i wstęp z content/pages/blog.html. */
add_action('loop_start', function ($query) {
    static $zrobione = false;

    if ($zrobione || !$query->is_main_query() || !is_home() || is_front_page()) {
        return;
    }
    $zrobione = true;

    $id = (int) get_option('page_for_posts');
    if (!$id) {
        return;
    }
    ?>
    <section class="vts-hero" style="padding-block:var(--vts-gap-l) var(--vts-gap-m)">
      <div class="vts-hero__scrim"></div>
      <div class="vts-wrap">
        <nav class="vts-crumbs" aria-label="Ścieżka">
          <a href="<?= esc_url(home_url('/')) ?>">Start</a><span>/</span>
          <span style="color:var(--vts-text);opacity:1;margin:0"><?= esc_html(get_the_title($id)) ?></span>
        </nav>
        <h1 style="max-width:20ch"><?= esc_html(get_the_title($id)) ?></h1>
      </div>
    </section>
    <?php /* Bez własnego .vts-wrap — treść strony bloga przynosi własną sekcję
             i kontener, a podwójne opakowanie zsuwało wstęp o 250 px w prawo
             i robiło trzecią lewą krawędź na stronie. */ ?>
    <div class="vts-blog-wstep">
      <?= wp_kses_post(apply_filters('the_content', get_post_field('post_content', $id))) ?>
    </div>
    <?php
});

/** Data przed zajawką — w znacznikach motywu nie ma jej wcale. */
add_filter('the_excerpt', function ($tresc) {
    if (!is_home() || is_front_page() || !in_the_loop()) {
        return $tresc;
    }

    $ikona = (string) get_post_meta(get_the_ID(), '_vts_icon', true);

    return '<span class="vts-post__meta">'
         . ($ikona !== '' ? vts_icon($ikona) : '')
         . '<span class="vts-post__data">' . esc_html(get_the_date('j F Y')) . '</span>'
         . '</span>' . $tresc;
});

/* ------------------------------------------------------- przykładowe wyniki
 *
 * Pięć realnych wersji z katalogu. Dane czytamy z bazy przy renderze, a nie
 * wpisujemy w treść — inaczej rozjechałyby się z katalogiem przy najbliższym
 * imporcie. Każdy kafelek linkuje do swojej wersji, więc wynik da się sprawdzić
 * u źródła.
 *
 * Wybór jest listą identyfikatorów w opcji, więc klient może podmienić przykłady
 * bez dotykania kodu. Domyślne pięć dobrane z przedziału 15–30% przyrostu —
 * w katalogu są wartości skrajne (41 wariantów powyżej 60%), ale to artefakty
 * danych, nie oferta, i nie mają czego szukać na stronie głównej.
 */
function vts_przyklady_id(): array
{
    // Trzy, a nie pięć: przy pięciu drugi rząd zostaje z dwoma kafelkami i rytm
    // się rwie. Trzy mieszczą się w jednym rzędzie i pokrywają cały przekrój
    // oferty — diesel osobowy, benzyna i dostawczy.
    return array_map('intval', (array) get_option('vts_przyklady', [
        10889,  // VW Passat CC 2.0 BlueTDI 143 KM — diesel osobowy
        10700,  // VW Golf VII GTI 2.0 TSI 220 KM  — benzyna
        8229,   // Ford Transit VI 2.2 TDCi 110 KM — dostawczy
    ]));
}

function vts_przyklady(): array
{
    global $wpdb;
    $ids = vts_przyklady_id();
    if (!$ids) {
        return [];
    }

    $klucz = 'vts_przyklady_' . md5(implode(',', $ids));
    $dane  = get_transient($klucz);
    if ($dane !== false) {
        return $dane;
    }

    $e = vts_table('engine'); $g = vts_table('generation');
    $m = vts_table('model');  $k = vts_table('make');
    $z = vts_table('gain');
    $in = implode(',', array_fill(0, count($ids), '%d'));

    $wiersze = $wpdb->get_results($wpdb->prepare(
        "SELECT e.id, e.name AS silnik, e.stock_hp, e.slug AS e_slug,
                g.name AS gen, g.slug AS g_slug,
                o.name AS model, o.slug AS o_slug,
                k.name AS marka, k.slug AS k_slug,
                z.gain_hp, z.gain_nm
           FROM {$e} e
           JOIN {$g} g ON g.id = e.generation_id
           JOIN {$m} o ON o.id = g.model_id
           JOIN {$k} k ON k.id = o.make_id
           JOIN {$z} z ON z.engine_id = e.id AND z.service_code = 'chip' AND z.visibility = 1
          WHERE e.id IN ({$in})",
        ...$ids
    ), ARRAY_A);

    // kolejność jak w opcji, nie jak w odpowiedzi bazy
    $wg_id = [];
    foreach ($wiersze as $w) {
        $wg_id[(int) $w['id']] = $w;
    }
    $dane = [];
    foreach ($ids as $id) {
        if (isset($wg_id[$id])) {
            $dane[] = $wg_id[$id];
        }
    }

    set_transient($klucz, $dane, DAY_IN_SECONDS);

    return $dane;
}

/**
 * Wykres mocy dla jednego wyniku.
 *
 * UWAGA co do uczciwości: mamy wartości SZCZYTOWE, nie przebieg z hamowni.
 * Dlatego realne dane — moc fabryczna i po modyfikacji — są zaznaczone kropkami
 * z liczbami, przebieg między nimi jest przerywany, a podpis „przebieg
 * poglądowy" stoi na samym wykresie, nie w przypisie pod spodem.
 *
 * Momentu nie rysujemy: w katalogu tylko 179 z 4853 wersji ma podany moment
 * fabryczny, więc dla prawie wszystkich nie byłoby punktu wyjścia. Moment
 * podajemy jako sam przyrost, liczbą.
 */
function vts_wykres_mocy(int $fabr, int $po, string $id): string
{
    $max = max($po, 1) * 1.16;                     // zapas nad krzywą
    $y   = fn(int $v) => 108 - ($v / $max) * 96;   // pole rysunku: y 12..108

    $krzywa = function (float $szczyt) {
        return sprintf(
            'M 30 108 C 92 106, 122 %.1f, 172 %.1f C 206 %.1f, 228 %.1f, 250 %.1f',
            $szczyt + 7, $szczyt, $szczyt - 2, $szczyt + 6, $szczyt + 15
        );
    };

    $yf = $y($fabr);
    $yp = $y($po);

    ob_start(); ?>
    <span class="vts-chart">
      <svg viewBox="0 0 260 130" role="img"
           aria-label="Moc fabryczna <?= $fabr ?> KM, po modyfikacji <?= $po ?> KM. Przebieg krzywej poglądowy.">
        <defs>
          <clipPath id="<?= esc_attr($id) ?>"><rect class="vts-chart__wipe" x="0" y="0" width="260" height="130"/></clipPath>
        </defs>
        <path class="vts-chart__os" d="M 30 12 V 108 H 252" fill="none"/>
        <g clip-path="url(#<?= esc_attr($id) ?>)">
          <path class="vts-chart__linia" d="<?= $krzywa($yf) ?>" fill="none"/>
          <path class="vts-chart__linia is-po" d="<?= $krzywa($yp) ?>" fill="none"/>
          <circle class="vts-chart__pkt" cx="172" cy="<?= round($yf, 1) ?>" r="3.4"/>
          <circle class="vts-chart__pkt is-po" cx="172" cy="<?= round($yp, 1) ?>" r="3.9"/>
        </g>
        <text class="vts-chart__opis" x="255" y="<?= round($yf, 1) + 4 ?>" text-anchor="end"><?= $fabr ?> KM</text>
        <text class="vts-chart__opis is-po" x="255" y="<?= round($yp, 1) - 5 ?>" text-anchor="end"><?= $po ?> KM</text>
        <text class="vts-chart__stopka" x="34" y="124">przebieg poglądowy</text>
      </svg>
    </span>
    <?php
    return ob_get_clean();
}

add_shortcode('vts_wyniki', function () {
    $wyniki = vts_przyklady();
    if (!$wyniki) {
        return '';
    }

    $nr  = 0;
    $out = '<div class="vts-grid vts-wyniki">';
    foreach ($wyniki as $w) {
        $fabr = (int) $w['stock_hp'];
        $po   = $fabr + (int) $w['gain_hp'];
        $url  = home_url(sprintf('/chiptuning/%s/%s/%s/%s/',
            $w['k_slug'], $w['o_slug'], $w['g_slug'], $w['e_slug']));

        ob_start(); ?>
        <a class="vts-wynik" href="<?= esc_url($url) ?>">
          <span class="vts-wynik__auto">
            <b><?= esc_html($w['marka'] . ' ' . $w['model']) ?></b>
            <span><?= esc_html($w['gen'] . ' · ' . $w['silnik']) ?></span>
          </span>
          <?= vts_wykres_mocy($fabr, $po, 'vts-wyk-' . (++$nr)) ?>
          <span class="vts-wynik__liczby">
            <span><em>moc</em><b><?= $fabr ?> → <?= $po ?></b><i>KM</i></span>
            <?php if ((int) $w['gain_nm'] > 0) : ?>
              <span><em>moment</em><b>+<?= (int) $w['gain_nm'] ?></b><i>Nm</i></span>
            <?php endif; ?>
          </span>
        </a>
        <?php
        $out .= ob_get_clean();
    }

    return $out . '</div>';
});

/* --------------------------------------------------------------- pasek liczb
 * Dwie pierwsze wartości czytane z licznika katalogu, więc same się aktualizują
 * po imporcie. Odliczanie obsługuje ten sam mechanizm co zegary.
 */
add_shortcode('vts_liczby', function () {
    $c = vts_catalog_counts();

    $poz = [
        [number_format_i18n($c['engine']), (string) $c['engine'], 'wersji silnikowych'],
        [number_format_i18n($c['make']),   (string) $c['make'],   'marek w katalogu'],
        ['2008',                            '2008',               'autoryzacja V-tech'],
        ['w cenie',                         '',                   'pomiar przed i po'],
    ];

    $out = '<div class="vts-liczby">';
    foreach ($poz as [$tekst, $licz, $opis]) {
        $out .= '<div class="vts-liczba"><b'
              . ($licz !== '' ? ' data-vts-count="' . esc_attr($licz) . '"' : '')
              . '>' . esc_html($tekst) . '</b><span>' . esc_html($opis) . '</span></div>';
    }

    return $out . '</div>';
});

/* ----------------------------------------------------------- pas ze zdjęciem
 *
 * Rozdziela sekcje na podstronach, które były samym tekstem. Zdjęcia są
 * stockowe i stonowane do palety, więc muszą być podpisane jako ilustracyjne —
 * inaczej sugerowałyby, że to hala Vitesse, a nie jest.
 */
add_shortcode('vts_band', function ($atts) {
    $a = shortcode_atts([
        'img'     => '',
        'alt'     => '',
        'eyebrow' => '',
        'title'   => '',
    ], $atts);

    $nazwa = preg_replace('/[^a-z0-9-]/', '', (string) $a['img']);
    $plik  = "img/pas-{$nazwa}.webp";
    $maly  = "img/pas-{$nazwa}-sm.webp";

    if ($nazwa === '' || !file_exists(VTS_ASSETS_DIR . '/' . $plik)) {
        return '';
    }

    $duzy_url = VTS_ASSETS_URL . '/' . $plik . '?v=' . vts_asset_ver($plik);
    $maly_url = VTS_ASSETS_URL . '/' . $maly . '?v=' . vts_asset_ver($maly);

    ob_start(); ?>
    <figure class="vts-band">
      <img src="<?= esc_url($duzy_url) ?>"
           srcset="<?= esc_url($maly_url) ?> 900w, <?= esc_url($duzy_url) ?> 1800w"
           sizes="(max-width:900px) 100vw, 1240px"
           width="1800" height="675" loading="lazy" decoding="async"
           alt="<?= esc_attr($a['alt']) ?>">
      <figcaption>
        <?php if ($a['eyebrow'] !== '') : ?>
          <span class="vts-band__e"><?= esc_html($a['eyebrow']) ?></span>
        <?php endif; ?>
        <?php if ($a['title'] !== '') : ?>
          <b><?= esc_html($a['title']) ?></b>
        <?php endif; ?>
        <span class="vts-band__note">zdjęcie ilustracyjne</span>
      </figcaption>
    </figure>
    <?php
    return ob_get_clean();
});

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
    // Wspólny atrybut name robi z <details> akordeon z wyłącznością: otwarcie
    // jednej pozycji zamyka poprzednią. Robi to przeglądarka, więc działa też
    // przy wyłączonym JavaScripcie. Licznik na wypadek dwóch bloków na stronie —
    // wtedy każdy ma własną grupę i nie zamykają się nawzajem.
    static $nr = 0;
    $grupa = 'vts-faq-' . (++$nr);

    $out = '<div class="vts-faq">';
    foreach (vts_faq_items() as [$q, $a]) {
        $out .= '<details name="' . esc_attr($grupa) . '"><summary>' . esc_html($q)
              . '</summary><div>'
              . esc_html(preg_replace('/\s+/', ' ', trim($a))) . '</div></details>';
    }
    return $out . '</div>';
});

/* ------------------------------------------------------------- kontakt */

add_shortcode('vts_contact_details', function () {
    $c = vts_company();
    ob_start(); ?>
    <div class="vts-card">
      <h3><?= vts_icon('pin') ?>Warsztat</h3>
      <p style="color:var(--vts-text)"><?= esc_html($c['street']) ?><br>
        <?= esc_html($c['postal_code'] . ' ' . $c['city']) ?></p>
      <h3 style="margin-top:var(--vts-gap-s)"><?= vts_icon('phone') ?>Telefony</h3>
      <p>
        <?php foreach ($c['phones'] as $p) : ?>
          <?= esc_html($p['label']) ?>:
          <a href="<?= esc_attr(vts_phone_href($p['number'])) ?>"
             style="color:var(--vts-accent);text-decoration:none;font-weight:600"><?= esc_html($p['number']) ?></a><br>
        <?php endforeach; ?>
      </p>
      <h3 style="margin-top:var(--vts-gap-s)"><?= vts_icon('mail') ?>E-mail</h3>
      <p><a href="mailto:<?= esc_attr($c['email']) ?>"
            style="color:var(--vts-accent);text-decoration:none"><?= esc_html($c['email']) ?></a></p>
      <h3 style="margin-top:var(--vts-gap-s)"><?= vts_icon('clock') ?>Godziny</h3>
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
