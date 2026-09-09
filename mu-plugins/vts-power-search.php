<?php
/**
 * Plugin Name: Vitesse — wyszukiwarka mocy
 * Description: REST kaskady Marka→Model→Generacja→Silnik, bramka leadowa, shortcode [vts_power_search].
 */

if (!defined('ABSPATH')) {
    exit;
}

const VTS_NS = 'vitesse/v1';

/* ------------------------------------------------------------ token bramki
 *
 * Nonce WordPressa jest wypalany w HTML i żyje 12–24 h, więc łamie się przy
 * cache'owaniu całych stron — a wymagamy cache'u. Zamiast tego wystawiamy
 * krótkotrwały token HMAC przy nieskeszowanym zapytaniu XHR.
 */

function vts_gate_secret(): string
{
    $s = vts_secret('VTS_TOKEN_SECRET');
    if ($s !== '') {
        return $s;
    }
    // Awaryjnie — środowisko bez skonfigurowanego sekretu.
    return wp_salt('auth');
}

function vts_gate_token(int $engine_id, int $ttl = 1800): string
{
    $exp  = time() + $ttl;
    $sig  = hash_hmac('sha256', $engine_id . '|' . $exp, vts_gate_secret());

    return $engine_id . '.' . $exp . '.' . $sig;
}

function vts_gate_verify(string $token, int $engine_id): bool
{
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return false;
    }
    [$id, $exp, $sig] = $parts;

    if ((int) $id !== $engine_id || (int) $exp < time()) {
        return false;
    }

    return hash_equals(hash_hmac('sha256', $id . '|' . $exp, vts_gate_secret()), $sig);
}

/* ------------------------------------------------------------------- REST */

add_action('rest_api_init', function () {
    $open = '__return_true';

    register_rest_route(VTS_NS, '/catalog/makes', [
        'methods'  => 'GET',
        'permission_callback' => $open,
        'callback' => function () {
            return vts_rest_cached('makes', fn() => array_map(fn($m) => [
                'slug'   => $m['slug'],
                'name'   => $m['name'],
                'models' => (int) $m['model_count'],
            ], vts_makes()));
        },
    ]);

    register_rest_route(VTS_NS, '/catalog/models', [
        'methods'  => 'GET',
        'permission_callback' => $open,
        'args'     => ['make' => ['required' => true]],
        'callback' => function (WP_REST_Request $r) {
            $make = sanitize_title($r->get_param('make'));
            return vts_rest_cached('models_' . $make, fn() => array_map(fn($m) => [
                'id'   => (int) $m['id'],
                'slug' => $m['slug'],
                'name' => $m['name'],
            ], vts_models($make)));
        },
    ]);

    register_rest_route(VTS_NS, '/catalog/generations', [
        'methods'  => 'GET',
        'permission_callback' => $open,
        'args'     => ['model' => ['required' => true]],
        'callback' => function (WP_REST_Request $r) {
            $id = (int) $r->get_param('model');
            return vts_rest_cached('gens_' . $id, fn() => array_map(fn($g) => [
                'id'   => (int) $g['id'],
                'slug' => $g['slug'],
                'name' => $g['name'],
            ], vts_generations($id)));
        },
    ]);

    /**
     * Warianty silnikowe — WYŁĄCZNIE dane fabryczne.
     * Wartości po tuningu i cena wychodzą dopiero z POST /lead.
     */
    register_rest_route(VTS_NS, '/catalog/engines', [
        'methods'  => 'GET',
        'permission_callback' => $open,
        'args'     => ['generation' => ['required' => true]],
        'callback' => function (WP_REST_Request $r) {
            $id = (int) $r->get_param('generation');
            return array_map(fn($e) => [
                'id'       => (int) $e['id'],
                'name'     => $e['name'],
                'fuel'     => $e['fuel'],
                'stock_hp' => (int) $e['stock_hp'],
                'stock_nm' => (int) $e['stock_nm'],
                'token'    => vts_gate_token((int) $e['id']),
            ], vts_engines($id));
        },
    ]);

    /**
     * Rozpoznanie pojazdu z numeru VIN.
     *
     * Bez płatnego API: pierwsze trzy znaki (WMI) identyfikują producenta, więc
     * mapujemy je na markę z własnego katalogu, a z dziesiątego znaku czytamy rok
     * modelowy. To wystarcza, żeby ustawić pierwszy krok kaskady — reszta zostaje
     * po stronie użytkownika. Endpoint NIE zwraca wartości po modyfikacji;
     * bramka leadowa pozostaje wyłącznie w POST /lead.
     */
    register_rest_route(VTS_NS, '/catalog/vin', [
        'methods'  => 'GET',
        'permission_callback' => $open,
        'args'     => ['vin' => ['required' => true]],
        'callback' => function (WP_REST_Request $r) {
            if (vts_rate_limited('vin', 30)) {
                return new WP_Error('vts_rate', 'Zbyt wiele zapytań. Spróbuj za chwilę.', ['status' => 429]);
            }
            return vts_vin_decode((string) $r->get_param('vin'));
        },
    ]);

    register_rest_route(VTS_NS, '/catalog/search', [
        'methods'  => 'GET',
        'permission_callback' => $open,
        'callback' => function (WP_REST_Request $r) {
            if (vts_rate_limited('search', 40)) {
                return new WP_Error('vts_rate', 'Zbyt wiele zapytań. Spróbuj za chwilę.', ['status' => 429]);
            }
            return vts_search_engines((string) $r->get_param('q'));
        },
    ]);

    register_rest_route(VTS_NS, '/lead', [
        'methods'  => 'POST',
        'permission_callback' => $open,
        'callback' => 'vts_rest_lead',
    ]);
});

