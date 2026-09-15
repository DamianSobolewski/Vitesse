<?php
// Runs when the plugin is deleted from WP Admin → Plugins.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Remove all plugin options.
$options = [
	'vt_vehicle_tree',
	'vt_vehicle_tree_meta',
	'vt_konfigurator_settings',
	'vt_konfigurator_errors',
];
foreach ( $options as $option ) {
	delete_option( $option );
}

// Remove result transients and stale backups.
global $wpdb;
$wpdb->query(
	"DELETE FROM {$wpdb->options}
	 WHERE option_name LIKE '_transient_vt_res_%'
	    OR option_name LIKE '_transient_timeout_vt_res_%'
	    OR option_name LIKE 'vt_stale_%'
	    OR option_name LIKE '_transient_vt_rl_%'
	    OR option_name LIKE '_transient_timeout_vt_rl_%'"
);
