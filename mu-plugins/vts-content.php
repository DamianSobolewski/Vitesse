<?php
/**
 * Plugin Name: Vitesse - treść i dane strukturalne
 * Description: Hero strony głównej, FAQ z jednego źródła, dane kontaktowe, okruszki, JSON-LD.
 */

if (!defined('ABSPATH')) {
    exit;
}

/* ------------------------------------------------- surowy HTML w treści stron
 *
 * wpautop służy do zamiany tekstu pisanego w edytorze na akapity. Treść naszych
 * stron to gotowy HTML z jawnymi znacznikami, więc filtr nie ma tu nic do roboty
 * - a szkodzi: wstawia `</p>` w środek `<a class="vts-card">`, przez co parser
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
 * Hero renderujemy przed treścią strony, a nie w niej - dzięki temu obrazek tła
 * i wyszukiwarka są jednym elementem szablonu, którego nikt nie skasuje przy edycji.
 */
add_filter('the_content', function ($content) {
    if (!in_the_loop() || !is_main_query() || is_admin()) {
        return $content;
    }

    if (is_front_page()) {
        return vts_capture('vts_render_home_hero') . $content;
    }

    // Wpisy dostają ten sam nagłówek co strony - okruszki i H1 z tytułu. Dzięki
    // temu żaden wpis nie musi sam dostarczać nagłówka, a te dwa typy treści
    // wyglądają spójnie.
    if (is_page() || is_singular('post')) {
        return vts_capture('vts_render_page_header') . $content;
    }

    return $content;
    // Priorytet 99, nie 5: wpautop działa na 10 i zamienia puste linie w znaczniki
    // akapitów - w środku wbudowanego SVG rozbijało to grafikę na kawałki.
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
 * Warstwy świetlne hero - „zapłon".
 *
 * Zdjęcie pokazuje auto na wprost w ciemnym garażu. Reflektory na fotografii są
 * zapalone, więc nie da się ich zgasić wprost - zamiast tego kładziemy na nie
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
    // od ściany. Środek i półosie wyznaczone z samego zdjęcia - piksele, w których
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
               przednie lampy są jeszcze zgaszone - i mrugnięcie by nie zagrało -->
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
          <!-- rozżarzony rdzeń żarówki - bez niego lampa jest plamą, a nie źródłem -->
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

      <?php /* Światła awaryjne - zapala je przycisk na konsoli. Osobna warstwa,
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

/**
 * Fabryczne slajdy hero strony głównej.
 *
 * Od X 2026 slajdy żyją w kokpicie jako typ treści „Slajdy hero" (vts-hero-slides.php),
 * a ta tablica jest ich źródłem przy pierwszym imporcie (content/import.php) oraz
 * zapasem, gdy klient wyłączy wszystkie: wtedy strona dostaje sam slajd 0, statycznie.
 *
 * `title` zapisany jak w kokpicie: fragment w *gwiazdkach* dostaje kolor akcentu.
 * `img` to nazwa pliku z assets/img (bez rozszerzenia); wariant -sm dobiera się sam.
 */
function vts_hero_slides_default(): array
{
    return [
        [
            'img'     => 'hero',
            'light'   => true,
            'eyebrow' => 'Chip tuning · Łódź',
            'title'   => 'Odblokowywanie sterowników ECU *na miejscu*, od ręki.',
            'lead'    => 'Najnowsze modele bez wysyłki sterownika za granicę. Otwieramy go u siebie, '
                       . 'więc Twoje auto wraca tego samego dnia z pomiarem na hamowni.',
        ],
        [
            'img'     => 'pas-chip',
            'eyebrow' => 'Gwarancja i leasing',
            'title'   => 'Chip tuning aut na gwarancji i w leasingu.',
            'lead'    => 'Bezpieczny przyrost mocy z kopią oryginalnego programu. Dla aut, które trzeba '
                       . 'oddać w stanie fabrycznym, mamy zdejmowany PowerChip.',
        ],
        [
            'img'     => 'pas-floty',
            'eyebrow' => 'Floty B2B',
            'title'   => 'Eco-tuning i zarządzanie flotą.',
            'lead'    => 'Zmniejsz zużycie paliwa nawet o 10% i załóż limitery prędkości we flocie firmowej. '
                       . 'Obsługa partiami, faktura VAT, pomiar na każdym aucie.',
        ],
        [
            'img'     => 'pas-onas',
            'eyebrow' => 'Zaplecze pomiarowe',
            'title'   => 'Hamownia obciążeniowa 4×4 i stanowisko motocyklowe w Łodzi.',
            'lead'    => 'Pomiar mocy i momentu na kołach dla aut 2WD i 4×4, dostawczych, kamperów '
                       . 'i motocykli. Z wydrukiem wykresu, także bez modyfikacji.',
        ],
    ];
}

/**
 * Slajdy do narysowania: opublikowane wpisy „Slajdy hero", a gdy nie ma żadnego,
 * fabryczny slajd 0. Każdy element ma img_url, img_sm_url, eyebrow, title (HTML),
 * lead i light.
 *
 * Nagłówek nosi tylko pierwszy slajd - to jedyny <h1> na stronie i nie może się
 * mnożyć razem z karuzelą. Pozostałe dostają <p> w tej samej skali; dla czytnika
 * ekranu i tak liczy się wyłącznie slajd aktywny, bo nieaktywne są `hidden`.
 */
function vts_hero_slides(): array
{
    $slides = function_exists('vts_hero_slides_from_posts') ? vts_hero_slides_from_posts() : [];
    if ($slides) {
        return $slides;
    }

    $s   = vts_hero_slides_default()[0];
    $img = vts_hero_slide_img($s['img']);
    return [[
        'img_url'    => $img,
        'img_sm_url' => vts_hero_slide_img($s['img'], 'sm') ?: $img,
        'eyebrow'    => $s['eyebrow'],
        'title'      => vts_slide_title_html($s['title']),
        'lead'       => $s['lead'],
        'light'      => !empty($s['light']),
    ]];
}

/** Adres obrazu slajdu; `hero` respektuje podmianę z opcji `vts_hero_image`. */
function vts_hero_slide_img(string $name, string $size = ''): string
{
    if ($name === 'hero') {
        return vts_hero_image_url($size);
    }

    $file  = $name . ($size === 'sm' ? '-sm' : '') . '.webp';
    $local = VTS_ASSETS_DIR . '/img/' . $file;

    return file_exists($local) ? VTS_ASSETS_URL . '/img/' . $file : '';
}

function vts_render_home_hero(): void
{
    $slides = array_values(array_filter(vts_hero_slides(), fn($s) => $s['img_url'] !== ''));

    $multi = count($slides) > 1;
    ?>
    <section class="vts-hero vts-hero--slider" data-active="0"
             <?= $multi ? 'data-vts-hero-slider' : '' ?>>
      <?php foreach ($slides as $i => $s) :
          $img = $s['img_url'];
          $sm  = $s['img_sm_url'] ?: $img;
          ?>
        <?php /* Obraz przypisujemy dopiero klasie .is-active (theme.css) - dzięki temu
                 przeglądarka pobiera tło slajdu 0 od razu, a pozostałe dopiero przy
                 przewinięciu. Bez tego cztery zdjęcia konkurowałyby o LCP. */ ?>
        <div class="vts-hero__bg<?= $i === 0 ? ' is-active' : '' ?>" data-slide-bg="<?= $i ?>"
             style="--vts-hero-img:url('<?= esc_url($img) ?>');--vts-hero-img-sm:url('<?= esc_url($sm) ?>')"></div>
        <?php if (!empty($s['light'])) { vts_render_hero_light(); } ?>
      <?php endforeach; ?>

      <div class="vts-hero__scrim"></div>
      <div class="vts-wrap">
        <div class="vts-hero__in">

          <div class="vts-hero__slides" aria-live="polite">
            <?php foreach ($slides as $i => $s) : ?>
              <div class="vts-hero__slide<?= $i === 0 ? ' is-active' : '' ?>"
                   data-slide="<?= $i ?>" <?= $i === 0 ? '' : 'hidden' ?>>
                <p class="vts-eyebrow"><?= esc_html($s['eyebrow']) ?></p>
                <?php if ($i === 0) : ?>
                  <h1><?= wp_kses_post($s['title']) ?></h1>
                <?php else : ?>
                  <p class="vts-hero__title"><?= wp_kses_post($s['title']) ?></p>
                <?php endif; ?>
                <p class="vts-lead"><?= esc_html($s['lead']) ?></p>
              </div>
            <?php endforeach; ?>
          </div>

          <?php if ($multi) : ?>
            <div class="vts-hero__dots" role="tablist" aria-label="Slajdy">
              <?php foreach ($slides as $i => $s) : ?>
                <button type="button" role="tab" data-slide-dot="<?= $i ?>"
                        class="<?= $i === 0 ? 'is-active' : '' ?>"
                        aria-selected="<?= $i === 0 ? 'true' : 'false' ?>"
                        aria-label="<?= esc_attr($s['eyebrow']) ?>"></button>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

        </div>
      </div>
    </section>

    <?php /* Wyszukiwarka stoi POD hero, na całą szerokość treści. W hero, obok
             nagłówka, wydłużała sekcję do dwóch ekranów i zasłaniała auto na
             zdjęciu; tu ma cztery listy w jednym rzędzie i jest pierwszą rzeczą
             pod hasłem. */ ?>
    <section class="vts-ps-band" id="wyszukiwarka">
      <div class="vts-wrap">
        <?= do_shortcode('[vts_power_search layout="wide"]') ?>
      </div>
    </section>
    <?php
}

/**
 * Hero podstrony.
 *
 * Do niedawna były to same okruszki i H1, przez co każda podstrona zaczynała się
 * akapitem prozy w kolumnie 680 px - klient słusznie zgłosił, że to wygląda
 * na niedokończone. Konfiguracja poniżej dokłada nadkreślenie, lead i zdjęcie tła.
 * Strony spoza mapy (prawne, katalog, wpisy) zachowują wariant minimalny.
 */
function vts_page_hero(string $slug): array
{
    $map = [
        'podnoszenie-mocy' => [
            'eyebrow' => 'Tuning i performance',
            'lead'    => 'Chip tuning, PowerChipy, eco-tuning dla flot i inne usługi na sterowniku ECU. '
                       . 'Efekt modyfikacji otrzymasz w postaci wykresów z hamowni.',
        ],
        'chip-tuning' => [
            'eyebrow' => 'Chip tuning Łódź',
            'lead'    => 'Więcej mocy i momentu z rezerw, które producent zostawił w silniku. '
                       . 'Program lub moduł PowerChip indywidualnie strojony do danego egzemplarza. '
                       . 'Potwierdzenie efektów na hamowni, tydzień na przetestowanie na co dzień.',
            'img'     => 'pas-chip',
        ],
        'powerboxy' => [
            'eyebrow' => 'Plug &amp; Play',
            'lead'    => 'Więcej mocy bez zmiany oprogramowania sterownika. Montaż na oryginalne '
                       . 'wtyczki. Dostępny także w wersji dla silników Volvo VEA.',
        ],
        'oferta-dla-flot' => [
            'eyebrow' => 'Eco-tuning i floty B2B',
            'lead'    => 'Usługi pozwalające zmniejszyć zużycie paliwa oraz wydłużyć żywotność '
                       . 'układu napędowego. Eco-tuning, limitery prędkości i obrotów, '
                       . 'diagnostyka DPF, SCR, EGR.',
            'img'     => 'pas-floty',
        ],
        'dodatkowe-uslugi-ecu' => [
            'eyebrow' => 'Serwis ECU',
            'lead'    => 'Kilkanaście różnych usług, które możemy wykonać dzięki modyfikacji oprogramowania '
                       . '- od podniesienia mocy, przez DPF, EGR, SCR, układ Start&Stop i ciśnienie oleju, '
                       . 'po sondę Lambda i skrzynię biegów. Zobacz poniżej czy znajdziesz to czego szukasz:',
        ],
        'ev-hybryda' => [
            'eyebrow' => 'Strefa EV i hybryd',
            'lead'    => 'EV box zwiększający zasięg, program podnoszący moc i ładowarka wallbox '
                       . 'z funkcją ochrony baterii.',
            'img'     => 'pas-ev',
        ],
        'hamownia' => [
            'eyebrow' => 'Hamownia Łódź',
            'lead'    => 'Hamownia inercyjna i obciążeniowa do pomiaru aut z napędem na dwa lub '
                       . 'cztery koła, a także motocykli.',
        ],
        'o-nas' => [
            'eyebrow' => 'Kim jesteśmy',
            'lead'    => 'Firma została założona w 2008 roku i od samego początku pracuje '
                       . 'z autoryzacją renomowanej firmy V-tech.',
            'img'     => 'pas-onas',
        ],
        'kontakt' => [
            'eyebrow' => 'Łódź, ul. Kolumny 267C',
            'lead'    => 'Zadzwoń lub napisz. Odpowiadamy w godzinach pracy warsztatu, '
                       . 'a termin pomiaru potwierdzamy telefonicznie.',
        ],
    ];

    return $map[$slug] ?? [];
}

function vts_render_page_header(): void
{
    $hero = is_page() ? vts_page_hero((string) get_post_field('post_name', get_the_ID())) : [];
    $img  = !empty($hero['img']) ? vts_hero_slide_img($hero['img']) : '';
    $pad  = $img ? '' : ' style="padding-block:var(--vts-gap-l) var(--vts-gap-m)"';
    ?>
    <section class="vts-hero vts-hero--page<?= $img ? ' has-img' : '' ?>"<?= $pad ?>>
      <?php if ($img) : ?>
        <div class="vts-hero__bg is-active"
             style="--vts-hero-img:url('<?= esc_url($img) ?>');--vts-hero-img-sm:url('<?= esc_url(vts_hero_slide_img($hero['img'], 'sm') ?: $img) ?>')"></div>
      <?php endif; ?>
      <div class="vts-hero__scrim"></div>
      <div class="vts-wrap">
        <?= vts_breadcrumbs() ?>
        <div class="vts-hero__in">
          <?php if (!empty($hero['eyebrow'])) : ?>
            <p class="vts-eyebrow"><?= wp_kses_post($hero['eyebrow']) ?></p>
          <?php endif; ?>
          <h1 style="max-width:20ch"><?= esc_html(get_the_title()) ?></h1>
          <?php if (!empty($hero['lead'])) : ?>
            <p class="vts-lead"><?= wp_kses_post($hero['lead']) ?></p>
          <?php endif; ?>
        </div>
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

    // Wpis wisi pod blogiem - bez tego okruszki prowadziłyby prosto ze startu
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
 * wstawionym SVG - bez obrazów, tak samo jak ikony.
 *
 * Wskazówki stoją na PRAWDZIWYCH wartościach, nie na ładnie wyglądających
 * położeniach. Dwie decyzje wynikające wprost z danych:
 *
 *  - PowerChip nie pokazuje przyrostu mocy. Średnia dla modułu to +36 KM wobec
 *    +28 KM dla chip tuningu, ale liczona z 50 wariantów wobec 4220 - próbka
 *    jest obciążona dużymi silnikami. Taki zegar sugerowałby, że moduł jest
 *    mocniejszy od zapisu w sterowniku, co nieprawda. Pokazuje więc czas
 *    montażu, który jest realną przewagą PowerChipa.
 *  - Hamownia nie ma wielkości mierzalnej, więc jej tarcza działa jak wskaźnik
 *    trybu napędu, a nie udaje pomiaru.
 */

/** Średni przyrost mocy dla wariantu usługi - liczony z bazy, nie wpisany. */
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
 * Jedna tarcza. Wartość podajemy jako ułamek zakresu (0–1) - to on ustawia
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

    // Kreski do wartości zapalają się na ciepło, reszta zostaje wygaszona -
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

    // Liczba do odliczenia - tylko jeśli odczyt jest liczbą. „4×4" nie jest.
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
          <?php /* Ten sam łuk drugi raz, na wierzchu - odsłaniany do wartości.
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
        <h3><?= esc_html($g['title']) ?></h3>
        <span class="vts-gauge__meta"><?= esc_html($g['meta']) ?></span>
        <span class="vts-gauge__desc"><?= esc_html($g['desc']) ?></span>
      </span>
    </a>
    <?php
    return ob_get_clean();
}

add_shortcode('vts_gauges', function () {
    $chip = vts_sredni_przyrost('chip');
    $eco  = (float) get_option('vts_eco_saving_pct', 10);   // ta sama opcja co w kalkulatorze

    // Sześć filarów oferty w kolejności ze struktury serwisu. Każdy prowadzi
    // w miejsce, które odpowiada na pytanie „czy robicie to, czego szukam?".
    // Tytuły i opisy po uwagach klienta z Figmy (X 2026).
    $zegary = [
        [
            'href'  => home_url('/podnoszenie-mocy/'),
            'val'   => '+' . $chip, 'unit' => 'KM',
            'frac'  => $chip / 100,                       // skala 0–100 KM
            'title' => 'Chip tuning i PowerChipy',
            'meta'  => 'średni przyrost z katalogu',
            'desc'  => 'Chip tuning aut osobowych, dostawczych, ciężarowych, autobusów, kamperów '
                     . 'i ciągników rolniczych, moduły Plug&Play, programowanie skrzyń biegów.',
        ],
        [
            'href'  => home_url('/podnoszenie-mocy/oferta-dla-flot/'),
            'val'   => number_format_i18n($eco, 1), 'unit' => '%',
            'frac'  => $eco / 15,                         // skala 0–15 %
            'title' => 'Oferta dla flot i eco-tuning',
            'meta'  => 'mniej paliwa, nierzadko ponad 10%',
            'desc'  => 'Niższe spalanie w autach firmowych, limitery prędkości i obrotów, '
                     . 'kalkulator oszczędności i wycena dla całej floty.',
        ],
        [
            'href'  => home_url('/podnoszenie-mocy/dodatkowe-uslugi-ecu/'),
            'val'   => '12', 'unit' => 'usług',
            'frac'  => 12 / 14,                           // skala 0–14 pozycji
            'title' => 'Modyfikacje oprogramowania',
            'meta'  => 'diagnostyka i modyfikacje',
            'desc'  => 'DPF, SCR (AdBlue), EGR, Start&Stop, ciśnienie oleju, temperatura pracy '
                     . 'silnika, klapy wirowe, limitery prędkości i obrotów, druga sonda Lambda, '
                     . 'korekta dawki rozruchowej, pompa powietrza dodatkowego (Subaru), '
                     . 'czyszczenie układów dolotowych.',
        ],
        [
            'href'  => home_url('/ev-hybryda/'),
            'val'   => 'EV', 'unit' => '',
            'frac'  => 0.62,                              // wskaźnik trybu, nie pomiar
            'title' => 'Pojazdy elektryczne i hybrydy',
            'meta'  => 'zasięg, moc, ładowanie',
            'desc'  => 'Większy zasięg oraz dobór, sprzedaż i montaż ładowarek wallbox '
                     . 'w domu i w firmie.',
        ],
        [
            'href'  => home_url('/hamownia/'),
            'val'   => '4×4', 'unit' => '',
            'frac'  => 0.5,                               // wskaźnik trybu, nie pomiar
            'title' => 'Hamownia 4×4 i motocyklowa',
            'meta'  => 'napęd na obie osie + moto',
            'desc'  => 'Pomiar inercyjny i obciążeniowy aut 2WD i 4×4, dostawczych, kamperów '
                     . 'i motocykli. Z wydrukiem wykresu, także bez modyfikacji.',
        ],
        [
            'href'  => home_url('/kontakt/'),
            'val'   => 'D2D', 'unit' => '',
            'frac'  => 0.8,                               // wskaźnik trybu, nie pomiar
            'title' => 'Door-2-door',
            'meta'  => 'odbiór i odstawienie auta',
            'desc'  => 'Jeśli nie masz czasu, odbierzemy Twoje auto w umówionym terminie '
                     . 'i odstawimy na miejsce po wykonanej usłudze. Samochody ciężarowe '
                     . 'w wielu przypadkach możemy zrobić także u Ciebie.',
        ],
    ];

    return '<div class="vts-gauges vts-gauges--6">' . implode('', array_map('vts_gauge', $zegary)) . '</div>';
});

/* Komentarze wyłączone. Warsztat nie ma kto moderować, a domyślny formularz
 * WordPressa i tak wychodzi poza nasz system stylów - z malinowym przyciskiem
 * z motywu bazowego włącznie. */
add_filter('comments_open', '__return_false', 20);
add_filter('pings_open', '__return_false', 20);

/* ----------------------------------------------------------- lista wpisów
 *
 * Szablon motywu renderuje archiwum wpisów jako gołe <article> poza naszym
 * kontenerem, a treść strony ustawionej jako „strona wpisów" pomija zupełnie.
 * Doklejamy jedno i drugie: nagłówek z wstępem przed pętlą i datę do zajawki.
 * Sam wygląd robi CSS - tu tylko dostarczamy brakujące elementy.
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
    <?php /* Bez własnego .vts-wrap - treść strony bloga przynosi własną sekcję
             i kontener, a podwójne opakowanie zsuwało wstęp o 250 px w prawo
             i robiło trzecią lewą krawędź na stronie. */ ?>
    <div class="vts-blog-wstep">
      <?= wp_kses_post(apply_filters('the_content', get_post_field('post_content', $id))) ?>
    </div>
    <?php
});

/** Data przed zajawką - w znacznikach motywu nie ma jej wcale. */
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
 * wpisujemy w treść - inaczej rozjechałyby się z katalogiem przy najbliższym
 * imporcie. Każdy kafelek linkuje do swojej wersji, więc wynik da się sprawdzić
 * u źródła.
 *
 * Wybór jest listą identyfikatorów w opcji, więc klient może podmienić przykłady
 * bez dotykania kodu. Domyślne pięć dobrane z przedziału 15–30% przyrostu -
 * w katalogu są wartości skrajne (41 wariantów powyżej 60%), ale to artefakty
 * danych, nie oferta, i nie mają czego szukać na stronie głównej.
 */
function vts_przyklady_id(): array
{
    // Trzy, a nie pięć: przy pięciu drugi rząd zostaje z dwoma kafelkami i rytm
    // się rwie. Trzy mieszczą się w jednym rzędzie i pokrywają cały przekrój
    // oferty - diesel osobowy, benzyna i dostawczy.
    return array_map('intval', (array) get_option('vts_przyklady', [
        10889,  // VW Passat CC 2.0 BlueTDI 143 KM - diesel osobowy
        10700,  // VW Golf VII GTI 2.0 TSI 220 KM  - benzyna
        8229,   // Ford Transit VI 2.2 TDCi 110 KM - dostawczy
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
 * Dlatego realne dane - moc fabryczna i po modyfikacji - są zaznaczone kropkami
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

/* Pasek liczb (10 000+ modyfikacji, 60+ flot) zdjęty w X 2026: klient nie chce
 * publikować wartości, których nie potwierdzi. Gdyby wrócił, liczby muszą
 * przyjść od klienta, nie z szacunku. */

/* ------------------------------------------------- przewagi, proces, etapy
 *
 * Pięć przewag i pięć kroków procesu to treść, która wg struktury serwisu ma stać
 * na stronie głównej, ale wraca też na podstronach ofertowych. Trzymamy ją
 * w shortcode'ach, żeby nie rozjechała się między kopiami w plikach HTML.
 */

/** Kluczowe przewagi konkurencyjne - sekcja 3 strony głównej. */
add_shortcode('vts_usps', function () {
    // [zdjęcie usp-*, alt, tytuł, treść]. Zdjęcia zamiast ikon - na życzenie
    // klienta; źródła i licencje w assets/img/CREDITS.md.
    $poz = [
        ['unlock', 'Laptop diagnostyczny podłączony do samochodu',
         'Odblokowanie sterowników ECU na miejscu',
         'Zablokowany sterownik (roczniki od 2020) odblokowujemy u siebie na miejscu. '
         . 'Bez wysyłki za granicę i bez tygodnia postoju.'],
        ['leasing', 'Kluczyk samochodowy na desce rozdzielczej',
         'Bezpieczny tuning aut na gwarancji i w leasingu',
         'W ofercie mamy rozwiązania podnoszące moc bez ryzyka utraty gwarancji '
         . 'czy problemów z leasingiem.'],
        ['floty', 'Rząd białych aut dostawczych na placu firmy',
         'Eco-tuning i kompleksowa obsługa flot',
         'Spalanie niższe do 10%, limitery prędkości i obrotów, obsługa partiami '
         . 'i faktura VAT. Możesz przetestować na 1–2 pojazdach.'],
        ['kamper', 'Kamper na górskiej drodze o zachodzie słońca',
         'Indywidualny tuning kamperów pod obciążeniem',
         'Program pod pełną masę: więcej momentu na podjazdach, spokojna praca '
         . 'silnika przy długim wysiłku i mniej paliwa w trasie.'],
        ['hamownia', 'Motocykl na stanowisku hamowni',
         'Hamownia 4×4 i stanowisko motocyklowe',
         'Pomiar inercyjny lub obciążeniowy aut 2WD i 4×4, dostawczych, kamperów '
         . 'i motocykli. Przed modyfikacją i po niej.'],
    ];

    $out = '<div class="vts-grid vts-usps">';
    foreach ($poz as [$foto, $alt, $tytul, $tresc]) {
        $out .= '<div class="vts-card vts-card--img">'
              . vts_img_tag('usp-' . $foto, $alt, 900, 506,
                    '(max-width:600px) 92vw, (max-width:1182px) 45vw, 400px')
              . '<div class="vts-card__body"><h3>' . esc_html($tytul) . '</h3>'
              . '<p>' . esc_html($tresc) . '</p></div></div>';
    }

    return $out . '</div>';
});

/**
 * Znacznik <img> dla zdjęcia z assets/img z wariantem -sm.
 * Zwraca pusty string, gdy pliku nie ma - sekcja nie może zostać z zepsutym obrazkiem.
 */
function vts_img_tag(string $nazwa, string $alt, int $w, int $h, string $sizes, string $class = ''): string
{
    $nazwa = preg_replace('/[^a-z0-9-]/', '', $nazwa);
    $duzy  = "img/{$nazwa}.webp";
    $maly  = "img/{$nazwa}-sm.webp";
    if ($nazwa === '' || !file_exists(VTS_ASSETS_DIR . '/' . $duzy)) {
        return '';
    }
    $du = VTS_ASSETS_URL . '/' . $duzy . '?v=' . vts_asset_ver($duzy);
    $ma = file_exists(VTS_ASSETS_DIR . '/' . $maly)
        ? VTS_ASSETS_URL . '/' . $maly . '?v=' . vts_asset_ver($maly) : $du;

    return '<img src="' . esc_url($du) . '" srcset="' . esc_url($ma) . ' ' . (int) ($w / 2) . 'w, '
         . esc_url($du) . ' ' . $w . 'w" sizes="' . esc_attr($sizes) . '"'
         . ' width="' . $w . '" height="' . $h . '" loading="lazy" decoding="async"'
         . ($class ? ' class="' . esc_attr($class) . '"' : '')
         . ' alt="' . esc_attr($alt) . '">';
}

/** Proces bezpiecznego tuningu - pięć kroków, jedno źródło dla wszystkich stron. */
add_shortcode('vts_proces', function () {
    $kroki = [
        ['Diagnostyka wstępna', 'Sprawdzamy wersję silnika, odczytujemy błędy i oceniamy stan '
         . 'techniczny. Jeśli auto wymaga naprawy, poinformujemy Cię o jej kosztach.'],
        ['Pomiar seryjny na hamowni', 'Auto wjeżdża na rolki. Mierzymy moc i moment, by ocenić '
         . 'stan techniczny silnika i mieć punkt wyjścia dla dalszych modyfikacji.'],
        ['Indywidualne strojenie', 'Chip tuning, PowerChip albo eco-tuning pod Twój egzemplarz. '
         . 'Odczyt oryginalnego oprogramowania zostaje na serwerze, by w razie potrzeby '
         . 'zawsze można było do niego wrócić.'],
        ['Pomiar końcowy i wykres', 'Drugi przejazd na tej samej hamowni. Jeśli trzeba dokonać '
         . 'korekt, poprawiamy. Jeśli efekt jest zgodny z oczekiwaniami, dostajesz wydruk '
         . 'mocy i momentu przed i po.'],
        ['2 lata gwarancji, tydzień na testy', 'Na usługę udzielamy dwuletniej gwarancji. Masz '
         . 'również tydzień na to, by przekonać się, że to jest to, czego oczekiwałeś. '
         . 'Jeśli nie, zwracamy pieniądze.'],
    ];

    $out = '<div class="vts-grid vts-steps">';
    foreach ($kroki as $i => [$tytul, $tresc]) {
        $out .= '<div class="vts-card"><span class="vts-card__n">Krok ' . ($i + 1) . '</span>'
              . '<h3>' . esc_html($tytul) . '</h3><p>' . esc_html($tresc) . '</p></div>';
    }

    return $out . '</div>';
});

/** Etapy modyfikacji: Stage 1 / Stage 2. */
add_shortcode('vts_stages', function () {
    $etapy = [
        [
            'Stage 1',
            'Bez zmian mechanicznych',
            'Zmiana wyłącznie w oprogramowaniu, na fabrycznym osprzęcie. Wykorzystujemy '
            . 'fabryczne rezerwy silnika.',
            [
                'Silnik i osprzęt pozostają seryjne',
                'Najczęstszy wybór, obejmuje większość naszych realizacji',
                'Odwracalny: oryginalne oprogramowanie zapisujemy przed każdą zmianą',
                'Zakres przyrostu sprawdzisz w wyszukiwarce mocy',
            ],
        ],
        [
            'Stage 2',
            'Po modyfikacjach mechanicznych',
            'Program dostrojony do silnika po modyfikacjach w układzie dolotowym, wydechowym, '
            . 'rozrządu itp. Modyfikacje mechaniczne wykonujemy sami bądź dostrajamy program '
            . 'do zmian już istniejących. Sugerujemy wstępny pomiar przed jakimikolwiek zmianami '
            . 'mechanicznymi, żeby ustalić punkt odniesienia.',
            [
                'Wymaga sprawnego, zmierzonego wcześniej pojazdu',
                'Zwykle łączy się z modyfikacją sterownika skrzyni (TCU)',
                'Zakres ustalamy po pomiarze seryjnym',
            ],
        ],
    ];

    $out = '<div class="vts-grid vts-stages">';
    foreach ($etapy as [$nazwa, $pod, $opis, $punkty]) {
        $out .= '<div class="vts-card"><span class="vts-card__n">' . esc_html($nazwa) . '</span>'
              . '<h3>' . esc_html($pod) . '</h3><p>' . esc_html($opis) . '</p><ul>';
        foreach ($punkty as $pkt) {
            $out .= '<li>' . esc_html($pkt) . '</li>';
        }
        $out .= '</ul></div>';
    }

    return $out . '</div>';
});

/* -------------------------------------------------------- dowód społeczny
 *
 * Opinie i oceny biorą się z opcji, nie z kodu. Dopóki klient nie dostarczy
 * realnych materiałów, sekcja renderuje się jako pusty string - wymyślona
 * opinia w serwisie usługowym to nie placeholder, tylko wprowadzanie w błąd.
 */
add_shortcode('vts_social_proof', function () {
    $ocena  = (float) get_option('vts_google_rating', 0);
    $liczba = (int) get_option('vts_google_reviews_count', 0);
    $link   = (string) get_option('vts_google_reviews_url', '');
    $opinie = get_option('vts_reviews', []);
    $opinie = is_array($opinie) ? $opinie : [];

    // Bez materiałów nie zostaje nawet nagłówek - pusta sekcja „Co mówią klienci"
    // wyglądałaby jak błąd. Dlatego shortcode oddaje całą sekcję albo nic.
    if (!$ocena && !$opinie) {
        return '';
    }

    $out = '<div class="vts-section"><div class="vts-wrap">'
         . '<p class="vts-eyebrow">Zaufanie</p><h2>Co mówią klienci</h2>'
         . '<div class="vts-proof">';

    if ($ocena) {
        $out .= '<div class="vts-proof__score">'
              . '<b>' . esc_html(number_format_i18n($ocena, 1)) . '</b>'
              . '<span>' . vts_icon('star') . 'Google'
              . ($liczba ? ' · ' . esc_html(number_format_i18n($liczba)) . ' opinii' : '')
              . '</span>';
        if ($link) {
            $out .= '<a href="' . esc_url($link) . '" rel="nofollow noopener" target="_blank">'
                  . 'Zobacz opinie</a>';
        }
        $out .= '</div>';
    }

    if ($opinie) {
        $out .= '<div class="vts-grid vts-proof__list">';
        foreach (array_slice($opinie, 0, 3) as $o) {
            if (empty($o['text'])) {
                continue;
            }
            $out .= '<figure class="vts-card"><blockquote>' . esc_html($o['text']) . '</blockquote>';
            if (!empty($o['author'])) {
                $out .= '<figcaption>' . esc_html($o['author']) . '</figcaption>';
            }
            $out .= '</figure>';
        }
        $out .= '</div>';
    }

    return $out . '</div></div></div>';
});

/* --------------------------------------------------------------- mapa
 *
 * Osadzamy tryb `q=` bez klucza API - nie wymaga konta Google Cloud i nie
 * dokłada skryptu do strony. Ramka jest leniwa, żeby nie konkurowała o LCP.
 */
add_shortcode('vts_map', function () {
    $c     = vts_company();
    $adres = $c['street'] . ', ' . $c['postal_code'] . ' ' . $c['city'];
    $q     = rawurlencode($adres);

    return '<div class="vts-map">'
         . '<iframe src="https://maps.google.com/maps?q=' . $q . '&output=embed"'
         . ' loading="lazy" referrerpolicy="no-referrer-when-downgrade"'
         . ' title="Mapa dojazdu - ' . esc_attr($adres) . '"></iframe>'
         . '</div><p class="vts-note"><a href="https://maps.google.com/?q=' . $q . '"'
         . ' rel="noopener" target="_blank">Otwórz w Mapach Google i wyznacz trasę</a></p>';
});

/* ----------------------------------------------------------- duże zdjęcie
 *
 * Kadr 4:3 w połowie układu dwudzielnego, obok tekstu. Bez podpisu: nadtytuł,
 * tytuł i nota „zdjęcie ilustracyjne" zeszły ze zdjęć na życzenie klienta
 * (X 2026) - opis niesie alt, a źródła i licencje plików foto-*.webp
 * opisuje assets/img/CREDITS.md. Nieznane atrybuty z dawnych wywołań
 * (eyebrow, title) shortcode_atts pomija.
 */
add_shortcode('vts_photo', function ($atts) {
    $a = shortcode_atts(['img' => '', 'alt' => ''], $atts);

    $img = vts_img_tag('foto-' . $a['img'], (string) $a['alt'], 1200, 900,
        '(max-width:900px) 92vw, (max-width:1240px) 44vw, 560px');
    if ($img === '') {
        return '';
    }

    return '<figure class="vts-photo">' . $img . '</figure>';
});

/* ------------------------------------------------------ nawigacja skokowa
 *
 * Lista kotwic do sekcji na bieżącej stronie. Źródłem jest klucz "anchors"
 * strony w content/pages.json - importer zapisuje go w meta _vts_anchors
 * i z tej samej listy buduje trzeci poziom menu głównego. Jedno źródło,
 * żeby menu i pasek pod wstępem nigdy się nie rozjechały.
 */
add_shortcode('vts_jumpnav', function () {
    $anchors = get_post_meta(get_the_ID(), '_vts_anchors', true);
    if (!is_array($anchors) || $anchors === []) {
        return '';
    }

    $li = '';
    foreach ($anchors as $a) {
        if (empty($a['id']) || empty($a['label'])) {
            continue;
        }
        $li .= '<li><a class="vts-chip" href="#' . esc_attr(sanitize_title($a['id'])) . '">' . esc_html($a['label']) . '</a></li>';
    }

    return '<nav class="vts-jump" aria-label="Usługi na tej stronie">'
         . '<p>Przejdź do usługi</p><ul>' . $li . '</ul></nav>';
});

/* ------------------------------------------------------- przebieg pomiaru
 * Cztery kroki pomiaru kontrolnego na hamowni - w tych samych kafelkach co
 * proces tuningu na stronie głównej.
 */
add_shortcode('vts_pomiar', function () {
    $kroki = [
        ['Przygotowanie', 'Odczytujemy ewentualne błędy w sterowniku silnika, sprawdzamy '
         . 'ciśnienie w oponach. W wielu autach trzeba diagnostycznie włączyć tryb hamowni.'],
        ['Wpięcie i zamocowanie', 'Pojazd ustawiamy na rolkach oraz mocujemy pasami do ramy '
         . 'hamowni. Podpinamy sondę AFR.'],
        ['Przejazdy pomiarowe', 'Zwykle dwa-trzy, żeby odrzucić przypadkowe odchylenia. Do wyniku '
         . 'bierzemy przebieg powtarzalny, a nie najlepszy pojedynczy.'],
        ['Wykres i omówienie', 'Tłumaczymy, co pokazują krzywe, z których często jesteśmy '
         . 'w stanie wychwycić usterki, których nie pokazuje komputer diagnostyczny.'],
    ];

    $out = '<div class="vts-grid vts-steps">';
    foreach ($kroki as $i => [$tytul, $tresc]) {
        $out .= '<div class="vts-card"><span class="vts-card__n">Krok ' . ($i + 1) . '</span>'
              . '<h3>' . esc_html($tytul) . '</h3><p>' . esc_html($tresc) . '</p></div>';
    }

    return $out . '</div>';
});

/* -------------------------------------------------- wykres poglądowy
 *
 * Rysunek na stronie hamowni: dwie krzywe mocy i momentu przed i po, podpisane
 * jako poglądowe. Realne wykresy mocy dla wersji silnika pochodzą z V-techa
 * i stoją przy wyniku wyszukiwarki (przycisk „Zobacz wykres mocy").
 */
add_shortcode('vts_wykres_pogladowy', function () {
    // Moc (ciągła) i moment (przerywana), przed (szare) i po (pomarańcz).
    ob_start(); ?>
    <figure class="vts-photo vts-photo--chart vts-photo--svg">
      <svg viewBox="0 0 640 400" role="img"
           aria-label="Poglądowy wykres z hamowni: moc i moment obrotowy przed modyfikacją i po niej">
        <g class="vts-dchart__grid">
          <?php for ($i = 1; $i <= 5; $i++) : ?>
            <line x1="56" y1="<?= 340 - $i * 56 ?>" x2="600" y2="<?= 340 - $i * 56 ?>"/>
          <?php endfor; ?>
          <?php for ($i = 1; $i <= 6; $i++) : ?>
            <line x1="<?= 56 + $i * 90 ?>" y1="40" x2="<?= 56 + $i * 90 ?>" y2="340"/>
          <?php endfor; ?>
        </g>
        <path class="vts-dchart__axis" d="M 56 40 V 340 H 600"/>
        <path class="vts-dchart__nm" d="M 80 300 C 140 200, 170 150, 230 146 C 300 142, 400 170, 470 205 C 520 230, 560 250, 590 262"/>
        <path class="vts-dchart__nm is-po" d="M 80 292 C 140 170, 170 112, 230 106 C 300 100, 400 128, 470 168 C 520 196, 560 220, 590 236"/>
        <path class="vts-dchart__hp" d="M 80 326 C 180 300, 280 240, 380 190 C 440 160, 500 148, 540 152 C 565 155, 580 165, 590 176"/>
        <path class="vts-dchart__hp is-po" d="M 80 322 C 180 288, 280 214, 380 148 C 440 110, 500 92, 540 96 C 565 99, 580 112, 590 126"/>
        <text class="vts-dchart__lbl" x="56" y="366">1500</text>
        <text class="vts-dchart__lbl" x="326" y="366" text-anchor="middle">3500 obr./min</text>
        <text class="vts-dchart__lbl" x="600" y="366" text-anchor="end">5500</text>
        <text class="vts-dchart__lbl is-po" x="596" y="118" text-anchor="end">moc po</text>
        <text class="vts-dchart__lbl" x="596" y="196" text-anchor="end">moc przed</text>
        <text class="vts-dchart__lbl is-po" x="236" y="94">moment po</text>
        <text class="vts-dchart__lbl" x="236" y="170">moment przed</text>
        <text class="vts-dchart__foot" x="56" y="392">przebieg poglądowy · ciągła: moc · przerywana: moment</text>
      </svg>
      <figcaption><span class="vts-photo__e">Jak czytać wykres</span>
        <b>Szeroki, płaski moment liczy się bardziej niż szczyt mocy</b></figcaption>
    </figure>
    <?php
    return ob_get_clean();
});

/* ------------------------------------------------------------------- FAQ */

/** Jedno źródło pytań - używane na stronie głównej, w FAQ i w JSON-LD. */
function vts_faq_items(): array
{
    return [
        ['Czy chip tuning szkodzi silnikowi?',
         'Prawidłowo wykonany jest całkowicie bezpieczny dla silnika i osprzętu. Ważna jest także
          szczegółowa diagnostyka, która może wykryć niedomagania lub ukryte usterki. W takiej
          sytuacji najpierw trzeba je będzie usunąć, zanim przystąpimy do podnoszenia mocy.'],
        ['Czy mogę mieć problem z gwarancją lub leasingiem?',
         'Niektórzy producenci mają możliwość wykrycia zmian w oprogramowaniu, dlatego dla aut na
          gwarancji proponujemy PowerChip, który zdejmuje się przed wizytą w serwisie. Leasing nie
          stanowi problemu - można tutaj bezpiecznie stosować zarówno PowerChip jak i chip tuning.'],
        ['Ile trwa chip tuning?',
         'Zwykle kilka godzin razem z pomiarem przed i po - auto możesz zostawić lub zaczekać przy
          kawie. Zablokowane sterowniki odblokowujemy u siebie, więc auto jest gotowe tego samego
          dnia, a nie po niemal tygodniu z powodu wysyłki modułu do firmy zewnętrznej.'],
        ['O ile spadnie spalanie?',
         'Przy tym samym stylu jazdy spalanie spada z uwagi na wzrost sprawności silnika.
          Najczęściej można liczyć na oszczędności rzędu 10%. Konkretną kwotę dla swojej floty
          policzysz w kalkulatorze na stronie oferty dla flot.'],
        ['Czy da się wrócić do stanu fabrycznego?',
         'Tak. Zawsze zaczynamy od zrobienia odczytu programu fabrycznego z Twojego auta, a odczyt
          ten pozostaje u nas na serwerze. Zawsze można do niego wrócić.'],
        ['Ile kosztuje chip tuning?',
         'Cena zależy od pojemności i mocy silnika oraz od roku produkcji auta. Odblokowanie
          sterownika wymaga dopłaty, także z uwagi na często bardziej skomplikowany soft. Po
          szczegóły zapraszamy do kontaktu.'],
        ['Na czym polega usługa Door-2-door?',
         'Nie zawsze masz możliwość poświęcenia kilku godzin na dojazd i oczekiwanie na wykonanie
          usługi. W takim przypadku możemy od Ciebie samochód odebrać, a po wykonanej usłudze
          odprowadzić w umówione miejsce.'],
    ];
}

add_shortcode('vts_faq', function () {
    // Wspólny atrybut name robi z <details> akordeon z wyłącznością: otwarcie
    // jednej pozycji zamyka poprzednią. Robi to przeglądarka, więc działa też
    // przy wyłączonym JavaScripcie. Licznik na wypadek dwóch bloków na stronie -
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
    <?php /* Etykiety grup to nie nagłówki treści - czytnik ekranu i robot dostają
             jeden H3 na kartę, a „Warsztat", „Telefony" są podpisami danych. */ ?>
    <div class="vts-card vts-contact">
      <h3>VITESSE V-tech Łódź</h3>
      <dl>
        <dt><?= vts_icon('pin') ?>Warsztat</dt>
        <dd><?= esc_html($c['street']) ?><br><?= esc_html($c['postal_code'] . ' ' . $c['city']) ?></dd>
        <dt><?= vts_icon('phone') ?>Telefony</dt>
        <dd>
          <?php foreach ($c['phones'] as $p) : ?>
            <?= esc_html($p['label']) ?>: <?= vts_phone_links($p) ?><br>
          <?php endforeach; ?>
        </dd>
        <dt><?= vts_icon('mail') ?>E-mail</dt>
        <dd><a href="mailto:<?= esc_attr($c['email']) ?>"><?= esc_html($c['email']) ?></a></dd>
        <dt><?= vts_icon('clock') ?>Godziny</dt>
        <dd><?= esc_html(vts_hours_text('weekdays')) ?>,<br>
          <?= esc_html(vts_hours_text('saturday')) ?></dd>
      </dl>
    </div>
    <?php
    return ob_get_clean();
});

/**
 * Formularz kontaktowy. `form` wskazuje slug szablonu z content/forms/.
 *
 * Osobne formularze zamiast jednego z ukrytym polem tematu: każda podstrona pyta
 * o co innego (liczbę aut we flocie, wersję silnika, termin pomiaru), a leady
 * flotowe idą na inną skrzynkę niż reszta.
 */
add_shortcode('vts_contact_form', function ($atts) {
    $a    = shortcode_atts(['form' => 'kontakt'], $atts);
    $slug = sanitize_key($a['form']);

    $form = get_page_by_path($slug, OBJECT, 'wpcf7_contact_form')
         ?: get_page_by_path('kontakt', OBJECT, 'wpcf7_contact_form');

    if ($form) {
        return do_shortcode('[contact-form-7 id="' . $form->ID . '"]');
    }

    // Brak formularza (np. wtyczka wyłączona) nie może zostawić gościa bez
    // drogi kontaktu - pokazujemy telefon i e-mail z konfiguracji.
    $c = vts_company();
    $t = $c['phones']['tuning'];
    return '<p class="vts-note">Napisz na <a href="mailto:' . esc_attr($c['email']) . '">'
         . esc_html($c['email']) . '</a> albo zadzwoń: <a href="' . esc_attr(vts_phone_href($t['number'])) . '">'
         . esc_html($t['number']) . '</a>.</p>';
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
        // Znak firmowy w wersji wektorowej - wyszukiwarka bierze go do wizytówki
        // firmy. Wskazujemy wariant na ciemne tło, bo taki jest w serwisie.
        'logo'     => VTS_ASSETS_URL . '/img/logo-vitesse.svg',
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