function vts_rest_cached(string $key, callable $fn)
{
    $ck  = 'vts_rest_' . $key;
    $hit = get_transient($ck);

    if ($hit === false) {
        $hit = $fn();
        set_transient($ck, $hit, 12 * HOUR_IN_SECONDS);
    }

    return $hit;
}

/** Czyścimy cache kaskady po każdym imporcie katalogu. */
function vts_flush_catalog_cache(): void
{
    global $wpdb;
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_vts_rest_%'
                     OR option_name LIKE '_transient_timeout_vts_rest_%'");
}

/* --------------------------------------------------------------- VIN
 *
 * Tablica WMI → slug marki w naszym katalogu. Świadomie niepełna: pokrywa
 * producentów obecnych w katalogu V-techa, a nie cały świat. Nierozpoznany VIN
 * nie jest błędem — użytkownik schodzi rząd niżej, do wyboru ręcznego.
 */
function vts_vin_wmi_map(): array
{
    return [
        // VAG
        'WVW' => 'volkswagen', 'WV1' => 'volkswagen', 'WV2' => 'volkswagen',
        '1VW' => 'volkswagen', '3VW' => 'volkswagen', 'WVG' => 'volkswagen',
        'WAU' => 'audi',       'WA1' => 'audi',       'TRU' => 'audi',
        'TMB' => 'skoda',      'TMP' => 'skoda',
        'VSS' => 'seat',       'VSZ' => 'cupra',
        // niemieckie
        'WBA' => 'bmw',        'WBS' => 'bmw',        'WBY' => 'bmw',        'WBX' => 'bmw',
        'WMW' => 'mini',       'WMZ' => 'mini',
        'WDD' => 'mercedes',   'WDB' => 'mercedes',   'WDC' => 'mercedes',
        'W1K' => 'mercedes',   'W1N' => 'mercedes',   'WDF' => 'mercedes',
        'WDA' => 'mercedes-truck', 'WMA' => 'man',
        'WME' => 'smart',      'WP0' => 'porsche',    'WP1' => 'porsche',
        'W0L' => 'opel',       'W0V' => 'opel',       'VXK' => 'opel',       'LRB' => 'opel',
        // francuskie
        'VF1' => 'renault',    'VF3' => 'peugeot',    'VF7' => 'citroen',    'VR1' => 'ds',
        'UU1' => 'dacia',      'UU2' => 'dacia',
        // włoskie
        'ZFA' => 'fiat',       'ZFC' => 'fiat',       'ZAR' => 'alfa-romeo',
        'ZLA' => 'lancia',     'ZAM' => 'maserati',   'ZCF' => 'iveco',      'ZFF' => 'abarth',
        'VNE' => 'iveco',      'WJM' => 'iveco',
        // skandynawskie i brytyjskie
        'YV1' => 'volvo',      'YV4' => 'volvo',      'YV3' => 'volvo',
        'YS3' => 'saab',       'YS2' => 'scania',
        'SAL' => 'land-rover', 'SAJ' => 'jaguar',     'SAD' => 'jaguar',
        'SCF' => 'aston-martin',
        'XLR' => 'daf',        'XLU' => 'daf',
        // azjatyckie
        'VNK' => 'toyota',     'JTD' => 'toyota',     'JTM' => 'toyota',     'SB1' => 'toyota',
        'JTH' => 'lexus',      'JTJ' => 'lexus',
        'JHM' => 'honda',      'SHH' => 'honda',
        'JMB' => 'mitsubishi', 'JMZ' => 'mazda',      'JM1' => 'mazda',
        'JN1' => 'nissan',     'VSK' => 'nissan',     'SJN' => 'nissan',
        'JNK' => 'infiniti',
        'KNA' => 'kia',        'KNE' => 'kia',        'KNH' => 'kia',        'U5Y' => 'kia',
        'KMH' => 'hyundai',    'TMA' => 'hyundai',    'NLH' => 'hyundai',
        'JSA' => 'suzuki',     'TSM' => 'suzuki',     'JF1' => 'subaru',     'JF2' => 'subaru',
        'KPT' => 'ssangyong',  'JAA' => 'isuzu',      'JAC' => 'isuzu',
        // amerykańskie
        'WF0' => 'ford',       '1FA' => 'ford',       '1FT' => 'ford',       '1FM' => 'ford',
        '1G1' => 'chevrolet',  'KL1' => 'chevrolet',  '1G6' => 'cadillac',
        '1C3' => 'chrysler',   '1C4' => 'jeep',       '1J4' => 'jeep',       '1B3' => 'dodge',
    ];
}

