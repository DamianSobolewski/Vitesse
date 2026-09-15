<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VT_Shortcode {

	public function __construct() {
		add_shortcode( 'vt_konfigurator', [ $this, 'render' ] );
	}

	public function render( array|string $atts ): string {
		$atts   = shortcode_atts( [ 'mode' => 'inline', 'charts' => '0' ], $atts, 'vt_konfigurator' );
		$mode   = in_array( $atts['mode'], [ 'inline', 'popup' ], true ) ? $atts['mode'] : 'inline';
		$charts = '1' === $atts['charts'] ? '1' : '0';

		$tree = VT_Storage::get_tree();

		if ( null === $tree ) {
			return '<p class="vtk-notice">' .
				esc_html__( 'Brak danych pojazdów. Administrator musi odświeżyć dane w panelu VT Konfigurator.', 'vt-konfigurator' ) .
			'</p>';
		}

		$this->enqueue_assets( $tree );

		static $widget_index = 0;
		++$widget_index;
		$widget_id = 'vtk-widget-' . $widget_index;

		ob_start();
		if ( 'popup' === $mode ) {
			?>
			<div class="vtk-popup-wrap">
				<button
					class="vtk-popup-btn"
					type="button"
					aria-controls="<?php echo esc_attr( $widget_id . '-modal' ); ?>"
					aria-expanded="false"
				>
					<?php esc_html_e( 'Sprawdź możliwości tuningu', 'vt-konfigurator' ); ?>
				</button>
			</div>
			<div
				class="vtk-modal-overlay"
				id="<?php echo esc_attr( $widget_id . '-modal' ); ?>"
				role="dialog"
				aria-modal="true"
				aria-label="<?php esc_attr_e( 'Konfigurator tuningu', 'vt-konfigurator' ); ?>"
				hidden
			>
				<div class="vtk-modal-box">
					<button
						class="vtk-modal-close"
						type="button"
						aria-label="<?php esc_attr_e( 'Zamknij', 'vt-konfigurator' ); ?>"
					>&times;</button>
					<div class="vtk-widget" id="<?php echo esc_attr( $widget_id ); ?>" data-mode="popup" data-charts="<?php echo esc_attr( $charts ); ?>"></div>
				</div>
			</div>
			<?php
		} else {
			?>
			<div class="vtk-widget" id="<?php echo esc_attr( $widget_id ); ?>" data-mode="inline" data-charts="<?php echo esc_attr( $charts ); ?>"></div>
			<?php
		}
		return ob_get_clean();
	}

	private function enqueue_assets( array $tree ): void {
		$settings = VT_Storage::get_settings();

		if ( '1' === $settings['load_css'] ) {
			wp_enqueue_style(
				'vt-konfigurator-widget',
				VT_KONFIGURATOR_URL . 'assets/css/widget.css',
				[],
				VT_KONFIGURATOR_VERSION
			);
		}

		wp_enqueue_script(
			'vt-konfigurator-widget',
			VT_KONFIGURATOR_URL . 'assets/js/widget.js',
			[],
			VT_KONFIGURATOR_VERSION,
			true
		);

		// wp_localize_script must be called once per script handle; subsequent
		// calls on the same page would overwrite. We use add_inline_script instead
		// so multiple shortcodes on one page each get their data appended.
		// In practice, the data (tree, settings) is the same on every call.
		wp_localize_script(
			'vt-konfigurator-widget',
			'vtKonfigurator',
			[
				'tree'     => $tree['tree'],
				'restUrl'  => esc_url_raw( rest_url( 'vt/v1/result' ) ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'settings' => [
					'accentColor'  => sanitize_hex_color( $settings['accent_color'] ) ?: '#e53e3e',
					'borderRadius' => absint( $settings['border_radius'] ),
				],
				'i18n'     => [
					'selectBrand'      => __( 'Wybierz markę', 'vt-konfigurator' ),
					'selectModel'      => __( 'Wybierz model', 'vt-konfigurator' ),
					'selectGeneration' => __( 'Wybierz generację', 'vt-konfigurator' ),
					'selectEngine'     => __( 'Wybierz silnik', 'vt-konfigurator' ),
					'selectYear'       => __( 'Wybierz rocznik', 'vt-konfigurator' ),
					'checkTuning'      => __( 'Sprawdź tuning', 'vt-konfigurator' ),
					'loading'          => __( 'Ładowanie wyników...', 'vt-konfigurator' ),
					'errorFetch'       => __( 'Błąd pobierania wyników. Spróbuj ponownie.', 'vt-konfigurator' ),
					'noData'           => __( 'Brak danych dla wybranej kombinacji.', 'vt-konfigurator' ),
					'hp'               => __( 'KM', 'vt-konfigurator' ),
					'nm'               => __( 'Nm', 'vt-konfigurator' ),
					'powerGain'        => __( 'Przyrost mocy', 'vt-konfigurator' ),
					'torqueGain'       => __( 'Przyrost momentu', 'vt-konfigurator' ),
					'resetBtn'         => __( 'Sprawdź inny pojazd', 'vt-konfigurator' ),
					'openPopup'        => __( 'Sprawdź możliwości tuningu', 'vt-konfigurator' ),
					'closeModal'       => __( 'Zamknij', 'vt-konfigurator' ),
					'resultsFor'       => __( 'Wyniki tuningu dla', 'vt-konfigurator' ),
					'notAvailable'     => __( 'Niedostępny dla tego pojazdu', 'vt-konfigurator' ),
					'powerchip'        => __( 'PowerChip', 'vt-konfigurator' ),
					'chipTuning'       => __( 'Chip Tuning', 'vt-konfigurator' ),
					'viewChart'        => __( 'Zobacz wykres mocy', 'vt-konfigurator' ),
					'interestedCall'   => __( 'Zainteresowany? Zadzwoń:', 'vt-konfigurator' ),
					'privateCharts'    => __( 'Twoje wykresy', 'vt-konfigurator' ),
				],
				'phones'   => [
					[ 'number' => $settings['phone_1_number'], 'name' => $settings['phone_1_name'] ],
					[ 'number' => $settings['phone_2_number'], 'name' => $settings['phone_2_name'] ],
				],
				'driveUrl' => $settings['drive_url'],
			]
		);
	}
}
