<?php
/**
 * Media Matcher — finds attachments by a loosely matched file name.
 *
 * Used to auto-link player photos ("vorname.nachname") and sponsor logos
 * (value of the CSV "Sponsor" column) without requiring an exact spelling.
 *
 * Matching ignores case, umlauts (ü → ue *and* u), separators (. - _ space),
 * WordPress size suffixes ("-300x300") and duplicate suffixes ("-1").
 *
 * @package UHC_Laupen_Importer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UHC_Media_Matcher {

	/**
	 * normalised name => attachment ID.
	 *
	 * @var array|null
	 */
	private static $index = null;

	/**
	 * Find an attachment whose file name matches the given name.
	 *
	 * @param string $name Name to look for, e.g. "Dominic Künzler" or "Raiffeisen".
	 * @return int Attachment ID, or 0 when nothing matches.
	 */
	public static function find( $name ) {
		$name = trim( (string) $name );
		if ( '' === $name ) {
			return 0;
		}

		$index = self::index();
		foreach ( self::variants( $name ) as $variant ) {
			if ( isset( $index[ $variant ] ) ) {
				return $index[ $variant ];
			}
		}

		return 0;
	}

	/**
	 * Find the attachment for a player, trying several name spellings.
	 *
	 * @param string $vorname     First name.
	 * @param string $nachname    Last name.
	 * @param string $benutzer_id Optional ClubDesk login ("dominic.kuenzler").
	 * @return int Attachment ID, or 0.
	 */
	public static function find_player_photo( $vorname, $nachname, $benutzer_id = '' ) {
		$candidates = array(
			trim( $vorname . '.' . $nachname ),
			trim( $vorname . ' ' . $nachname ),
			trim( $nachname . '.' . $vorname ),
			trim( $nachname . ' ' . $vorname ),
		);
		if ( $benutzer_id ) {
			array_unshift( $candidates, $benutzer_id );
		}

		foreach ( $candidates as $candidate ) {
			$id = self::find( $candidate );
			if ( $id ) {
				return $id;
			}
		}

		return 0;
	}

	/**
	 * Resolve a "Sponsor" cell into attachment IDs. Several sponsors may be
	 * listed separated by comma, semicolon, slash or pipe.
	 *
	 * @param string $value Raw cell value.
	 * @return array{ids:int[],missing:string[]}
	 */
	public static function find_sponsors( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return array( 'ids' => array(), 'missing' => array() );
		}

		$names   = preg_split( '/[,;\/|]+/', $value );
		$ids     = array();
		$missing = array();

		foreach ( $names as $name ) {
			$name = trim( $name );
			if ( '' === $name ) {
				continue;
			}
			$id = self::find( $name );
			if ( $id ) {
				$ids[] = $id;
			} else {
				$missing[] = $name;
			}
		}

		return array( 'ids' => array_values( array_unique( $ids ) ), 'missing' => $missing );
	}

	/**
	 * Build (once per request) the lookup table of all attachments.
	 *
	 * @return array normalised name => attachment ID.
	 */
	private static function index() {
		if ( null !== self::$index ) {
			return self::$index;
		}

		global $wpdb;
		self::$index = array();

		$rows = $wpdb->get_results(
			"SELECT p.ID, p.post_title, p.post_name, m.meta_value AS file
			   FROM {$wpdb->posts} p
			   LEFT JOIN {$wpdb->postmeta} m
			          ON m.post_id = p.ID AND m.meta_key = '_wp_attached_file'
			  WHERE p.post_type = 'attachment'"
		);

		foreach ( $rows as $row ) {
			$names = array();

			if ( $row->file ) {
				$base = pathinfo( $row->file, PATHINFO_FILENAME );
				// Strip WordPress size ("-300x300") and duplicate ("-1") suffixes.
				$names[] = $base;
				$names[] = preg_replace( '/-\d+x\d+$/', '', $base );
				$names[] = preg_replace( '/-\d+$/', '', preg_replace( '/-\d+x\d+$/', '', $base ) );
			}
			$names[] = $row->post_title;
			$names[] = $row->post_name;

			foreach ( array_filter( array_unique( $names ) ) as $name ) {
				foreach ( self::variants( $name ) as $variant ) {
					// Keep the first (usually the original upload) on collisions.
					if ( ! isset( self::$index[ $variant ] ) ) {
						self::$index[ $variant ] = (int) $row->ID;
					}
				}
			}
		}

		return self::$index;
	}

	/**
	 * Normalised comparison forms: lowercase, umlauts folded both ways,
	 * everything non-alphanumeric removed.
	 *
	 * @param string $value Raw string.
	 * @return array Unique normalised variants.
	 */
	private static function variants( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return array();
		}

		$lower = function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );

		$common = array(
			'ß' => 'ss', 'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'å' => 'a',
			'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ì' => 'i', 'í' => 'i',
			'î' => 'i', 'ï' => 'i', 'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o',
			'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ç' => 'c', 'ñ' => 'n', 'ø' => 'o',
		);

		$long  = strtr( $lower, $common + array( 'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue' ) );
		$short = strtr( $lower, $common + array( 'ä' => 'a', 'ö' => 'o', 'ü' => 'u' ) );

		$variants = array();
		foreach ( array( $long, $short ) as $candidate ) {
			$clean = preg_replace( '/[^a-z0-9]/u', '', $candidate );
			if ( '' !== $clean ) {
				$variants[ $clean ] = true;
			}
		}

		return array_keys( $variants );
	}

	/**
	 * Reset the cached index (used after uploads during a long-running import).
	 */
	public static function flush() {
		self::$index = null;
	}
}
