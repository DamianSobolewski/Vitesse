<?php
/**
 * Dane demonstracyjne dowodu społecznego - DEV.
 *
 * Źródło: content/seed.json. Trzy opinie i ocena nie są prawdziwe - przed startem
 * produkcyjnym podmienić na opinie z profilu Google (PLAN-WDROZENIA.md, „Do produkcji").
 * Skrypt jest idempotentny: nadpisuje opcje przy każdym uruchomieniu.
 */

if (!defined('ABSPATH')) {
    exit(1);
}

$seed = json_decode(file_get_contents('/content/seed.json'), true);
if (!$seed || empty($seed['reviews'])) {
    echo "BŁĄD: nie da się odczytać content/seed.json\n";
    exit(1);
}

$r = $seed['reviews'];
update_option('vts_google_rating', (float) $r['rating']);
update_option('vts_google_reviews_count', (int) $r['count']);
update_option('vts_google_reviews_url', (string) $r['url']);
update_option('vts_reviews', $r['items']);

echo "dowód społeczny: dane demonstracyjne (" . count($r['items']) . " opinie, do podmiany przed startem)\n";
