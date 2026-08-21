<?php
/**
 * Plugin Name: Vitesse — leady
 * Description: Zapis zapytań, wysyłka na skrzynkę działu, autoresponder, retencja.
 *
 * Lead najpierw ląduje w bazie, dopiero potem idzie mailem. Świeży hosting bez
 * reputacji regularnie trafia do spamu — gdyby jedynym nośnikiem był e-mail,
 * awaria SMTP oznaczałaby cichą utratę zapytania.
 */

if (!defined('ABSPATH')) {
    exit;
}

/** Treść zgody. Hash trafia do bazy jako dowód, co dokładnie pokazaliśmy. */
function vts_consent_text(): string
{
    return 'Wyrażam zgodę na kontakt w sprawie mojego zapytania. Administratorem danych jest '
         . vts_company()['name'] . '. Podanie danych jest dobrowolne, a zgodę mogę wycofać w każdej chwili.';
}

function vts_hash_ip(string $ip): string
{
    return hash('sha256', $ip . '|' . vts_secret('VTS_LEAD_SALT', 'vts-dev-salt'));
}

function vts_client_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '');
}

/**
 * Limit zapytań na adres IP. Chroni przed masowym zbieraniem katalogu
 * i przed zalewaniem skrzynki klienta.
 */
function vts_rate_limited(string $bucket, int $max = 5, int $window = HOUR_IN_SECONDS): bool
{
    $key = 'vts_rl_' . $bucket . '_' . substr(vts_hash_ip(vts_client_ip()), 0, 20);
    $hits = (int) get_transient($key);

    if ($hits >= $max) {
        return true;
    }

    set_transient($key, $hits + 1, $window);

    return false;
}

/**
 * @param array $data source,email,phone,name,engine_id,service_code,query_text,payload,consent_marketing
 * @return int|WP_Error id leada
 */
