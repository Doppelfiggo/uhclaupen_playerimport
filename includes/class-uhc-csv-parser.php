<?php
/**
 * CSV Parser for ClubDesk exports.
 *
 * Reads a semicolon-separated ClubDesk export, validates expected columns,
 * and returns a clean array of player rows ready for import.
 *
 * @package UHC_Laupen_Importer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UHC_CSV_Parser {

	/**
	 * CSV columns we need. Keys = CSV header name, values = internal key.
	 *
	 * @var array
	 */
	private $column_map = array(
		'Vorname'       => 'vorname',
		'Nachname'      => 'nachname',
		'Rückennummer'  => 'spielernummer',
		'Funktion'      => 'position',
		'Nationalität'  => 'nationalitat',
		'Geburtsdatum'  => 'geburtsdatum',
		'Team'          => 'team',
		'Gruppe'        => 'gruppe',
		'E-Mail'        => 'email',
	);

	/**
	 * Gruppe values that identify importable people (players + staff).
	 * Rows with any other Gruppe value are skipped.
	 *
	 * @var array
	 */
	private $valid_groups = array(
		'Spieler Herren 1',
		'Spieler Damen 1',
		'Spieler U21',
		'Spieler U17',
		'Spieler Damen U21',
		'Spieler Damen U17',
		'Staff Herren 1',
		'Staff Damen 1',
		'Staff U21',
		'Staff U17',
	);

	/**
	 * Parse a CSV file and return structured player rows.
	 *
	 * @param string $file_path Absolute path to the uploaded CSV.
	 * @return array|WP_Error  Array with 'rows' and 'skipped', or WP_Error on failure.
	 */
	public function parse( $file_path ) {
		if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
			return new WP_Error( 'file_not_found', __( 'Datei nicht gefunden oder nicht lesbar.', 'uhc-laupen-importer' ) );
		}

		// Detect encoding and convert to UTF-8 if needed.
		$raw = file_get_contents( $file_path );
		if ( false === $raw ) {
			return new WP_Error( 'file_read_error', __( 'Datei konnte nicht gelesen werden.', 'uhc-laupen-importer' ) );
		}

		$encoding = mb_detect_encoding( $raw, array( 'UTF-8', 'ISO-8859-1', 'Windows-1252' ), true );
		if ( $encoding && 'UTF-8' !== $encoding ) {
			$raw = mb_convert_encoding( $raw, 'UTF-8', $encoding );
		}

		// Remove BOM if present.
		$raw = ltrim( $raw, "\xEF\xBB\xBF" );

		// Split into lines and parse as CSV.
		$lines = explode( "\n", str_replace( "\r\n", "\n", $raw ) );
		if ( empty( $lines ) ) {
			return new WP_Error( 'empty_file', __( 'Die Datei ist leer.', 'uhc-laupen-importer' ) );
		}

		// Detect separator (semicolon or comma).
		$separator = $this->detect_separator( $lines[0] );

		// Parse header row.
		$headers = str_getcsv( array_shift( $lines ), $separator );
		$headers = array_map( 'trim', $headers );

		// Check required columns exist.
		$missing = array();
		foreach ( array_keys( $this->column_map ) as $required ) {
			if ( ! in_array( $required, $headers, true ) ) {
				$missing[] = $required;
			}
		}
		if ( ! empty( $missing ) ) {
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

		foreach ( $lines as $line_number => $line ) {
			$line = trim( $line );
			if ( empty( $line ) ) {
				continue;
			}

			$cells = str_getcsv( $line, $separator );

			// Pad short rows to avoid undefined offset warnings.
			while ( count( $cells ) < count( $headers ) ) {
				$cells[] = '';
			}

			$raw_row = array_combine( $headers, $cells );
			if ( ! $raw_row ) {
				continue;
			}

			$gruppe = trim( $raw_row['Gruppe'] ?? '' );

			// Skip rows that aren't players or staff of a known group.
			if ( ! $this->is_valid_gruppe( $gruppe ) ) {
				$skipped++;
				continue;
			}

			// Must have at least a name.
			$vorname  = trim( $raw_row['Vorname']  ?? '' );
			$nachname = trim( $raw_row['Nachname'] ?? '' );
			if ( empty( $vorname ) && empty( $nachname ) ) {
				$skipped++;
				continue;
			}

			// Map to internal keys.
			$row = array();
			foreach ( $this->column_map as $csv_col => $internal_key ) {
				$row[ $internal_key ] = trim( $raw_row[ $csv_col ] ?? '' );
			}

			// Normalise jersey number (stored as float in CSV e.g. "46.0").
			if ( ! empty( $row['spielernummer'] ) ) {
				$row['spielernummer'] = (string) intval( floatval( $row['spielernummer'] ) );
			}

			// Normalise position: map Staff funktions to 'Staff'.
			$row['position'] = $this->normalise_position( $row['position'], $gruppe );

			$rows[] = $row;
		}

		if ( empty( $rows ) ) {
			return new WP_Error( 'no_rows', __( 'Keine importierbaren Datensätze gefunden. Prüfe ob die Gruppe-Spalte Werte wie "Spieler Herren 1" enthält.', 'uhc-laupen-importer' ) );
		}

		return array(
			'rows'    => $rows,
			'skipped' => $skipped,
		);
	}

	/**
	 * Detect whether the CSV uses semicolons or commas.
	 *
	 * @param string $header_line First line of the file.
	 * @return string ';' or ','
	 */
	private function detect_separator( $header_line ) {
		$semicolons = substr_count( $header_line, ';' );
		$commas     = substr_count( $header_line, ',' );
		return $semicolons >= $commas ? ';' : ',';
	}

	/**
	 * Whether a Gruppe value represents an importable person.
	 * Accepts any prefix-match (e.g. "Spieler Herren 1" matches "Spieler").
	 *
	 * @param string $gruppe Raw Gruppe cell value.
	 * @return bool
	 */
	private function is_valid_gruppe( $gruppe ) {
		if ( empty( $gruppe ) ) {
			return false;
		}
		foreach ( $this->valid_groups as $valid ) {
			if ( 0 === stripos( $gruppe, $valid ) ) {
				return true;
			}
		}
		// Also accept any "Spieler *" or "Staff *" as a catch-all.
		return 0 === stripos( $gruppe, 'Spieler' ) || 0 === stripos( $gruppe, 'Staff' );
	}

	/**
	 * Normalise the Funktion/position value.
	 *
	 * - "Torhüter"              → Torhüter
	 * - "Feldspieler"           → Feldspieler
	 * - Staff gruppe values     → Staff
	 * - Anything else           → Feldspieler (safe default for players)
	 *
	 * @param string $funktion Raw Funktion value.
	 * @param string $gruppe   Raw Gruppe value for context.
	 * @return string
	 */
	private function normalise_position( $funktion, $gruppe ) {
		if ( 'Torhüter' === $funktion ) {
			return 'Torhüter';
		}
		if ( 'Feldspieler' === $funktion ) {
			return 'Feldspieler';
		}
		if ( stripos( $gruppe, 'Staff' ) !== false ) {
			return 'Staff';
		}
		// Assistenztrainer, Trainer, etc. living in Spieler group → Staff.
		$staff_funktionen = array( 'Trainer', 'Assistenztrainer', 'Goalietrainer', 'Physiotherapeut', 'Betreuer', 'Manager' );
		foreach ( $staff_funktionen as $sf ) {
			if ( stripos( $funktion, $sf ) !== false ) {
				return 'Staff';
			}
		}
		return 'Feldspieler'; // Default for players with no Funktion set.
	}
}
