<?php
/**
 * CSV Parser for ClubDesk exports.
 *
 * Reads a semicolon-separated ClubDesk export, resolves the columns we need
 * (tolerant of spelling/case/umlaut variants) and returns a clean array of
 * player rows ready for import.
 *
 * @package UHC_Laupen_Importer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UHC_CSV_Parser {

	/**
	 * Accepted header spellings per internal key, best match first.
	 * Matching is case-insensitive and ignores umlauts, spaces and punctuation
	 * ("Rückennummer", "RUECKENNUMMER" and "rueckenummer" all match).
	 *
	 * @var array
	 */
	private $column_candidates = array(
		'vorname'       => array( 'Vorname', 'First name', 'Firstname' ),
		'nachname'      => array( 'Nachname', 'Name', 'Last name', 'Lastname' ),
		'spielernummer' => array( 'Rückennummer', 'Rückenummer', 'Rueckennummer', 'Spielernummer', 'Trikotnummer', 'Nummer', 'Nr.', 'Nr' ),
		'position'      => array( 'Funktion', 'Position' ),
		'nationalitat'  => array( 'Nationalität', 'Nationalitaet', 'Nation' ),
		'geburtsdatum'  => array( 'Geburtsdatum', 'Geburtstag' ),
		'team'          => array( 'Team', 'Mannschaft' ),
		'gruppe'        => array( 'Gruppe', 'Gruppen' ),
		'email'         => array( 'E-Mail', 'EMail', 'Email', 'E-Mail Adresse' ),
		'sponsor'       => array( 'Sponsor', 'Sponsoren' ),
		'benutzer_id'   => array( 'Benutzer-Id', 'Benutzer-ID', 'BenutzerId', 'Login' ),
	);

	/**
	 * Columns without which an import makes no sense.
	 *
	 * @var array
	 */
	private $required = array( 'vorname', 'nachname' );

	/**
	 * Gruppe values that identify importable people (players + staff).
	 * Rows with any other Gruppe value are skipped. If the file has no
	 * Gruppe column at all, no filtering happens.
	 *
	 * @var array
	 */
	private $valid_groups = array( 'Spieler', 'Staff' );

	/**
	 * Parse a CSV file and return structured player rows.
	 *
	 * @param string $file_path        Absolute path to the uploaded CSV.
	 * @param string $number_column    Optional exact header name to use for the
	 *                                 jersey number (user choice when auto-detection failed).
	 * @return array|WP_Error Array with 'rows', 'skipped', 'headers' and 'resolved', or WP_Error.
	 */
	public function parse( $file_path, $number_column = '' ) {
		if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
			return new WP_Error( 'file_not_found', __( 'Datei nicht gefunden oder nicht lesbar.', 'uhc-laupen-importer' ) );
		}

		$raw = file_get_contents( $file_path );
		if ( false === $raw ) {
			return new WP_Error( 'file_read_error', __( 'Datei konnte nicht gelesen werden.', 'uhc-laupen-importer' ) );
		}

		/*
		 * Encoding: only convert when the content is NOT already valid UTF-8.
		 * mb_detect_encoding() happily reports ISO-8859-1 for valid UTF-8 files,
		 * and converting those mangles every umlaut ("Rückennummer" →
		 * "RÃ¼ckennummer"), which is why column detection used to fail.
		 */
		if ( ! mb_check_encoding( $raw, 'UTF-8' ) ) {
			$encoding = mb_detect_encoding( $raw, array( 'UTF-8', 'Windows-1252', 'ISO-8859-1' ), true );
			$raw      = mb_convert_encoding( $raw, 'UTF-8', $encoding ? $encoding : 'Windows-1252' );
		}

		// Remove BOM if present.
		$raw = preg_replace( '/^\xEF\xBB\xBF/', '', $raw );

		$separator = $this->detect_separator( strtok( $raw, "\n" ) );

		// Proper CSV reading — ClubDesk exports contain quoted fields with
		// embedded newlines (multi-line "Bemerkungen"), so we cannot split
		// the file on "\n" ourselves.
		$rows_raw = $this->read_csv( $raw, $separator );
		if ( empty( $rows_raw ) ) {
			return new WP_Error( 'empty_file', __( 'Die Datei ist leer.', 'uhc-laupen-importer' ) );
		}

		$headers = array_map( 'trim', array_shift( $rows_raw ) );

		// Resolve which header belongs to which internal key.
		$resolved = $this->resolve_columns( $headers );

		// Explicit user choice for the jersey number column wins.
		if ( $number_column && in_array( $number_column, $headers, true ) ) {
			$resolved['spielernummer'] = $number_column;
		}

		$missing = array();
		foreach ( $this->required as $key ) {
			if ( empty( $resolved[ $key ] ) ) {
				$missing[] = $this->column_candidates[ $key ][0];
			}
		}
		if ( $missing ) {
			return new WP_Error(
				'missing_columns',
				sprintf(
					/* translators: %s: comma-separated list of missing column names */
					__( 'Fehlende Spalten in der CSV: %s', 'uhc-laupen-importer' ),
					implode( ', ', $missing )
				)
			);
		}

		$rows    = array();
		$skipped = 0;

		foreach ( $rows_raw as $cells ) {
			// Pad short rows to avoid undefined offset warnings.
			while ( count( $cells ) < count( $headers ) ) {
				$cells[] = '';
			}
			// Ignore surplus cells so array_combine() does not fail.
			$cells = array_slice( $cells, 0, count( $headers ) );

			$raw_row = array_combine( $headers, $cells );
			if ( ! $raw_row ) {
				continue;
			}

			$get = function ( $key ) use ( $raw_row, $resolved ) {
				$header = $resolved[ $key ] ?? '';
				return $header && isset( $raw_row[ $header ] ) ? trim( $raw_row[ $header ] ) : '';
			};

			$gruppe = $get( 'gruppe' );

			// Skip rows that aren't players or staff (only when we have a Gruppe column).
			if ( ! empty( $resolved['gruppe'] ) && ! $this->is_valid_gruppe( $gruppe ) ) {
				$skipped++;
				continue;
			}

			$vorname  = $get( 'vorname' );
			$nachname = $get( 'nachname' );
			if ( '' === $vorname && '' === $nachname ) {
				$skipped++;
				continue;
			}

			$row = array(
				'vorname'       => $vorname,
				'nachname'      => $nachname,
				'spielernummer' => $get( 'spielernummer' ),
				'position'      => $get( 'position' ),
				'nationalitat'  => $get( 'nationalitat' ),
				'geburtsdatum'  => $get( 'geburtsdatum' ),
				'team'          => $get( 'team' ),
				'gruppe'        => $gruppe,
				'email'         => $get( 'email' ),
				'sponsor'       => $get( 'sponsor' ),
				'benutzer_id'   => $get( 'benutzer_id' ),
			);

			// Normalise jersey number (CSV may deliver "46.0").
			if ( '' !== $row['spielernummer'] ) {
				$row['spielernummer'] = (string) intval( floatval( str_replace( ',', '.', $row['spielernummer'] ) ) );
				if ( '0' === $row['spielernummer'] ) {
					$row['spielernummer'] = '';
				}
			}

			$row['geburtsdatum'] = $this->normalise_date( $row['geburtsdatum'] );
			$row['position']     = $this->normalise_position( $row['position'], $gruppe );

			// Team fallback: derive from the Gruppe value ("Staff Herren 1" → "Herren 1").
			if ( '' === $row['team'] && $gruppe ) {
				$row['team'] = trim( preg_replace( '/^\s*(Spieler|Staff)\s*/i', '', $gruppe ) );
			}

			$rows[] = $row;
		}

		if ( empty( $rows ) ) {
			return new WP_Error( 'no_rows', __( 'Keine importierbaren Datensätze gefunden. Prüfe, ob die Spalte „Gruppe“ Werte wie „Spieler Herren 1“ enthält.', 'uhc-laupen-importer' ) );
		}

		return array(
			'rows'     => $rows,
			'skipped'  => $skipped,
			'headers'  => $headers,
			'resolved' => $resolved,
		);
	}

	/**
	 * Read the whole CSV string into rows, honouring quoted fields that may
	 * contain the separator or newlines.
	 *
	 * @param string $raw       Full file content (UTF-8).
	 * @param string $separator Field separator.
	 * @return array List of cell arrays.
	 */
	private function read_csv( $raw, $separator ) {
		$handle = fopen( 'php://temp', 'r+' );
		fwrite( $handle, $raw );
		rewind( $handle );

		$rows = array();
		while ( false !== ( $cells = fgetcsv( $handle, 0, $separator, '"', '\\' ) ) ) {
			// Skip completely empty lines.
			if ( array( null ) === $cells || ( 1 === count( $cells ) && ( null === $cells[0] || '' === trim( (string) $cells[0] ) ) ) ) {
				continue;
			}
			$rows[] = array_map( fn( $c ) => is_string( $c ) ? trim( $c ) : '', $cells );
		}
		fclose( $handle );

		return $rows;
	}

	/**
	 * Map internal keys to the actual header strings of this file.
	 *
	 * @param array $headers Header row.
	 * @return array key => header string (missing keys hold '').
	 */
	private function resolve_columns( $headers ) {
		// Pre-normalise all headers once.
		$normalised = array();
		foreach ( $headers as $header ) {
			foreach ( $this->normalise_variants( $header ) as $variant ) {
				// First occurrence wins — CSVs may repeat header names.
				if ( ! isset( $normalised[ $variant ] ) ) {
					$normalised[ $variant ] = $header;
				}
			}
		}

		$resolved = array();
		foreach ( $this->column_candidates as $key => $candidates ) {
			$resolved[ $key ] = '';
			foreach ( $candidates as $candidate ) {
				foreach ( $this->normalise_variants( $candidate ) as $variant ) {
					if ( isset( $normalised[ $variant ] ) ) {
						$resolved[ $key ] = $normalised[ $variant ];
						break 2;
					}
				}
			}
		}

		return $resolved;
	}

	/**
	 * Normalised comparison forms of a string: lowercase, umlauts folded both
	 * ways (ü → ue and ü → u), everything non-alphanumeric removed.
	 *
	 * @param string $value Raw string.
	 * @return array Unique normalised variants.
	 */
	private function normalise_variants( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return array();
		}

		$lower = function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );

		$long = strtr( $lower, array(
			'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss',
			'à' => 'a', 'á' => 'a', 'â' => 'a', 'è' => 'e', 'é' => 'e',
			'ê' => 'e', 'ë' => 'e', 'ì' => 'i', 'í' => 'i', 'î' => 'i',
			'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'ù' => 'u', 'ú' => 'u',
			'û' => 'u', 'ç' => 'c', 'ñ' => 'n',
		) );
		$short = strtr( $lower, array(
			'ä' => 'a', 'ö' => 'o', 'ü' => 'u', 'ß' => 'ss',
			'à' => 'a', 'á' => 'a', 'â' => 'a', 'è' => 'e', 'é' => 'e',
			'ê' => 'e', 'ë' => 'e', 'ì' => 'i', 'í' => 'i', 'î' => 'i',
			'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'ù' => 'u', 'ú' => 'u',
			'û' => 'u', 'ç' => 'c', 'ñ' => 'n',
		) );

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
	 * Convert a date to ACF's storage format (Ymd).
	 *
	 * Accepts "26.08.1996", "26/08/1996", "1996-08-26" and "19960826".
	 *
	 * @param string $value Raw date cell.
	 * @return string Ymd, or '' when unparsable.
	 */
	private function normalise_date( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}
		if ( preg_match( '/^\d{8}$/', $value ) ) {
			return $value;
		}
		if ( preg_match( '/^(\d{1,2})[.\/-](\d{1,2})[.\/-](\d{4})$/', $value, $m ) ) {
			return sprintf( '%04d%02d%02d', $m[3], $m[2], $m[1] );
		}
		if ( preg_match( '/^(\d{4})[.\/-](\d{1,2})[.\/-](\d{1,2})$/', $value, $m ) ) {
			return sprintf( '%04d%02d%02d', $m[1], $m[2], $m[3] );
		}
		return '';
	}

	/**
	 * Detect whether the CSV uses semicolons or commas.
	 *
	 * @param string $header_line First line of the file.
	 * @return string ';' or ','
	 */
	private function detect_separator( $header_line ) {
		$semicolons = substr_count( (string) $header_line, ';' );
		$commas     = substr_count( (string) $header_line, ',' );
		return $semicolons >= $commas ? ';' : ',';
	}

	/**
	 * Whether a Gruppe value represents an importable person.
	 *
	 * @param string $gruppe Raw Gruppe cell value.
	 * @return bool
	 */
	private function is_valid_gruppe( $gruppe ) {
		if ( '' === $gruppe ) {
			return false;
		}
		foreach ( $this->valid_groups as $valid ) {
			if ( 0 === stripos( $gruppe, $valid ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Normalise the Funktion/position value to an ACF choice.
	 *
	 * @param string $funktion Raw Funktion value.
	 * @param string $gruppe   Raw Gruppe value for context.
	 * @return string
	 */
	private function normalise_position( $funktion, $gruppe ) {
		$funktion = trim( $funktion );

		if ( 0 === stripos( $funktion, 'Torhüter' ) || 0 === stripos( $funktion, 'Torhueter' ) || 0 === stripos( $funktion, 'Goalie' ) ) {
			return 'Torhüter';
		}
		if ( 0 === stripos( $funktion, 'Feldspieler' ) ) {
			return 'Feldspieler';
		}
		if ( 0 === stripos( $funktion, 'Sturm' ) ) {
			return 'Sturm';
		}
		if ( 0 === stripos( $funktion, 'Verteidigung' ) ) {
			return 'Verteidigung';
		}
		if ( false !== stripos( $gruppe, 'Staff' ) ) {
			return 'Staff';
		}
		// Trainer & co. living inside a Spieler group → Staff.
		foreach ( array( 'Trainer', 'Coach', 'Physio', 'Betreuer', 'Manager', 'Materialwart' ) as $sf ) {
			if ( false !== stripos( $funktion, $sf ) ) {
				return 'Staff';
			}
		}
		return 'Feldspieler'; // Default for players with no Funktion set.
	}
}
