<?php
/**
 * Dane demonstracyjne bazy wykresów — DEV.
 *
 * Zdjęcia pochodzą z obecnego serwisu i są materiałem zastępczym. Przed startem
 * produkcyjnym trzeba je zastąpić realnym archiwum hamowni wraz ze zgodami
 * właścicieli pojazdów na publikację.
 */

if (!defined('ABSPATH')) {
    exit(1);
}

$seed = [
    ['Ford Focus III 1.6 TDCi', 'pomiar-osobowe', 'Ford', 'diesel', 'Chip tuning', 'osobowe',
     95, 205, 117, 267, 'Pomiar przed modyfikacją i po niej, ten sam dzień, to samo stanowisko.'],
    ['Scania R 440 — eco-tuning', 'pomiar-ciezarowe', 'Scania', 'diesel', 'Eco-tuning', 'ciezarowe',
     440, 2300, 466, 2450, 'Pojazd flotowy. Priorytetem było zużycie paliwa, nie moc maksymalna.'],
    ['Ford Transit 2.2 TDCi', 'pomiar-dostawcze', 'Ford', 'diesel', 'Chip tuning', 'dostawcze',
     125, 350, 155, 410, 'Auto obciążone, mierzone w konfiguracji roboczej.'],
    ['Autobus miejski — pomiar kontrolny', 'pomiar-autobus', 'MAN', 'diesel', 'Pomiar', 'ciezarowe',
     280, 1100, 280, 1100, 'Pomiar zamówiony osobno, bez modyfikacji — weryfikacja po naprawie.'],
];

$created = 0;

foreach ($seed as [$title, $img, $marka, $paliwo, $usluga, $klasa, $shp, $snm, $thp, $tnm, $note]) {
    if (get_page_by_title($title, OBJECT, 'vts_dyno')) {
        continue;
    }

    // Strażnik z vts-dyno-panel.php cofa wpis do szkicu, dopóki nie ma zapisanej
    // zgody właściciela. Dlatego zakładamy szkic, ustawiamy zgodę i publikujemy.
    $id = wp_insert_post([
        'post_type'    => 'vts_dyno',
        'post_status'  => 'draft',
        'post_title'   => $title,
        'post_content' => $note,
    ]);
    if (is_wp_error($id)) {
        continue;
    }

    update_post_meta($id, '_vts_stock_hp', $shp);
    update_post_meta($id, '_vts_stock_nm', $snm);
    update_post_meta($id, '_vts_tuned_hp', $thp);
    update_post_meta($id, '_vts_tuned_nm', $tnm);
    update_post_meta($id, '_vts_date', date('Y-m-d'));
    update_post_meta($id, '_vts_consent', 1);   // dane demonstracyjne
    wp_update_post(['ID' => $id, 'post_status' => 'publish']);

    wp_set_object_terms($id, $marka,  'vts_dyno_marka');
    wp_set_object_terms($id, $paliwo, 'vts_dyno_paliwo');
    wp_set_object_terms($id, $usluga, 'vts_dyno_usluga');
    wp_set_object_terms($id, $klasa,  'vts_dyno_klasa');

    // obrazek wyróżniający
    $src = '/content/dyno/seed/' . $img . '.webp';
    if (file_exists($src)) {
        $upload = wp_upload_bits($img . '.webp', null, file_get_contents($src));
        if (empty($upload['error'])) {
            $att = wp_insert_attachment([
                'post_mime_type' => 'image/webp',
                'post_title'     => $title,
                'post_status'    => 'inherit',
            ], $upload['file'], $id);
            require_once ABSPATH . 'wp-admin/includes/image.php';
            wp_update_attachment_metadata($att, wp_generate_attachment_metadata($att, $upload['file']));
            set_post_thumbnail($id, $att);
        }
    }

    $created++;
}

echo "wykresy demonstracyjne: {$created}\n";
