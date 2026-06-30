<?php
/**
 * Player Writer — creates or updates 'spieler' posts from parsed CSV rows.
 *
 * Requires ACF to be active. Uses update_field() to set all ACF fields,
 * including the 'team' relationship field which links the player to the
 * correct team post by ID.
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
	 * In-memory team post cache to avoid repeated WP_Query calls.
	 *
	 * @var array
	 */
	private $team_cache = array();

	/**
	 * @param bool $dry_run Pass true to skip all database writes.
	 */
	public function __construct( $dry_run = false ) {
		$this->dry_run = $dry_run;
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
			'name'        => $full_name,
			'status'      => 'error',
			'status_label'=> __( 'Fehler', 'uhc-laupen-importer' ),
			'team_title'  => '',
			'message'     => '',
		);

		if ( empty( $full_name ) ) {
			$result['message'] = __( 'Kein Name vorhanden.', 'uhc-laupen-importer' );
			return $result;
		}

		// Find the linked team post.
		// team_id_override is set when the user manually assigned a team in the preview step.
		if ( ! empty( $row['team_id_override'] ) ) {
			$team_post = get_post( absint( $row['team_id_override'] ) );
		} else {
			$team_post = $this->find_team( $row['team'] ?? '' );
		}
		$team_id    = $team_post ? $team_post->ID : null;
		$team_title = $team_post ? $team_post->post_title : '';
		$result['team_title'] = $team_title ?: ( $row['team'] ?: '—' );

		if ( ! $team_post ) {
			$result['message'] = sprintf(
				/* translators: %s: team name from CSV */
				__( 'Team "%s" nicht gefunden — Spieler wird ohne Teamverknüpfung angelegt.', 'uhc-laupen-importer' ),
				$row['team']
			);
		}

		// Check for existing spieler post (duplicate detection).
		$existing = $this->find_existing_spieler( $full_name );

		if ( $this->dry_run ) {
			$result['status']       = $existing ? 'updated' : 'created';
			$result['status_label'] = $existing
				? __( '(Probelauf) Würde aktualisieren', 'uhc-laupen-importer' )
				: __( '(Probelauf) Würde erstellen', 'uhc-laupen-importer' );
			$result['message']      = $result['message'] ?: __( 'Probelauf — keine Änderungen gespeichert.', 'uhc-laupen-importer' );
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
			$result['status']       = 'error';
			$result['status_label'] = __( 'Fehler', 'uhc-laupen-importer' );
			$result['message']      = $post_id->get_error_message();
			return $result;
		}

		// Write ACF fields — requires ACF to be active.
		if ( function_exists( 'update_field' ) ) {
			update_field( 'vorname',       $vorname,                  $post_id );
			update_field( 'nachname',      $nachname,                 $post_id );
			update_field( 'spielernummer', $row['spielernummer'] ?? '', $post_id );
			update_field( 'position',      $row['position']     ?? '', $post_id );
			update_field( 'nationalitat',  $row['nationalitat'] ?? '', $post_id );
			update_field( 'geburtsdatum',  $row['geburtsdatum'] ?? '', $post_id );
			update_field( 'email',         $row['email']        ?? '', $post_id );

			// Relationship field: ACF expects an array of post IDs.
			if ( $team_id ) {
				update_field( 'teams', array( $team_id ), $post_id );
			}
		} else {
			// ACF not active — fall back to raw post meta so data isn't lost.
			update_post_meta( $post_id, 'vorname',       $vorname );
			update_post_meta( $post_id, 'nachname',      $nachname );
			update_post_meta( $post_id, 'spielernummer', $row['spielernummer'] ?? '' );
			update_post_meta( $post_id, 'position',      $row['position']     ?? '' );
			update_post_meta( $post_id, 'nationalitat',  $row['nationalitat'] ?? '' );
			update_post_meta( $post_id, 'geburtsdatum',  $row['geburtsdatum'] ?? '' );
			update_post_meta( $post_id, 'email',         $row['email']        ?? '' );
			if ( $team_id ) {
				update_post_meta( $post_id, 'teams', array( $team_id ) );
			}
			$result['message'] = __( 'ACF nicht aktiv — Daten als raw post meta gespeichert.', 'uhc-laupen-importer' );
		}

		$result['status']       = $existing ? 'updated' : 'created';
		$result['status_label'] = $existing
			? __( 'Aktualisiert', 'uhc-laupen-importer' )
			: __( 'Erstellt', 'uhc-laupen-importer' );

		if ( empty( $result['message'] ) ) {
			$result['message'] = sprintf(
				__( 'Post ID %d', 'uhc-laupen-importer' ),
				$post_id
			);
		}

		return $result;
	}

	/**
	 * Find a team post by title. Results are cached per request.
	 *
	 * @param string $team_name Team name string from the CSV.
	 * @return WP_Post|null
	 */
	private function find_team( $team_name ) {
		$team_name = trim( $team_name );
		if ( empty( $team_name ) ) {
			return null;
		}

		if ( array_key_exists( $team_name, $this->team_cache ) ) {
			return $this->team_cache[ $team_name ];
		}

		$posts = get_posts( array(
			'post_type'              => 'team',
			'post_status'            => 'publish',
			'title'                  => $team_name,
			'posts_per_page'         => 1,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		) );

		$this->team_cache[ $team_name ] = ! empty( $posts ) ? $posts[0] : null;
		return $this->team_cache[ $team_name ];
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
			'post_status'            => array( 'publish', 'draft' ),
			'title'                  => $full_name,
			'posts_per_page'         => 1,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		) );

		return ! empty( $posts ) ? $posts[0] : null;
	}
}
