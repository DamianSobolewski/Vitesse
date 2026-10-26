<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Looks up Stage 1 gains for a vtech vehicle on external tuner sites
 * (ziptuning.nl, rica.nl). Used only in owner mode (charts="1").
 *
 * Vehicles are matched heuristically: brand/model by normalized name,
 * generation by production years + chassis code, engine by displacement,
 * base power (±5 KM) and fuel type.
 */
class VT_External {

	private const ZIP_BASE       = 'https://www.ziptuning.nl';
	private const RICA_GATEWAY   = 'https://rica.nl/wp-content/plugins/rica-api-connector/php/gateway.php';
	private const INDEX_TTL      = 7 * DAY_IN_SECONDS;
	private const MAX_GENS       = 3;
	private const MAX_HP_DIFF    = 5;

	private const DIESEL_RE = '/\b(\d{2,3}d|\d\.\dd|tdi|tdci|cdi|crdi?|hdi|bluehdi|dci|jtd\w*|multijet|cdti|d-?4d|sdi|td|tdv\d|td4|sd4|ed4|d[2-5]|i-?dtec|crd|did|diesel|ecoblue|bluetec|blue\s?motion\s?tdi)\b/i';
	private const PETROL_RE = '/\b(t?fsi|tsi|e-?tsi|\d{2,3}i|gti|thp|tce|ecoboost|vtec|t-?jet|vvt-?i?|mpi|t?-?gdi|tb|benzine|cgi|kompressor|puretech|skyactiv-g)\b/i';

	/**
	 * @return array{ziptuning:array|null,rica:array|null}|WP_Error
	 */
	public static function lookup( string $brand, string $model, string $gen, string $engine, string $year ): array|WP_Error {
		$vehicle = self::resolve_vehicle( $brand, $model, $gen, $engine, $year );
		if ( null === $vehicle ) {
			return new WP_Error( 'unknown_vehicle', 'Nie znaleziono pojazdu w drzewie.' );
		}

		$result = [];
		$errors = [];
		foreach ( [ 'ziptuning' => 'lookup_ziptuning', 'rica' => 'lookup_rica' ] as $key => $method ) {
			try {
				$found          = self::$method( $vehicle );
				$result[ $key ] = $found;
			} catch ( Exception $e ) {
				$result[ $key ] = null;
				$errors[]       = $key . ': ' . $e->getMessage();
			}
		}

		if ( $errors ) {
			// Network/parse failures — let the caller fall back to stale data.
			return new WP_Error( 'external_error', implode( '; ', $errors ), [ 'partial' => $result ] );
		}

		return $result;
	}

	// ── Vehicle from tree ───────────────────────────────────────────────────

	private static function resolve_vehicle( string $brand, string $model, string $gen, string $engine, string $year ): ?array {
		$tree = VT_Storage::get_tree();
		$m    = $tree['tree'][ $brand ]['models'][ $model ] ?? null;
		if ( null === $m ) {
			return null;
		}

		$target = $gen . '::' . $engine;
		$entry  = null;
		$years  = $m['years'] ?? [];
		// Prefer the selected year, fall back to any year with that engine.
		foreach ( array_merge( [ $year => $years[ $year ] ?? [] ], $years ) as $y ) {
			foreach ( $y['engines'] ?? [] as $e ) {
				if ( ( $e['value'] ?? '' ) === $target ) {
					$entry = $e;
					break 2;
				}
			}
		}
		if ( null === $entry ) {
			return null;
		}

		$eng = (string) ( $entry['engine'] ?? '' );
		return [
			'brand'        => (string) $tree['tree'][ $brand ]['label'],
			'brand_slug'   => $brand,
			'model'        => (string) $m['label'],
			'gen'          => (string) ( $entry['gen_label'] ?? '' ),
			'engine'       => $eng,
			'year'         => (int) $year,
			'hp'           => preg_match( '/(\d+)\s*KM\b/i', $eng, $mm ) ? (int) $mm[1] : 0,
			'displacement' => self::displacement( $eng ),
		];
	}

	// ── ziptuning.nl ────────────────────────────────────────────────────────

