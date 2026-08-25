<?php
/**
 * Plugin Name: Vitesse — zestaw ikon
 * Description: Wstawiane w kod ikony SVG. Funkcja vts_icon() i skrót [vts_icon name="..."].
 *
 * Bez pliku sprite'a i bez żądań sieciowych: ikona to kilkaset bajtów w HTML,
 * dziedziczy kolor z currentColor i jest ostra w każdej skali.
 *
 * Jeden styl dla całego zestawu: płótno 24×24, obrys 1,5, bez wypełnień. Motywy
 * bierzemy z warsztatu, a nie z ogólnego zestawu „biznesowego" — to jedyny
 * sposób, żeby ikonografia mówiła cokolwiek o tej konkretnej firmie.
 */

if (!defined('ABSPATH')) {
    exit;
}

/** Same ścieżki — otoczka jest wspólna, żeby zestaw nie rozjechał się stylistycznie. */
function vts_icon_paths(): array
{
    return [
        // --- usługi ---------------------------------------------------------
        'ecu'      => '<rect x="5" y="8" width="14" height="8.5" rx="1.5"/>'
                    . '<path d="M8.5 8V5M12 8V5M15.5 8V5M8.5 16.5v2.5M12 16.5v2.5M15.5 16.5v2.5"/>'
                    . '<path d="M9 12.2h6"/>',
        'plug'     => '<path d="M9 3v5M15 3v5"/><rect x="6" y="8" width="12" height="5.5" rx="2"/>'
                    . '<path d="M12 13.5v3.5a4 4 0 0 0 4 4h2.5"/>',
        'unlock'   => '<rect x="4.5" y="11" width="15" height="9.5" rx="2"/>'
                    . '<path d="M8 11V7.5a4 4 0 0 1 7.8-1.2"/><path d="M12 15v2.5"/>',
        'fuel'     => '<path d="M12 3.2s6 6.4 6 10.3a6 6 0 0 1-12 0c0-3.9 6-10.3 6-10.3Z"/>'
                    . '<path d="M9.2 14.3a2.8 2.8 0 0 0 2.8 2.8"/>',
        'limiter'  => '<path d="M3.5 17.5a8.5 8.5 0 1 1 17 0"/><path d="M12 17.5l5-4.6"/>'
                    . '<circle cx="12" cy="17.5" r="1.2"/><path d="M12 5.5v2M5.9 8.1l1.4 1.4M18.1 8.1l-1.4 1.4"/>',
        'curve'    => '<path d="M4 3.5v17h16.5"/>'
                    . '<path d="M6.5 17.8c4.5-.2 6.1-4 7.7-6.8 1.4-2.4 3.2-3.4 5.8-3.8"/>',
        'wrench'   => '<path d="M15.6 3.6a5.2 5.2 0 0 0-6.7 6.7L3.2 16v4.4h4.4l5.7-5.7a5.2 5.2 0 0 0 6.7-6.7l-3.2 3.2-3-3z"/>',
        'shield'   => '<path d="M12 3l8 2.9v6.2c0 4.5-3.3 7.8-8 8.9-4.7-1.1-8-4.4-8-8.9V5.9z"/>'
                    . '<path d="M8.8 12.2l2.2 2.2 4.2-4.4"/>',
        'invoice'  => '<path d="M6 3.2h8.6L19 7.6v13.2H6z"/><path d="M14.4 3.2v4.6H19"/>'
                    . '<path d="M9 12.4h7M9 16h4.6"/>',
        'clock'    => '<circle cx="12" cy="12" r="8.8"/><path d="M12 6.6V12l3.6 2.2"/>',
        'temp'     => '<path d="M14.2 14.6V5.4a2.4 2.4 0 0 0-4.8 0v9.2a4.2 4.2 0 1 0 4.8 0z"/>'
                    . '<path d="M11.8 8.6v6.4"/>',
        'air'      => '<path d="M2.8 8.4h10.4a2.8 2.8 0 1 0-2.8-2.8"/>'
                    . '<path d="M2.8 12.4h13.6a2.8 2.8 0 1 1-2.8 2.8"/><path d="M2.8 16.4h6.4"/>',
        'battery'  => '<rect x="2.5" y="7.5" width="16.5" height="9" rx="2"/><path d="M21.5 11v2"/>'
                    . '<path d="M11.6 9.6l-2.6 3.8h3.4l-0.6 3.2"/>',

        // --- kontakt --------------------------------------------------------
        'pin'      => '<path d="M12 21s6.8-6.1 6.8-11a6.8 6.8 0 1 0-13.6 0C5.2 14.9 12 21 12 21z"/>'
                    . '<circle cx="12" cy="10" r="2.6"/>',
        'phone'    => '<path d="M7 3.6h3.3l1.7 4.2-2.1 1.5a11.2 11.2 0 0 0 4.8 4.8l1.5-2.1 4.2 1.7V17'
                    . 'a3.4 3.4 0 0 1-3.7 3.4C10.3 19.6 4.4 13.7 3.6 7.3A3.4 3.4 0 0 1 7 3.6z"/>',
        'mail'     => '<rect x="2.6" y="5" width="18.8" height="14" rx="2"/>'
                    . '<path d="M3.4 6.5l8.6 5.9 8.6-5.9"/>',

        // --- pojazdy --------------------------------------------------------
        'car'      => '<path d="M4 16.2v-3.4l1.8-.5 2.3-4.1h7.8l2.3 4.1 1.8.5v3.4"/>'
                    . '<path d="M5.8 12.3h12.4"/>'
                    . '<circle cx="7.8" cy="17.6" r="1.9"/><circle cx="16.2" cy="17.6" r="1.9"/>',
        'van'      => '<path d="M2.5 6.6h10.6v9.6H2.5z"/><path d="M13.1 10h3.5l4 4v2.2h-7.5"/>'
                    . '<circle cx="6.6" cy="18" r="1.9"/><circle cx="16.8" cy="18" r="1.9"/>',
        'truck'    => '<path d="M2 5.8h9.2v10.4H2z"/><path d="M12.6 9.2h3.7L20.5 13v3.2h-7.9z"/>'
                    . '<circle cx="5.6" cy="18.2" r="1.8"/><circle cx="9.4" cy="18.2" r="1.8"/>'
                    . '<circle cx="17.4" cy="18.2" r="1.8"/>',
        'bus'      => '<rect x="2.5" y="5.5" width="19" height="10.8" rx="2"/><path d="M2.5 9.6h19"/>'
                    . '<path d="M8.5 5.5v4.1M15.5 5.5v4.1"/>'
                    . '<circle cx="7" cy="18.2" r="1.7"/><circle cx="17" cy="18.2" r="1.7"/>',
        'camper'   => '<path d="M2.6 16.4V5.8h14.8v4.2h2.4a1.6 1.6 0 0 1 1.6 1.6v4.8"/>'
                    . '<path d="M2.6 16.4h18.8"/><path d="M5.2 8.4h5.4v3.4H5.2z"/>'
                    . '<circle cx="7" cy="17.8" r="1.8"/><circle cx="17.2" cy="17.8" r="1.8"/>',
        'tractor'  => '<circle cx="16.4" cy="15.6" r="5.2"/><circle cx="16.4" cy="15.6" r="1.7"/>'
                    . '<circle cx="5.2" cy="17.4" r="3"/>'
                    . '<path d="M5.6 14.4V9.4h5.8l2.6 3.4"/><path d="M8.6 9.4V6h2.8"/>',
        'moto'     => '<circle cx="5" cy="16.6" r="3.4"/><circle cx="5" cy="16.6" r="1.1"/>'
                    . '<circle cx="19" cy="16.6" r="3.4"/><circle cx="19" cy="16.6" r="1.1"/>'
                    . '<path d="M5 16.6l2.6-4.4h4.8l2 2.4"/>'
                    . '<path d="M7.2 12.2h5.6l1.2-2.6h2.2"/><path d="M14.4 14.6L19 16.6"/>',

        // --- hamownia i pomiar ---------------------------------------------
        'dyno'     => '<circle cx="12" cy="9.2" r="5.2"/><circle cx="12" cy="9.2" r="1.7"/>'
                    . '<circle cx="5.4" cy="17.4" r="3"/><circle cx="18.6" cy="17.4" r="3"/>',
        'chart'    => '<path d="M4 3.5v17h16.5"/><path d="M7.6 16.4v-3.6M11.8 16.4V8.6M16 16.4v-6"/>',
    ];
}

/**
 * Zwraca gotowy do wstawienia znacznik SVG.
 *
 * Ikona jest dekoracją obok tekstu, więc domyślnie znika przed czytnikami ekranu.
 * Gdy niesie treść, której nie ma obok, trzeba podać $label.
 */
function vts_icon(string $name, string $class = '', string $label = ''): string
{
    $paths = vts_icon_paths();
    if (!isset($paths[$name])) {
        return '';
    }

    $cls  = trim('vts-i ' . $class);
    $a11y = $label !== ''
        ? 'role="img" aria-label="' . esc_attr($label) . '"'
        : 'aria-hidden="true" focusable="false"';

    return '<svg class="' . esc_attr($cls) . '" ' . $a11y . ' viewBox="0 0 24 24" '
         . 'fill="none" stroke="currentColor" stroke-width="1.5" '
         . 'stroke-linecap="round" stroke-linejoin="round">' . $paths[$name] . '</svg>';
}

add_shortcode('vts_icon', function ($atts) {
    $a = shortcode_atts(['name' => '', 'class' => '', 'label' => ''], $atts);

    return vts_icon((string) $a['name'], (string) $a['class'], (string) $a['label']);
});
