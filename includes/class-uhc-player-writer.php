<?php
/**
 * Player Writer — creates or updates 'spieler' posts from parsed CSV rows.
 *
 * Requires ACF to be active. Uses update_field() to set all ACF fields,
 * including the 'teams' relationship field which links the player to the
 * correct team post by ID, plus the auto-matched media (player photo and
 * sponsor logo).
 *
 * @package UHC_Laupen_Importer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UHC_Player_Writer {

	/**
	 * Whether this is a dry run (no DB writes).
	 *
	 * @var bool
	 */
	private $dry_run;

	/**
	 * Shared team lookup cache (team name => WP_Post|null).
	 *
	 * @var array
	 */
	private static $team_cache = array();

	/**
	 * All team posts, loaded once per request.
	 *
	 * @var WP_Post[]|null
	 */
	private static $teams = null;

	/**
	 * IDs of all spieler posts touched during this run — used by the
	 * "fresh import" mode to decide what to trash.
	 *
	 * @var int[]
	 */
	private $touched = array();

	/**
	 * @param bool $dry_run Pass true to skip all database writes.
	 */
	public function __construct( $dry_run = false ) {
		$this->dry_run = $dry_run;
	}

	/**
	 * Post IDs created or updated during this run.
	 *
	 * @return int[]
	 */
	public function get_touched_ids() {
		return array_values( array_unique( $this->touched ) );
	}

	/**
	 * Write a single player row.
	 *
	 * @param array $row Parsed row from UHC_CSV_Parser::parse().
	 * @return array Result array with keys: name, status, status_label, team_title, message.
	 */
	public function write( $row ) {
		$vorname   = $row['vorname']  ?? '';
		$nachname  = $row['nachname'] ?? '';
		$full_name = trim( $vorname . ' ' . $nachname );

		$result = array(
			'name'         => $full_name,
			'status'       => 'error',
			'status_label' => __( 'Fehler', 'uhc-laupen-importer' ),
			'team_title'   => '',
			'message'      => '',
			'photo'        => false,
			'sponsor'      => false,
		);

		if ( '' === $full_name ) {
			$result['message'] = __( 'Kein Name vorhanden.', 'uhc-laupen-importer' );
			return $result;
		}

		// Find the linked team post.
		// team_id_override is set when the user manually assigned a team in the preview step.
		if ( ! empty( $row['team_id_override'] ) ) {
			$team_post = get_post( absint( $row['team_id_override'] ) );
		} else {
			$team_post = self::find_team( $row['team'] ?? '' );
		}
		$team_id              = $team_post ? $team_post->ID : null;
		$result['team_title'] = $team_post ? $team_post->post_title : ( $row['team'] ?: '—' );

		$notes = array();
		if ( ! $team_post ) {
			$notes[] = sprintf(
				/* translators: %s: team name from CSV */
				__( 'Team „%s“ nicht gefunden — ohne Teamverknüpfung.', 'uhc-laupen-importer' ),
				$row['team'] ?: '?'
			);
		}

		// Media matching (also runs in the dry run so the preview is honest).
		$photo_id = UHC_Media_Matcher::find_player_photo( $vorname, $nachname, $row['benutzer_id'] ?? '' );
		$sponsors = UHC_Media_Matcher::find_sponsors( $row['sponsor'] ?? '' );

		$result['photo']   = (bool) $photo_id;
		$result['sponsor'] = ! empty( $sponsors['ids'] );

		if ( ! empty( $sponsors['missing'] ) ) {
			$notes[] = sprintf(
				/* translators: %s: comma separated sponsor names */
				__( 'Sponsorenbild nicht gefunden: %s', 'uhc-laupen-importer' ),
				implode( ', ', $sponsors['missing'] )
			);
		}

		$existing = $this->find_existing_spieler( $full_name );

		if ( $this->dry_run ) {
			if ( $existing ) {
				$this->touched[] = $existing->ID;
			}
			$result['status']       = $existing ? 'updated' : 'created';
			$result['status_label'] = $existing
				? __( '(Probelauf) Würde aktualisieren', 'uhc-laupen-importer' )
				: __( '(Probelauf) Würde erstellen', 'uhc-laupen-importer' );
			$result['message'] = implode( ' ', $notes );
			return $result;
		}

		// Create or update the spieler post.
		if ( $existing ) {
			$post_id = wp_update_post( array(
				'ID'          => $existing->ID,
				'post_title'  => $full_name,
				'post_status' => 'publish',
				'post_type'   => 'spieler',
			), true );
		} else {
			$post_id = wp_insert_post( array(
				'post_title'  => $full_name,
				'post_status' => 'publish',
				'post_type'   => 'spieler',
			), true );
		}

		if ( is_wp_error( $post_id ) ) {
			$result['message'] = $post_id->get_error_message();
			return $result;
		}

		$this->touched[] = (int) $post_id;

		$fields = array(
			'vorname'       => $vorname,
			'nachname'      => $nachname,
			'spielernummer' => $row['spielernummer'] ?? '',
			'position'      => $row['position'] ?? '',
			'geburtsdatum'  => $row['geburtsdatum'] ?? '',
			'email'         => $row['email'] ?? '',
		);
		// Only write the nationality when the CSV actually has one, so an empty
		// cell doesn't wipe a manually curated value.
		if ( ! empty( $row['nationalitat'] ) ) {
			$fields['nationalitat'] = $row['nationalitat'];
		}

		if ( function_exists( 'update_field' ) ) {
			foreach ( $fields as $key => $value ) {
				update_field( $key, $value, $post_id );
			}
			if ( $team_id ) {
				update_field( 'teams', array( $team_id ), $post_id );
			}
			if ( $photo_id ) {
				update_field( 'spielerbild', $photo_id, $post_id );
			}
			if ( ! empty( $sponsors['ids'] ) ) {
				update_field( 'sponsorenbild', $sponsors['ids'], $post_id );
			}
		} else {
			// ACF not active — fall back to raw post meta so data isn't lost.
			foreach ( $fields as $key => $value ) {
				update_post_meta( $post_id, $key, $value );
			}
			if ( $team_id ) {
				update_post_meta( $post_id, 'teams', array( $team_id ) );
			}
			if ( $photo_id ) {
				update_post_meta( $post_id, 'spielerbild', $photo_id );
			}
			$notes[] = __( 'ACF nicht aktiv — Daten als raw post meta gespeichert.', 'uhc-laupen-importer' );
		}

		$result['status']       = $existing ? 'updated' : 'created';
		$result['status_label'] = $existing
			? __( 'Aktualisiert', 'uhc-laupen-importer' )
			: __( 'Erstellt', 'uhc-laupen-importer' );
		$result['message']      = implode( ' ', $notes );

		return $result;
	}

	/**
	 * Find a team post by title. Results are cached per request.
	 *
	 * Tries an exact title match first, then a case-insensitive one, then a
	 * unique "starts with" match (so "U21 Junioren" finds
	 * "U21 Junioren (Jg. 05-07)"). Ambiguous prefixes are left unmatched so
	 * the user assigns them manually in the preview.
	 *
	 * @param string $team_name Team name string from the CSV.
	 * @return WP_Post|null
	 */
	public static function find_team( $team_name ) {
		$team_name = trim( (string) $team_name );
		if ( '' === $team_name ) {
			return null;
		}

		if ( array_key_exists( $team_name, self::$team_cache ) ) {
			return self::$team_cache[ $team_name ];
		}

		if ( null === self::$teams ) {
			self::$teams = get_posts( array(
				'post_type'              => 'team',
				'post_status'            => 'publish',
				'posts_per_page'         => -1,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			) );
		}

		$match = null;
		$teams = self::$teams;

		foreach ( $teams as $team ) {
			if ( $team->post_title === $team_name ) {
				$match = $team;
				break;
			}
		}

		if ( ! $match ) {
			foreach ( $teams as $team ) {
				if ( 0 === strcasecmp( $team->post_title, $team_name ) ) {
					$match = $team;
					break;
				}
			}
		}

		if ( ! $match ) {
			$prefix_hits = array();
			foreach ( $teams as $team ) {
				if ( 0 === stripos( $team->post_title, $team_name ) ) {
					$prefix_hits[] = $team;
				}
			}
			if ( 1 === count( $prefix_hits ) ) {
				$match = $prefix_hits[0];
			}
		}

		self::$team_cache[ $team_name ] = $match;
		return $match;
	}

	/**
	 * Find an existing spieler post by full name (post title).
	 *
	 * @param string $full_name "Vorname Nachname".
	 * @return WP_Post|null
	 */
	private function find_existing_spieler( $full_name ) {
		$posts = get_posts( array(
			'post_type'              => 'spieler',
			'post_status'            => array( 'publish', 'draft', 'pending', 'private' ),
			'title'                  => $full_name,
			'posts_per_page'         => 1,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		) );

		return ! empty( $posts ) ? $posts[0] : null;
	}
}