	private static function lookup_ziptuning( array $v ): ?array {
		// Brands.
		$brands = [];
		foreach ( self::html_links( self::fetch_cached( self::ZIP_BASE . '/chiptuning/' ), '#^/chiptuning/([a-z0-9-]+)/$#' ) as $l ) {
			$brands[ $l['m'][1] ] = $l['m'][1];
		}
		$brand_slug = self::match_brand( [ $v['brand'], $v['brand_slug'] ], $brands );
		if ( null === $brand_slug ) {
			return null;
		}

		// Models.
		$base   = '/chiptuning/' . $brand_slug . '/';
		$models = [];
		foreach ( self::zip_tiles( self::fetch_cached( self::ZIP_BASE . $base ), '#^' . preg_quote( $base, '#' ) . '[a-z0-9-]+/$#' ) as $t ) {
			$models[ $t['href'] ] = $t['name'];
		}
		$matched = self::match_models( $v['model'], $models, $v['brand'] );
		if ( ! $matched ) {
			return null;
		}

		// Generations.
		$gens = [];
		foreach ( $matched as $model_href => $suffix ) {
			foreach ( self::zip_tiles( self::fetch_cached( self::ZIP_BASE . $model_href ), '#^' . preg_quote( $model_href, '#' ) . '[a-z0-9-]+/$#' ) as $t ) {
				$range  = self::zip_gen_range( $t['name'] );
				$gens[] = [
					'href'  => $t['href'],
					'score' => self::gen_score( $v, $suffix . ' ' . $range['code'], $range['from'], $range['to'] ),
				];
			}
		}

		// Engines from the best-scoring generations.
		$best = null;
		foreach ( self::top_gens( $gens ) as $g ) {
			foreach ( self::zip_tiles( self::fetch_cached( self::ZIP_BASE . $g['href'] ), '#^/chiptuning/chiptuning-[a-z0-9-]+/$#' ) as $t ) {
				$hp = preg_match( '/(\d+)\s*pk/i', $t['name'], $mm ) ? (int) $mm[1]
					: ( preg_match( '/(\d+)\s*→/u', $t['sub'], $mm ) ? (int) $mm[1] : 0 );
				$score = self::engine_score( $v, $t['name'], $hp );
				if ( null !== $score && ( null === $best || $score + $g['score'] > $best['score'] ) ) {
					$best = [ 'href' => $t['href'], 'label' => $t['name'], 'score' => $score + $g['score'] ];
				}
			}
		}
		if ( null === $best ) {
			return null;
		}

		$url  = self::ZIP_BASE . $best['href'];
		$text = self::html_text( self::fetch( $url ) );
		if ( ! preg_match( '/Vermogen\s*\+\s*(\d+)\s*pk/iu', $text, $hp ) ) {
			throw new Exception( 'brak przyrostu mocy na stronie ' . $url );
		}
		preg_match( '/Koppel\s*\+\s*(\d+)\s*Nm/iu', $text, $nm );

		return [
			'hp'    => (int) $hp[1],
			'nm'    => isset( $nm[1] ) ? (int) $nm[1] : 0,
			'label' => $best['label'],
			'url'   => $url,
		];
	}

	/**
	 * Tiles on ziptuning listing pages: <a class="zip-tegel"> with
	 * .zip-tegel__naam (name) and .zip-tegel__sub (e.g. "200 → 301 pk").
	 */
	private static function zip_tiles( string $html, string $href_re ): array {
		$tiles = [];
		foreach ( self::html_links( $html, $href_re ) as $l ) {
			$name = $sub = '';
			foreach ( $l['node']->getElementsByTagName( 'span' ) as $span ) {
				$cls = ' ' . $span->getAttribute( 'class' ) . ' ';
				if ( str_contains( $cls, ' zip-tegel__naam ' ) ) {
					$name = trim( $span->textContent );
				} elseif ( str_contains( $cls, ' zip-tegel__sub ' ) ) {
					$sub = trim( $span->textContent );
				}
			}
			if ( '' !== $name ) {
				$tiles[ $l['href'] ] = [ 'href' => $l['href'], 'name' => $name, 'sub' => $sub ];
			}
		}
		return array_values( $tiles );
	}

	/**
	 * "8P Mk1 - 2003 -> 2008", "8Y - 10/2020 -> ...", "F2x LCI 2015 2019".
	 */
	private static function zip_gen_range( string $name ): array {
		$name = preg_replace( '#\b\d{1,2}/(\d{4})#', '$1', $name );
		if ( ! preg_match( '/(\d{4})\s*(?:->|→|-|>)?\s*(\d{4})?/u', $name, $m, PREG_OFFSET_CAPTURE ) ) {
			return [ 'code' => $name, 'from' => null, 'to' => null ];
		}
		return [
			'code' => trim( substr( $name, 0, $m[0][1] ), " -\t" ),
			'from' => (int) $m[1][0],
			'to'   => isset( $m[2] ) && '' !== $m[2][0] ? (int) $m[2][0] : null,
		];
	}

