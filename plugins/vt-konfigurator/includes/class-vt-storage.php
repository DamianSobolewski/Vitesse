<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VT_Storage {

	// ── Tree ────────────────────────────────────────────────────────────────

	public static function get_tree_raw(): string {
		return (string) get_option( VT_OPTION_TREE, '' );
	}

	public static function get_tree(): ?array {
		$raw = self::get_tree_raw();
		if ( '' === $raw ) {
			return null;
		}
		$data = json_decode( $raw, true );
		return ( JSON_ERROR_NONE === json_last_error() && isset( $data['tree'] ) ) ? $data : null;
	}

	public static function save_tree( string $json ): void {
		update_option( VT_OPTION_TREE, $json, 'no' );
	}

	// ── Tree meta ───────────────────────────────────────────────────────────

	public static function get_tree_meta(): array {
		return (array) get_option( VT_OPTION_TREE_META, [] );
	}

	public static function save_tree_meta( array $meta ): void {
		update_option( VT_OPTION_TREE_META, $meta, 'yes' );
	}

	// ── Settings ────────────────────────────────────────────────────────────

	public static function get_settings(): array {
		$defaults = [
			'source_url'     => 'https://sklep.vtech.pl/konfigurator-powerchip/',
			'accent_color'   => '#e53e3e',
			'border_radius'  => '8',
			'load_css'       => '1',
			'cache_enabled'  => '1',
			'cache_ttl'      => '7',
			'phone_1_number' => '',
			'phone_1_name'   => '',
			'phone_2_number' => '',
			'phone_2_name'   => '',
			'drive_url'      => '',
		];
		return wp_parse_args( (array) get_option( VT_OPTION_SETTINGS, [] ), $defaults );
	}

	public static function save_settings( array $settings ): void {
		update_option( VT_OPTION_SETTINGS, $settings, 'yes' );
	}

	// ── Result cache ────────────────────────────────────────────────────────

	public static function get_cached_result( string $key ): ?array {
		$val = get_transient( VT_CACHE_PREFIX . $key );
		return false === $val ? null : (array) $val;
	}

	public static function set_cached_result( string $key, array $data, int $ttl_days ): void {
		set_transient( VT_CACHE_PREFIX . $key, $data, max( 1, $ttl_days ) * DAY_IN_SECONDS );
		// Stale backup (no expiry) — used when live fetch fails.
		update_option( VT_STALE_PREFIX . $key, $data, 'no' );
	}

	public static function get_stale_result( string $key ): ?array {
		$val = get_option( VT_STALE_PREFIX . $key, false );
		return false === $val ? null : (array) $val;
	}

	public static function delete_all_result_cache(): int {
		global $wpdb;
		$deleted = (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_' . VT_CACHE_PREFIX ) . '%',
				$wpdb->esc_like( '_transient_timeout_' . VT_CACHE_PREFIX ) . '%'
			)
		);
		return $deleted;
	}

	// ── Error log ───────────────────────────────────────────────────────────

	public static function log_error( string $message ): void {
		$log   = (array) get_option( VT_OPTION_ERROR_LOG, [] );
		array_unshift(
			$log,
			[
				'time'    => current_time( 'mysql' ),
				'message' => $message,
			]
		);
		update_option( VT_OPTION_ERROR_LOG, array_slice( $log, 0, 20 ), 'no' );
	}

	public static function get_error_log(): array {
		return (array) get_option( VT_OPTION_ERROR_LOG, [] );
	}

	public static function clear_error_log(): void {
		delete_option( VT_OPTION_ERROR_LOG );
	}
}
