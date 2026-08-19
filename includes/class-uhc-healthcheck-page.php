<?php
/**
 * Admin page for the health check.
 *
 * Sits next to the importer under Tools and is readable by everyone who may
 * run the import. The page itself only reads — the "Import" and "Teams"
 * groups run on load, the API group needs an extra click because it makes a
 * dozen outgoing HTTP requests.
 *
 * @package UHC_Laupen_Importer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UHC_Healthcheck_Page {

	const SLUG = 'uhc-spieler-import-check';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
	}

	public function register_menu() {
		// 'read' is a real capability every logged-in user has; the stricter
		// check happens in render_page(), the same way the importer does it.
		add_management_page(
			__( 'Spieler Import — Systemcheck', 'uhc-laupen-importer' ),
			__( 'Spieler Import: Systemcheck', 'uhc-laupen-importer' ),
			'read',
			self::SLUG,
			array( $this, 'render_page' )
		);

		if ( ! UHC_Importer_Settings::current_user_can_import() ) {
			remove_submenu_page( 'tools.php', self::SLUG );
		}
	}

	public function render_page() {
		if ( ! UHC_Importer_Settings::current_user_can_import() ) {
			wp_die( esc_html__( 'Du hast keine Berechtigung, den Systemcheck zu verwenden.', 'uhc-laupen-importer' ) );
		}

		$with_api = isset( $_GET['api'] ) && '1' === $_GET['api'];
		if ( $with_api ) {
			check_admin_referer( 'uhc_healthcheck_api' );
		}

		$groups = $with_api ? array( 'import', 'teams', 'api' ) : array( 'import', 'teams' );

		// The API group makes a dozen requests to a service that regularly needs
		// several seconds per call — more than the default execution time on
		// many hosts.
		if ( $with_api && function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 180 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		$checker = new UHC_Healthcheck();
		$started = microtime( true );
		$results = $checker->run( $groups );
		$seconds = round( microtime( true ) - $started, 1 );
		$counts  = UHC_Healthcheck::summarise( $results );

		$overall = $counts[ UHC_Healthcheck::FAIL ] ? 'warn' : ( $counts[ UHC_Healthcheck::WARN ] ? 'info' : 'success' );

		echo '<div class="wrap uhc-importer">';
		echo '<h1>' . esc_html__( 'Spieler Import — Systemcheck', 'uhc-laupen-importer' ) . '</h1>';

		echo '<p>' . esc_html__( 'Prüft den Import, die Team-Verwaltung und die Swiss-Unihockey-Schnittstelle. Alle Prüfungen sind reine Lesevorgänge — es werden keine Daten verändert.', 'uhc-laupen-importer' ) . '</p>';
		?>

		<div class="uhc-importer__notice uhc-importer__notice--<?php echo esc_attr( $overall ); ?>">
			<strong>
				<?php
				printf(
					/* translators: 1: passed, 2: warnings, 3: failures */
					esc_html__( '%1$d bestanden · %2$d Hinweise · %3$d Fehler', 'uhc-laupen-importer' ),
					(int) $counts[ UHC_Healthcheck::PASS ],
					(int) $counts[ UHC_Healthcheck::WARN ],
					(int) $counts[ UHC_Healthcheck::FAIL ]
				);
				?>
			</strong>
			<?php if ( ! $counts[ UHC_Healthcheck::FAIL ] && ! $counts[ UHC_Healthcheck::WARN ] ) : ?>
				— <?php esc_html_e( 'alles in Ordnung.', 'uhc-laupen-importer' ); ?>
			<?php endif; ?>
			<br>
			<em>
				<?php
				printf(
					/* translators: %s: duration in seconds */
					esc_html__( 'Laufzeit: %s Sekunden. Es wurden keine Daten geschrieben.', 'uhc-laupen-importer' ),
					esc_html( (string) $seconds )
				);
				?>
			</em>
		</div>

		<p class="uhc-importer__actions">
			<a href="<?php echo esc_url( admin_url( 'tools.php?page=' . self::SLUG ) ); ?>" class="button">
				<?php esc_html_e( 'Erneut prüfen', 'uhc-laupen-importer' ); ?>
			</a>
			<?php if ( ! $with_api ) : ?>
				<a
					href="<?php echo esc_url( wp_nonce_url( admin_url( 'tools.php?page=' . self::SLUG . '&api=1' ), 'uhc_healthcheck_api' ) ); ?>"
					class="button button-primary"
					style="margin-left:8px"
				>
					<?php esc_html_e( 'Auch die Swiss-Unihockey-API prüfen', 'uhc-laupen-importer' ); ?>
				</a>
				<span class="description" style="margin-left:8px">
					<?php esc_html_e( 'dauert einige Sekunden', 'uhc-laupen-importer' ); ?>
				</span>
			<?php endif; ?>
			<a href="<?php echo esc_url( admin_url( 'tools.php?page=uhc-spieler-import' ) ); ?>" class="button" style="margin-left:8px">
				<?php esc_html_e( '→ Zum Importer', 'uhc-laupen-importer' ); ?>
			</a>
		</p>

		<?php
		foreach ( UHC_Healthcheck::groups() as $key => $label ) {
			$group_results = array_values( array_filter( $results, fn( $r ) => $r['group'] === $key ) );
			if ( ! $group_results ) {
				continue;
			}
			$this->render_group( $label, $group_results );
		}

		echo '</div>';
	}

	/**
	 * Render one group of results as a table.
	 *
	 * @param string $label   Group label.
	 * @param array  $results Result rows.
	 */
	private function render_group( $label, $results ) {
		?>
		<div class="uhc-importer__card">
			<h3 class="uhc-importer__section-title"><?php echo esc_html( $label ); ?></h3>
			<table class="widefat striped">
				<thead>
					<tr>
						<th style="width:120px"><?php esc_html_e( 'Status', 'uhc-laupen-importer' ); ?></th>
						<th style="width:280px"><?php esc_html_e( 'Prüfung', 'uhc-laupen-importer' ); ?></th>
						<th><?php esc_html_e( 'Ergebnis', 'uhc-laupen-importer' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $results as $result ) : ?>
					<tr>
						<td>
							<span class="uhc-importer__badge uhc-importer__badge--<?php echo esc_attr( $this->badge( $result['status'] ) ); ?>">
								<?php echo esc_html( $this->status_label( $result['status'] ) ); ?>
							</span>
						</td>
						<td><strong><?php echo esc_html( $result['label'] ); ?></strong></td>
						<td><?php echo esc_html( $result['message'] ); ?></td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Map a status to an existing badge modifier from importer.css.
	 *
	 * @param string $status Status key.
	 * @return string
	 */
	private function badge( $status ) {
		switch ( $status ) {
			case UHC_Healthcheck::PASS:
				return 'ok';
			case UHC_Healthcheck::WARN:
				return 'warn';
			case UHC_Healthcheck::FAIL:
				return 'error';
			default:
				return 'skipped';
		}
	}

	/**
	 * Human label for a status.
	 *
	 * @param string $status Status key.
	 * @return string
	 */
	private function status_label( $status ) {
		switch ( $status ) {
			case UHC_Healthcheck::PASS:
				return __( '✓ OK', 'uhc-laupen-importer' );
			case UHC_Healthcheck::WARN:
				return __( '⚠ Hinweis', 'uhc-laupen-importer' );
			case UHC_Healthcheck::FAIL:
				return __( '✕ Fehler', 'uhc-laupen-importer' );
			default:
				return __( '– übersprungen', 'uhc-laupen-importer' );
		}
	}
}