	// ── rica.nl ─────────────────────────────────────────────────────────────

	private static function lookup_rica( array $v ): ?array {
		$makes = [];
		foreach ( self::rica_list( self::rica_get( [ 'ep' => 'GetVehicles', 'mode' => 'MAKES' ], true ), 'DB_VehicleMakes' ) as $mk ) {
			$makes[ $mk['MakeID'] ] = $mk['Make'];
		}
		$make_id = self::match_brand( [ $v['brand'], $v['brand_slug'] ], $makes );
		if ( null === $make_id ) {
			return null;
		}

		$rows   = self::rica_list( self::rica_get( [ 'ep' => 'GetVehicles', 'mode' => 'MODELS', 'MakeID' => $make_id ], true ), 'DB_Models' );
		$names  = [];
		foreach ( $rows as $r ) {
			$names[ $r['Description'] ] = $r['Description'];
		}
		// Rica often puts the generation in the name: "Astra J", "Golf (7)".
		$matched = self::match_models( $v['model'], $names, $v['brand'] );
		if ( ! $matched ) {
			return null;
		}

		$gens = [];
		foreach ( $rows as $r ) {
			if ( ! isset( $matched[ $r['Description'] ] ) ) {
				continue;
			}
			// ModelType: "2003 - 2012 (8P)", "2018 - (GB)", "- 2017", "2015-".
			$type = is_string( $r['ModelType'] ?? null ) ? $r['ModelType'] : '';
			preg_match( '/^\s*(\d{4})?\s*-?\s*(\d{4})?/', $type, $ym );
			$code   = $matched[ $r['Description'] ] . ' ' . ( preg_match( '/\(([^)]*)\)/', $type, $cm ) ? $cm[1] : '' );
			$gens[] = [
				'id'    => $r['ModelID'],
				'score' => self::gen_score( $v, $code, ! empty( $ym[1] ) ? (int) $ym[1] : null, ! empty( $ym[2] ) ? (int) $ym[2] : null ),
			];
		}

		$best = null;
		foreach ( self::top_gens( $gens ) as $g ) {
			$variants = self::rica_list(
				self::rica_get( [ 'ep' => 'GetVehicles', 'mode' => 'VARIANTS', 'MakeID' => $make_id, 'ModelID' => $g['id'], 'cc' => 'NL' ], true ),
				'DB_Variants'
			);
			foreach ( $variants as $var ) {
				$label = preg_replace( '/\s+/', ' ', (string) $var['ModelVariant'] );
				$hp    = preg_match( '/(\d+)\s*(?:hp|pk)\b/i', $label, $mm ) ? (int) $mm[1] : 0;
				$score = self::engine_score( $v, $label, $hp );
				if ( null !== $score && ( null === $best || $score + $g['score'] > $best['score'] ) ) {
					$best = [ 'id' => $var['VehicleID'], 'label' => $label, 'score' => $score + $g['score'] ];
				}
			}
		}
		if ( null === $best ) {
			return null;
		}

		$data     = self::rica_get( [ 'ep' => 'GetVehicles', 'mode' => 'VEHICLE', 'VehicleID' => $best['id'], 'cc' => 'NL', 'lc' => 'nl' ], false );
		$products = $data['products']['DB_ProductInfo'] ?? [];
		if ( isset( $products['ProductID'] ) ) {
			$products = [ $products ];
		}

		// Prefer "Stage 1"; otherwise the first product with real gains.
		$pick = null;
		foreach ( (array) $products as $p ) {
			if ( (int) ( $p['MaxModPower'] ?? 0 ) <= (int) ( $p['MaxBasePower'] ?? 0 ) ) {
				continue;
			}
			if ( null === $pick || ( preg_match( '/stage\s*1/i', (string) ( $p['Category'] ?? '' ) ) && ! preg_match( '/stage\s*1/i', (string) ( $pick['Category'] ?? '' ) ) ) ) {
				$pick = $p;
			}
		}
		if ( null === $pick ) {
			return null;
		}

		return [
			'hp'    => (int) $pick['MaxModPower'] - (int) $pick['MaxBasePower'],
			'nm'    => max( 0, (int) $pick['MaxModTorque'] - (int) $pick['MaxBaseTorque'] ),
			'label' => $best['label'],
			'url'   => 'https://rica.nl/nl/chiptuning-nl/',
		];
	}

