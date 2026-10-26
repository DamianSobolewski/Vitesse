<?php
/**
 * Plugin Name: Vitesse - typografia polska
 * Description: Twarde spacje po jednoliterowych i krótkich spójnikach, żeby żaden wiersz nie kończył się „i", „z", „w", „na".
 *
 * Uwaga klienta (X 2026): wiersze nie mogą kończyć się krótkim słowem. Robimy to jednym filtrem na
 * całej stronie, a nie ręcznie w treści, bo teksty są w plikach, shortcode'ach, kafelkach z bazy
 * i w polach slajdów - ręczne &nbsp; rozjechałoby się przy pierwszej edycji.
 *
 * Działa na gotowym HTML-u strony (bufor wyjścia). Zmienia wyłącznie węzły tekstowe poza
 * <script>, <style>, <textarea>, <pre>, <code>, <title>, <select>, <option>, więc atrybuty,
 * JSON-LD i wartości pól formularzy zostają nietknięte.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Słowa, po których nie wolno złamać wiersza. Jedna litera plus krótkie spójniki i przyimki.
 * Porównanie bez rozróżniania wielkości liter; spacja po słowie zamienia się na &nbsp;.
 */
function vts_typo_krotkie_slowa(): array
{
    return [
        'a', 'i', 'o', 'u', 'w', 'z',
        'ale', 'bo', 'by', 'co', 'czy', 'do', 'dla', 'go', 'ich', 'ja', 'je', 'ku', 'lub', 'ma',
        'na', 'nad', 'nie', 'od', 'po', 'pod', 'przy', 'są', 'to', 'we', 'za', 'ze', 'że', 'albo',
        'oraz', 'jak', 'też', 'już',
    ];
}

/** Przetwarza pojedynczy węzeł tekstowy (bez znaczników). */
function vts_typo_tekst(string $tekst): string
{
    static $wzor = null;
    if ($wzor === null) {
        $slowa = array_map('preg_quote', vts_typo_krotkie_slowa());
        usort($slowa, fn($a, $b) => strlen($b) <=> strlen($a));
        // samodzielne słowo (nie koniec dłuższego); lookbehind pozwala sklejać ciągi „w i z"
        $wzor = '/(?<![\p{L}\p{N}])(' . implode('|', $slowa) . ')[ \t\r\n]+(?=\S)/iu';
    }

    $tekst = preg_replace($wzor, "$1\u{00A0}", $tekst) ?? $tekst;

    // liczba i jednostka, np. „365 cm", „10 %", „200 KM" - nie rozrywamy
    $tekst = preg_replace('/(\d)[ ](?=(?:cm|mm|km|kg|kw|KM|Nm|%|zł|l|obr)(?!\p{L}))/u', "$1\u{00A0}", $tekst) ?? $tekst;

    return $tekst;
}

/** Przetwarza cały dokument HTML, omijając bloki, w których tekstu nie wolno ruszać. */
function vts_typo_html(string $html): string
{
    // Dokument bez znaczników to nie HTML strony (np. odpowiedź AJAX) - nie ruszamy.
    if (stripos($html, '<html') === false) {
        return $html;
    }

    $kawalki = preg_split('/(<[^>]+>)/', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
    if ($kawalki === false) {
        return $html;
    }

    $pomijaj = ['script', 'style', 'textarea', 'pre', 'code', 'title', 'select', 'option', 'svg'];
    $stos    = 0;
    $out     = '';

    foreach ($kawalki as $k) {
        if ($k !== '' && $k[0] === '<') {
            if (preg_match('#^<(/?)([a-z0-9]+)#i', $k, $m)) {
                $nazwa = strtolower($m[2]);
                if (in_array($nazwa, $pomijaj, true)) {
                    if ($m[1] === '/') {
                        $stos = max(0, $stos - 1);
                    } elseif (substr($k, -2) !== '/>') {
                        $stos++;
                    }
                }
            }
            $out .= $k;
            continue;
        }

        $out .= $stos > 0 ? $k : vts_typo_tekst($k);
    }

    return $out;
}

add_action('template_redirect', function () {
    if (is_admin() || is_feed() || is_robots() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)) {
        return;
    }
    ob_start('vts_typo_html');
}, 0);
