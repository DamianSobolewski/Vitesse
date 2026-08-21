<?php
/**
 * Plugin Name: Vitesse — Poczta deweloperska
 * Description: Na środowisku lokalnym kieruje całą pocztę do Mailpit (host.docker.internal:1025), żeby dało się testować bramkę leadową bez wysyłania maili w świat. Nieaktywne poza localhostem.
 * Version: 1.0
 *
 * Na produkcji ten plik nic nie robi — wysyłką zajmuje się wtedy SMTP serwera.
 * Warunek poniżej jest jedynym przełącznikiem.
 */

if (!defined('ABSPATH')) {
    exit;
}

function vts_is_local_env(): bool
{
    $host = wp_parse_url(home_url(), PHP_URL_HOST);

    return in_array($host, ['localhost', '127.0.0.1'], true) || str_ends_with((string) $host, '.local');
}

if (!vts_is_local_env()) {
    return;
}

add_action('phpmailer_init', function ($phpmailer) {
    $phpmailer->isSMTP();
    $phpmailer->Host        = 'host.docker.internal';
    $phpmailer->Port        = 1025;
    $phpmailer->SMTPAuth    = false;
    $phpmailer->SMTPAutoTLS = false;
    $phpmailer->SMTPSecure  = '';
});