	private static function rica_get( array $params, bool $cache ): array {
		$url  = add_query_arg( array_map( 'rawurlencode', $params ), self::RICA_GATEWAY );
		$body = $cache ? self::fetch_cached( $url ) : self::fetch( $url );
		$data = json_decode( $body, true );
		if ( ! is_array( $data ) || 'success' !== ( $data['status'] ?? '' ) ) {
			throw new Exception( 'nieprawidłowa odpowiedź API rica' );
		}
		return $data;
	}

	/** API returns a single object instead of a one-element list. */
	private static function rica_list( array $data, string $key ): array {
		$list = $data['result'][ $key ] ?? [];
		return isset( $list[0] ) || [] === $list ? $list : [ $list ];
	}

	// ── Matching ────────────────────────────────────────────────────────────

	private static function norm( string $s ): string {
		$s = strtolower( remove_accents( $s ) );
		$s = str_replace( 'gran turismo', 'gt', $s );
		$s = preg_replace( '/\b(series|serie|class|klasse|klasa)\b/', '', $s );
		return preg_replace( '/[^a-z0-9]/', '', $s );
	}

	/** @param string[] $candidates key => name */
	private static function match_brand( array $names, array $candidates ): ?string {
		$wanted = array_filter( array_map( [ self::class, 'norm' ], $names ) );
		foreach ( $candidates as $key => $name ) {
			if ( in_array( self::norm( $name ), $wanted, true ) ) {
				return (string) $key;
			}
		}
		// "Mercedes" ↔ "Mercedes Benz", "Opel" ↔ "Opel / Vauxhall".
		foreach ( $candidates as $key => $name ) {
			$n = self::norm( $name );
			foreach ( $wanted as $w ) {
				if ( '' !== $n && ( str_starts_with( $n, $w ) || str_starts_with( $w, $n ) ) ) {
					return (string) $key;
				}
			}
		}
		return null;
	}

	/**
	 * Returns matching candidates as key => generation suffix.
	 * Exact name matches win ("A3" → "A3", suffix ''). Otherwise names that
	 * start with the model are accepted and the rest is treated as a
	 * generation hint ("Golf (7)" → '(7)', "Astra J" → 'J').
	 * Candidate names may hold aliases: "E / E Coupé", "A / A Berline"
	 * and the brand: "Mazda 6".
	 */
	private static function match_models( string $model, array $candidates, string $brand ): array {
		$wanted = self::norm( $model );
		$exact  = [];
		$prefix = [];
		if ( '' === $wanted ) {
			return [];
		}
		foreach ( $candidates as $key => $name ) {
			foreach ( explode( '/', $name ) as $alias ) {
				$alias = preg_replace( '/^\s*' . preg_quote( $brand, '/' ) . '\s+/iu', '', $alias );
				$n     = self::norm( $alias );
				if ( $n === $wanted ) {
					$exact[ $key ] = '';
					break;
				}
				// Suffix must start with a separator: "Golf (7)" yes, "Golfino" no.
				if ( preg_match( '/^\s*' . preg_quote( trim( $model ), '/' ) . '[\s(\-]+(.+)$/iu', trim( $alias ), $m ) ) {
					$prefix[ $key ] = $m[1];
				}
			}
		}
		return $exact ?: $prefix;
	}

	private static function gen_score( array $v, string $code, ?int $from, ?int $to ): int {
		$score = 0;
		if ( null !== $from || null !== $to ) {
			if ( ( null === $from || $v['year'] >= $from ) && ( null === $to || $v['year'] <= $to ) ) {
				$score += 2;
			} elseif ( ( null === $from || $v['year'] >= $from - 1 ) && ( null === $to || $v['year'] <= $to + 1 ) ) {
				// Model years rarely line up exactly between sources.
				$score += 1;
			}
		}
		$ours = self::codes( $v['gen'] );
		foreach ( self::codes( $code ) as $c ) {
			// "E8x" / "F2x" are wildcards for E81, F20…
			$re = '/^' . preg_replace( '/(?<=\d)x/', '[0-9a-z]', preg_quote( $c, '/' ) ) . '$/';
			foreach ( $ours as $o ) {
				if ( preg_match( $re, $o ) ) {
					$score += 3;
					break 2;
				}
			}
		}
		return $score;
	}

