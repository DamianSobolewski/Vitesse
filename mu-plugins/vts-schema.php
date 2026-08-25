<?php
/**
 * Plugin Name: Vitesse — schemat bazy
 * Description: Własne tabele katalogu mocy i leadów. Aplikowane przez bin/migrate.sh, nie przy każdym żądaniu.
 *
 * Dlaczego własne tabele, a nie CPT: ~17 tys. wariantów silnikowych × ~2,5 usługi
 * to ~60 tys. wpisów w wp_posts i ~600 tys. w wp_postmeta. Kaskada w Hero byłaby
 * wtedy meta_query z JOIN-ami po LONGTEXT (indeks tylko prefiksowy) — filesort
 * na dziesiątkach tysięcy wierszy, trzy razy na jedną interakcję. Patrz plan §4.1.
 *
 * Uwaga do dbDelta: nie rozumie kluczy obcych ani kolumn generowanych, dlatego
 * FK dokładamy osobno w vts_schema_apply_foreign_keys(), a przyrosty liczymy
 * w zapytaniach zamiast trzymać w kolumnach STORED.
 */

if (!defined('ABSPATH')) {
    exit;
}

define('VTS_DB_VERSION', '1.1.0');

/** Pełne nazwy tabel (z prefiksem instalacji). */
function vts_table(string $name): string
{
    global $wpdb;

    return $wpdb->prefix . 'vts_' . $name;
}

/**
 * Definicje tabel w formacie, który dbDelta faktycznie rozumie:
 * jedno pole w linii, dwie spacje po PRIMARY KEY, klucze przez KEY/UNIQUE KEY.
 *
 * @return array<string,string>
 */
function vts_schema_definitions(): array
{
    global $wpdb;

    $charset = $wpdb->get_charset_collate();

    $sql = [];

    $sql['make'] = "CREATE TABLE " . vts_table('make') . " (
  id smallint(5) unsigned NOT NULL AUTO_INCREMENT,
  slug varchar(64) NOT NULL,
  name varchar(64) NOT NULL,
  legacy_key varchar(96) DEFAULT NULL,
  logo varchar(128) DEFAULT NULL,
  model_count smallint(5) unsigned NOT NULL DEFAULT 0,
  is_truck tinyint(3) unsigned NOT NULL DEFAULT 0,
  post_id bigint(20) unsigned DEFAULT NULL,
  visibility tinyint(3) unsigned NOT NULL DEFAULT 1,
  feature_flag varchar(32) DEFAULT NULL,
  sort smallint(5) unsigned NOT NULL DEFAULT 0,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uq_make_slug (slug),
  UNIQUE KEY uq_make_legacy (legacy_key),
  KEY idx_make_vis (visibility,name)
) $charset;";

    $sql['model'] = "CREATE TABLE " . vts_table('model') . " (
  id mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
  make_id smallint(5) unsigned NOT NULL,
  slug varchar(96) NOT NULL,
  name varchar(128) NOT NULL,
  vehicle_class varchar(24) NOT NULL DEFAULT 'osobowe',
  generation_count smallint(5) unsigned NOT NULL DEFAULT 0,
  legacy_key varchar(160) DEFAULT NULL,
  visibility tinyint(3) unsigned NOT NULL DEFAULT 1,
  sort smallint(5) unsigned NOT NULL DEFAULT 0,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uq_model (make_id,slug),
  UNIQUE KEY uq_model_legacy (legacy_key),
  KEY idx_model_class (vehicle_class,visibility),
  KEY idx_model_make_vis (make_id,visibility,name)
) $charset;";

    $sql['generation'] = "CREATE TABLE " . vts_table('generation') . " (
  id mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
  model_id mediumint(8) unsigned NOT NULL,
  slug varchar(96) NOT NULL,
  name varchar(128) NOT NULL,
  year_from smallint(5) unsigned DEFAULT NULL,
  year_to smallint(5) unsigned DEFAULT NULL,
  image_id bigint(20) unsigned DEFAULT NULL,
  engine_count smallint(5) unsigned NOT NULL DEFAULT 0,
  legacy_key varchar(190) DEFAULT NULL,
  visibility tinyint(3) unsigned NOT NULL DEFAULT 1,
  sort smallint(5) unsigned NOT NULL DEFAULT 0,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uq_generation (model_id,slug),
  UNIQUE KEY uq_generation_legacy (legacy_key),
  KEY idx_generation_model_vis (model_id,visibility,sort)
) $charset;";

    $sql['engine'] = "CREATE TABLE " . vts_table('engine') . " (
  id int(10) unsigned NOT NULL AUTO_INCREMENT,
  generation_id mediumint(8) unsigned NOT NULL,
  slug varchar(96) NOT NULL,
  name varchar(128) NOT NULL,
  fuel varchar(16) NOT NULL DEFAULT 'diesel',
  displacement smallint(5) unsigned DEFAULT NULL,
  ecu varchar(64) DEFAULT NULL,
  gearbox varchar(16) NOT NULL DEFAULT 'nieznana',
  stock_kw smallint(5) unsigned DEFAULT NULL,
  stock_hp smallint(5) unsigned DEFAULT NULL,
  stock_nm smallint(5) unsigned DEFAULT NULL,
  legacy_key varchar(190) DEFAULT NULL,
  search_blob varchar(255) NOT NULL DEFAULT '',
  visibility tinyint(3) unsigned NOT NULL DEFAULT 1,
  sort smallint(5) unsigned NOT NULL DEFAULT 0,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uq_engine (generation_id,slug),
  UNIQUE KEY uq_engine_legacy (legacy_key),
  KEY idx_engine_gen_vis (generation_id,visibility,stock_kw),
  FULLTEXT KEY ft_engine_search (search_blob)
) $charset;";

    $sql['gain'] = "CREATE TABLE " . vts_table('gain') . " (
  id int(10) unsigned NOT NULL AUTO_INCREMENT,
  engine_id int(10) unsigned NOT NULL,
  service_code varchar(32) NOT NULL,
  label varchar(64) DEFAULT NULL,
  tuned_kw smallint(5) unsigned DEFAULT NULL,
  tuned_hp smallint(5) unsigned DEFAULT NULL,
  tuned_nm smallint(5) unsigned DEFAULT NULL,
  gain_hp smallint(5) unsigned DEFAULT NULL,
  gain_nm smallint(5) unsigned DEFAULT NULL,
  fuel_saving_pct decimal(4,1) DEFAULT NULL,
  price_net decimal(9,2) DEFAULT NULL,
  price_is_from tinyint(1) NOT NULL DEFAULT 1,
  duration_h decimal(3,1) DEFAULT NULL,
  note varchar(255) DEFAULT NULL,
  visibility tinyint(3) unsigned NOT NULL DEFAULT 1,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uq_gain (engine_id,service_code),
  KEY idx_gain_service (service_code,visibility)
) $charset;";

    // Bez tego każdy ponowny scrape kosztuje zaindeksowane adresy: zmiana nazwy
    // modelu przesuwa slug, a stary URL zwraca 404 zamiast 301.
    $sql['slug_history'] = "CREATE TABLE " . vts_table('slug_history') . " (
  id int(10) unsigned NOT NULL AUTO_INCREMENT,
  entity varchar(16) NOT NULL,
  entity_id mediumint(8) unsigned NOT NULL,
  old_path varchar(190) NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uq_slug_path (old_path),
  KEY idx_slug_entity (entity,entity_id)
) $charset;";

    // Lead nie może żyć wyłącznie w mailu — świeży hosting bez reputacji trafia
    // do spamu, a awaria SMTP oznaczałaby cichą utratę pieniędzy klienta.
    $sql['lead'] = "CREATE TABLE " . vts_table('lead') . " (
  id int(10) unsigned NOT NULL AUTO_INCREMENT,
  created_at datetime NOT NULL,
  source varchar(32) NOT NULL,
  email varchar(190) NOT NULL,
  phone varchar(32) DEFAULT NULL,
  name varchar(96) DEFAULT NULL,
  engine_id int(10) unsigned DEFAULT NULL,
  service_code varchar(32) DEFAULT NULL,
  vin varchar(17) DEFAULT NULL,
  query_text varchar(255) DEFAULT NULL,
  payload_json longtext DEFAULT NULL,
  consent_contact tinyint(1) NOT NULL DEFAULT 0,
  consent_marketing tinyint(1) NOT NULL DEFAULT 0,
  consent_text_hash char(64) NOT NULL DEFAULT '',
  ip_hash char(64) DEFAULT NULL,
  user_agent varchar(255) DEFAULT NULL,
  status varchar(16) NOT NULL DEFAULT 'new',
  mail_sent_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY idx_lead_created (created_at),
  KEY idx_lead_status (status),
  KEY idx_lead_email (email)
) $charset;";

    return $sql;
}

