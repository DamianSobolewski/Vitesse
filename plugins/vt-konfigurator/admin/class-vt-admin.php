<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VT_Admin {

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'add_menu' ] );
		add_action( 'admin_init', [ $this, 'handle_actions' ] );
		add_action( 'admin_notices', [ $this, 'show_notices' ] );
	}

	public function add_menu(): void {
		add_options_page(
			__( 'VT Konfigurator', 'vt-konfigurator' ),
			__( 'VT Konfigurator', 'vt-konfigurator' ),
			'manage_options',
			'vt-konfigurator',
			[ $this, 'render_page' ]
		);
	}

	// ── Action handling ──────────────────────────────────────────────────────

	public function handle_actions(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( empty( $_POST['vt_action'] ) ) {
			return;
		}

		$action = sanitize_key( $_POST['vt_action'] );

		match ( $action ) {
			'refresh_tree'  => $this->action_refresh_tree(),
			'save_settings' => $this->action_save_settings(),
			'clear_cache'   => $this->action_clear_cache(),
			'clear_log'     => $this->action_clear_log(),
			default         => null,
		};
	}

	private function action_refresh_tree(): void {
		check_admin_referer( 'vt_refresh_tree' );

		$settings   = VT_Storage::get_settings();
		$source_url = esc_url_raw( $settings['source_url'] );

		$result = VT_Fetcher::refresh_tree( $source_url );

		if ( ! $result['success'] ) {
			$this->add_notice( 'error', sprintf(
				/* translators: %s: error message */
				__( 'Błąd odświeżania: %s', 'vt-konfigurator' ),
				esc_html( $result['message'] )
			) );
			VT_Storage::log_error( 'refresh_tree: ' . $result['message'] );
			return;
		}

		// Safety check: new data must have ≥ 80 % of previous combinations.
		$existing_meta = VT_Storage::get_tree_meta();
		$old_count     = (int) ( $existing_meta['combinations_count'] ?? 0 );
		$new_count     = (int) $result['meta']['combinations_count'];

		if ( $old_count > 0 && $new_count < (int) round( $old_count * 0.8 ) ) {
			$this->add_notice( 'warning', sprintf(
				__( 'Dane NIE zostały zapisane. Nowe dane zawierają tylko %1$d kombinacji (poprzednio: %2$d). Prawdopodobna zmiana struktury strony vtech.', 'vt-konfigurator' ),
				$new_count,
				$old_count
			) );
			VT_Storage::log_error( sprintf(
				'Bezpiecznik: nowe kombinacje (%d) < 80%% poprzednich (%d). Drzewo nie zapisano.',
				$new_count,
				$old_count
			) );
			return;
		}

		VT_Storage::save_tree( $result['json'] );
		VT_Storage::save_tree_meta( $result['meta'] );

		$this->add_notice( 'success', sprintf(
			__( 'Dane odświeżone pomyślnie. Marki: %1$d · Kombinacje: %2$d · Rozmiar: %3$s', 'vt-konfigurator' ),
			$result['meta']['brands_count'],
			$result['meta']['combinations_count'],
			size_format( $result['meta']['size_bytes'] )
		) );
	}

	private function action_save_settings(): void {
		check_admin_referer( 'vt_save_settings' );

		$raw      = $_POST['vt'] ?? [];
		$settings = VT_Storage::get_settings();

		$settings['source_url']     = esc_url_raw( $raw['source_url'] ?? $settings['source_url'] );
		$settings['accent_color']   = sanitize_hex_color( $raw['accent_color'] ?? '' ) ?: $settings['accent_color'];
		$settings['border_radius']  = (string) absint( $raw['border_radius'] ?? $settings['border_radius'] );
		$settings['load_css']       = isset( $raw['load_css'] ) ? '1' : '0';
		$settings['cache_enabled']  = isset( $raw['cache_enabled'] ) ? '1' : '0';
		$settings['cache_ttl']      = (string) max( 1, absint( $raw['cache_ttl'] ?? $settings['cache_ttl'] ) );
		$settings['phone_1_number'] = sanitize_text_field( $raw['phone_1_number'] ?? '' );
		$settings['phone_1_name']   = sanitize_text_field( $raw['phone_1_name']   ?? '' );
		$settings['phone_2_number'] = sanitize_text_field( $raw['phone_2_number'] ?? '' );
		$settings['phone_2_name']   = sanitize_text_field( $raw['phone_2_name']   ?? '' );
		$settings['drive_url']      = esc_url_raw( $raw['drive_url'] ?? '' );

		VT_Storage::save_settings( $settings );
		$this->add_notice( 'success', __( 'Ustawienia zapisane.', 'vt-konfigurator' ) );
	}

	private function action_clear_cache(): void {
		check_admin_referer( 'vt_clear_cache' );
		$count = VT_Storage::delete_all_result_cache();
		$this->add_notice( 'success', sprintf(
			/* translators: %d: number of deleted entries */
			__( 'Cache wyczyszczony (%d wpisów usuniętych).', 'vt-konfigurator' ),
			$count
		) );
	}

	private function action_clear_log(): void {
		check_admin_referer( 'vt_clear_log' );
		VT_Storage::clear_error_log();
		$this->add_notice( 'success', __( 'Log błędów wyczyszczony.', 'vt-konfigurator' ) );
	}

	// ── Transient notices ────────────────────────────────────────────────────

	private function add_notice( string $type, string $message ): void {
		$notices   = get_transient( 'vt_admin_notices_' . get_current_user_id() ) ?: [];
		$notices[] = compact( 'type', 'message' );
		set_transient( 'vt_admin_notices_' . get_current_user_id(), $notices, 30 );
		// Redirect to prevent form re-submission on refresh.
		wp_safe_redirect( admin_url( 'options-general.php?page=vt-konfigurator' ) );
		exit;
	}

	public function show_notices(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$key     = 'vt_admin_notices_' . get_current_user_id();
		$notices = get_transient( $key );
		if ( empty( $notices ) ) {
			return;
		}
		delete_transient( $key );
		foreach ( $notices as $notice ) {
			$class = 'notice notice-' . esc_attr( $notice['type'] ) . ' is-dismissible';
			printf( '<div class="%s"><p>%s</p></div>', $class, wp_kses_post( $notice['message'] ) );
		}
	}

	// ── Page render ──────────────────────────────────────────────────────────

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Brak uprawnień.', 'vt-konfigurator' ) );
		}

		$settings = VT_Storage::get_settings();
		$meta     = VT_Storage::get_tree_meta();
		$log      = VT_Storage::get_error_log();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'VT Konfigurator — Ustawienia', 'vt-konfigurator' ); ?></h1>

			<?php /* ── Sekcja 1: Odśwież drzewo ─────────────────────────────── */ ?>
			<h2><?php esc_html_e( 'Dane pojazdów', 'vt-konfigurator' ); ?></h2>
			<?php if ( ! empty( $meta ) ) : ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Stan drzewa', 'vt-konfigurator' ); ?></th>
					<td>
						<ul>
							<li><?php
								printf(
									/* translators: %s: date */
									esc_html__( 'Ostatnie odświeżenie: %s', 'vt-konfigurator' ),
									esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $meta['last_refresh'] ) )
								);
							?></li>
							<li><?php printf( esc_html__( 'Rozmiar danych: %s', 'vt-konfigurator' ), esc_html( size_format( $meta['size_bytes'] ) ) ); ?></li>
							<li><?php printf( esc_html__( 'Marek: %d', 'vt-konfigurator' ), (int) $meta['brands_count'] ); ?></li>
							<li><?php printf( esc_html__( 'Kombinacji: %d', 'vt-konfigurator' ), (int) $meta['combinations_count'] ); ?></li>
						</ul>
					</td>
				</tr>
			</table>
			<?php endif; ?>

			<form method="post" style="margin-bottom:2rem">
				<?php wp_nonce_field( 'vt_refresh_tree' ); ?>
				<input type="hidden" name="vt_action" value="refresh_tree">
				<?php submit_button( __( 'Odśwież dane pojazdów', 'vt-konfigurator' ), 'primary', 'submit', false ); ?>
			</form>

			<?php /* ── Sekcja 2: Wygląd + URL źródłowy + Cache ─────────────── */ ?>
			<h2><?php esc_html_e( 'Wygląd', 'vt-konfigurator' ); ?></h2>
			<form method="post">
				<?php wp_nonce_field( 'vt_save_settings' ); ?>
				<input type="hidden" name="vt_action" value="save_settings">
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="vt_source_url"><?php esc_html_e( 'URL źródłowy', 'vt-konfigurator' ); ?></label>
						</th>
						<td>
							<input
								type="url"
								name="vt[source_url]"
								id="vt_source_url"
								value="<?php echo esc_attr( $settings['source_url'] ); ?>"
								class="regular-text"
							>
							<p class="description"><?php esc_html_e( 'Strona konfiguratora vtech, z której pobierane jest drzewo pojazdów.', 'vt-konfigurator' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="vt_accent_color"><?php esc_html_e( 'Kolor akcentu', 'vt-konfigurator' ); ?></label>
						</th>
						<td>
							<input
								type="color"
								name="vt[accent_color]"
								id="vt_accent_color"
								value="<?php echo esc_attr( $settings['accent_color'] ); ?>"
							>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="vt_border_radius"><?php esc_html_e( 'Zaokrąglenie rogów (px)', 'vt-konfigurator' ); ?></label>
						</th>
						<td>
							<input
								type="number"
								name="vt[border_radius]"
								id="vt_border_radius"
								value="<?php echo esc_attr( $settings['border_radius'] ); ?>"
								min="0"
								max="50"
								class="small-text"
							>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Ładuj własny CSS', 'vt-konfigurator' ); ?></th>
						<td>
							<label>
								<input
									type="checkbox"
									name="vt[load_css]"
									value="1"
									<?php checked( '1', $settings['load_css'] ); ?>
								>
								<?php esc_html_e( 'Włącz (odznacz, jeśli chcesz stylować widżet od zera w motywie)', 'vt-konfigurator' ); ?>
							</label>
						</td>
					</tr>
				</table>

				<?php /* ── Sekcja 3: Kontakt ─────────────────────────────────── */ ?>
				<h2><?php esc_html_e( 'Numery kontaktowe', 'vt-konfigurator' ); ?></h2>
				<p class="description" style="margin-bottom:1rem"><?php esc_html_e( 'Numery wyświetlane pod wynikami tuningu jako klikalne linki tel:. Jeżeli pole jest puste, numer nie będzie wyświetlany.', 'vt-konfigurator' ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Telefon 1', 'vt-konfigurator' ); ?></th>
						<td>
							<input
								type="text"
								name="vt[phone_1_name]"
								value="<?php echo esc_attr( $settings['phone_1_name'] ); ?>"
								placeholder="<?php esc_attr_e( 'Imię / nazwa (np. Marek)', 'vt-konfigurator' ); ?>"
								class="regular-text"
								style="margin-bottom:6px"
							>
							<br>
							<input
								type="tel"
								name="vt[phone_1_number]"
								value="<?php echo esc_attr( $settings['phone_1_number'] ); ?>"
								placeholder="<?php esc_attr_e( 'Numer (np. +48 600 100 200)', 'vt-konfigurator' ); ?>"
								class="regular-text"
							>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Telefon 2', 'vt-konfigurator' ); ?></th>
						<td>
							<input
								type="text"
								name="vt[phone_2_name]"
								value="<?php echo esc_attr( $settings['phone_2_name'] ); ?>"
								placeholder="<?php esc_attr_e( 'Imię / nazwa (np. Tomek)', 'vt-konfigurator' ); ?>"
								class="regular-text"
								style="margin-bottom:6px"
							>
							<br>
							<input
								type="tel"
								name="vt[phone_2_number]"
								value="<?php echo esc_attr( $settings['phone_2_number'] ); ?>"
								placeholder="<?php esc_attr_e( 'Numer (np. +48 600 100 201)', 'vt-konfigurator' ); ?>"
								class="regular-text"
							>
						</td>
					</tr>
				</table>

				<?php /* ── Sekcja 4: Prywatny link Drive ──────────────────────── */ ?>
				<h2><?php esc_html_e( 'Prywatny link do wykresów', 'vt-konfigurator' ); ?></h2>
				<p class="description" style="margin-bottom:1rem"><?php esc_html_e( 'Link do folderu Google Drive z prywatnymi wykresami. Widoczny wyłącznie dla zalogowanego administratora — nie jest przesyłany do przeglądarek zwykłych odwiedzających.', 'vt-konfigurator' ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="vt_drive_url"><?php esc_html_e( 'URL Google Drive', 'vt-konfigurator' ); ?></label>
						</th>
						<td>
							<input
								type="url"
								name="vt[drive_url]"
								id="vt_drive_url"
								value="<?php echo esc_attr( $settings['drive_url'] ); ?>"
								placeholder="https://drive.google.com/drive/folders/..."
								class="large-text"
							>
							<p class="description"><?php esc_html_e( 'Wklej link do folderu lub pliku na Google Drive.', 'vt-konfigurator' ); ?></p>
						</td>
					</tr>
				</table>

				<?php /* ── Sekcja 5: Cache ──────────────────────────────────── */ ?>
				<h2><?php esc_html_e( 'Cache wyników', 'vt-konfigurator' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Cache włączony', 'vt-konfigurator' ); ?></th>
						<td>
							<label>
								<input
									type="checkbox"
									name="vt[cache_enabled]"
									value="1"
									<?php checked( '1', $settings['cache_enabled'] ); ?>
								>
								<?php esc_html_e( 'Włącz cachowanie wyników', 'vt-konfigurator' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="vt_cache_ttl"><?php esc_html_e( 'Czas życia cache (dni)', 'vt-konfigurator' ); ?></label>
						</th>
						<td>
							<input
								type="number"
								name="vt[cache_ttl]"
								id="vt_cache_ttl"
								value="<?php echo esc_attr( $settings['cache_ttl'] ); ?>"
								min="1"
								max="365"
								class="small-text"
							>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Zapisz ustawienia', 'vt-konfigurator' ) ); ?>
			</form>

			<form method="post" style="margin-bottom:2rem">
				<?php wp_nonce_field( 'vt_clear_cache' ); ?>
				<input type="hidden" name="vt_action" value="clear_cache">
				<?php submit_button( __( 'Wyczyść cache wyników', 'vt-konfigurator' ), 'secondary', 'submit', false ); ?>
			</form>

			<?php /* ── Sekcja 4: Diagnostyka ──────────────────────────────────── */ ?>
			<h2><?php esc_html_e( 'Diagnostyka', 'vt-konfigurator' ); ?></h2>

			<h3><?php esc_html_e( 'Test pobrania kombinacji', 'vt-konfigurator' ); ?></h3>
			<?php
			$test_tree = VT_Storage::get_tree();
			if ( null === $test_tree ) : ?>
				<p><?php esc_html_e( 'Najpierw odśwież dane pojazdów.', 'vt-konfigurator' ); ?></p>
			<?php else : ?>
			<div id="vtk-test-form" style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;margin-bottom:12px">
				<?php
				$test_fields = [
					'brand'  => __( 'Marka', 'vt-konfigurator' ),
					'model'  => __( 'Model', 'vt-konfigurator' ),
					'gen'    => __( 'Generacja', 'vt-konfigurator' ),
					'engine' => __( 'Silnik', 'vt-konfigurator' ),
					'year'   => __( 'Rocznik', 'vt-konfigurator' ),
				];
				foreach ( $test_fields as $field => $label ) : ?>
				<div>
					<label for="vtk-test-<?php echo esc_attr( $field ); ?>" style="display:block;font-size:12px;margin-bottom:4px;font-weight:600">
						<?php echo esc_html( $label ); ?>
					</label>
					<select
						id="vtk-test-<?php echo esc_attr( $field ); ?>"
						style="min-width:150px;max-width:<?php echo 'engine' === $field ? '300px' : '180px'; ?>"
						<?php echo 'brand' === $field ? '' : 'disabled'; ?>
					>
						<option value=""><?php echo esc_html( '— ' . $label ); ?></option>
					</select>
				</div>
				<?php endforeach; ?>
				<button type="button" id="vtk-test-btn" class="button button-secondary" disabled>
					<?php esc_html_e( 'Testuj', 'vt-konfigurator' ); ?>
				</button>
			</div>
			<pre id="vtk-test-result" style="background:#f0f0f1;padding:12px;display:none;max-height:300px;overflow:auto;white-space:pre-wrap;word-break:break-all"></pre>

			<script>
			(function () {
				var tree = <?php echo wp_json_encode( $test_tree['tree'] ); ?>;
				var restUrl = '<?php echo esc_js( rest_url( 'vt/v1/result' ) ); ?>';
				var nonce   = '<?php echo esc_js( wp_create_nonce( 'wp_rest' ) ); ?>';

				function sortByLabel(items) {
					return items.sort(function(a,b){ return a.label.localeCompare(b.label,'pl'); });
				}
				function populate(sel, items, disabled) {
					while (sel.options.length > 1) sel.remove(1);
					sel.disabled = disabled || !items.length;
					sel.value = '';
					items.forEach(function(item){
						var o = document.createElement('option');
						o.value = item.slug; o.textContent = item.label;
						sel.appendChild(o);
					});
				}

				var s = {
					brand:  document.getElementById('vtk-test-brand'),
					model:  document.getElementById('vtk-test-model'),
					gen:    document.getElementById('vtk-test-gen'),
					engine: document.getElementById('vtk-test-engine'),
					year:   document.getElementById('vtk-test-year'),
				};
				var btn = document.getElementById('vtk-test-btn');
				var out = document.getElementById('vtk-test-result');

				// populate brands
				populate(s.brand, sortByLabel(Object.keys(tree).map(function(k){ return {slug:k,label:tree[k].label}; })), false);

				s.brand.addEventListener('change', function(){
					var b = this.value;
					[s.model,s.gen,s.engine,s.year].forEach(function(x){ populate(x,[],true); });
					btn.disabled = true;
					if (!b) return;
					var models = tree[b].models || {};
					populate(s.model, sortByLabel(Object.keys(models).map(function(k){ return {slug:k,label:models[k].label}; })), false);
				});

				s.model.addEventListener('change', function(){
					var b = s.brand.value, m = this.value;
					[s.gen,s.engine,s.year].forEach(function(x){ populate(x,[],true); });
					btn.disabled = true;
					if (!m) return;
					var years = (tree[b].models[m] || {}).years || {};
					var seen = {};
					var gens = [];
					Object.values(years).forEach(function(yd){
						(yd.engines||[]).forEach(function(e){
							var gs = e.value.split('::')[0];
							if (!seen[gs]) { seen[gs]=true; gens.push({slug:gs,label:e.gen_label}); }
						});
					});
					populate(s.gen, sortByLabel(gens), false);
				});

				s.gen.addEventListener('change', function(){
					var b=s.brand.value, m=s.model.value, g=this.value;
					[s.engine,s.year].forEach(function(x){ populate(x,[],true); });
					btn.disabled = true;
					if (!g) return;
					var years = (tree[b].models[m]||{}).years||{};
					var seen={}, engs=[];
					Object.values(years).forEach(function(yd){
						(yd.engines||[]).forEach(function(e){
							var parts=e.value.split('::');
							if (parts[0]===g && !seen[parts[1]]) { seen[parts[1]]=true; engs.push({slug:parts[1],label:e.engine}); }
						});
					});
					populate(s.engine, sortByLabel(engs), false);
				});

				s.engine.addEventListener('change', function(){
					var b=s.brand.value, m=s.model.value, g=s.gen.value, e=this.value;
					populate(s.year,[],true); btn.disabled=true;
					if (!e) return;
					var years=(tree[b].models[m]||{}).years||{};
					var target=g+'::'+e;
					var yrs=[];
					Object.keys(years).forEach(function(yk){
						if ((years[yk].engines||[]).some(function(en){ return en.value===target; }))
							yrs.push({slug:yk,label:years[yk].label});
					});
					populate(s.year, yrs.sort(function(a,b){ return a.slug.localeCompare(b.slug); }), false);
				});

				s.year.addEventListener('change', function(){ btn.disabled = !this.value; });

				btn.addEventListener('click', function(){
					out.style.display = 'block';
					out.textContent = 'Ładowanie...';
					var qs = ['brand','model','gen','engine','year'].map(function(k){
						return encodeURIComponent(k)+'='+encodeURIComponent(s[k].value);
					}).join('&');
					fetch(restUrl.replace(/\/$/, '') + '?' + qs, { headers: {'X-WP-Nonce': nonce} })
						.then(function(r){ return r.json(); })
						.then(function(d){ out.textContent = JSON.stringify(d,null,2); })
						.catch(function(e){ out.textContent = 'Błąd: '+e.message; });
				});
			}());
			</script>
			<?php endif; ?>

			<?php /* Error log */ ?>
			<h3><?php esc_html_e( 'Log błędów (ostatnie 20)', 'vt-konfigurator' ); ?></h3>
			<?php if ( empty( $log ) ) : ?>
				<p><?php esc_html_e( 'Brak wpisów.', 'vt-konfigurator' ); ?></p>
			<?php else : ?>
				<table class="widefat striped" style="margin-bottom:12px">
					<thead>
						<tr>
							<th style="width:160px"><?php esc_html_e( 'Data', 'vt-konfigurator' ); ?></th>
							<th><?php esc_html_e( 'Komunikat', 'vt-konfigurator' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $log as $entry ) : ?>
						<tr>
							<td><code><?php echo esc_html( $entry['time'] ); ?></code></td>
							<td><?php echo esc_html( $entry['message'] ); ?></td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<form method="post">
					<?php wp_nonce_field( 'vt_clear_log' ); ?>
					<input type="hidden" name="vt_action" value="clear_log">
					<?php submit_button( __( 'Wyczyść log błędów', 'vt-konfigurator' ), 'secondary', 'submit', false ); ?>
				</form>
			<?php endif; ?>

		</div><!-- .wrap -->
		<?php
	}
}