	/**
	 * Generation-code-like tokens: "8P", "W204", "E90/E91", "F2x", "J",
	 * roman numerals as digits ("VII" → "7", so it matches "Golf (7)").
	 */
	private static function codes( string $s ): array {
		$roman = [ 'i' => '1', 'ii' => '2', 'iii' => '3', 'iv' => '4', 'v' => '5', 'vi' => '6', 'vii' => '7', 'viii' => '8', 'ix' => '9', 'x' => '10' ];
		$out   = [];
		foreach ( preg_split( '/[^a-z0-9]+/', strtolower( $s ), -1, PREG_SPLIT_NO_EMPTY ) as $t ) {
			if ( isset( $roman[ $t ] ) ) {
				$out[] = $roman[ $t ];
			} elseif ( 1 === strlen( $t ) || ( preg_match( '/^[a-z]{0,3}\d[0-9a-z]*$/', $t ) && ! preg_match( '/^(\d{4}|mk\d|ph\d)$/', $t ) ) ) {
				$out[] = $t;
			}
		}
		return array_unique( $out );
	}

	private static function top_gens( array $gens ): array {
		// A model with a single generation needs no year/code evidence.
		if ( 1 === count( $gens ) ) {
			return $gens;
		}
		$gens = array_filter( $gens, static fn( $g ) => $g['score'] > 0 );
		usort( $gens, static fn( $a, $b ) => $b['score'] <=> $a['score'] );
		return array_slice( $gens, 0, self::MAX_GENS );
	}

	/** Returns null when the engine clearly doesn't match. */
	private static function engine_score( array $v, string $label, int $hp ): ?int {
		if ( ! $hp || ! $v['hp'] ) {
			return null;
		}
		$diff = abs( $hp - $v['hp'] );
		if ( $diff > self::MAX_HP_DIFF ) {
			return null;
		}

		$disp = self::displacement( $label );
		if ( $disp && $v['displacement'] && $disp !== $v['displacement'] ) {
			return null;
		}

		$our_diesel   = (bool) preg_match( self::DIESEL_RE, $v['engine'] );
		$their_diesel = (bool) preg_match( self::DIESEL_RE, $label );
		if ( $our_diesel !== $their_diesel
			&& ( $our_diesel ? preg_match( self::PETROL_RE, $label ) : preg_match( self::PETROL_RE, $v['engine'] ) ) ) {
			return null;
		}

		$score  = 10 - $diff;
		$score += $our_diesel === $their_diesel ? 2 : 0;
		$score += 2 * count( array_intersect( self::words( $v['engine'] ), self::words( $label ) ) );
		return $score;
	}

	private static function words( string $s ): array {
		preg_match_all( '/[a-z][a-z0-9-]*|\d{2,3}[a-z]+/i', strtolower( $s ), $m );
		return array_unique( array_diff( $m[0], [ 'kw', 'km', 'hp', 'pk', 'ps' ] ) );
	}

	private static function displacement( string $s ): ?string {
		return preg_match( '/\b(\d\.\d)\d?\b/', $s, $m ) ? $m[1] : null;
	}

	// ── HTTP / HTML helpers ─────────────────────────────────────────────────

	private static function fetch( string $url ): string {
		$response = wp_remote_get(
			$url,
			[
				'timeout'    => 10,
				'user-agent' => 'Mozilla/5.0 (compatible; VTKonfigurator/1.0; +' . get_site_url() . ')',
			]
		);
		if ( is_wp_error( $response ) ) {
			throw new Exception( $response->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			throw new Exception( sprintf( 'HTTP %d dla URL: %s', $code, $url ) );
		}
		return (string) wp_remote_retrieve_body( $response );
	}

	/** Listing pages change rarely — keep them for a week. */
	private static function fetch_cached( string $url ): string {
		$key  = VT_CACHE_PREFIX . 'idx_' . md5( $url );
		$body = get_transient( $key );
		if ( false === $body ) {
			$body = self::fetch( $url );
			set_transient( $key, $body, self::INDEX_TTL );
		}
		return (string) $body;
	}

	/** @return array<int,array{href:string,m:array,node:DOMElement}> */
	private static function html_links( string $html, string $href_re ): array {
		libxml_use_internal_errors( true );
		$dom = new DOMDocument();
		$dom->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR );
		libxml_clear_errors();

		$links = [];
		foreach ( $dom->getElementsByTagName( 'a' ) as $a ) {
			$href = preg_replace( '#^https?://(www\.)?ziptuning\.nl#', '', $a->getAttribute( 'href' ) );
			if ( preg_match( $href_re, $href, $m ) ) {
				$links[] = [ 'href' => $href, 'm' => $m, 'node' => $a ];
			}
		}
		return $links;
	}

	private static function html_text( string $html ): string {
		$html = preg_replace( '#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $html );
		$text = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return preg_replace( '/\s+/u', ' ', $text );
	}
}
