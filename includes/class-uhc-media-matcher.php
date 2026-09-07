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

		// Middle names ("Mia Sarina Lilljeqvist") — photo files usually use
		// only the everyday first name ("10_mia_lilljeqvist"), so also try
		// the first word of the first name alone.
		$first = trim( (string) strtok( trim( $vorname ), " \t" ) );
		if ( $first && $first !== trim( $vorname ) ) {
			$candidates[] = $first . '.' . $nachname;
			$candidates[] = $nachname . '.' . $first;
		}

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
				$base    = pathinfo( $row->file, PATHINFO_FILENAME );
				$names[] = $base;

				// Iteratively strip WordPress suffixes from the end, in any
				// order/combination: size ("-300x300"), duplicate ("-1") and
				// big-image ("-scaled") — "Sabrina-Aerne-300x300-1" needs two
				// passes, "17_jessica_riedi-scaled" one.
				$stripped = $base;
				do {
					$prev     = $stripped;
					$stripped = preg_replace( '/(?:-scaled|-\d+x\d+|-\d+)$/', '', $stripped );
				} while ( $stripped !== $prev );
				$names[] = $stripped;

				// Live photo naming conventions around the plain name:
				// - jersey-number prefix: "12_lara_abderhalden"
				// - staff prefix:         "Staff-Yves-Kempf-Headcoach"
				// - role suffix:          "hanka_lackova_trainer.h1",
				//                         "Remo-Zysset-Assistenzcoach",
				//                         "leana_schoch_vorstand"
				// - percent-size suffix:  "Kempf_Yves_50p"
				$plain = preg_replace( '/^\d+[_-]+/', '', $stripped );
				$plain = preg_replace( '/^staff[_-]+/i', '', $plain );
				$plain = preg_replace( '/[_\-. ]+(trainer|assistenzcoach|headcoach|goalitrainer|coach|vorstand|staff)\b.*$/i', '', $plain );
				$plain = preg_replace( '/[_-]+\d+p$/i', '', $plain );
				if ( $plain !== $stripped ) {
					$names[] = $plain;
				}
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
