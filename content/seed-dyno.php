<?php
/**
 * Dane demonstracyjne bazy wykresów i dowodu społecznego — DEV.
 *
 * Źródło: content/dyno/seed.json. Wykresy to wydruki wygenerowane przez
 * tools/make-dyno-charts.py z wartości szczytowych — pojazdy i przyrosty
 * odpowiadają katalogowi V-tech, ale to nie są realne pomiary klientów ani
 * realne opinie. Przed startem produkcyjnym zastąpić archiwum hamowni
 * i opiniami z profilu Google (patrz PLAN-WDROZENIA.md, „Do produkcji").
 *
 * Skrypt jest idempotentny: wpisy z poprzedniego przebiegu (meta _vts_seed)
 * usuwa i zakłada od nowa, więc zmiana w seed.json wchodzi po ponownym
 * uruchomieniu. Wpisów dodanych ręcznie w panelu nie rusza.
 */

if (!defined('ABSPATH')) {
    exit(1);
}

$seed = json_decode(file_get_contents('/content/dyno/seed.json'), true);
if (!$seed) {
    echo "BŁĄD: nie da się odczytać content/dyno/seed.json\n";
    exit(1);
}

/* ------------------------------------------------ porządek po poprzednim seedzie */
$stare = get_posts([
    'post_type'      => 'vts_dyno',
    'post_status'    => 'any',
    'posts_per_page' => -1,
    'fields'         => 'ids',
    'meta_key'       => '_vts_seed',
]);
// Cztery wpisy z pierwszej wersji seedu nie miały jeszcze flagi — poznajemy je po tytułach.
$pierwsze = ['Ford Focus III 1.6 TDCi', 'Scania R 440 — eco-tuning', 'Ford Transit 2.2 TDCi', 'Autobus miejski — pomiar kontrolny'];
foreach (get_posts(['post_type' => 'vts_dyno', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids']) as $pid) {
    if (in_array(get_the_title($pid), $pierwsze, true)) {
        $stare[] = $pid;
    }
}
foreach (array_unique($stare) as $pid) {
    $att = get_post_thumbnail_id($pid);
    if ($att) {
        wp_delete_attachment($att, true);
    }
    wp_delete_post($pid, true);
}
if ($stare) {
    echo 'usunięto poprzednie wpisy demonstracyjne: ' . count(array_unique($stare)) . "\n";
}

require_once ABSPATH . 'wp-admin/includes/image.php';

$created = 0;
foreach ($seed['entries'] as $e) {
    // Strażnik z vts-dyno-panel.php cofa wpis do szkicu, dopóki nie ma zapisanej
    // zgody właściciela. Dlatego zakładamy szkic, ustawiamy zgodę i publikujemy.
    $id = wp_insert_post([
        'post_type'    => 'vts_dyno',
        'post_status'  => 'draft',
        'post_title'   => $e['title'],
        'post_content' => $e['note'],
        'post_date'    => $e['date'] . ' 14:30:00',
    ]);
    if (is_wp_error($id)) {
        echo 'BŁĄD ' . $e['title'] . ': ' . $id->get_error_message() . "\n";
        continue;
    }

    update_post_meta($id, '_vts_seed', 1);
    update_post_meta($id, '_vts_stock_hp', $e['stock_hp']);
    update_post_meta($id, '_vts_stock_nm', $e['stock_nm']);
    if ($e['tuned_hp'] !== null) {
        update_post_meta($id, '_vts_tuned_hp', $e['tuned_hp']);
        update_post_meta($id, '_vts_tuned_nm', $e['tuned_nm']);
    }
    update_post_meta($id, '_vts_date', $e['date']);
    if (!empty($e['engine'])) {
        update_post_meta($id, '_vts_engine_id', (int) $e['engine']);
    }
    update_post_meta($id, '_vts_consent', 1);   // dane demonstracyjne
    // Data wpisu = data pomiaru, żeby baza sortowała się chronologicznie. Szkic ma
    // post_date_gmt wyzerowane i bez edit_date WordPress podstawiłby „teraz".
    wp_update_post([
        'ID'            => $id,
        'post_status'   => 'publish',
        'post_date'     => $e['date'] . ' 14:30:00',
        'post_date_gmt' => get_gmt_from_date($e['date'] . ' 14:30:00'),
        'edit_date'     => true,
    ]);

    wp_set_object_terms($id, $e['make'],    'vts_dyno_marka');
    wp_set_object_terms($id, $e['fuel'],    'vts_dyno_paliwo');
    wp_set_object_terms($id, $e['service'], 'vts_dyno_usluga');
    wp_set_object_terms($id, $e['class'],   'vts_dyno_klasa');

    $src = '/content/dyno/seed/' . $e['key'] . '.webp';
    if (file_exists($src)) {
        $upload = wp_upload_bits($e['key'] . '.webp', null, file_get_contents($src));
        if (empty($upload['error'])) {
            $att = wp_insert_attachment([
                'post_mime_type' => 'image/webp',
                'post_title'     => 'Wykres: ' . $e['title'],
                'post_status'    => 'inherit',
            ], $upload['file'], $id);
            wp_update_attachment_metadata($att, wp_generate_attachment_metadata($att, $upload['file']));
            set_post_thumbnail($id, $att);
        }
    } else {
        echo '  brak wykresu ' . $src . " — uruchom tools/make-dyno-charts.py\n";
    }

    $created++;
}

echo "wykresy demonstracyjne: {$created}\n";

/* ------------------------------------------------- dowód społeczny (DEMO) */
$r = $seed['reviews'];
update_option('vts_google_rating', (float) $r['rating']);
update_option('vts_google_reviews_count', (int) $r['count']);
update_option('vts_google_reviews_url', (string) $r['url']);
update_option('vts_reviews', $r['items']);

echo "dowód społeczny: dane demonstracyjne (" . count($r['items']) . " opinie, do podmiany przed startem)\n";
