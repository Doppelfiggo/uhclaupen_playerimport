<?php
/**
 * Settings page for the UHC Laupen Spieler Importer.
 *
 * Only Administrators (manage_options) can open this page.
 * It lets them grant specific non-admin users access to the
 * importer tool without giving them full admin rights.
 *
 * Stored as a single wp_option: 'uhc_importer_allowed_users'
 * — an array of WP user IDs.
 *
 * @package UHC_Laupen_Importer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UHC_Importer_Settings {

	/** Option key used to store the allowed user IDs. */
	const OPTION_KEY = 'uhc_importer_allowed_users';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'handle_save' ) );
	}

	/**
	 * Register a sub-page under Settings (not Tools) so it is clearly
	 * separated from the importer tool itself and only discoverable by
	 * Administrators.
	 */
	public function register_menu() {
		add_options_page(
			__( 'Spieler Import — Berechtigungen', 'uhc-laupen-importer' ),
			__( 'Spieler Import', 'uhc-laupen-importer' ),
			'manage_options',     // Admins only.
			'uhc-spieler-import-settings',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Handle the save action — must be manage_options, nonce-verified.
	 */
	public function handle_save() {
		if (
			! isset( $_POST['uhc_importer_settings_nonce'] ) ||
			! wp_verify_nonce(
				sanitize_text_field( wp_unslash( $_POST['uhc_importer_settings_nonce'] ) ),
				'uhc_importer_settings_save'
			)
		) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// $_POST['uhc_allowed_users'] is an array of user ID strings, or absent.
		$raw = isset( $_POST['uhc_allowed_users'] ) && is_array( $_POST['uhc_allowed_users'] )
			? $_POST['uhc_allowed_users']
			: array();

		$clean = array_map( 'absint', $raw );
		$clean = array_filter( $clean );       // Remove zeros.
		$clean = array_values( $clean );       // Re-index.

		update_option( self::OPTION_KEY, $clean );

		// Redirect back with a success flag.
		wp_safe_redirect( add_query_arg(
			array(
				'page'    => 'uhc-spieler-import-settings',
				'updated' => '1',
			),
			admin_url( 'options-general.php' )
		) );
		exit;
	}

	/**
	 * Render the settings page.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'uhc-laupen-importer' ) );
		}

		$allowed     = self::get_allowed_users();
		$all_users   = get_users( array(
			'orderby' => 'display_name',
			'order'   => 'ASC',
			'exclude' => array( get_current_user_id() ), // Admin managing this doesn't need to be listed.
		) );

		$saved = isset( $_GET['updated'] ) && '1' === $_GET['updated'];
		?>
		<div class="wrap uhc-importer">
			<h1><?php esc_html_e( 'Spieler Import — Berechtigungen', 'uhc-laupen-importer' ); ?></h1>

			<p class="description">
				<?php esc_html_e( 'Administratoren haben immer Zugriff auf den Spieler-Importer. Hier kannst du festlegen, welche weiteren Benutzer ebenfalls Zugriff erhalten sollen.', 'uhc-laupen-importer' ); ?>
			</p>

			<?php if ( $saved ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Berechtigungen gespeichert.', 'uhc-laupen-importer' ); ?></p>
				</div>
			<?php endif; ?>

			<div class="uhc-importer__card">
				<form method="post" action="">
					<?php wp_nonce_field( 'uhc_importer_settings_save', 'uhc_importer_settings_nonce' ); ?>

					<h2 style="margin-top:0"><?php esc_html_e( 'Zugelassene Benutzer', 'uhc-laupen-importer' ); ?></h2>

					<?php if ( empty( $all_users ) ) : ?>
						<p><?php esc_html_e( 'Keine weiteren Benutzer gefunden.', 'uhc-laupen-importer' ); ?></p>
					<?php else : ?>
						<div class="uhc-importer__user-list">
							<?php foreach ( $all_users as $user ) :
								$is_admin   = $user->has_cap( 'manage_options' );
								$is_checked = in_array( $user->ID, $allowed, true );
							?>
							<label class="uhc-importer__user-row<?php echo $is_admin ? ' uhc-importer__user-row--admin' : ''; ?>">
								<input
									type="checkbox"
									name="uhc_allowed_users[]"
									value="<?php echo esc_attr( $user->ID ); ?>"
									<?php checked( $is_checked || $is_admin ); ?>
									<?php disabled( $is_admin ); ?>
								/>
								<span class="uhc-importer__user-name">
									<?php echo esc_html( $user->display_name ); ?>
									<span class="uhc-importer__user-login"><?php echo esc_html( $user->user_login ); ?></span>
								</span>
								<span class="uhc-importer__user-role">
									<?php
									$roles = array_map(
										fn( $r ) => isset( wp_roles()->roles[ $r ]['name'] )
											? translate_user_role( wp_roles()->roles[ $r ]['name'] )
											: $r,
										$user->roles
									);
									echo esc_html( implode( ', ', $roles ) );
									?>
									<?php if ( $is_admin ) : ?>
										<span class="uhc-importer__badge uhc-importer__badge--ok"><?php esc_html_e( 'immer Zugriff', 'uhc-laupen-importer' ); ?></span>
									<?php endif; ?>
								</span>
							</label>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>

					<p class="uhc-importer__actions" style="margin-top:24px">
						<?php submit_button( __( 'Berechtigungen speichern', 'uhc-laupen-importer' ), 'primary', 'submit', false ); ?>
						<a
							href="<?php echo esc_url( admin_url( 'tools.php?page=uhc-spieler-import' ) ); ?>"
							class="button"
							style="margin-left:8px"
						>
							<?php esc_html_e( '→ Zum Importer', 'uhc-laupen-importer' ); ?>
						</a>
					</p>
				</form>
			</div>

			<div class="uhc-importer__info">
				<h3><?php esc_html_e( 'Wie funktioniert die Zugriffskontrolle?', 'uhc-laupen-importer' ); ?></h3>
				<ul>
					<li><?php esc_html_e( 'Administratoren haben immer Zugriff — unabhängig von dieser Liste.', 'uhc-laupen-importer' ); ?></li>
					<li><?php esc_html_e( 'Benutzer in der Liste sehen den Importer unter Werkzeuge → Spieler Import.', 'uhc-laupen-importer' ); ?></li>
					<li><?php esc_html_e( 'Alle anderen Benutzer sehen den Menüpunkt nicht und erhalten eine Fehlermeldung, wenn sie die URL direkt aufrufen.', 'uhc-laupen-importer' ); ?></li>
					<li><?php esc_html_e( 'Diese Einstellung kann nur von Administratoren geändert werden.', 'uhc-laupen-importer' ); ?></li>
				</ul>
			</div>
		</div>
		<?php
	}

	/**
	 * Returns the list of user IDs allowed to use the importer.
	 * Admins are always allowed and don't need to appear in this list.
	 *
	 * @return int[]
	 */
	public static function get_allowed_users() {
		$option = get_option( self::OPTION_KEY, array() );
		return is_array( $option ) ? array_map( 'absint', $option ) : array();
	}

	/**
	 * Whether the current user is allowed to use the importer.
	 * Admins always pass. Others are checked against the allowed list.
	 *
	 * @return bool
	 */
	public static function current_user_can_import() {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		$allowed = self::get_allowed_users();
		return in_array( get_current_user_id(), $allowed, true );
	}
}
