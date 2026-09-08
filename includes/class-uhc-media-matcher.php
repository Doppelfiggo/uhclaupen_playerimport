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
	 * Lookup tables, one per search scope:
	 * FileBird folder ID (0 = whole library) => [ normalised name => attachment ID ].
	 *
	 * @var array<int,array<string,int>>
	 */
	private static $indexes = array();

	/**
	 * Find an attachment whose file name matches the given name.
	 *
	 * @param string $name      Name to look for, e.g. "Dominic Künzler" or "Raiffeisen".
	 * @param int    $folder_id Optional FileBird folder ID — restricts the search
	 *                          to that folder and its subfolders. 0 = whole library.
	 * @return int Attachment ID, or 0 when nothing matches.
	 */
	public static function find( $name, $folder_id = 0 ) {
		$name = trim( (string) $name );
		if ( '' === $name ) {
			return 0;
		}

		$index = self::index( (int) $folder_id );
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

		// Optional FileBird scope (settings page): only search the configured
		// player folder — keeps a player photo from being confused with a
		// same-named sponsor logo (and vice versa).
		$folder = self::configured_folder( 'player' );

		foreach ( $candidates as $candidate ) {
			$id = self::find( $candidate, $folder );
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

		// Optional FileBird scope (settings page) — see find_player_photo().
		$folder = self::configured_folder( 'sponsor' );

		foreach ( $names as $name ) {
			$name = trim( $name );
			if ( '' === $name ) {
				continue;
			}
			$id = self::find( $name, $folder );
			if ( $id ) {
				$ids[] = $id;
			} else {
				$missing[] = $name;
			}
		}

		return array( 'ids' => array_values( array_unique( $ids ) ), 'missing' => $missing );
	}

	/**
	 * The FileBird folder configured for a lookup type, from the settings page.
	 *
	 * @param string $type 'player' or 'sponsor'.
	 * @return int FileBird folder ID, 0 = whole library.
	 */
	private static function configured_folder( $type ) {
		if ( ! class_exists( 'UHC_Importer_Settings' ) ) {
			return 0;
		}

		return 'sponsor' === $type
			? UHC_Importer_Settings::get_sponsor_folder()
			: UHC_Importer_Settings::get_player_folder();
	}

	/**
	 * Build (once per request and scope) the lookup table of attachments.
	 *
	 * @param int $folder_id FileBird folder scope, 0 = whole library.
	 * @return array normalised name => attachment ID.
	 */
	private static function index( $folder_id = 0 ) {
		$folder_id = max( 0, (int) $folder_id );

		if ( isset( self::$indexes[ $folder_id ] ) ) {
			return self::$indexes[ $folder_id ];
		}

		global $wpdb;

		$where = '';
		if ( $folder_id > 0 ) {
			$subtree = self::filebird_subtree( $folder_id );

			if ( null === $subtree ) {
				// FileBird is gone or the folder was deleted — the scope can't
				// be applied, so behave like "whole library" instead of
				// silently matching nothing.
				self::$indexes[ $folder_id ] = self::index( 0 );
				return self::$indexes[ $folder_id ];
			}

			$in    = implode( ',', array_map( 'intval', $subtree ) );
			$where = " AND p.ID IN (
				SELECT attachment_id FROM {$wpdb->prefix}fbv_attachment_folder
				 WHERE folder_id IN ( {$in} )
			)";
		}

		self::$indexes[ $folder_id ] = array();

		$rows = $wpdb->get_results(
			"SELECT p.ID, p.post_title, p.post_name, m.meta_value AS file
			   FROM {$wpdb->posts} p
			   LEFT JOIN {$wpdb->postmeta} m
			          ON m.post_id = p.ID AND m.meta_key = '_wp_attached_file'
			  WHERE p.post_type = 'attachment'{$where}"
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
					if ( ! isset( self::$indexes[ $folder_id ][ $variant ] ) ) {
						self::$indexes[ $folder_id ][ $variant ] = (int) $row->ID;
					}
				}
			}
		}

		return self::$indexes[ $folder_id ];
	}

	/**
	 * All FileBird folder IDs in the subtree of the given folder (itself
	 * included), or null when FileBird's tables are missing or the folder
	 * doesn't exist (deleted after being configured).
	 *
	 * @param int $folder_id FileBird folder ID.
	 * @return int[]|null
	 */
	private static function filebird_subtree( $folder_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'fbv';
		if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
			return null;
		}

		$rows = $wpdb->get_results( "SELECT id, parent FROM {$table}", ARRAY_A );

		$children = array();
		$known    = array();
		foreach ( (array) $rows as $row ) {
			$known[ (int) $row['id'] ]        = true;
			$children[ (int) $row['parent'] ][] = (int) $row['id'];
		}

		if ( ! isset( $known[ $folder_id ] ) ) {
			return null;
		}

		$subtree = array();
		$queue   = array( $folder_id );
		while ( $queue ) {
			$id        = array_shift( $queue );
			$subtree[] = $id;
			if ( isset( $children[ $id ] ) ) {
				foreach ( $children[ $id ] as $child ) {
					$queue[] = $child;
				}
			}
		}

		return $subtree;
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
	 * Reset the cached indexes (used after uploads during a long-running import).
	 */
	public static function flush() {
		self::$indexes = array();
	}
}
