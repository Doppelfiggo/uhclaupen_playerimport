<?php
/**
 * Read-only health check for the yearly player import.
 *
 * Runs three groups of checks and returns a structured result list:
 *
 *  - 'import' — parses a bundled sample CSV through the real parser and runs
 *               the real writer in dry-run mode, then verifies that not a
 *               single row in the database changed.
 *  - 'teams'  — verifies that teams/players are still manageable: CPTs and
 *               taxonomy registered, ACF fields present, permalinks resolving,
 *               player↔team links intact.
 *  - 'api'    — verifies that the Swiss Unihockey API still answers and still
 *               returns the shapes the theme reads.
 *
 * NOTHING in this class writes to the database. The API group deliberately
 * bypasses the plugin's API client so it does not even write cache transients.
 *
 * @package UHC_Laupen_Importer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UHC_Healthcheck {

	/** Sample CSV used by the import checks, relative to the plugin dir. */
	const FIXTURE = 'tests/fixtures/clubdesk-sample.csv';

	/** Result statuses. */
	const PASS = 'pass';
	const WARN = 'warn';
	const FAIL = 'fail';
	const SKIP = 'skip';

	/**
	 * Collected results.
	 *
	 * @var array
	 */
	private $results = array();

	/**
	 * Group key the current checks belong to.
	 *
	 * @var string
	 */
	private $group = '';

	/**
	 * Available check groups.
	 *
	 * @return array key => human label.
	 */
	public static function groups() {
		return array(
			'import' => __( 'Import (Probelauf)', 'uhc-laupen-importer' ),
			'teams'  => __( 'Teams & Spieler', 'uhc-laupen-importer' ),
			'api'    => __( 'Swiss Unihockey API', 'uhc-laupen-importer' ),
		);
	}

	/**
	 * Run the given check groups.
	 *
	 * @param string[] $groups Group keys. Defaults to import + teams (no network).
	 * @return array List of result arrays: group, label, status, message.
	 */
	public function run( $groups = array( 'import', 'teams' ) ) {
		$this->results = array();

		foreach ( (array) $groups as $group ) {
			$this->group = $group;
			switch ( $group ) {
				case 'import':
					$this->check_import();
					break;
				case 'teams':
					$this->check_teams();
					break;
				case 'api':
					$this->check_api();
					break;
			}
		}

		return $this->results;
	}

	/**
	 * Count results per status.
	 *
	 * @param array $results Result list.
	 * @return array status => count.
	 */
	public static function summarise( $results ) {
		$counts = array( self::PASS => 0, self::WARN => 0, self::FAIL => 0, self::SKIP => 0 );
		foreach ( $results as $result ) {
			if ( isset( $counts[ $result['status'] ] ) ) {
				$counts[ $result['status'] ]++;
			}
		}
		return $counts;
	}

	// ── Group 1: import ───────────────────────────────────────────────────

	/**
	 * Parse the bundled sample CSV and run a dry-run import over it.
	 *
	 * Every assertion below mirrors a rule the importer relies on, so a change
	 * in ClubDesk's export format or in the parser shows up here instead of
	 * during the yearly import.
	 */
	private function check_import() {
		$fixture = UHC_IMPORTER_DIR . self::FIXTURE;

		if ( ! is_readable( $fixture ) ) {
			$this->add( self::FAIL, __( 'Testdatei vorhanden', 'uhc-laupen-importer' ), $fixture );
			return;
		}

		$parser = new UHC_CSV_Parser();
		$parsed = $parser->parse( $fixture );

		if ( is_wp_error( $parsed ) ) {
			$this->add( self::FAIL, __( 'CSV einlesen', 'uhc-laupen-importer' ), $parsed->get_error_message() );
			return;
		}

		$rows     = $parsed['rows'];
		$resolved = $parsed['resolved'];

		// 1. Every column we care about is recognised, including the umlaut
		//    headers ("Rückennummer", "Nationalität") that broke before.
		$missing = array();
		foreach ( array_keys( $resolved ) as $key ) {
			if ( '' === $resolved[ $key ] ) {
				$missing[] = $key;
			}
		}
		if ( $missing ) {
			$this->add(
				self::FAIL,
				__( 'Spaltenerkennung', 'uhc-laupen-importer' ),
				sprintf(
					/* translators: %s: comma-separated list of column keys */
					__( 'Nicht erkannt: %s', 'uhc-laupen-importer' ),
					implode( ', ', $missing )
				)
			);
		} else {
			$this->add(
				self::PASS,
				__( 'Spaltenerkennung', 'uhc-laupen-importer' ),
				sprintf(
					/* translators: %d: number of recognised columns */
					__( '%d Spalten erkannt, inkl. Umlaut-Kopfzeilen und Semikolon-Trennzeichen.', 'uhc-laupen-importer' ),
					count( $resolved )
				)
			);
		}

		if ( count( $rows ) < 4 ) {
			// Without the expected rows the remaining assertions would be noise.
			$this->add(
				self::FAIL,
				__( 'Zeilenfilter (Gruppe)', 'uhc-laupen-importer' ),
				sprintf(
					/* translators: 1: parsed rows, 2: skipped rows */
					__( '%1$d Zeilen gelesen, %2$d übersprungen — erwartet 4 / 2.', 'uhc-laupen-importer' ),
					count( $rows ),
					$parsed['skipped']
				)
			);
			return;
		}

		// 2. Non-players are filtered out, unnamed rows skipped.
		$this->expect(
			__( 'Zeilenfilter (Gruppe)', 'uhc-laupen-importer' ),
			array( count( $rows ), $parsed['skipped'] ),
			array( 4, 2 ),
			__( '4 Spieler/Staff importierbar, 2 Zeilen übersprungen (Passivmitglied + Zeile ohne Namen).', 'uhc-laupen-importer' )
		);

		// 3. Quoted multi-line cells ("Bemerkungen") do not shift the rows.
		$this->expect(
			__( 'Mehrzeilige Felder', 'uhc-laupen-importer' ),
			array( $rows[0]['nachname'], $rows[1]['vorname'] ),
			array( 'Muster', 'Anna' ),
			__( 'Zeilenumbrüche innerhalb von Anführungszeichen verschieben die Datensätze nicht.', 'uhc-laupen-importer' )
		);

		// 4. "46.0" → "46", "0" → leer.
		$this->expect(
			__( 'Rückennummer normalisiert', 'uhc-laupen-importer' ),
			array( $rows[0]['spielernummer'], $rows[2]['spielernummer'], $rows[3]['spielernummer'] ),
			array( '46', '', '' ),
			__( '„46.0“ wird zu „46“, „0“ und leere Zellen bleiben leer.', 'uhc-laupen-importer' )
		);

		// 5. All three date notations land in ACFs Ymd storage format.
		$this->expect(
			__( 'Geburtsdatum normalisiert', 'uhc-laupen-importer' ),
			array_column( $rows, 'geburtsdatum' ),
			array( '19960826', '19990105', '', '19880703' ),
			__( '26.08.1996, 1999-01-05 und 03/07/1988 werden korrekt umgewandelt.', 'uhc-laupen-importer' )
		);

		// 6. Funktion → ACF-Auswahl (Trainer im Spieler-Team wird zu Staff).
		$this->expect(
			__( 'Funktion → Position', 'uhc-laupen-importer' ),
			array_column( $rows, 'position' ),
			array( 'Feldspieler', 'Torhüter', 'Staff', 'Staff' ),
			__( 'Torhüter/Feldspieler erkannt, „Cheftrainer“ und Staff-Gruppe werden zu Staff.', 'uhc-laupen-importer' )
		);

		// 7. Leere Team-Spalte fällt auf die Gruppe zurück ("Staff Herren 1").
		$this->expect(
			__( 'Team aus Gruppe abgeleitet', 'uhc-laupen-importer' ),
			$rows[3]['team'],
			'Herren 1',
			__( 'Ohne Team-Spalte wird das Team aus der Gruppe „Staff Herren 1“ abgeleitet.', 'uhc-laupen-importer' )
		);

		// 8. Der echte Writer im Probelauf — inklusive Medien- und Team-Abgleich.
		$before = $this->db_fingerprint();

		$writer  = new UHC_Player_Writer( true );
		$results = array();
		foreach ( $rows as $row ) {
			$results[] = $writer->write( $row );
		}

		$after  = $this->db_fingerprint();
		$errors = array_filter( $results, fn( $r ) => 'error' === $r['status'] );

		if ( $errors ) {
			$this->add(
				self::FAIL,
				__( 'Probelauf ohne Fehler', 'uhc-laupen-importer' ),
				implode( ' | ', array_map( fn( $r ) => $r['name'] . ': ' . $r['message'], $errors ) )
			);
		} else {
			$this->add(
				self::PASS,
				__( 'Probelauf ohne Fehler', 'uhc-laupen-importer' ),
				sprintf(
					/* translators: %d: number of simulated rows */
					__( '%d Datensätze durch den echten Import-Code geschickt (Probelauf).', 'uhc-laupen-importer' ),
					count( $results )
				)
			);
		}

		// 9. Die Garantie: der Probelauf darf nichts verändert haben.
		if ( $before === $after ) {
			$this->add(
				self::PASS,
				__( 'Probelauf verändert keine Daten', 'uhc-laupen-importer' ),
				sprintf(
					/* translators: 1: number of player posts, 2: number of meta rows */
					__( 'Unverändert: %1$d Spieler-Beiträge, %2$d Meta-Einträge.', 'uhc-laupen-importer' ),
					$before['spieler'],
					$before['postmeta']
				)
			);
		} else {
			$this->add(
				self::FAIL,
				__( 'Probelauf verändert keine Daten', 'uhc-laupen-importer' ),
				sprintf(
					/* translators: 1: before state, 2: after state */
					__( 'Datenbestand hat sich geändert! Vorher: %1$s — nachher: %2$s', 'uhc-laupen-importer' ),
					wp_json_encode( $before ),
					wp_json_encode( $after )
				)
			);
		}

		// 10. Teamnamen der echten Team-Beiträge werden vom Matcher gefunden.
		$this->check_team_matching();

		// 11. Doppelte Spielernamen brechen die Zuordnung "bestehender Spieler".
		$this->check_duplicate_players();
	}

	/**
	 * Every published team must be findable by its own title, otherwise the
	 * CSV column "Team" will not auto-match during the import.
	 */
	private function check_team_matching() {
		$teams = get_posts( array(
			'post_type'              => 'team',
			'post_status'            => 'publish',
			'posts_per_page'         => -1,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		) );

		if ( ! $teams ) {
			$this->add( self::WARN, __( 'Team-Zuordnung', 'uhc-laupen-importer' ), __( 'Keine veröffentlichten Team-Beiträge gefunden.', 'uhc-laupen-importer' ) );
			return;
		}

		$unmatched = array();
		foreach ( $teams as $team ) {
			$match = UHC_Player_Writer::find_team( $team->post_title );
			if ( ! $match || (int) $match->ID !== (int) $team->ID ) {
				$unmatched[] = $team->post_title;
			}
		}

		if ( $unmatched ) {
			$this->add(
				self::FAIL,
				__( 'Team-Zuordnung', 'uhc-laupen-importer' ),
				sprintf(
					/* translators: %s: comma-separated team titles */
					__( 'Nicht eindeutig auffindbar: %s', 'uhc-laupen-importer' ),
					implode( ', ', $unmatched )
				)
			);
		} else {
			$this->add(
				self::PASS,
				__( 'Team-Zuordnung', 'uhc-laupen-importer' ),
				sprintf(
					/* translators: %d: number of teams */
					__( 'Alle %d Teams werden anhand ihres Namens eindeutig gefunden.', 'uhc-laupen-importer' ),
					count( $teams )
				)
			);
		}

		// Titles that are a prefix of another title can only be matched exactly —
		// a CSV spelling variant would land in the "manuell zuweisen" list.
		$ambiguous = array();
		foreach ( $teams as $team ) {
			foreach ( $teams as $other ) {
				if ( $team->ID !== $other->ID && 0 === stripos( $other->post_title, $team->post_title ) ) {
					$ambiguous[] = $team->post_title . ' → ' . $other->post_title;
				}
			}
		}
		if ( $ambiguous ) {
			$this->add(
				self::WARN,
				__( 'Mehrdeutige Teamnamen', 'uhc-laupen-importer' ),
				sprintf(
					/* translators: %s: list of ambiguous team titles */
					__( 'Nur exakte Schreibweise matcht: %s', 'uhc-laupen-importer' ),
					implode( ', ', array_unique( $ambiguous ) )
				)
			);
		}
	}

	/**
	 * The importer recognises an existing player by post title. Duplicate
	 * titles mean one of them is never updated again.
	 */
	private function check_duplicate_players() {
		global $wpdb;

		$dupes = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_title FROM {$wpdb->posts}
				  WHERE post_type = %s
				    AND post_status IN ( 'publish', 'draft', 'pending', 'private' )
			   GROUP BY post_title
				 HAVING COUNT(*) > 1",
				'spieler'
			)
		);

		if ( $dupes ) {
			$this->add(
				self::WARN,
				__( 'Doppelte Spielernamen', 'uhc-laupen-importer' ),
				sprintf(
					/* translators: %s: comma-separated player names */
					__( 'Beim Import wird jeweils nur einer aktualisiert: %s', 'uhc-laupen-importer' ),
					implode( ', ', $dupes )
				)
			);
		} else {
			$this->add( self::PASS, __( 'Doppelte Spielernamen', 'uhc-laupen-importer' ), __( 'Keine — jeder Spieler wird beim Import eindeutig wiedergefunden.', 'uhc-laupen-importer' ) );
		}
	}

	// ── Group 2: teams & players ──────────────────────────────────────────

	/**
	 * Verify that teams and players are still editable in the backend and that
	 * the pieces the front end depends on are in place.
	 */
	private function check_teams() {
		// Post types / taxonomy come from the theme — if it is switched off,
		// every team and player disappears from the admin.
		$missing_types = array();
		foreach ( array( 'team', 'spieler' ) as $type ) {
			if ( ! post_type_exists( $type ) ) {
				$missing_types[] = $type;
			}
		}
		if ( ! taxonomy_exists( 'team-category' ) ) {
			$missing_types[] = 'team-category';
		}

		if ( $missing_types ) {
			$this->add(
				self::FAIL,
				__( 'Inhaltstypen registriert', 'uhc-laupen-importer' ),
				sprintf(
					/* translators: %s: comma-separated list */
					__( 'Fehlt: %s — ist das Theme „uhc-laupen“ aktiv?', 'uhc-laupen-importer' ),
					implode( ', ', $missing_types )
				)
			);
			return;
		}
		$this->add( self::PASS, __( 'Inhaltstypen registriert', 'uhc-laupen-importer' ), 'team, spieler, team-category' );

		$this->check_acf_fields();
		$this->check_team_content();
		$this->check_player_links();
		$this->check_permalinks();
		$this->check_permissions();
	}

	/**
	 * Every field the importer writes must exist as an ACF field, otherwise
	 * the value lands in the database but is invisible in the backend.
	 */
	private function check_acf_fields() {
		if ( ! function_exists( 'acf_get_field_groups' ) ) {
			$this->add( self::FAIL, __( 'ACF-Felder', 'uhc-laupen-importer' ), __( 'ACF ist nicht aktiv — der Import speichert dann nur rohe Post-Meta.', 'uhc-laupen-importer' ) );
			return;
		}

		$names = $this->acf_field_names();

		// Written by UHC_Player_Writer::write().
		$written = array( 'vorname', 'nachname', 'spielernummer', 'position', 'geburtsdatum', 'email', 'teams', 'spielerbild', 'sponsorenbild', 'nationalitat' );
		$missing = array_values( array_diff( $written, $names ) );

		if ( $missing ) {
			$this->add(
				self::WARN,
				__( 'ACF-Felder (Spieler)', 'uhc-laupen-importer' ),
				sprintf(
					/* translators: %s: comma-separated field names */
					__( 'Der Import schreibt Felder, die es in ACF nicht gibt: %s. Die Werte werden gespeichert, sind im Backend aber nicht sichtbar.', 'uhc-laupen-importer' ),
					implode( ', ', $missing )
				)
			);
		} else {
			$this->add(
				self::PASS,
				__( 'ACF-Felder (Spieler)', 'uhc-laupen-importer' ),
				sprintf(
					/* translators: %d: number of fields */
					__( 'Alle %d vom Import beschriebenen Felder sind vorhanden.', 'uhc-laupen-importer' ),
					count( $written )
				)
			);
		}

		// Fields the team pages read.
		$team_fields = array( 'teamname', 'teambild', 'swiss_unihockey_team_number' );
		$missing     = array_values( array_diff( $team_fields, $names ) );
		if ( $missing ) {
			$this->add(
				self::FAIL,
				__( 'ACF-Felder (Team)', 'uhc-laupen-importer' ),
				sprintf(
					/* translators: %s: comma-separated field names */
					__( 'Fehlende Team-Felder: %s', 'uhc-laupen-importer' ),
					implode( ', ', $missing )
				)
			);
		} else {
			$this->add( self::PASS, __( 'ACF-Felder (Team)', 'uhc-laupen-importer' ), implode( ', ', $team_fields ) );
		}
	}

	/**
	 * All ACF field names known to this installation, including sub fields.
	 *
	 * @return string[]
	 */
	private function acf_field_names() {
		$names = array();

		foreach ( (array) acf_get_field_groups() as $group ) {
			$fields = function_exists( 'acf_get_fields' ) ? acf_get_fields( $group ) : array();
			$this->collect_field_names( (array) $fields, $names );
		}

		return array_values( array_unique( $names ) );
	}

	/**
	 * Recursively collect 'name' values from an ACF field list.
	 *
	 * @param array $fields Field definitions.
	 * @param array $names  Collected names, by reference.
	 */
	private function collect_field_names( $fields, &$names ) {
		foreach ( $fields as $field ) {
			if ( ! empty( $field['name'] ) ) {
				$names[] = $field['name'];
			}
			if ( ! empty( $field['sub_fields'] ) ) {
				$this->collect_field_names( $field['sub_fields'], $names );
			}
			if ( ! empty( $field['layouts'] ) ) {
				foreach ( $field['layouts'] as $layout ) {
					if ( ! empty( $layout['sub_fields'] ) ) {
						$this->collect_field_names( $layout['sub_fields'], $names );
					}
				}
			}
		}
	}

	/**
	 * Each published team needs a category term (its permalink depends on it)
	 * and a Swiss Unihockey team number (games + rankings depend on it).
	 */
	private function check_team_content() {
		$teams = get_posts( array(
			'post_type'      => 'team',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
		) );

		if ( ! $teams ) {
			$this->add( self::WARN, __( 'Teams vorhanden', 'uhc-laupen-importer' ), __( 'Keine veröffentlichten Teams.', 'uhc-laupen-importer' ) );
			return;
		}

		$this->add(
			self::PASS,
			__( 'Teams vorhanden', 'uhc-laupen-importer' ),
			sprintf(
				/* translators: 1: number of teams, 2: comma-separated titles */
				__( '%1$d Teams: %2$s', 'uhc-laupen-importer' ),
				count( $teams ),
				implode( ', ', wp_list_pluck( $teams, 'post_title' ) )
			)
		);

		$without_term   = array();
		$without_number = array();
		foreach ( $teams as $team ) {
			$terms = get_the_terms( $team, 'team-category' );
			if ( ! $terms || is_wp_error( $terms ) ) {
				$without_term[] = $team->post_title;
			}
			if ( '' === trim( (string) get_post_meta( $team->ID, 'swiss_unihockey_team_number', true ) ) ) {
				$without_number[] = $team->post_title;
			}
		}

		if ( $without_term ) {
			$this->add(
				self::WARN,
				__( 'Team-Kategorie gesetzt', 'uhc-laupen-importer' ),
				sprintf(
					/* translators: %s: comma-separated team titles */
					__( 'Ohne Kategorie (Permalink fällt auf /teamname/ zurück): %s', 'uhc-laupen-importer' ),
					implode( ', ', $without_term )
				)
			);
		} else {
			$this->add( self::PASS, __( 'Team-Kategorie gesetzt', 'uhc-laupen-importer' ), __( 'Alle Teams haben eine Kategorie (Leistungssport/Breitensport).', 'uhc-laupen-importer' ) );
		}

		if ( $without_number ) {
			$this->add(
				self::WARN,
				__( 'Swiss-Unihockey-Nummer gesetzt', 'uhc-laupen-importer' ),
				sprintf(
					/* translators: %s: comma-separated team titles */
					__( 'Ohne Teamnummer (keine Spiele/Tabelle): %s', 'uhc-laupen-importer' ),
					implode( ', ', $without_number )
				)
			);
		} else {
			$this->add( self::PASS, __( 'Swiss-Unihockey-Nummer gesetzt', 'uhc-laupen-importer' ), __( 'Alle Teams sind mit einer Teamnummer verknüpft.', 'uhc-laupen-importer' ) );
		}
	}

	/**
	 * The team page finds its squad through a LIKE query on the serialised
	 * 'teams' relationship. Check that link from both sides.
	 */
	private function check_player_links() {
		$teams = get_posts( array(
			'post_type'      => 'team',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
		) );

		$empty = array();
		$total = 0;
		foreach ( $teams as $team ) {
			$count = count( get_posts( array(
				'post_type'      => 'spieler',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'     => 'teams',
						'value'   => '"' . $team->ID . '"',
						'compare' => 'LIKE',
					),
				),
			) ) );
			$total += $count;
			if ( 0 === $count ) {
				$empty[] = $team->post_title;
			}
		}

		if ( $empty ) {
			$this->add(
				self::WARN,
				__( 'Kader je Team', 'uhc-laupen-importer' ),
				sprintf(
					/* translators: 1: total linked players, 2: comma-separated team titles */
					__( '%1$d Spieler verknüpft, aber ohne Kader: %2$s', 'uhc-laupen-importer' ),
					$total,
					implode( ', ', $empty )
				)
			);
		} else {
			$this->add(
				self::PASS,
				__( 'Kader je Team', 'uhc-laupen-importer' ),
				sprintf(
					/* translators: %d: number of linked players */
					__( '%d Spieler sind einem Team zugeordnet — jedes Team hat einen Kader.', 'uhc-laupen-importer' ),
					$total
				)
			);
		}

		// Players pointing at a team post that no longer exists (or is trashed).
		$all_players = get_posts( array(
			'post_type'      => 'spieler',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
		) );

		$orphans  = array();
		$unlinked = array();
		foreach ( $all_players as $player ) {
			$ids = get_post_meta( $player->ID, 'teams', true );
			$ids = is_array( $ids ) ? array_map( 'absint', $ids ) : array();

			if ( ! $ids ) {
				$unlinked[] = $player->post_title;
				continue;
			}
			foreach ( $ids as $id ) {
				$post = get_post( $id );
				if ( ! $post || 'team' !== $post->post_type || 'publish' !== $post->post_status ) {
					$orphans[] = $player->post_title;
					break;
				}
			}
		}

		if ( $orphans ) {
			$this->add(
				self::WARN,
				__( 'Verwaiste Team-Verknüpfungen', 'uhc-laupen-importer' ),
				sprintf(
					/* translators: %s: comma-separated player names */
					__( 'Zeigen auf ein gelöschtes/nicht veröffentlichtes Team: %s', 'uhc-laupen-importer' ),
					implode( ', ', $orphans )
				)
			);
		} else {
			$this->add( self::PASS, __( 'Verwaiste Team-Verknüpfungen', 'uhc-laupen-importer' ), __( 'Keine.', 'uhc-laupen-importer' ) );
		}

		if ( $unlinked ) {
			$this->add(
				self::WARN,
				__( 'Spieler ohne Team', 'uhc-laupen-importer' ),
				sprintf(
					/* translators: 1: count, 2: comma-separated player names */
					__( '%1$d Spieler erscheinen auf keiner Teamseite: %2$s', 'uhc-laupen-importer' ),
					count( $unlinked ),
					implode( ', ', array_slice( $unlinked, 0, 15 ) ) . ( count( $unlinked ) > 15 ? ' …' : '' )
				)
			);
		} else {
			$this->add( self::PASS, __( 'Spieler ohne Team', 'uhc-laupen-importer' ), __( 'Keine — jeder Spieler ist verknüpft.', 'uhc-laupen-importer' ) );
		}
	}

	/**
	 * The team CPT uses %team-category% as its permalink base, which produces
	 * catch-all rewrite rules that the theme has to demote. If the rules are
	 * ever flushed into a different order, team and player URLs 404 — so we
	 * resolve one real URL of each type back to its post.
	 */
	private function check_permalinks() {
		foreach ( array( 'team' => __( 'Team-Permalinks', 'uhc-laupen-importer' ), 'spieler' => __( 'Spieler-Permalinks', 'uhc-laupen-importer' ) ) as $type => $label ) {
			$posts = get_posts( array(
				'post_type'      => $type,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
			) );

			if ( ! $posts ) {
				$this->add( self::SKIP, $label, __( 'Kein Beitrag zum Prüfen vorhanden.', 'uhc-laupen-importer' ) );
				continue;
			}

			$post = $posts[0];
			$url  = get_permalink( $post );
			$id   = url_to_postid( $url );

			if ( (int) $id === (int) $post->ID ) {
				$this->add( self::PASS, $label, $url );
			} else {
				$this->add(
					self::FAIL,
					$label,
					sprintf(
						/* translators: %s: permalink URL */
						__( '%s löst nicht auf den Beitrag auf — Permalinks unter Einstellungen → Permalinks neu speichern.', 'uhc-laupen-importer' ),
						$url
					)
				);
			}
		}
	}

	/**
	 * Who can run the import, and does the role that does it still exist.
	 */
	private function check_permissions() {
		$admins = get_users( array( 'role' => 'administrator', 'fields' => 'ID' ) );
		if ( $admins ) {
			$this->add(
				self::PASS,
				__( 'Administratoren', 'uhc-laupen-importer' ),
				sprintf(
					/* translators: %d: number of administrators */
					__( '%d Administrator(en) — haben immer Zugriff auf den Importer.', 'uhc-laupen-importer' ),
					count( $admins )
				)
			);
		} else {
			$this->add( self::FAIL, __( 'Administratoren', 'uhc-laupen-importer' ), __( 'Kein Administrator gefunden.', 'uhc-laupen-importer' ) );
		}

		$allowed = UHC_Importer_Settings::get_allowed_users();
		$stale   = array_values( array_filter( $allowed, fn( $id ) => ! get_userdata( $id ) ) );

		if ( $stale ) {
			$this->add(
				self::WARN,
				__( 'Import-Berechtigungen', 'uhc-laupen-importer' ),
				sprintf(
					/* translators: %s: comma-separated user IDs */
					__( 'Freigeschaltete Benutzer existieren nicht mehr: %s', 'uhc-laupen-importer' ),
					implode( ', ', $stale )
				)
			);
		} else {
			$this->add(
				self::PASS,
				__( 'Import-Berechtigungen', 'uhc-laupen-importer' ),
				sprintf(
					/* translators: %d: number of additionally allowed users */
					__( '%d zusätzlich freigeschaltete Benutzer.', 'uhc-laupen-importer' ),
					count( $allowed )
				)
			);
		}
	}

	// ── Group 3: Swiss Unihockey API ──────────────────────────────────────

	/**
	 * Check the live API the same way the theme uses it — but with direct HTTP
	 * calls, so no cache transients are written and a stale cache cannot mask
	 * an outage.
	 */
	private function check_api() {
		// 1. Reachability.
		$seasons = $this->api_get( 'seasons' );
		if ( is_wp_error( $seasons ) ) {
			$this->add( self::FAIL, __( 'API erreichbar', 'uhc-laupen-importer' ), $seasons->get_error_message() );
			return;
		}
		$this->add( self::PASS, __( 'API erreichbar', 'uhc-laupen-importer' ), 'https://api-v2.swissunihockey.ch/api/seasons → HTTP 200' );

		// 2. Configured season. The Swiss season is named after its starting
		//    year and begins in the summer.
		$season   = (int) get_option( 'swissfloorball_actual_season', gmdate( 'Y' ) );
		$expected = (int) gmdate( 'm' ) >= 7 ? (int) gmdate( 'Y' ) : (int) gmdate( 'Y' ) - 1;

		if ( $season === $expected ) {
			$this->add( self::PASS, __( 'Eingestellte Saison', 'uhc-laupen-importer' ), (string) $season );
		} else {
			$this->add(
				self::WARN,
				__( 'Eingestellte Saison', 'uhc-laupen-importer' ),
				sprintf(
					/* translators: 1: configured season, 2: expected season */
					__( 'Eingestellt: %1$d, erwartet: %2$d — unter Swiss Floorball API → Einstellungen anpassen.', 'uhc-laupen-importer' ),
					$season,
					$expected
				)
			);
		}

		// 3. Club statistics — also gives us every team of the club, which the
		//    front page configuration is checked against further down.
		$club_teams = array();
		$club       = get_option( 'swissfloorball_club_number', '' );
		if ( $club ) {
			$stats = $this->api_get( 'clubs/' . rawurlencode( $club ) . '/statistics' );
			if ( is_wp_error( $stats ) ) {
				$this->add( self::FAIL, __( 'Vereinsnummer', 'uhc-laupen-importer' ), $club . ': ' . $stats->get_error_message() );
			} else {
				foreach ( (array) ( $stats['data']['regions'][0]['rows'] ?? array() ) as $row ) {
					if ( ! empty( $row['team_id'] ) ) {
						$name = isset( $row['cells'][0]['text'] ) ? trim( implode( ' ', (array) $row['cells'][0]['text'] ) ) : '';
						$club_teams[ (string) $row['team_id'] ] = $name;
					}
				}
				$this->add(
					self::PASS,
					__( 'Vereinsnummer', 'uhc-laupen-importer' ),
					sprintf(
						/* translators: 1: club number, 2: number of teams */
						__( '%1$s — die API meldet %2$d Teams für den Verein.', 'uhc-laupen-importer' ),
						$club,
						count( $club_teams )
					)
				);
			}
		} else {
			$this->add( self::WARN, __( 'Vereinsnummer', 'uhc-laupen-importer' ), __( 'Keine Vereinsnummer in den Plugin-Einstellungen hinterlegt.', 'uhc-laupen-importer' ) );
		}

		// 4. Every team number stored on a team post.
		$teams   = get_posts( array(
			'post_type'      => 'team',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
		) );
		$numbers = array();
		foreach ( $teams as $team ) {
			$number = trim( (string) get_post_meta( $team->ID, 'swiss_unihockey_team_number', true ) );
			if ( '' !== $number ) {
				$numbers[ $number ] = $team->post_title;
			}
		}

		if ( ! $numbers ) {
			$this->add( self::SKIP, __( 'Spiele & Tabellen', 'uhc-laupen-importer' ), __( 'Keine Teamnummern hinterlegt.', 'uhc-laupen-importer' ) );
		}

		foreach ( $numbers as $number => $title ) {
			$this->check_api_team( $number, $title, $season );
		}

		// 5. The teams configured for the front page must still exist.
		$this->check_frontpage_teams( $club_teams );
	}

	/**
	 * Games feed + rankings for a single team, exactly as the theme reads them
	 * — including the theme's fallback to earlier seasons, which keeps the
	 * table populated during the summer break.
	 *
	 * @param string $team_id Swiss Unihockey team ID.
	 * @param string $title   Team post title, for the message.
	 * @param int    $season  Season to start from.
	 */
	private function check_api_team( $team_id, $title, $season ) {
		$label = sprintf(
			/* translators: 1: team title, 2: swiss unihockey team id */
			__( 'Spiele & Tabelle: %1$s (%2$s)', 'uhc-laupen-importer' ),
			$title,
			$team_id
		);

		$games_count = null;
		$last_error  = null;

		// uhc_laupen_get_rankings() walks back up to two seasons — do the same,
		// so a check in August does not report an off-season as a failure.
		for ( $s = (int) $season; $s >= (int) $season - 2; $s-- ) {
			$games = $this->api_get( 'games', array(
				'mode'    => 'team',
				'team_id' => $team_id,
				'season'  => $s,
			) );

			if ( is_wp_error( $games ) ) {
				$last_error = $games;
				continue;
			}

			$rows = $games['data']['regions'][0]['rows'] ?? array();
			$ids  = $games['data']['tabs'][0]['link']['ids'] ?? array();

			if ( null === $games_count ) {
				$games_count = count( $rows );
			}

			if ( count( $ids ) < 4 ) {
				continue; // No league assignment for this season.
			}

			$rankings = $this->api_get( 'rankings', array(
				'season'     => $ids[0],
				'league'     => $ids[1],
				'game_class' => $ids[2],
				'group'      => $ids[3],
			) );

			if ( is_wp_error( $rankings ) ) {
				$last_error = $rankings;
				continue;
			}

			$table = $rankings['data']['regions'][0]['rows'] ?? array();
			if ( ! $table ) {
				continue; // Season has no table yet — try the previous one.
			}

			// The theme resolves the columns by header text — if those ever
			// change, the table silently shows the wrong numbers.
			$headers = array();
			foreach ( (array) ( $rankings['data']['headers'] ?? array() ) as $header ) {
				$headers[] = isset( $header['text'] ) ? trim( implode( '', (array) $header['text'] ) ) : '';
			}
			$missing_headers = array_diff( array( 'Rg.', 'Team', 'Sp', 'TD', 'P' ), $headers );

			if ( $missing_headers ) {
				$this->add(
					self::WARN,
					$label,
					sprintf(
						/* translators: %s: comma-separated column names */
						__( 'Tabelle geladen, aber die Spalten %s fehlen — die Anzeige nutzt dann Ersatzspalten.', 'uhc-laupen-importer' ),
						implode( ', ', $missing_headers )
					)
				);
				return;
			}

			$message = sprintf(
				/* translators: 1: number of games, 2: number of ranking rows, 3: season */
				__( '%1$d Spiele, Tabelle mit %2$d Mannschaften (Saison %3$d).', 'uhc-laupen-importer' ),
				$games_count,
				count( $table ),
				$s
			);

			// A table from an earlier season is what the front end shows too,
			// but it is worth pointing out outside the summer break.
			$this->add( $s === (int) $season ? self::PASS : self::WARN, $label, $message );
			return;
		}

		if ( $last_error ) {
			$this->add( self::FAIL, $label, $last_error->get_error_message() );
			return;
		}

		$this->add(
			self::WARN,
			$label,
			sprintf(
				/* translators: 1: number of games, 2: season */
				__( '%1$d Spiele in Saison %2$d, aber keine Tabelle in den letzten drei Saisons — Teamnummer prüfen.', 'uhc-laupen-importer' ),
				(int) $games_count,
				(int) $season
			)
		);
	}

	/**
	 * The teams shown on the front page (games, table, next games) are picked
	 * in the Customizer. Verify that each one still belongs to the club, and
	 * point out when the old hardcoded fallback is still in use.
	 *
	 * @param array $club_teams API team ID => team name, from clubs/…/statistics.
	 */
	private function check_frontpage_teams( $club_teams ) {
		$label = __( 'Teams auf der Startseite', 'uhc-laupen-importer' );

		if ( ! function_exists( 'uhc_laupen_frontpage_teams' ) ) {
			$this->add( self::SKIP, $label, __( 'Theme-Funktion nicht verfügbar.', 'uhc-laupen-importer' ) );
			return;
		}

		// Teams in the Leistungssport category whose Swiss-Unihockey-Nummer is
		// missing simply do not appear on the front page.
		$missing = function_exists( 'uhc_laupen_frontpage_teams_without_id' )
			? wp_list_pluck( uhc_laupen_frontpage_teams_without_id(), 'post_title' )
			: array();

		$teams = uhc_laupen_frontpage_teams();
		if ( ! $teams ) {
			$this->add(
				self::FAIL,
				$label,
				$missing
					? sprintf(
						/* translators: %s: comma-separated team titles */
						__( 'Kein Team auf der Startseite: bei keinem Leistungssport-Team ist eine Swiss-Unihockey-Nummer hinterlegt (%s).', 'uhc-laupen-importer' ),
						implode( ', ', $missing )
					)
					: __( 'Kein Team auf der Startseite: es gibt keine veröffentlichten Leistungssport-Teams.', 'uhc-laupen-importer' )
			);
			return;
		}

		$summary = array();
		$unknown = array();

		foreach ( $teams as $team ) {
			$id   = (string) $team['team_id'];
			$name = $club_teams[ $id ] ?? '';

			if ( $club_teams && '' === $name ) {
				$unknown[] = $team['label'] . ' (ID ' . $id . ')';
			}

			$summary[] = $team['label'] . ' → ' . ( $name ? $name : 'ID ' . $id );
		}

		if ( $unknown ) {
			$this->add(
				self::FAIL,
				$label,
				sprintf(
					/* translators: %s: comma-separated team names with IDs */
					__( 'Diese Teams gehören laut API nicht (mehr) zum Verein: %s. Swiss-Unihockey-Nummer auf der Team-Seite korrigieren.', 'uhc-laupen-importer' ),
					implode( ', ', $unknown )
				)
			);
			return;
		}

		if ( $missing ) {
			$this->add(
				self::WARN,
				$label,
				sprintf(
					/* translators: 1: teams shown, 2: teams without a number */
					__( 'Angezeigt: %1$s. Ohne Swiss-Unihockey-Nummer und darum nicht auf der Startseite: %2$s', 'uhc-laupen-importer' ),
					implode( ', ', $summary ),
					implode( ', ', $missing )
				)
			);
			return;
		}

		$this->add(
			self::PASS,
			$label,
			sprintf(
				/* translators: 1: number of tabs, 2: list of teams */
				__( '%1$d Leistungssport-Teams: %2$s', 'uhc-laupen-importer' ),
				count( $teams ),
				implode( ', ', $summary )
			)
		);
	}

	/**
	 * Direct API request — no transient cache is read or written.
	 *
	 * @param string $endpoint Endpoint path.
	 * @param array  $args     Query args.
	 * @param int    $retries  How often to retry a connection error. Default 1.
	 * @return array|WP_Error Decoded response.
	 */
	private function api_get( $endpoint, $args = array(), $retries = 1 ) {
		$url = 'https://api-v2.swissunihockey.ch/api/' . $endpoint;
		if ( $args ) {
			$url = add_query_arg( $args, $url );
		}

		// The API regularly needs several seconds; single slow responses should
		// not be reported as an outage, so we retry once on a transport error.
		$response = wp_remote_get( $url, array( 'timeout' => 30 ) );
		if ( is_wp_error( $response ) && $retries > 0 ) {
			$response = wp_remote_get( $url, array( 'timeout' => 30 ) );
		}

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return new WP_Error(
				'api_error',
				sprintf(
					/* translators: 1: HTTP status code, 2: request URL */
					__( 'HTTP %1$d für %2$s', 'uhc-laupen-importer' ),
					$code,
					$url
				)
			);
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return new WP_Error( 'json_error', __( 'Antwort ist kein gültiges JSON.', 'uhc-laupen-importer' ) );
		}

		return $data;
	}

	// ── Helpers ───────────────────────────────────────────────────────────

	/**
	 * Row counts used to prove the dry run wrote nothing.
	 *
	 * @return array
	 */
	private function db_fingerprint() {
		global $wpdb;

		return array(
			'spieler'  => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s", 'spieler' ) ),
			'team'     => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s", 'team' ) ),
			'posts'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts}" ),
			'postmeta' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta}" ),
		);
	}

	/**
	 * Assert that a value equals the expected one.
	 *
	 * @param string $label    Check name.
	 * @param mixed  $actual   Actual value.
	 * @param mixed  $expected Expected value.
	 * @param string $note     Message shown when the check passes.
	 */
	private function expect( $label, $actual, $expected, $note = '' ) {
		if ( $actual === $expected ) {
			$this->add( self::PASS, $label, $note ? $note : $this->describe( $actual ) );
			return;
		}

		$this->add(
			self::FAIL,
			$label,
			sprintf(
				/* translators: 1: expected value, 2: actual value */
				__( 'Erwartet: %1$s — erhalten: %2$s', 'uhc-laupen-importer' ),
				$this->describe( $expected ),
				$this->describe( $actual )
			)
		);
	}

	/**
	 * Printable form of a value.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function describe( $value ) {
		if ( is_scalar( $value ) || null === $value ) {
			return (string) wp_json_encode( $value );
		}
		return (string) wp_json_encode( $value, JSON_UNESCAPED_UNICODE );
	}

	/**
	 * Record a result.
	 *
	 * @param string $status  One of PASS/WARN/FAIL/SKIP.
	 * @param string $label   Check name.
	 * @param string $message Detail.
	 */
	private function add( $status, $label, $message = '' ) {
		$this->results[] = array(
			'group'   => $this->group,
			'status'  => $status,
			'label'   => $label,
			'message' => $message,
		);
	}
}