/** Rok modelowy z 10. znaku VIN. Kod jest cykliczny (30 lat), więc wybieramy bliższy cykl. */
function vts_vin_year(string $code): int
{
    $tab = 'ABCDEFGHJKLMNPRSTVWXY123456789';
    $i   = strpos($tab, $code);
    if ($i === false) {
        return 0;
    }

    $rok = 1980 + $i;
    while ($rok + 30 <= (int) date('Y') + 1) {
        $rok += 30;
    }

    return $rok;
}

/**
 * @return array{ok:bool,message:string,make?:array{slug:string,name:string},year?:int}
 */
function vts_vin_decode(string $vin): array
{
    $vin = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $vin));

    // I, O i Q nie występują w VIN-ie — mylą się z 1 i 0, więc norma ich nie dopuszcza.
    if (strlen($vin) !== 17 || preg_match('/[IOQ]/', $vin)) {
        return ['ok' => false, 'message' => 'To nie wygląda na poprawny numer VIN (17 znaków, bez liter I, O i Q).'];
    }

    $slug = vts_vin_wmi_map()[substr($vin, 0, 3)] ?? '';
    $rok  = vts_vin_year(substr($vin, 9, 1));

    if ($slug === '') {
        return ['ok' => false, 'message' => 'Nie rozpoznaliśmy marki z tego numeru VIN — wybierzcie pojazd z list poniżej.'];
    }

    // Marka musi być widoczna w katalogu; ukryta zachowuje się jak nieznana.
    $marka = null;
    foreach (vts_makes() as $m) {
        if ($m['slug'] === $slug) {
            $marka = ['slug' => $m['slug'], 'name' => $m['name']];
            break;
        }
    }

    if (!$marka) {
        return ['ok' => false, 'message' => 'Tej marki nie mamy w katalogu — wybierzcie pojazd z list poniżej albo zadzwońcie.'];
    }

    return [
        'ok'      => true,
        'make'    => $marka,
        'year'    => $rok,
        'message' => 'Rozpoznaliśmy markę ' . $marka['name']
                   . ($rok ? ' (rok modelowy ' . $rok . ')' : '')
                   . '. Wskażcie model i wersję silnika.',
    ];
}

