<?php
/**
 * Plugin Name: Vitesse — konfiguracja
 * Description: Dane firmy, flagi funkcji i dostęp do sekretów. Jedyne źródło prawdy dla obu.
 *
 * Zasada: sekrety czytamy WYŁĄCZNIE ze środowiska (docker-compose → getenv).
 * Nigdy z wp_options — opcje wyciekają w eksportach bazy, backupach i przez wtyczki.
 */

if (!defined('ABSPATH')) {
    exit;
}

define('VTS_VERSION', '0.1.0');
define('VTS_ASSETS_URL', content_url('vts-assets'));
define('VTS_ASSETS_DIR', WP_CONTENT_DIR . '/vts-assets');

/* -------------------------------------------------------------------------
 * Flagi funkcji
 *
 * Domyślne wartości mieszkają w kodzie (wersjonowane), a opcja `vts_feature_*`
 * je nadpisuje — dzięki temu przełączenie to jeden `wp option update`,
 * bez deployu. Patrz plan §6.
 * ---------------------------------------------------------------------- */

/**
 * @param string $flag Nazwa flagi bez prefiksu.
 */
function vts_feature(string $flag): bool
{
    static $defaults = [
        // Serwis aut osobowych (Jaguar / Land Rover) — architektura gotowa, treść niewidoczna.
        'jlr_service'     => false,
        // Dekoder VIN jako trzecie wejście wyszukiwarki (faza 5).
        'vin_decoder'     => false,
        // Agent AI nad katalogiem (faza 5). Bez niego działa wyszukiwanie pełnotekstowe.
        'ai_agent'        => false,
        // Podstrony DPF/EGR/SCR. Domyślnie WYŁĄCZONE do czasu akceptacji prawnej klienta.
        'emissions_pages' => false,
    ];

    if (!array_key_exists($flag, $defaults)) {
        _doing_it_wrong(__FUNCTION__, 'Nieznana flaga funkcji: ' . esc_html($flag), '0.1.0');
        return false;
    }

    $option = get_option('vts_feature_' . $flag, null);

    return $option === null ? $defaults[$flag] : (bool) $option;
}

/**
 * Do jakiego poziomu katalogu wypuszczamy indeksowanie.
 *
 * Przełącznik odwrotu z planu §4.4 — gdyby Search Console pokazała problem
 * z thin contentem, schodzimy poziom niżej jedną komendą, bez zmian w adresach.
 *
 * @return string make|model|generation|engine
 */
function vts_catalog_index_level(): string
{
    $allowed = ['make', 'model', 'generation', 'engine'];
    $level   = (string) get_option('vts_catalog_index_level', 'engine');

    return in_array($level, $allowed, true) ? $level : 'engine';
}

/* -------------------------------------------------------------------------
 * Sekrety
 * ---------------------------------------------------------------------- */

/**
 * @param string $name Nazwa zmiennej środowiskowej.
 */
function vts_secret(string $name, string $fallback = ''): string
{
    $value = getenv($name);

    if ($value === false || $value === '') {
        return $fallback;
    }

    return (string) $value;
}

/** Skrzynka, na którą lecą leady. Rozdzielona na detal i floty. */
function vts_lead_inbox(string $kind = 'retail'): string
{
    $fallback = get_option('admin_email');

    if ($kind === 'fleet') {
        return vts_secret('VTS_LEAD_INBOX_FLEET', vts_secret('VTS_LEAD_INBOX', $fallback));
    }

    return vts_secret('VTS_LEAD_INBOX', $fallback);
}

/* -------------------------------------------------------------------------
 * Dane firmy
 *
 * TODO(klient): NIP i REGON — brak na starym serwisie, wymagane w stopce i JSON-LD.
 * TODO(klient): przypisanie numerów telefonów do działów (Tuning / Floty).
 * ---------------------------------------------------------------------- */

function vts_company(): array
{
    static $data = null;

    if ($data !== null) {
        return $data;
    }

    $data = [
        'name'        => 'Vitesse V-tech Łódź',
        'legal_name'  => get_option('vts_legal_name', ''),   // TODO(klient): pełna nazwa rejestrowa
        'nip'         => get_option('vts_nip', ''),          // TODO(klient)
        'regon'       => get_option('vts_regon', ''),        // TODO(klient)

        'street'      => 'ul. Kolumny 267C',
        'postal_code' => '93-631',
        'city'        => 'Łódź',
        'country'     => 'PL',
        // Poprzedni adres — wciąż krąży w sieci, przydaje się w treści „jak dojechać”.
        'former_address' => 'Tuszyn, ul. Tysiąclecia 17B',

        'email'       => 'biuro@vitesse.auto.pl',

        // Numery ze starego serwisu. Przypisanie do działów do potwierdzenia.
        'phones'      => [
            'tuning' => ['label' => 'Tuning', 'number' => '511 205 980'],
            'fleet'  => ['label' => 'Floty',  'number' => '515 660 210'],
            'office' => ['label' => 'Biuro',  'number' => '42 203 22 31'],
        ],

        'hours'       => [
            'weekdays' => ['open' => '08:00', 'close' => '17:00', 'label' => 'poniedziałek – piątek'],
            'saturday' => ['open' => '08:00', 'close' => '14:00', 'label' => 'sobota'],
        ],

        'geo'         => ['lat' => null, 'lng' => null],     // TODO(klient): dokładne współrzędne pod mapę
    ];

    return $data;
}

/** Telefon w formacie do `tel:` — same cyfry z prefiksem krajowym. */
function vts_phone_href(string $number): string
{
    $digits = preg_replace('/\D+/', '', $number);

    if (strlen($digits) === 9) {
        $digits = '48' . $digits;
    }

    return 'tel:+' . $digits;
}

/**
 * Wersja zasobu na potrzeby cache-bustingu.
 *
 * Bierzemy mtime pliku, więc przeglądarka dostaje nową wersję dokładnie wtedy,
 * gdy plik faktycznie się zmienił — i ani razu więcej.
 */
function vts_asset_ver(string $relative_path): string
{
    $file = VTS_ASSETS_DIR . '/' . ltrim($relative_path, '/');

    return file_exists($file) ? (string) filemtime($file) : VTS_VERSION;
}
