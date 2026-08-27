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

    $id = vts_lead_store([
        'source'    => 'hero-cascade',
        'email'     => (string) $r->get_param('email'),
        'phone'     => (string) $r->get_param('phone'),
        'engine_id' => $engine_id,
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
    $a = shortcode_atts(['title' => 'Sprawdź, ile zyska Twój silnik'], $atts);

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
    <div class="vts-ps" data-vts-ps data-rest="<?= esc_attr(rest_url(VTS_NS)) ?>">

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

      <p class="vts-ps__soon">Odczyt z numeru VIN i asystent AI — <b>wkrótce</b></p>

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
               nie w sekcjach niżej. Świadomie ciche: to zabawka, nie nawigacja. */ ?>
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
    </div>
    <?php
    return ob_get_clean();
});