function vts_rest_lead(WP_REST_Request $r)
{
    $engine_id = (int) $r->get_param('engine_id');
    $token     = (string) $r->get_param('token');

    // Honeypot — pole ukryte w formularzu, człowiek go nie wypełni.
    if ($r->get_param('company') !== null && $r->get_param('company') !== '') {
        return new WP_Error('vts_spam', 'Nie udało się wysłać.', ['status' => 400]);
    }

    if (!$engine_id || !vts_gate_verify($token, $engine_id)) {
        return new WP_Error('vts_token', 'Sesja wygasła — wybierz silnik ponownie.', ['status' => 403]);
    }

    if (!$r->get_param('consent')) {
        return new WP_Error('vts_consent', 'Zaznacz zgodę na kontakt.', ['status' => 400]);
    }

    if (vts_rate_limited('lead', 5)) {
        return new WP_Error('vts_rate', 'Zbyt wiele zapytań z tego adresu. Zadzwoń do nas.', ['status' => 429]);
    }

    $path = vts_engine_path($engine_id);
    if (!$path) {
        return new WP_Error('vts_engine', 'Nie znaleziono wersji silnika.', ['status' => 404]);
    }

    // VIN jest opcjonalny — pole w wyszukiwarce bywa puste, a wybór ręczny go pomija.
    // Zapisujemy go, bo dla warsztatu jest cenniejszy niż sama nazwa wersji.
    $vin = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $r->get_param('vin')));
    if (strlen($vin) !== 17 || preg_match('/[IOQ]/', $vin)) {
        $vin = '';
    }

    $id = vts_lead_store([
        'source'    => 'hero-cascade',
        'email'     => (string) $r->get_param('email'),
        'phone'     => (string) $r->get_param('phone'),
        'engine_id' => $engine_id,
        'vin'       => $vin,
        'payload'   => ['pojazd' => "{$path['make']} {$path['model']} {$path['generation']} {$path['engine']}"],
    ]);

    if (is_wp_error($id)) {
        return new WP_Error('vts_save', $id->get_error_message(), ['status' => 400]);
    }

    $services = vts_services();
    $results  = [];
    $stock_hp = (int) $path['stock_hp'];
    $stock_nm = (int) $path['stock_nm'];   // 0 znaczy „nieznany" — V-tech nie podaje momentu fabrycznego

    foreach (vts_engine_gains($engine_id) as $g) {
        $code = $g['service_code'];

        // Brak wartości to w tej tabeli ZERO, nie NULL — importer zapisuje to, co
        // przychodzi ze źródła, a konfigurator V-techa podaje w nieużywanych polach
        // zera. Wcześniejszy warunek `!== null` nie łapał więc niczego i moc po
        // modyfikacji była pusta dla 11 277 z 11 514 wariantów. Traktujemy zero
        // jako „nie wiadomo" i liczymy brakującą stronę z drugiej.
        $zap_hp = (int) $g['tuned_hp'];
        $zap_nm = (int) $g['tuned_nm'];

        // Dane z konfiguratora V-techa to delty, dane ze starego katalogu Vitesse
        // to wartości po modyfikacji — obsługujemy oba źródła.
        $gain_hp = (int) $g['gain_hp'] > 0
            ? (int) $g['gain_hp']
            : max(0, $zap_hp - $stock_hp);
        $gain_nm = (int) $g['gain_nm'] > 0
            ? (int) $g['gain_nm']
            : ($stock_nm > 0 ? max(0, $zap_nm - $stock_nm) : 0);

        $tuned_hp = $zap_hp > 0 ? $zap_hp : ($stock_hp > 0 && $gain_hp > 0 ? $stock_hp + $gain_hp : null);
        $tuned_nm = $zap_nm > 0 ? $zap_nm : ($stock_nm > 0 && $gain_nm > 0 ? $stock_nm + $gain_nm : null);

        $results[] = [
            'code'     => $code,
            'label'    => $g['label'] ?: ($services[$code]['label'] ?? $code),
            'gain_hp'  => $gain_hp,
            'gain_nm'  => $gain_nm,
            'tuned_hp' => $tuned_hp,
            'tuned_nm' => $tuned_nm,
            'price'    => $g['price_net'] !== null ? (float) $g['price_net'] : null,
        ];
    }

    // Kafelki podsumowania pokazują JEDEN wariant — ten o największym przyroście
    // mocy — a nie maksima z różnych produktów. Inaczej klient widziałby moc
    // z jednego pakietu i moment z drugiego, czego nie da się kupić razem.
    $top = null;
    foreach ($results as $r) {
        if ($top === null || $r['gain_hp'] > $top['gain_hp']) {
            $top = $r;
        }
    }

    return [
        'vehicle'  => "{$path['make']} {$path['model']} {$path['generation']} · {$path['engine']}",
        'stock_hp' => $stock_hp,
        'stock_nm' => $stock_nm ?: null,
        'best'     => $top ? [
            'label'    => $top['label'],
            'gain_hp'  => $top['gain_hp'],
            'gain_nm'  => $top['gain_nm'],
            'tuned_hp' => $top['tuned_hp'],
        ] : null,
        'results'  => $results,
        'note'     => 'Podane wartości to przyrosty względem stanu fabrycznego, orientacyjne '
                    . 'i zależne od stanu technicznego pojazdu. Ostateczny wynik potwierdzamy '
                    . 'pomiarem na hamowni przed modyfikacją i po niej.',
    ];
}

