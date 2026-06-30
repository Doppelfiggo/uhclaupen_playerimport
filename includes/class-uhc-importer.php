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

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	public function register_menu() {
		// Show the menu item to any user who is allowed to import.
		// The actual capability passed to add_management_page must be a real
		// WordPress cap string, so we use 'read' (everyone has it) and then
		// do our own stricter check in render_page().
		$hook = add_management_page(
			__( 'Spieler Import', 'uhc-laupen-importer' ),
			__( 'Spieler Import', 'uhc-laupen-importer' ),
			'read',
			'uhc-spieler-import',
			array( $this, 'render_page' )
		);

		// Hide the menu item entirely from users who are not allowed.
		// We do this by removing it from the global menu array after it's
		// added — cleaner than filtering the capability on the hook itself.
		if ( ! UHC_Importer_Settings::current_user_can_import() ) {
			remove_menu_page( 'uhc-spieler-import' );
		}
	}

	public function enqueue_assets( $hook ) {
		$our_hooks = array(
			'tools_page_uhc-spieler-import',
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
		?>
		<p><?php esc_html_e( 'Lade eine CSV-Exportdatei aus ClubDesk hoch. Spieler werden automatisch mit dem passenden Team verknüpft. Unbekannte Teams können manuell zugewiesen werden.', 'uhc-laupen-importer' ); ?></p>

		<div class="uhc-importer__card">
			<form method="post" enctype="multipart/form-data" action="">
				<?php wp_nonce_field( 'uhc_import_action', 'uhc_import_nonce' ); ?>
				<input type="hidden" name="uhc_import_step" value="preview" />
				<table class="form-table">
					<tr>
						<th><label for="uhc_csv_file"><?php esc_html_e( 'CSV-Datei', 'uhc-laupen-importer' ); ?></label></th>
						<td>
							<input type="file" name="uhc_csv_file" id="uhc_csv_file" accept=".csv" required />
							<p class="description"><?php esc_html_e( 'ClubDesk CSV-Export (Semikolon-getrennt, UTF-8).', 'uhc-laupen-importer' ); ?></p>
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
					<tr><td>Rückennummer</td><td>spielernummer</td><td>—</td></tr>
					<tr><td>Funktion</td><td>position</td><td><?php esc_html_e( 'Torhüter, Feldspieler, oder Staff', 'uhc-laupen-importer' ); ?></td></tr>
					<tr><td>Nationalität</td><td>nationalitat</td><td>—</td></tr>
					<tr><td>Geburtsdatum</td><td>geburtsdatum</td><td>—</td></tr>
					<tr><td>Team</td><td>team (Relationship)</td><td><?php esc_html_e( 'Automatisch verknüpft — oder manuell im nächsten Schritt', 'uhc-laupen-importer' ); ?></td></tr>
					<tr><td>E-Mail</td><td>email</td><td><?php esc_html_e( 'Optional', 'uhc-laupen-importer' ); ?></td></tr>
				</tbody>
			</table>
		</div>
		<?php
	}

	// ── Step 2: Preview ───────────────────────────────────────────────────

	private function step_preview() {
		if ( empty( $_FILES['uhc_csv_file']['tmp_name'] ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Keine Datei hochgeladen.', 'uhc-laupen-importer' ) . '</p></div>';
			$this->step_upload();
			return;
		}

		$dry_run = ! empty( $_POST['uhc_dry_run'] );
		$parser  = new UHC_CSV_Parser();
		$result  = $parser->parse( sanitize_text_field( $_FILES['uhc_csv_file']['tmp_name'] ) );

		if ( is_wp_error( $result ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $result->get_error_message() ) . '</p></div>';
			$this->step_upload();
			return;
		}

		$rows    = $result['rows'];
		$skipped = $result['skipped'];
		$total   = count( $rows );

		// Separate into matched/unmatched.
		$matched   = array();
		$unmatched = array();
		foreach ( $rows as $index => $row ) {
			$team_post = $this->find_team_post( $row['team'] );
			if ( $team_post ) {
				$matched[] = array( 'index' => $index, 'row' => $row, 'team_post' => $team_post );
			} else {
				$unmatched[] = array( 'index' => $index, 'row' => $row );
			}
		}

		// Get all team posts for the assignment dropdowns.
		$all_teams = get_posts( array(
			'post_type'      => 'team',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		) );

		$encoded = base64_encode( json_encode( $rows ) );
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
			<?php if ( $dry_run ) : ?>
				<br><em><?php esc_html_e( '⚠ Probelauf — keine Daten werden gespeichert.', 'uhc-laupen-importer' ); ?></em>
			<?php endif; ?>
		</div>

		<form method="post" action="" id="uhc-preview-form">
			<?php wp_nonce_field( 'uhc_import_action', 'uhc_import_nonce' ); ?>
			<input type="hidden" name="uhc_import_step" value="import" />
			<input type="hidden" name="uhc_import_data" value="<?php echo esc_attr( $encoded ); ?>" />
			<input type="hidden" name="uhc_dry_run" value="<?php echo $dry_run ? '1' : '0'; ?>" />

			<?php if ( ! empty( $unmatched ) ) : ?>
			<!-- ── Unmatched players: manual team assignment ────────────── -->
			<div class="uhc-importer__card uhc-importer__card--warn">
				<h3 class="uhc-importer__section-title uhc-importer__section-title--warn">
					⚠ <?php printf( esc_html__( '%d Spieler ohne erkanntes Team — bitte manuell zuweisen', 'uhc-laupen-importer' ), count( $unmatched ) ); ?>
				</h3>

				<!-- Bulk assignment toolbar -->
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
							<option value="<?php echo esc_attr( $tp->ID ); ?>">
								<?php echo esc_html( $tp->post_title ); ?>
							</option>
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
								<th><?php esc_html_e( 'Team manuell zuweisen', 'uhc-laupen-importer' ); ?></th>
								<th><?php esc_html_e( 'Aktion', 'uhc-laupen-importer' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $unmatched as $entry ) :
								$row    = $entry['row'];
								$index  = $entry['index'];
								$status = $this->get_existing_spieler( $row['vorname'], $row['nachname'] );
							?>
							<tr class="uhc-importer__row--warn" data-row-index="<?php echo esc_attr( $index ); ?>">
								<td>
									<input
										type="checkbox"
										class="uhc-row-check"
										data-row="<?php echo esc_attr( $index ); ?>"
									/>
								</td>
								<td><strong><?php echo esc_html( $row['vorname'] . ' ' . $row['nachname'] ); ?></strong></td>
								<td>
									<em class="uhc-importer__csv-team"><?php echo esc_html( $row['team'] ?: '—' ); ?></em>
								</td>
								<td><?php echo esc_html( $row['position'] ); ?></td>
								<td><?php echo esc_html( $row['spielernummer'] ); ?></td>
								<td>
									<select
										name="uhc_team_override[<?php echo esc_attr( $index ); ?>]"
										class="uhc-importer__select uhc-row-team-select"
										data-row="<?php echo esc_attr( $index ); ?>"
									>
										<option value=""><?php esc_html_e( '— Überspringen —', 'uhc-laupen-importer' ); ?></option>
										<?php foreach ( $all_teams as $tp ) : ?>
											<option value="<?php echo esc_attr( $tp->ID ); ?>">
												<?php echo esc_html( $tp->post_title ); ?>
											</option>
										<?php endforeach; ?>
									</select>
								</td>
								<td>
									<?php if ( $status ) : ?>
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
								<th><?php esc_html_e( 'Aktion', 'uhc-laupen-importer' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $matched as $entry ) :
								$row    = $entry['row'];
								$status = $this->get_existing_spieler( $row['vorname'], $row['nachname'] );
							?>
							<tr>
								<td><strong><?php echo esc_html( $row['vorname'] . ' ' . $row['nachname'] ); ?></strong></td>
								<td><span class="uhc-importer__badge uhc-importer__badge--ok">✓ <?php echo esc_html( $entry['team_post']->post_title ); ?></span></td>
								<td><?php echo esc_html( $row['position'] ); ?></td>
								<td><?php echo esc_html( $row['spielernummer'] ); ?></td>
								<td><?php echo esc_html( $row['geburtsdatum'] ); ?></td>
								<td>
									<?php if ( $status ) : ?>
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
		<?php
	}

	// ── Step 3: Import ────────────────────────────────────────────────────

	private function step_import() {
		$dry_run   = '1' === sanitize_text_field( wp_unslash( $_POST['uhc_dry_run'] ?? '0' ) );
		$encoded   = sanitize_text_field( wp_unslash( $_POST['uhc_import_data'] ?? '' ) );
		$rows      = json_decode( base64_decode( $encoded ), true );
		$overrides = isset( $_POST['uhc_team_override'] ) && is_array( $_POST['uhc_team_override'] )
			? array_map( 'absint', $_POST['uhc_team_override'] )
			: array();

		if ( ! is_array( $rows ) || empty( $rows ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Keine Daten zum Importieren.', 'uhc-laupen-importer' ) . '</p></div>';
			return;
		}

		// Merge manual team overrides into the rows.
		foreach ( $overrides as $index => $team_id ) {
			if ( isset( $rows[ $index ] ) && $team_id > 0 ) {
				$team_post = get_post( $team_id );
				if ( $team_post ) {
					// Replace the CSV team name with the manually selected team post title.
					$rows[ $index ]['team']            = $team_post->post_title;
					$rows[ $index ]['team_id_override'] = $team_id;
				}
			}
		}

		$writer  = new UHC_Player_Writer( $dry_run );
		$results = array();
		foreach ( $rows as $row ) {
			$results[] = $writer->write( $row );
		}

		$created = count( array_filter( $results, fn( $r ) => 'created' === $r['status'] ) );
		$updated = count( array_filter( $results, fn( $r ) => 'updated' === $r['status'] ) );
		$failed  = count( array_filter( $results, fn( $r ) => 'error'   === $r['status'] ) );
		$skipped = count( array_filter( $results, fn( $r ) => 'skipped' === $r['status'] ) );
		?>

		<div class="uhc-importer__notice uhc-importer__notice--<?php echo $failed ? 'warn' : 'success'; ?>">
			<?php if ( $dry_run ) : ?>
				<strong><?php esc_html_e( 'Probelauf abgeschlossen — keine Daten wurden gespeichert.', 'uhc-laupen-importer' ); ?></strong><br>
			<?php else : ?>
				<strong><?php esc_html_e( 'Import abgeschlossen.', 'uhc-laupen-importer' ); ?></strong><br>
			<?php endif; ?>
			<?php printf(
				esc_html__( 'Erstellt: %1$d | Aktualisiert: %2$d | Übersprungen: %3$d | Fehler: %4$d', 'uhc-laupen-importer' ),
				$created, $updated, $skipped, $failed
			); ?>
		</div>

		<div class="uhc-importer__card">
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Name', 'uhc-laupen-importer' ); ?></th>
						<th><?php esc_html_e( 'Ergebnis', 'uhc-laupen-importer' ); ?></th>
						<th><?php esc_html_e( 'Team verknüpft', 'uhc-laupen-importer' ); ?></th>
						<th><?php esc_html_e( 'Details', 'uhc-laupen-importer' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $results as $r ) : ?>
					<tr>
						<td><strong><?php echo esc_html( $r['name'] ); ?></strong></td>
						<td><span class="uhc-importer__badge uhc-importer__badge--<?php echo esc_attr( $r['status'] ); ?>"><?php echo esc_html( $r['status_label'] ); ?></span></td>
						<td><?php echo esc_html( $r['team_title'] ?? '—' ); ?></td>
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

	public function find_team_post( $team_name ) {
		if ( empty( $team_name ) ) return null;
		$posts = get_posts( array(
			'post_type'              => 'team',
			'post_status'            => 'publish',
			'title'                  => trim( $team_name ),
			'posts_per_page'         => 1,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		) );
		return ! empty( $posts ) ? $posts[0] : null;
	}

	public function get_existing_spieler( $vorname, $nachname ) {
		$title = trim( $vorname . ' ' . $nachname );
		$posts = get_posts( array(
			'post_type'              => 'spieler',
			'post_status'            => array( 'publish', 'draft' ),
			'title'                  => $title,
			'posts_per_page'         => 1,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		) );
		return ! empty( $posts ) ? $posts[0] : null;
	}
}
