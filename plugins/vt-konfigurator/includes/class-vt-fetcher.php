<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VT_Fetcher {

	private static function user_agent(): string {
		return 'VTKonfigurator/1.0 (+' . get_site_url() . ')';
	}

	// ── Vehicle tree ────────────────────────────────────────────────────────

	/**
	 * Fetches the vehicle tree from the vtech configurator page.
	 *
	 * @return array{success:true,json:string,meta:array}|array{success:false,message:string}
	 */
	public static function refresh_tree( string $source_url ): array {
		$response = wp_remote_get(
			$source_url,
			[
				'timeout'    => 30,
				'user-agent' => self::user_agent(),
			]
		);

		if ( is_wp_error( $response ) ) {
			return [ 'success' => false, 'message' => $response->get_error_message() ];
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return [ 'success' => false, 'message' => sprintf( 'HTTP %d', $code ) ];
		}

		$body  = wp_remote_retrieve_body( $response );
		$start = strpos( $body, 'var vtFitmentSearch = ' );

		if ( false === $start ) {
			return [ 'success' => false, 'message' => 'Nie znaleziono var vtFitmentSearch w źródle strony.' ];
		}

		$start   += strlen( 'var vtFitmentSearch = ' );
		$json_str = self::extract_json_object( $body, $start );

		if ( null === $json_str ) {
			return [ 'success' => false, 'message' => 'Nie udało się wyekstrahować JSON z var vtFitmentSearch.' ];
		}

		$data = json_decode( $json_str, true );

		if ( JSON_ERROR_NONE !== json_last_error() || ! isset( $data['tree'] ) || ! is_array( $data['tree'] ) ) {
			return [ 'success' => false, 'message' => 'Błąd parsowania JSON: ' . json_last_error_msg() ];
		}

		return [
			'success' => true,
			'json'    => $json_str,
			'meta'    => self::compute_meta( $data['tree'], $json_str ),
		];
	}

	/**
	 * Extracts a JSON object from $body starting at $offset using brace matching.
	 * Handles nested objects and strings with escaped quotes.
	 */
	private static function extract_json_object( string $body, int $offset ): ?string {
		$depth     = 0;
		$in_string = false;
		$escaped   = false;
		$len       = strlen( $body );
		$begin     = $offset;

		for ( $i = $offset; $i < $len; $i++ ) {
			$c = $body[ $i ];

			if ( $escaped ) {
				$escaped = false;
				continue;
			}
			if ( $in_string ) {
				if ( '\\' === $c ) {
					$escaped = true;
				} elseif ( '"' === $c ) {
					$in_string = false;
				}
				continue;
			}
			if ( '"' === $c ) {
				$in_string = true;
			} elseif ( '{' === $c ) {
				++$depth;
			} elseif ( '}' === $c ) {
				--$depth;
				if ( 0 === $depth ) {
					return substr( $body, $begin, $i - $begin + 1 );
				}
			}
		}

		return null;
	}

	private static function compute_meta( array $tree, string $json_str ): array {
		$combinations = 0;
		foreach ( $tree as $brand ) {
			foreach ( $brand['models'] ?? [] as $model ) {
				foreach ( $model['years'] ?? [] as $year ) {
					$combinations += count( $year['engines'] ?? [] );
				}
			}
		}
		return [
			'last_refresh'       => time(),
			'size_bytes'         => strlen( $json_str ),
			'brands_count'       => count( $tree ),
			'combinations_count' => $combinations,
		];
	}

	// ── Result page ─────────────────────────────────────────────────────────

	/**
	 * Fetches and parses a result page for a specific vehicle/engine combination.
	 *
	 * @return array{powerchip:array{hp:int,nm:int}|null,chip_tuning:array{hp:int,nm:int}|null}|WP_Error
	 */
	public static function fetch_result(
		string $brand,
		string $model,
		string $gen,
		string $engine,
		string $year
	): array|WP_Error {
		$url = sprintf(
			'https://sklep.vtech.pl/powerchip/%s/%s/%s/%s/?vehicle_year=%s',
			rawurlencode( $brand ),
			rawurlencode( $model ),
			rawurlencode( $gen ),
			rawurlencode( $engine ),
			rawurlencode( $year )
		);

		$response = wp_remote_get(
			$url,
			[
				'timeout'    => 15,
				'user-agent' => self::user_agent(),
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return new WP_Error( 'http_error', sprintf( 'HTTP %d dla URL: %s', $code, $url ) );
		}

		return self::parse_result_page( wp_remote_retrieve_body( $response ) );
	}

	/**
	 * Parses the vtech result page HTML.
	 * Returns an array with 'powerchip' and 'chip_tuning' keys,
	 * each containing {hp, nm} or null if the product was not found.
	 */
	private static function parse_result_page( string $html ): array {
		libxml_use_internal_errors( true );
		$dom = new DOMDocument();
		$dom->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR );
		libxml_clear_errors();

		$xpath = new DOMXPath( $dom );

		// Collect all product name nodes.
		$name_nodes = $xpath->query( '//*[contains(@class,"vt-fitment-product-name")]' );

		if ( 0 === $name_nodes->length ) {
			VT_Storage::log_error(
				'Parsowanie wyniku: nie znaleziono węzłów .vt-fitment-product-name. ' .
				'Możliwa zmiana struktury strony vtech.'
			);
			return [ 'powerchip' => null, 'chip_tuning' => null ];
		}

		$cards = [
			'powerchip'   => [],
			'chip_tuning' => [],
		];

		foreach ( $name_nodes as $name_node ) {
			$text = trim( $name_node->textContent );

			if ( false !== stripos( $text, 'PowerChip' ) ) {
				$key = 'powerchip';
			} elseif ( false !== stripos( $text, 'Chip Tuning' ) ) {
				$key = 'chip_tuning';
			} else {
				continue;
			}

			$gains = self::find_gains( $xpath, $name_node );
			if ( null !== $gains ) {
				$chart = self::find_chart_url( $xpath, $name_node );
				if ( $chart ) {
					$gains['chart_url'] = $chart;
				}
				$cards[ $key ][] = $gains;
			}
		}

		return [
			'powerchip'   => ! empty( $cards['powerchip'] ) ? end( $cards['powerchip'] ) : null,
			'chip_tuning' => ! empty( $cards['chip_tuning'] ) ? end( $cards['chip_tuning'] ) : null,
		];
	}

	/**
	 * Walks up the DOM tree from $name_node to find the card container
	 * that holds gain spans, then reads data-count-to attributes.
	 */
	private static function find_gains( DOMXPath $xpath, DOMNode $name_node ): ?array {
		$container = $name_node->parentNode;

		for ( $i = 0; $i < 6; $i++ ) {
			if ( ! $container instanceof DOMElement ) {
				break;
			}

			$hp_nodes = $xpath->query(
				'.//*[contains(@class,"vt-fitment-gain-value") and contains(@class,"hp")]',
				$container
			);
			$nm_nodes = $xpath->query(
				'.//*[contains(@class,"vt-fitment-gain-value") and contains(@class,"nm")]',
				$container
			);

			if ( $hp_nodes->length > 0 || $nm_nodes->length > 0 ) {
				return [
					'hp' => $hp_nodes->length > 0
						? (int) $hp_nodes->item( 0 )->getAttribute( 'data-count-to' )
						: 0,
					'nm' => $nm_nodes->length > 0
						? (int) $nm_nodes->item( 0 )->getAttribute( 'data-count-to' )
						: 0,
				];
			}

			$container = $container->parentNode;
		}

		return null;
	}

	/**
	 * Walks up the DOM from $name_node to find a .vt-fitment-chart-link
	 * within the same card container and returns its data-vt-modal-src value.
	 */
	private static function find_chart_url( DOMXPath $xpath, DOMNode $name_node ): ?string {
		$container = $name_node->parentNode;

		for ( $i = 0; $i < 8; $i++ ) {
			if ( ! $container instanceof DOMElement ) {
				break;
			}

			$nodes = $xpath->query( './/*[contains(@class,"vt-fitment-chart-link")]', $container );
			if ( $nodes->length > 0 ) {
				$src = $nodes->item( 0 )->getAttribute( 'data-vt-modal-src' );
				if ( $src ) {
					return esc_url_raw( $src );
				}
			}

			$container = $container->parentNode;
		}

		return null;
	}
}