function vts_lead_store(array $data)
{
    global $wpdb;

    $email = sanitize_email($data['email'] ?? '');
    if (!is_email($email)) {
        return new WP_Error('vts_bad_email', 'Podaj poprawny adres e-mail.');
    }

    $ok = $wpdb->insert(vts_table('lead'), [
        'created_at'        => current_time('mysql'),
        'source'            => sanitize_key($data['source'] ?? 'unknown'),
        'email'             => $email,
        'phone'             => sanitize_text_field($data['phone'] ?? '') ?: null,
        'name'              => sanitize_text_field($data['name'] ?? '') ?: null,
        'engine_id'         => !empty($data['engine_id']) ? (int) $data['engine_id'] : null,
        'service_code'      => sanitize_key($data['service_code'] ?? '') ?: null,
        'query_text'        => sanitize_text_field($data['query_text'] ?? '') ?: null,
        'payload_json'      => !empty($data['payload']) ? wp_json_encode($data['payload']) : null,
        'consent_contact'   => 1,
        'consent_marketing' => !empty($data['consent_marketing']) ? 1 : 0,
        'consent_text_hash' => hash('sha256', vts_consent_text()),
        'ip_hash'           => vts_hash_ip(vts_client_ip()),
        'user_agent'        => substr(sanitize_text_field($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        'status'            => 'new',
    ]);

    if (!$ok) {
        return new WP_Error('vts_db', 'Nie udało się zapisać zapytania.');
    }

    $id = (int) $wpdb->insert_id;
    vts_lead_notify($id, $data);

    return $id;
}

function vts_lead_notify(int $id, array $data): void
{
    global $wpdb;

    $company = vts_company();
    $inbox   = vts_lead_inbox(($data['source'] ?? '') === 'fleet-calc' ? 'fleet' : 'retail');
    $path    = !empty($data['engine_id']) ? vts_engine_path((int) $data['engine_id']) : null;

    $vehicle = $path
        ? "{$path['make']} {$path['model']} {$path['generation']} — {$path['engine']}"
        : ($data['query_text'] ?? 'zapytanie ogólne');

    $lines = [
        'Nowe zapytanie ze strony.',
        '',
        'Pojazd:  ' . $vehicle,
        'E-mail:  ' . $data['email'],
    ];
    if (!empty($data['phone'])) {
        $lines[] = 'Telefon: ' . $data['phone'];
    }
    $lines[] = 'Źródło:  ' . ($data['source'] ?? '—');

    if (!empty($data['payload'])) {
        $lines[] = '';
        $lines[] = 'Dane z formularza:';
        foreach ((array) $data['payload'] as $k => $v) {
            $lines[] = '  ' . $k . ': ' . (is_scalar($v) ? $v : wp_json_encode($v));
        }
    }

    $lines[] = '';
    $lines[] = 'Podgląd w panelu: ' . admin_url('admin.php?page=vts-leads');

    $from    = 'no-reply@' . wp_parse_url(home_url(), PHP_URL_HOST);
    $headers = [
        'Content-Type: text/plain; charset=UTF-8',
        'From: ' . $company['name'] . ' <' . $from . '>',
        'Reply-To: ' . $data['email'],
    ];

    $sent = wp_mail($inbox, '[Vitesse] Lead: ' . $vehicle, implode("\n", $lines), $headers);

    // Autoresponder pełni też rolę doręczenia klauzuli informacyjnej.
    if ($sent) {
        $reply = [
            'Dzień dobry,',
            '',
            'dziękujemy za zapytanie dotyczące: ' . $vehicle . '.',
            'Odezwiemy się w godzinach pracy warsztatu — ' . $company['hours']['weekdays']['label'] . ', '
                . $company['hours']['weekdays']['open'] . '–' . $company['hours']['weekdays']['close'] . '.',
            '',
            'Podane wartości są orientacyjne i zależą od stanu technicznego pojazdu.',
            'Ostateczny wynik potwierdzamy pomiarem na hamowni przed i po modyfikacji.',
            '',
            '--',
            $company['name'],
            $company['street'] . ', ' . $company['postal_code'] . ' ' . $company['city'],
            $company['phones']['tuning']['number'],
            '',
            vts_consent_text(),
        ];
        wp_mail($data['email'], 'Vitesse — potwierdzenie zapytania', implode("\n", $reply), $headers);
    }

    $wpdb->update(vts_table('lead'), [
        'status'       => $sent ? 'sent' : 'failed',
        'mail_sent_at' => $sent ? current_time('mysql') : null,
    ], ['id' => $id]);
}

/* ------------------------------------------------- podgląd w panelu admina */

add_action('admin_menu', function () {
    add_menu_page('Zapytania', 'Zapytania', 'manage_options', 'vts-leads',
        'vts_leads_screen', 'dashicons-email-alt', 26);
});

function vts_leads_screen(): void
{
    global $wpdb;
    $rows = $wpdb->get_results(
        'SELECT * FROM ' . vts_table('lead') . ' ORDER BY created_at DESC LIMIT 200',
        ARRAY_A
    );
    ?>
    <div class="wrap">
      <h1>Zapytania ze strony</h1>
      <p>Ostatnie 200. Lead zapisuje się tutaj niezależnie od tego, czy mail dotarł.</p>
      <table class="widefat striped">
        <thead><tr><th>Data</th><th>E-mail</th><th>Pojazd</th><th>Źródło</th><th>Mail</th></tr></thead>
        <tbody>
        <?php if (!$rows) : ?>
          <tr><td colspan="5">Brak zapytań.</td></tr>
        <?php else : foreach ($rows as $r) :
            $path = $r['engine_id'] ? vts_engine_path((int) $r['engine_id']) : null; ?>
          <tr>
            <td><?= esc_html($r['created_at']) ?></td>
            <td><a href="mailto:<?= esc_attr($r['email']) ?>"><?= esc_html($r['email']) ?></a></td>
            <td><?= esc_html($path ? "{$path['make']} {$path['model']} {$path['engine']}" : ($r['query_text'] ?: '—')) ?></td>
            <td><?= esc_html($r['source']) ?></td>
            <td><?= $r['status'] === 'sent' ? 'wysłany' : esc_html($r['status']) ?></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
    <?php
}

/* -------------------------------------------------------------- retencja */

add_action('init', function () {
    if (!wp_next_scheduled('vts_purge_leads')) {
        wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'vts_purge_leads');
    }
});

/** Retencja zapisana w polityce musi być faktycznie egzekwowana, nie tylko zadeklarowana. */
add_action('vts_purge_leads', function () {
    global $wpdb;
    $months = (int) get_option('vts_lead_retention_months', 24);
    $wpdb->query($wpdb->prepare(
        'DELETE FROM ' . vts_table('lead') . ' WHERE created_at < DATE_SUB(NOW(), INTERVAL %d MONTH)',
        $months
    ));
});
