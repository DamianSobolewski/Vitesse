<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VT_Ajax {

	// Transient key for per-IP rate limiting.
	private const RATE_LIMIT_MAX     = 30;
	private const RATE_LIMIT_WINDOW  = 60; // seconds

	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		register_rest_route(
			'vt/v1',
			'/result',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'handle_result' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'brand'  => [
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					],
					'model'  => [
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					],
					'gen'    => [
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					],
					'engine' => [
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					],
					'year'   => [
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);
	}

	public function handle_result( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$rate_error = $this->check_rate_limit();
		if ( is_wp_error( $rate_error ) ) {
			return $rate_error;
		}

		$brand  = $request->get_param( 'brand' );
		$model  = $request->get_param( 'model' );
		$gen    = $request->get_param( 'gen' );
		$engine = $request->get_param( 'engine' );
		$year   = $request->get_param( 'year' );

		// Strict slug validation — only chars that appear in vtech slugs.
		$slug_re = '/^[a-z0-9][a-z0-9\-\.]*$/i';
		foreach ( [ 'brand' => $brand, 'model' => $model, 'gen' => $gen, 'engine' => $engine ] as $name => $val ) {
			if ( ! preg_match( $slug_re, $val ) ) {
				return new WP_Error(
					'invalid_param',
					/* translators: %s: parameter name */
					sprintf( __( 'Nieprawidłowy parametr: %s', 'vt-konfigurator' ), $name ),
					[ 'status' => 400 ]
				);
			}
		}
		if ( ! preg_match( '/^\d{4}$/', $year ) ) {
			return new WP_Error(
				'invalid_param',
				__( 'Nieprawidłowy rocznik.', 'vt-konfigurator' ),
				[ 'status' => 400 ]
			);
		}

		$cache_key = md5( "$brand|$model|$gen|$engine|$year" );
		$settings  = VT_Storage::get_settings();
		$use_cache = '1' === $settings['cache_enabled'];

		if ( $use_cache ) {
			$cached = VT_Storage::get_cached_result( $cache_key );
			if ( null !== $cached ) {
				$cached['_source'] = 'cache';
				return new WP_REST_Response( $cached, 200 );
			}
		}

		$result = VT_Fetcher::fetch_result( $brand, $model, $gen, $engine, $year );

		if ( is_wp_error( $result ) ) {
			VT_Storage::log_error(
				sprintf( 'Błąd fetch_result [%s/%s/%s/%s/%s]: %s', $brand, $model, $gen, $engine, $year, $result->get_error_message() )
			);

			// Return stale data rather than an error when available.
			if ( $use_cache ) {
				$stale = VT_Storage::get_stale_result( $cache_key );
				if ( null !== $stale ) {
					$stale['_source'] = 'stale';
					return new WP_REST_Response( $stale, 200 );
				}
			}

			return new WP_Error(
				'fetch_error',
				__( 'Nie udało się pobrać wyników. Spróbuj ponownie później.', 'vt-konfigurator' ),
				[ 'status' => 503 ]
			);
		}

		if ( $use_cache ) {
			VT_Storage::set_cached_result( $cache_key, $result, (int) $settings['cache_ttl'] );
		}

		$result['_source'] = 'live';
		return new WP_REST_Response( $result, 200 );
	}

	// ── Rate limiting ────────────────────────────────────────────────────────

	private function check_rate_limit(): true|WP_Error {
		$ip  = $this->get_client_ip();
		$key = 'vt_rl_' . md5( $ip );

		$count = (int) get_transient( $key );
		if ( $count >= self::RATE_LIMIT_MAX ) {
			return new WP_Error(
				'rate_limit',
				__( 'Zbyt wiele zapytań. Odczekaj chwilę i spróbuj ponownie.', 'vt-konfigurator' ),
				[ 'status' => 429 ]
			);
		}

		if ( 0 === $count ) {
			set_transient( $key, 1, self::RATE_LIMIT_WINDOW );
		} else {
			// Increment without resetting TTL.
			set_transient( $key, $count + 1, self::RATE_LIMIT_WINDOW );
		}

		return true;
	}

	private function get_client_ip(): string {
		// Check common proxy headers in order of preference.
		foreach ( [ 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' ] as $header ) {
			if ( ! empty( $_SERVER[ $header ] ) ) {
				$ip = sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) );
				// X-Forwarded-For may contain a comma-separated list; take the first.
				if ( str_contains( $ip, ',' ) ) {
					$ip = trim( explode( ',', $ip )[0] );
				}
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
			}
		}
		return '0.0.0.0';
	}
}