/**
 * Klucze obce. dbDelta ich nie obsługuje, więc dokładamy je raz, po sprawdzeniu
 * w information_schema — inaczej każdy przebieg migracji próbowałby je dodać ponownie.
 */
function vts_schema_apply_foreign_keys(): array
{
    global $wpdb;

    $constraints = [
        'fk_model_make'      => [vts_table('model'), 'make_id', vts_table('make'), 'id'],
        'fk_generation_model'=> [vts_table('generation'), 'model_id', vts_table('model'), 'id'],
        'fk_engine_generation'=> [vts_table('engine'), 'generation_id', vts_table('generation'), 'id'],
        'fk_gain_engine'     => [vts_table('gain'), 'engine_id', vts_table('engine'), 'id'],
    ];

    $added = [];

    foreach ($constraints as $name => [$table, $column, $parent, $parent_column]) {
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE()
               AND TABLE_NAME = %s
               AND CONSTRAINT_NAME = %s",
            $table,
            $name
        ));

        if ((int) $exists > 0) {
            continue;
        }

        $wpdb->query(
            "ALTER TABLE `$table`
             ADD CONSTRAINT `$name` FOREIGN KEY (`$column`)
             REFERENCES `$parent` (`$parent_column`) ON DELETE CASCADE"
        );

        $added[] = $name;
    }

    return $added;
}

/** Uruchamiane z bin/migrate.sh. Idempotentne. */
function vts_schema_migrate(): array
{
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $result = [];

    foreach (vts_schema_definitions() as $key => $sql) {
        $result[$key] = dbDelta($sql);
    }

    $result['foreign_keys'] = vts_schema_apply_foreign_keys();

    update_option('vts_db_version', VTS_DB_VERSION, false);

    return $result;
}

/**
 * Tani strażnik: gdyby ktoś wdrożył kod bez uruchomienia migracji, zobaczy o tym
 * komunikat zamiast białego ekranu przy pierwszym zapytaniu do katalogu.
 */
add_action('admin_notices', function () {
    if (!current_user_can('manage_options')) {
        return;
    }

    if (get_option('vts_db_version') === VTS_DB_VERSION) {
        return;
    }

    echo '<div class="notice notice-error"><p><strong>Vitesse:</strong> schemat bazy jest nieaktualny. Uruchom <code>./bin/migrate.sh</code>.</p></div>';
});
