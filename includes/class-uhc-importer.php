<?php
/**
 * Main importer class.
 *
 * @package UHC_Laupen_Importer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UHC_Importer {

	/**
	 * Import modes.
	 */
	const MODE_UPDATE = 'update';
	const MODE_FRESH  = 'fresh';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	public function register_menu() {
		// Show the menu item to any user who is allowed to import.
		// The actual capability passed to add_management_page must be a real
		// WordPress cap string, so we use 'read' (everyone has it) and then
		// do our own stricter check in render_page().
		add_management_page(
			__( 'Spieler Import', 'uhc-laupen-importer' ),
			__( 'Spieler Import', 'uhc-laupen-importer' ),
			'read',
			'uhc-spieler-import',
			array( $this, 'render_page' )
		);

		if ( ! UHC_Importer_Settings::current_user_can_import() ) {
			remove_menu_page( 'uhc-spieler-import' );
		}
	}

	public function enqueue_assets( $hook ) {
		$our_hooks = array(
			'tools_page_uhc-spieler-import',
			'tools_page_uhc-spieler-import-check',
			'settings_page_uhc-spieler-import-settings',
		);
		if ( ! in_array( $hook, $our_hooks, true ) ) {
			return;
		}
		wp_enqueue_style(
			'uhc-importer-style',
			plugin_dir_url( UHC_IMPORTER_FILE ) . 'assets/importer.css',
			array(),
			UHC_IMPORTER_VERSION
		);
		wp_enqueue_script(
			'uhc-importer-script',
			plugin_dir_url( UHC_IMPORTER_FILE ) . 'assets/importer.js',
			array(),
			UHC_IMPORTER_VERSION,
			true
		);
	}

	public function render_page() {
		if ( ! UHC_Importer_Settings::current_user_can_import() ) {
			wp_die( esc_html__( 'Du hast keine Berechtigung, den Spieler-Importer zu verwenden.', 'uhc-laupen-importer' ) );
		}

		echo '<div class="wrap uhc-importer">';
		echo '<h1>' . esc_html__( 'Spieler Import', 'uhc-laupen-importer' ) . '</h1>';

		$step = isset( $_POST['uhc_import_step'] ) ? sanitize_key( $_POST['uhc_import_step'] ) : 'upload';

		if ( 'upload' !== $step ) {
			if ( ! isset( $_POST['uhc_import_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['uhc_import_nonce'] ) ), 'uhc_import_action' ) ) {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'Sicherheitsüberprüfung fehlgeschlagen.', 'uhc-laupen-importer' ) . '</p></div>';
				$step = 'upload';
			}
		}

		switch ( $step ) {
			case 'preview': $this->step_preview(); break;
			case 'import':  $this->step_import();  break;
			default:        $this->step_upload();
		}

		echo '</div>';
	}

	// ── Step 1: Upload form ───────────────────────────────────────────────

	private function step_upload() {
		$player_count = wp_count_posts( 'spieler' );
		$published    = isset( $player_count->publish ) ? (int) $player_count->publish : 0;
		?>
		<p><?php esc_html_e( 'Lade eine CSV-Exportdatei aus ClubDesk hoch. Spieler werden automatisch mit dem passenden Team, dem Spielerbild und dem Sponsorenbild verknüpft.', 'uhc-laupen-importer' ); ?></p>
		<p class="description">
			<?php esc_html_e( 'Vor dem Saisonimport lohnt sich der', 'uhc-laupen-importer' ); ?>
			<a href="<?php echo esc_url( admin_url( 'tools.php?page=uhc-spieler-import-check' ) ); ?>"><?php esc_html_e( 'Systemcheck', 'uhc-laupen-importer' ); ?></a>
			— <?php esc_html_e( 'er prüft Import, Teams und die Swiss-Unihockey-Schnittstelle, ohne Daten zu verändern.', 'uhc-laupen-importer' ); ?>
		</p>

		<div class="uhc-importer__card">
			<form method="post" enctype="multipart/form-data" action="">
				<?php wp_nonce_field( 'uhc_import_action', 'uhc_import_nonce' ); ?>
				<input type="hidden" name="uhc_import_step" value="preview" />
				<table class="form-table">
					<tr>
						<th><label for="uhc_csv_file"><?php esc_html_e( 'CSV-Datei', 'uhc-laupen-importer' ); ?></label></th>
						<td>
							<input type="file" name="uhc_csv_file" id="uhc_csv_file" accept=".csv" required />
							<p class="description"><?php esc_html_e( 'ClubDesk CSV-Export (Semikolon-getrennt).', 'uhc-laupen-importer' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Modus', 'uhc-laupen-importer' ); ?></th>
						<td>
							<fieldset class="uhc-importer__modes">
								<label class="uhc-importer__mode">
									<input type="radio" name="uhc_mode" value="<?php echo esc_attr( self::MODE_UPDATE ); ?>" checked />
									<span>
										<strong><?php esc_html_e( 'Spieler aktualisieren', 'uhc-laupen-importer' ); ?></strong><br />
										<span class="description"><?php esc_html_e( 'Bestehende Spieler werden aktualisiert, neue hinzugefügt. Spieler, die nicht in der Datei stehen, bleiben unverändert. Ideal, um einzelne Teams nachzuimportieren.', 'uhc-laupen-importer' ); ?></span>
									</span>
								</label>
								<label class="uhc-importer__mode">
									<input type="radio" name="uhc_mode" value="<?php echo esc_attr( self::MODE_FRESH ); ?>" />
									<span>
										<strong><?php esc_html_e( 'Spieler neu importieren', 'uhc-laupen-importer' ); ?></strong><br />
										<span class="description">
											<?php
											printf(
												/* translators: %d: number of published players */
												esc_html__( 'Für den Saisonstart: alle Spieler, die nicht in dieser Datei stehen, werden in den Papierkorb verschoben (aktuell %d Spieler). Danach mit „Spieler aktualisieren“ die weiteren Teams nachimportieren.', 'uhc-laupen-importer' ),
												$published
											);
											?>
										</span>
									</span>
								</label>
							</fieldset>
						</td>
					</tr>
					<tr>
						<th><label for="uhc_dry_run"><?php esc_html_e( 'Probelauf', 'uhc-laupen-importer' ); ?></label></th>
						<td>
							<label>
								<input type="checkbox" name="uhc_dry_run" id="uhc_dry_run" value="1" checked />
								<?php esc_html_e( 'Nur Vorschau — keine Daten werden gespeichert', 'uhc-laupen-importer' ); ?>
							</label>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'CSV analysieren', 'uhc-laupen-importer' ) ); ?>
			</form>
		</div>

		<div class="uhc-importer__info">
			<h3><?php esc_html_e( 'Welche Felder werden importiert?', 'uhc-laupen-importer' ); ?></h3>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'CSV-Spalte', 'uhc-laupen-importer' ); ?></th>
						<th><?php esc_html_e( 'ACF-Feld', 'uhc-laupen-importer' ); ?></th>
						<th><?php esc_html_e( 'Hinweis', 'uhc-laupen-importer' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<tr><td>Vorname</td><td>vorname</td><td><?php esc_html_e( 'Wird auch als Post-Titel verwendet', 'uhc-laupen-importer' ); ?></td></tr>
					<tr><td>Nachname</td><td>nachname</td><td>—</td></tr>
					<tr><td>Rückennummer / Spielernummer</td><td>spielernummer</td><td><?php esc_html_e( 'Schreibweise egal — sonst Spalte manuell wählen', 'uhc-laupen-importer' ); ?></td></tr>
					<tr><td>Funktion</td><td>position</td><td><?php esc_html_e( 'Torhüter, Feldspieler oder Staff', 'uhc-laupen-importer' ); ?></td></tr>
					<tr><td>Nationalität</td><td>nationalitat</td><td><?php esc_html_e( 'Leere Werte überschreiben nichts', 'uhc-laupen-importer' ); ?></td></tr>
					<tr><td>Geburtsdatum</td><td>geburtsdatum</td><td>—</td></tr>
					<tr><td>Team</td><td>teams (Relationship)</td><td><?php esc_html_e( 'Automatisch verknüpft — oder manuell im nächsten Schritt', 'uhc-laupen-importer' ); ?></td></tr>
					<tr><td>E-Mail</td><td>email</td><td><?php esc_html_e( 'Optional', 'uhc-laupen-importer' ); ?></td></tr>
					<tr><td>Sponsor</td><td>sponsorenbild</td><td><?php esc_html_e( 'Mediendatei mit demselben Namen wird verknüpft', 'uhc-laupen-importer' ); ?></td></tr>
					<tr><td>Vorname + Nachname</td><td>spielerbild</td><td><?php esc_html_e( 'Mediendatei „vorname.nachname“ wird verknüpft', 'uhc-laupen-importer' ); ?></td></tr>
				</tbody>
			</table>
		</div>
		<?php
	}

	// ── Step 2: Preview ───────────────────────────────────────────────────

	private function step_preview() {
		$mode          = $this->get_mode();
		$dry_run       = ! empty( $_POST['uhc_dry_run'] );
		$number_column = isset( $_POST['uhc_number_column'] ) ? sanitize_text_field( wp_unslash( $_POST['uhc_number_column'] ) ) : '';

		// Either a fresh upload, or a re-analysis of the file we already stored.
		if ( ! empty( $_FILES['uhc_csv_file']['tmp_name'] ) ) {
			$token = $this->store_upload( sanitize_text_field( $_FILES['uhc_csv_file']['tmp_name'] ) );
			if ( is_wp_error( $token ) ) {
				echo '<div class="notice notice-error"><p>' . esc_html( $token->get_error_message() ) . '</p></div>';
				$this->step_upload();
				return;
			}
		} else {
			$token = isset( $_POST['uhc_file_token'] ) ? sanitize_file_name( wp_unslash( $_POST['uhc_file_token'] ) ) : '';
		}

		$path = $this->token_path( $token );
		if ( ! $path ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Keine Datei gefunden. Bitte lade die CSV erneut hoch.', 'uhc-laupen-importer' ) . '</p></div>';
			$this->step_upload();
			return;
		}

		$parser = new UHC_CSV_Parser();
		$result = $parser->parse( $path, $number_column );

		if ( is_wp_error( $result ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $result->get_error_message() ) . '</p></div>';
			$this->step_upload();
			return;
		}

		$rows     = $result['rows'];
		$skipped  = $result['skipped'];
		$headers  = $result['headers'];
		$resolved = $result['resolved'];
		$total    = count( $rows );

		$matched   = array();
		$unmatched = array();
		foreach ( $rows as $index => $row ) {
			$team_post = $this->find_team_post( $row['team'] );
			$entry     = array(
				'index'     => $index,
				'row'       => $row,
				'team_post' => $team_post,
				'photo'     => UHC_Media_Matcher::find_player_photo( $row['vorname'], $row['nachname'], $row['benutzer_id'] ?? '' ),
				'sponsors'  => UHC_Media_Matcher::find_sponsors( $row['sponsor'] ?? '' ),
				'existing'  => $this->get_existing_spieler( $row['vorname'], $row['nachname'] ),
			);
			if ( $team_post ) {
				$matched[] = $entry;
			} else {
				$unmatched[] = $entry;
			}
		}

		$all_teams = get_posts( array(
			'post_type'              => 'team',
			'post_status'            => 'publish',
			'posts_per_page'         => -1,
			'orderby'                => 'title',
			'order'                  => 'ASC',
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		) );

		// How many players would the fresh mode remove?
		$to_trash = array();
		if ( self::MODE_FRESH === $mode ) {
			$keep = array();
			foreach ( $rows as $row ) {
				$existing = $this->get_existing_spieler( $row['vorname'], $row['nachname'] );
				if ( $existing ) {
					$keep[] = $existing->ID;
				}
			}
			$to_trash = $this->players_to_trash( $keep );
		}

		$photos_found   = count( array_filter( array_merge( $matched, $unmatched ), fn( $e ) => (bool) $e['photo'] ) );
		$sponsors_found = count( array_filter( array_merge( $matched, $unmatched ), fn( $e ) => ! empty( $e['sponsors']['ids'] ) ) );
		?>

		<div class="uhc-importer__notice uhc-importer__notice--info">
			<strong><?php printf( esc_html__( '%d Datensätze gefunden', 'uhc-laupen-importer' ), $total ); ?></strong>
			<?php if ( $skipped ) : ?>
				— <?php printf( esc_html__( '%d übersprungen', 'uhc-laupen-importer' ), $skipped ); ?>
			<?php endif; ?>
			· <span class="uhc-importer__badge uhc-importer__badge--ok"><?php printf( esc_html__( '%d Team automatisch erkannt', 'uhc-laupen-importer' ), count( $matched ) ); ?></span>
			<?php if ( count( $unmatched ) ) : ?>
				· <span class="uhc-importer__badge uhc-importer__badge--warn"><?php printf( esc_html__( '%d Team nicht gefunden', 'uhc-laupen-importer' ), count( $unmatched ) ); ?></span>
			<?php endif; ?>
			· <span class="uhc-importer__badge uhc-importer__badge--ok"><?php printf( esc_html__( '%d Spielerbilder', 'uhc-laupen-importer' ), $photos_found ); ?></span>
			<?php if ( $sponsors_found ) : ?>
				· <span class="uhc-importer__badge uhc-importer__badge--ok"><?php printf( esc_html__( '%d Sponsorenbilder', 'uhc-laupen-importer' ), $sponsors_found ); ?></span>
			<?php endif; ?>
			<br>
			<em>
				<?php
				printf(
					esc_html__( 'Modus: %s', 'uhc-laupen-importer' ),
					self::MODE_FRESH === $mode
						? esc_html__( 'Spieler neu importieren', 'uhc-laupen-importer' )
						: esc_html__( 'Spieler aktualisieren', 'uhc-laupen-importer' )
				);
				?>
				<?php if ( $dry_run ) : ?>
					· <?php esc_html_e( '⚠ Probelauf — keine Daten werden gespeichert.', 'uhc-laupen-importer' ); ?>
				<?php endif; ?>
			</em>
		</div>

		<form method="post" action="" id="uhc-preview-form">
			<?php wp_nonce_field( 'uhc_import_action', 'uhc_import_nonce' ); ?>
			<input type="hidden" name="uhc_import_step" value="import" />
			<input type="hidden" name="uhc_file_token" value="<?php echo esc_attr( $token ); ?>" />
			<input type="hidden" name="uhc_mode" value="<?php echo esc_attr( $mode ); ?>" />
			<input type="hidden" name="uhc_dry_run" value="<?php echo $dry_run ? '1' : '0'; ?>" />
			<input type="hidden" name="uhc_number_column" value="<?php echo esc_attr( $resolved['spielernummer'] ); ?>" />

			<?php if ( empty( $resolved['spielernummer'] ) ) : ?>
			<!-- ── Jersey number column could not be detected ───────────── -->
			<div class="uhc-importer__card uhc-importer__card--warn">
				<h3 class="uhc-importer__section-title uhc-importer__section-title--warn">
					⚠ <?php esc_html_e( 'Spalte für die Rückennummer nicht erkannt', 'uhc-laupen-importer' ); ?>
				</h3>
				<p><?php esc_html_e( 'Wähle die Spalte aus, in der die Rückennummer steht, und analysiere die Datei erneut.', 'uhc-laupen-importer' ); ?></p>
			</div>
			<?php endif; ?>

			<?php if ( self::MODE_FRESH === $mode ) : ?>
			<!-- ── Fresh import: confirmation ────────────────────────────── -->
			<div class="uhc-importer__card uhc-importer__card--warn">
				<h3 class="uhc-importer__section-title uhc-importer__section-title--warn">
					⚠ <?php esc_html_e( 'Neuimport — bestehende Spieler entfernen', 'uhc-laupen-importer' ); ?>
				</h3>
				<?php if ( empty( $to_trash ) ) : ?>
					<p><?php esc_html_e( 'Es gibt keine Spieler, die entfernt werden müssten.', 'uhc-laupen-importer' ); ?></p>
				<?php else : ?>
					<p>
						<?php printf(
							/* translators: %d: number of players */
							esc_html__( '%d Spieler stehen nicht in dieser Datei und werden in den Papierkorb verschoben:', 'uhc-laupen-importer' ),
							count( $to_trash )
						); ?>
					</p>
					<p class="uhc-importer__trash-list">
						<?php echo esc_html( implode( ', ', wp_list_pluck( $to_trash, 'post_title' ) ) ); ?>
					</p>
					<p>
						<label>
							<input type="checkbox" name="uhc_confirm_delete" value="1" id="uhc-confirm-delete" />
							<strong><?php esc_html_e( 'Ja, alle bestehenden Spieler löschen', 'uhc-laupen-importer' ); ?></strong>
						</label>
					</p>
				<?php endif; ?>
			</div>
			<?php endif; ?>

			<?php if ( ! empty( $unmatched ) ) : ?>
			<!-- ── Unmatched players: manual team assignment ────────────── -->
			<div class="uhc-importer__card uhc-importer__card--warn">
				<h3 class="uhc-importer__section-title uhc-importer__section-title--warn">
					⚠ <?php printf( esc_html__( '%d Spieler ohne erkanntes Team — bitte manuell zuweisen', 'uhc-laupen-importer' ), count( $unmatched ) ); ?>
				</h3>

				<div class="uhc-importer__bulk-bar">
					<label>
						<input type="checkbox" id="uhc-select-all" />
						<?php esc_html_e( 'Alle auswählen', 'uhc-laupen-importer' ); ?>
					</label>
					<span class="uhc-importer__bulk-sep">→</span>
					<label for="uhc-bulk-team"><?php esc_html_e( 'Ausgewählte zuweisen zu:', 'uhc-laupen-importer' ); ?></label>
					<select id="uhc-bulk-team" class="uhc-importer__select">
						<option value=""><?php esc_html_e( '— Team wählen —', 'uhc-laupen-importer' ); ?></option>
						<?php foreach ( $all_teams as $tp ) : ?>
							<option value="<?php echo esc_attr( $tp->ID ); ?>"><?php echo esc_html( $tp->post_title ); ?></option>
						<?php endforeach; ?>
					</select>
					<button type="button" id="uhc-bulk-apply" class="button">
						<?php esc_html_e( 'Zuweisen', 'uhc-laupen-importer' ); ?>
					</button>
				</div>

				<div class="uhc-importer__table-wrap">
					<table class="widefat striped uhc-importer__preview-table">
						<thead>
							<tr>
								<th class="uhc-importer__col-check"></th>
								<th><?php esc_html_e( 'Name', 'uhc-laupen-importer' ); ?></th>
								<th><?php esc_html_e( 'Team (CSV)', 'uhc-laupen-importer' ); ?></th>
								<th><?php esc_html_e( 'Position', 'uhc-laupen-importer' ); ?></th>
								<th><?php esc_html_e( 'Nr.', 'uhc-laupen-importer' ); ?></th>
								<th><?php esc_html_e( 'Bild', 'uhc-laupen-importer' ); ?></th>
								<th><?php esc_html_e( 'Team manuell zuweisen', 'uhc-laupen-importer' ); ?></th>
								<th><?php esc_html_e( 'Aktion', 'uhc-laupen-importer' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $unmatched as $entry ) :
								$row   = $entry['row'];
								$index = $entry['index'];
							?>
							<tr class="uhc-importer__row--warn" data-row-index="<?php echo esc_attr( $index ); ?>">
								<td><input type="checkbox" class="uhc-row-check" data-row="<?php echo esc_attr( $index ); ?>" /></td>
								<td><strong><?php echo esc_html( $row['vorname'] . ' ' . $row['nachname'] ); ?></strong></td>
								<td><em class="uhc-importer__csv-team"><?php echo esc_html( $row['team'] ?: '—' ); ?></em></td>
								<td><?php echo esc_html( $row['position'] ); ?></td>
								<td><?php echo esc_html( $row['spielernummer'] ?: '—' ); ?></td>
								<td><?php echo $entry['photo'] ? '✓' : '—'; ?></td>
								<td>
									<select
										name="uhc_team_override[<?php echo esc_attr( $index ); ?>]"
										class="uhc-importer__select uhc-row-team-select"
										data-row="<?php echo esc_attr( $index ); ?>"
									>
										<option value=""><?php esc_html_e( '— Überspringen —', 'uhc-laupen-importer' ); ?></option>
										<?php foreach ( $all_teams as $tp ) : ?>
											<option value="<?php echo esc_attr( $tp->ID ); ?>"><?php echo esc_html( $tp->post_title ); ?></option>
										<?php endforeach; ?>
									</select>
								</td>
								<td>
									<?php if ( $entry['existing'] ) : ?>
										<span class="uhc-importer__badge uhc-importer__badge--update"><?php esc_html_e( 'Aktualisieren', 'uhc-laupen-importer' ); ?></span>
									<?php else : ?>
										<span class="uhc-importer__badge uhc-importer__badge--new"><?php esc_html_e( 'Neu erstellen', 'uhc-laupen-importer' ); ?></span>
									<?php endif; ?>
								</td>
							</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</div>
			<?php endif; ?>

			<?php if ( ! empty( $matched ) ) : ?>
			<!-- ── Matched players: auto-assigned ─────────────────────────── -->
			<div class="uhc-importer__card">
				<h3 class="uhc-importer__section-title">
					✓ <?php printf( esc_html__( '%d Spieler mit automatisch erkanntem Team', 'uhc-laupen-importer' ), count( $matched ) ); ?>
				</h3>
				<div class="uhc-importer__table-wrap">
					<table class="widefat striped uhc-importer__preview-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Name', 'uhc-laupen-importer' ); ?></th>
								<th><?php esc_html_e( 'Team', 'uhc-laupen-importer' ); ?></th>
								<th><?php esc_html_e( 'Position', 'uhc-laupen-importer' ); ?></th>
								<th><?php esc_html_e( 'Nr.', 'uhc-laupen-importer' ); ?></th>
								<th><?php esc_html_e( 'Geburtsdatum', 'uhc-laupen-importer' ); ?></th>
								<th><?php esc_html_e( 'Bild', 'uhc-laupen-importer' ); ?></th>
								<th><?php esc_html_e( 'Sponsor', 'uhc-laupen-importer' ); ?></th>
								<th><?php esc_html_e( 'Aktion', 'uhc-laupen-importer' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $matched as $entry ) :
								$row = $entry['row'];
							?>
							<tr>
								<td><strong><?php echo esc_html( $row['vorname'] . ' ' . $row['nachname'] ); ?></strong></td>
								<td><span class="uhc-importer__badge uhc-importer__badge--ok">✓ <?php echo esc_html( $entry['team_post']->post_title ); ?></span></td>
								<td><?php echo esc_html( $row['position'] ); ?></td>
								<td><?php echo esc_html( $row['spielernummer'] ?: '—' ); ?></td>
								<td><?php echo esc_html( $this->format_date( $row['geburtsdatum'] ) ); ?></td>
								<td><?php echo $entry['photo'] ? '✓' : '—'; ?></td>
								<td>
									<?php
									if ( ! empty( $entry['sponsors']['ids'] ) ) {
										echo '✓';
									} elseif ( ! empty( $entry['sponsors']['missing'] ) ) {
										echo '<span class="uhc-importer__badge uhc-importer__badge--warn">' . esc_html( implode( ', ', $entry['sponsors']['missing'] ) ) . '</span>';
									} else {
										echo '—';
									}
									?>
								</td>
								<td>
									<?php if ( $entry['existing'] ) : ?>
										<span class="uhc-importer__badge uhc-importer__badge--update"><?php esc_html_e( 'Aktualisieren', 'uhc-laupen-importer' ); ?></span>
									<?php else : ?>
										<span class="uhc-importer__badge uhc-importer__badge--new"><?php esc_html_e( 'Neu erstellen', 'uhc-laupen-importer' ); ?></span>
									<?php endif; ?>
								</td>
							</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</div>
			<?php endif; ?>

			<p class="uhc-importer__actions">
				<a href="<?php echo esc_url( admin_url( 'tools.php?page=uhc-spieler-import' ) ); ?>" class="button">
					← <?php esc_html_e( 'Zurück', 'uhc-laupen-importer' ); ?>
				</a>
				<?php submit_button(
					$dry_run
						? __( 'Probelauf starten', 'uhc-laupen-importer' )
						: __( 'Import starten', 'uhc-laupen-importer' ),
					'primary',
					'submit',
					false,
					array( 'style' => 'margin-left: 8px;' )
				); ?>
			</p>
		</form>

		<?php if ( empty( $resolved['spielernummer'] ) ) : ?>
		<!-- Separate form so choosing a column re-analyses instead of importing. -->
		<div class="uhc-importer__card">
			<form method="post" action="">
				<?php wp_nonce_field( 'uhc_import_action', 'uhc_import_nonce' ); ?>
				<input type="hidden" name="uhc_import_step" value="preview" />
				<input type="hidden" name="uhc_file_token" value="<?php echo esc_attr( $token ); ?>" />
				<input type="hidden" name="uhc_mode" value="<?php echo esc_attr( $mode ); ?>" />
				<?php if ( $dry_run ) : ?><input type="hidden" name="uhc_dry_run" value="1" /><?php endif; ?>
				<label for="uhc_number_column"><strong><?php esc_html_e( 'Spalte für die Rückennummer:', 'uhc-laupen-importer' ); ?></strong></label>
				<select name="uhc_number_column" id="uhc_number_column" class="uhc-importer__select">
					<option value=""><?php esc_html_e( '— keine —', 'uhc-laupen-importer' ); ?></option>
					<?php foreach ( $headers as $header ) : ?>
						<option value="<?php echo esc_attr( $header ); ?>"><?php echo esc_html( $header ); ?></option>
					<?php endforeach; ?>
				</select>
				<?php submit_button( __( 'Erneut analysieren', 'uhc-laupen-importer' ), 'secondary', 'submit', false ); ?>
			</form>
		</div>
		<?php endif; ?>
		<?php
	}

	// ── Step 3: Import ────────────────────────────────────────────────────

	private function step_import() {
		$mode          = $this->get_mode();
		$dry_run       = '1' === sanitize_text_field( wp_unslash( $_POST['uhc_dry_run'] ?? '0' ) );
		$token         = isset( $_POST['uhc_file_token'] ) ? sanitize_file_name( wp_unslash( $_POST['uhc_file_token'] ) ) : '';
		$number_column = isset( $_POST['uhc_number_column'] ) ? sanitize_text_field( wp_unslash( $_POST['uhc_number_column'] ) ) : '';
		$confirmed     = ! empty( $_POST['uhc_confirm_delete'] );
		$overrides     = isset( $_POST['uhc_team_override'] ) && is_array( $_POST['uhc_team_override'] )
			? array_map( 'absint', wp_unslash( $_POST['uhc_team_override'] ) )
			: array();

		$path = $this->token_path( $token );
		if ( ! $path ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Die hochgeladene Datei ist nicht mehr verfügbar. Bitte starte den Import neu.', 'uhc-laupen-importer' ) . '</p></div>';
			return;
		}

		$parser = new UHC_CSV_Parser();
		$parsed = $parser->parse( $path, $number_column );
		if ( is_wp_error( $parsed ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $parsed->get_error_message() ) . '</p></div>';
			return;
		}
		$rows = $parsed['rows'];

		// Merge manual team overrides into the rows.
		foreach ( $overrides as $index => $team_id ) {
			if ( isset( $rows[ $index ] ) && $team_id > 0 ) {
				$team_post = get_post( $team_id );
				if ( $team_post ) {
					$rows[ $index ]['team']             = $team_post->post_title;
					$rows[ $index ]['team_id_override'] = $team_id;
				}
			}
		}

		$writer  = new UHC_Player_Writer( $dry_run );
		$results = array();
		foreach ( $rows as $row ) {
			$results[] = $writer->write( $row );
		}

		// Fresh import: trash everyone who is not part of this file.
		$trashed     = array();
		$trash_notice = '';
		if ( self::MODE_FRESH === $mode ) {
			$to_trash = $this->players_to_trash( $writer->get_touched_ids() );
			if ( $dry_run ) {
				$trash_notice = sprintf(
					/* translators: %d: number of players */
					__( '(Probelauf) %d Spieler würden in den Papierkorb verschoben.', 'uhc-laupen-importer' ),
					count( $to_trash )
				);
			} elseif ( ! $confirmed ) {
				$trash_notice = __( 'Bestätigung fehlt — es wurden keine Spieler entfernt.', 'uhc-laupen-importer' );
			} else {
				foreach ( $to_trash as $player ) {
					if ( wp_trash_post( $player->ID ) ) {
						$trashed[] = $player->post_title;
					}
				}
				$trash_notice = sprintf(
					/* translators: %d: number of players */
					__( '%d Spieler in den Papierkorb verschoben.', 'uhc-laupen-importer' ),
					count( $trashed )
				);
			}
		}

		if ( ! $dry_run ) {
			$this->delete_token_file( $token );
		}

		$created = count( array_filter( $results, fn( $r ) => 'created' === $r['status'] ) );
		$updated = count( array_filter( $results, fn( $r ) => 'updated' === $r['status'] ) );
		$failed  = count( array_filter( $results, fn( $r ) => 'error'   === $r['status'] ) );
		$photos  = count( array_filter( $results, fn( $r ) => ! empty( $r['photo'] ) ) );
		?>

		<div class="uhc-importer__notice uhc-importer__notice--<?php echo $failed ? 'warn' : 'success'; ?>">
			<?php if ( $dry_run ) : ?>
				<strong><?php esc_html_e( 'Probelauf abgeschlossen — keine Daten wurden gespeichert.', 'uhc-laupen-importer' ); ?></strong><br>
			<?php else : ?>
				<strong><?php esc_html_e( 'Import abgeschlossen.', 'uhc-laupen-importer' ); ?></strong><br>
			<?php endif; ?>
			<?php printf(
				esc_html__( 'Erstellt: %1$d | Aktualisiert: %2$d | Fehler: %3$d | Spielerbilder verknüpft: %4$d', 'uhc-laupen-importer' ),
				$created, $updated, $failed, $photos
			); ?>
			<?php if ( $trash_notice ) : ?>
				<br><?php echo esc_html( $trash_notice ); ?>
			<?php endif; ?>
		</div>

		<div class="uhc-importer__card">
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Name', 'uhc-laupen-importer' ); ?></th>
						<th><?php esc_html_e( 'Ergebnis', 'uhc-laupen-importer' ); ?></th>
						<th><?php esc_html_e( 'Team verknüpft', 'uhc-laupen-importer' ); ?></th>
						<th><?php esc_html_e( 'Bild', 'uhc-laupen-importer' ); ?></th>
						<th><?php esc_html_e( 'Details', 'uhc-laupen-importer' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $results as $r ) : ?>
					<tr>
						<td><strong><?php echo esc_html( $r['name'] ); ?></strong></td>
						<td><span class="uhc-importer__badge uhc-importer__badge--<?php echo esc_attr( $r['status'] ); ?>"><?php echo esc_html( $r['status_label'] ); ?></span></td>
						<td><?php echo esc_html( $r['team_title'] ?? '—' ); ?></td>
						<td><?php echo ! empty( $r['photo'] ) ? '✓' : '—'; ?></td>
						<td><?php echo esc_html( $r['message'] ?? '' ); ?></td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<p class="uhc-importer__actions">
				<a href="<?php echo esc_url( admin_url( 'tools.php?page=uhc-spieler-import' ) ); ?>" class="button">
					← <?php esc_html_e( 'Neuen Import starten', 'uhc-laupen-importer' ); ?>
				</a>
				<?php if ( ! $dry_run ) : ?>
					<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=spieler' ) ); ?>" class="button button-primary" style="margin-left:8px;">
						<?php esc_html_e( 'Alle Spieler ansehen', 'uhc-laupen-importer' ); ?>
					</a>
				<?php endif; ?>
			</p>
		</div>
		<?php
	}

	// ── Helpers ───────────────────────────────────────────────────────────

	/**
	 * Selected import mode, defaulting to the safe "update".
	 *
	 * @return string
	 */
	private function get_mode() {
		$mode = isset( $_POST['uhc_mode'] ) ? sanitize_key( wp_unslash( $_POST['uhc_mode'] ) ) : self::MODE_UPDATE;
		return self::MODE_FRESH === $mode ? self::MODE_FRESH : self::MODE_UPDATE;
	}

	/**
	 * Published players that are NOT in the given keep-list.
	 *
	 * @param int[] $keep_ids Post IDs to keep.
	 * @return WP_Post[]
	 */
	private function players_to_trash( $keep_ids ) {
		$keep    = array_map( 'intval', (array) $keep_ids );
		$players = get_posts( array(
			'post_type'              => 'spieler',
			'post_status'            => array( 'publish', 'draft', 'pending', 'private' ),
			'posts_per_page'         => -1,
			'orderby'                => 'title',
			'order'                  => 'ASC',
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		) );

		return array_values( array_filter( $players, fn( $p ) => ! in_array( (int) $p->ID, $keep, true ) ) );
	}

	/**
	 * Move an uploaded CSV to a private temp file and return its token.
	 *
	 * @param string $tmp_name PHP upload tmp path.
	 * @return string|WP_Error Token, or error.
	 */
	private function store_upload( $tmp_name ) {
		if ( ! is_uploaded_file( $tmp_name ) ) {
			return new WP_Error( 'upload_invalid', __( 'Ungültiger Upload.', 'uhc-laupen-importer' ) );
		}
		$token = 'uhc-import-' . wp_generate_password( 16, false ) . '.csv';
		$dest  = trailingslashit( get_temp_dir() ) . $token;
		if ( ! @move_uploaded_file( $tmp_name, $dest ) ) {
			return new WP_Error( 'upload_move_failed', __( 'Die Datei konnte nicht zwischengespeichert werden.', 'uhc-laupen-importer' ) );
		}
		return $token;
	}

	/**
	 * Resolve a token into a readable temp file path.
	 *
	 * @param string $token File token.
	 * @return string|null Absolute path, or null when unavailable.
	 */
	private function token_path( $token ) {
		if ( ! $token || ! preg_match( '/^uhc-import-[A-Za-z0-9]+\.csv$/', $token ) ) {
			return null;
		}
		$path = trailingslashit( get_temp_dir() ) . $token;
		return file_exists( $path ) && is_readable( $path ) ? $path : null;
	}

	/**
	 * Remove the temp file after a completed import.
	 *
	 * @param string $token File token.
	 */
	private function delete_token_file( $token ) {
		$path = $this->token_path( $token );
		if ( $path ) {
			@unlink( $path );
		}
	}

	/**
	 * Format a stored Ymd date for display.
	 *
	 * @param string $ymd Date in Ymd.
	 * @return string
	 */
	private function format_date( $ymd ) {
		if ( ! $ymd || ! preg_match( '/^\d{8}$/', $ymd ) ) {
			return $ymd ?: '—';
		}
		return substr( $ymd, 6, 2 ) . '.' . substr( $ymd, 4, 2 ) . '.' . substr( $ymd, 0, 4 );
	}

	public function find_team_post( $team_name ) {
		return UHC_Player_Writer::find_team( $team_name );
	}

	public function get_existing_spieler( $vorname, $nachname ) {
		$title = trim( $vorname . ' ' . $nachname );
		$posts = get_posts( array(
			'post_type'              => 'spieler',
			'post_status'            => array( 'publish', 'draft', 'pending', 'private' ),
			'title'                  => $title,
			'posts_per_page'         => 1,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		) );
		return ! empty( $posts ) ? $posts[0] : null;
	}
}