/** Bramka nie może być cache'owana. */
add_filter('rest_post_dispatch', function ($response, $server, $request) {
    if (strpos($request->get_route(), '/vitesse/v1/lead') !== false) {
        $response->header('Cache-Control', 'no-store, max-age=0');
    } elseif (strpos($request->get_route(), '/vitesse/v1/catalog') !== false) {
        $response->header('Cache-Control', 'public, max-age=300, s-maxage=3600');
    }
    return $response;
}, 10, 3);

/* -------------------------------------------------------------- shortcode */

add_shortcode('vts_power_search', function ($atts) {
    $a = shortcode_atts([
        'title'  => 'Sprawdź, ile zyska Twój silnik',
        'layout' => 'inline',   // hero — z zabawkami przy zdjęciu auta; inline — bez nich
        'vin'    => '',         // puste = decyduje flaga funkcji
    ], $atts);

    $vin_on = $a['vin'] === '' ? vts_feature('vin_decoder') : (bool) $a['vin'];
    $hero   = $a['layout'] === 'hero';

    // Marki renderujemy po stronie serwera — bez tego wyszukiwarka jest dla
    // robota pustym divem, a to najważniejszy element strony głównej.
    $makes  = vts_makes();
    $counts = vts_catalog_counts();
    $c      = vts_company();

    // Kaskada to cztery natywne <select>. Przy 61 markach i 4853 silnikach własna
    // lista kosztowałaby systemowy wybór na telefonie, obsługę klawiatury
    // i czytniki ekranu — a nie dałaby nic w zamian.
    $slots = [
        ['make',  'Marka'],
        ['model', 'Model'],
        ['gen',   'Generacja'],
        ['eng',   'Silnik'],
    ];

    ob_start(); ?>
    <div class="vts-ps<?= $hero ? ' vts-ps--hero' : '' ?>" data-vts-ps
         data-rest="<?= esc_attr(rest_url(VTS_NS)) ?>">

      <div class="vts-ps__head">
        <span class="vts-ps__title"><?= esc_html($a['title']) ?></span>
        <span class="vts-ps__count"><?= esc_html(number_format_i18n($counts['engine'])) ?> wersji silnikowych</span>
      </div>

      <div class="vts-ps__read">
        <p class="vts-ps__veh" data-f="veh">Wybierz pojazd z list poniżej</p>
        <div class="vts-ps__cells">
          <div class="vts-ps__cell"><span>Moc fabryczna</span><b data-f="shp">– – –</b></div>
          <div class="vts-ps__cell"><span>Moment fabr.</span><b data-f="snm">– – –</b></div>
          <div class="vts-ps__cell is-gain is-locked"><span>Po modyfikacji</span><b data-f="thp">– – –</b></div>
          <div class="vts-ps__cell is-gain is-locked"><span>Przyrost mocy</span><b data-f="ghp">– – –</b></div>
        </div>
      </div>

      <?php if ($vin_on) : ?>
        <?php /* Górny rząd: VIN. Rozpoznajemy markę po WMI z własnego katalogu —
                 bez płatnego API. Trafienie ustawia pierwszy select i odblokowuje
                 resztę kaskady; brak trafienia sprowadza użytkownika o rząd niżej,
                 zamiast zostawiać go z komunikatem błędu. */ ?>
        <div class="vts-ps__vin" data-vin>
          <label class="vts-ps__slot vts-ps__slot--vin">
            <span>Wpisz numer VIN</span>
            <input type="text" data-vin-input inputmode="latin" autocomplete="off"
                   spellcheck="false" maxlength="17" placeholder="np. WVWZZZ1KZAW123456"
                   aria-label="Numer VIN">
          </label>
          <button type="button" class="vts-btn vts-btn--ghost" data-vin-go>Rozkoduj</button>
          <p class="vts-ps__vinmsg" data-vin-msg hidden></p>
        </div>

        <p class="vts-ps__or"><span>lub</span></p>
      <?php endif; ?>

      <p class="vts-ps__slotslabel"<?= $vin_on ? '' : ' hidden' ?>>Wybierz model ręcznie</p>

      <div class="vts-ps__slots">
        <?php foreach ($slots as [$key, $label]) : ?>
          <label class="vts-ps__slot">
            <span><?= esc_html($label) ?></span>
            <select data-sel="<?= esc_attr($key) ?>" aria-label="<?= esc_attr($label) ?>"
                    <?= $key === 'make' ? '' : 'disabled' ?>>
              <option value="" selected disabled><?= esc_html($label) ?></option>
              <?php if ($key === 'make') : foreach ($makes as $m) : ?>
                <option value="<?= esc_attr($m['slug']) ?>"><?= esc_html($m['name']) ?></option>
              <?php endforeach; endif; ?>
            </select>
          </label>
        <?php endforeach; ?>
      </div>

      <button type="button" class="vts-btn vts-btn--primary vts-ps__cta" data-cta disabled>
        Sprawdź potencjał i pobierz wycenę
      </button>

      <div data-out hidden>
        <form class="vts-ps__gate" data-gate novalidate>
          <p>Wynik dla <b data-f="veh2">—</b> jest gotowy. Zostaw adres e-mail,
             a odsłonimy wartości po modyfikacji.</p>
          <div class="vts-ps__gatef">
            <input type="email" name="email" required placeholder="twoj@email.pl" aria-label="Adres e-mail">
            <button class="vts-btn vts-btn--primary" type="submit">Pokaż wynik</button>
          </div>
          <label class="vts-ps__consent">
            <input type="checkbox" name="consent" required>
            <span><?= esc_html(vts_consent_text()) ?>
              <a href="<?= esc_url(home_url('/polityka-prywatnosci/')) ?>">Polityka prywatności</a>.</span>
          </label>
          <input type="text" name="company" tabindex="-1" autocomplete="off" aria-hidden="true" class="vts-ps__hp">
          <p class="vts-ps__err" data-err hidden></p>
        </form>

        <p class="vts-ps__note" data-note hidden></p>
      </div>

      <p class="vts-ps__hint">Nie ma Twojej wersji? Zadzwoń —
        <a href="<?= esc_attr(vts_phone_href($c['phones']['tuning']['number'])) ?>">
          <?= esc_html($c['phones']['tuning']['number']) ?></a>
        — często mamy rozwiązanie, którego nie ma w katalogu.</p>

      <?php /* Dwa sterowania auta ze zdjęcia obok. Zostają po zdjęciu obudowy,
               bo działają i bo tylko tutaj widać ich efekt — auto jest w hero,
               nie w sekcjach niżej. Świadomie ciche: to zabawka, nie nawigacja.
               Poza hero nie ma czego zapalać, więc ich tam nie renderujemy. */ ?>
      <?php if ($hero) : ?>
      <div class="vts-ps__toys">
        <button type="button" class="vts-ps__toy vts-ps__toy--haz" data-hazard
                aria-pressed="false">
          <i aria-hidden="true"></i>Awaryjne
        </button>
        <button type="button" class="vts-ps__toy vts-ps__toy--pwr" data-power
                aria-pressed="true">
          <i aria-hidden="true"></i>Światła
        </button>
      </div>
      <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
});
