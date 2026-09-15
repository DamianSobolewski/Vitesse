<?php
/**
 * Plugin Name: VT Konfigurator
 * Description: Konfigurator chip tuningu Vtech — kaskadowe dropdowny Marka/Model/Generacja/Silnik/Rocznik z wynikami PowerChip i Chip Tuning.
 * Version:     1.0.0
 * Author:      Signuply
 * Author URI:  https://signuply.io
 * Text Domain: vt-konfigurator
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'VT_KONFIGURATOR_VERSION', '1.0.0' );
define( 'VT_KONFIGURATOR_PATH', plugin_dir_path( __FILE__ ) );
define( 'VT_KONFIGURATOR_URL', plugin_dir_url( __FILE__ ) );
define( 'VT_TEXT_DOMAIN', 'vt-konfigurator' );

// Option keys.
define( 'VT_OPTION_TREE', 'vt_vehicle_tree' );
define( 'VT_OPTION_TREE_META', 'vt_vehicle_tree_meta' );
define( 'VT_OPTION_SETTINGS', 'vt_konfigurator_settings' );
define( 'VT_OPTION_ERROR_LOG', 'vt_konfigurator_errors' );
define( 'VT_CACHE_PREFIX', 'vt_res_' );
define( 'VT_STALE_PREFIX', 'vt_stale_' );

require_once VT_KONFIGURATOR_PATH . 'includes/class-vt-storage.php';
require_once VT_KONFIGURATOR_PATH . 'includes/class-vt-fetcher.php';
require_once VT_KONFIGURATOR_PATH . 'includes/class-vt-ajax.php';
require_once VT_KONFIGURATOR_PATH . 'includes/class-vt-shortcode.php';

if ( is_admin() ) {
	require_once VT_KONFIGURATOR_PATH . 'admin/class-vt-admin.php';
	new VT_Admin();
}

( new VT_Ajax() );
( new VT_Shortcode() );
