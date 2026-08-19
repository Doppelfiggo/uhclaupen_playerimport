<?php
/**
 * Command line runner for the import health check.
 *
 * Usage (from the site root):
 *
 *   studio wp eval-file wp-content/plugins/uhc-laupen-importer/tests/healthcheck-cli.php
 *   studio wp eval-file wp-content/plugins/uhc-laupen-importer/tests/healthcheck-cli.php api
 *
 * Without arguments the import and teams groups run (no network). Pass "api"
 * to also check the Swiss Unihockey API, or "import" / "teams" / "api" in any
 * combination to run just those groups.
 *
 * Exits with status 1 when a check fails, so it can be used in a deploy script
 * or a cron job. Nothing is written to the database.
 *
 * @package UHC_Laupen_Importer
 */

if ( ! class_exists( 'UHC_Healthcheck' ) ) {
	echo "Das Plugin „UHC Laupen Spieler Import“ ist nicht aktiv — Systemcheck nicht möglich.\n";
	if ( class_exists( 'WP_CLI' ) ) {
		WP_CLI::halt( 1 );
	}
	exit( 1 );
}

// $args holds everything after the file name when run through `wp eval-file`.
$requested = array_values( array_intersect(
	array_map( 'strtolower', isset( $args ) && is_array( $args ) ? $args : array() ),
	array_keys( UHC_Healthcheck::groups() )
) );

if ( ! $requested ) {
	$requested = array( 'import', 'teams' );
	if ( isset( $args ) && is_array( $args ) && in_array( 'all', array_map( 'strtolower', $args ), true ) ) {
		$requested[] = 'api';
	}
}
$requested = array_values( array_unique( $requested ) );

$checker = new UHC_Healthcheck();
$started = microtime( true );
$results = $checker->run( $requested );
$seconds = round( microtime( true ) - $started, 1 );
$counts  = UHC_Healthcheck::summarise( $results );

$symbols = array(
	UHC_Healthcheck::PASS => 'OK  ',
	UHC_Healthcheck::WARN => 'WARN',
	UHC_Healthcheck::FAIL => 'FAIL',
	UHC_Healthcheck::SKIP => 'SKIP',
);

$current_group = null;
foreach ( $results as $result ) {
	if ( $result['group'] !== $current_group ) {
		$current_group = $result['group'];
		$labels        = UHC_Healthcheck::groups();
		echo "\n== " . ( $labels[ $current_group ] ?? $current_group ) . " ==\n";
	}

	printf(
		"[%s] %s\n       %s\n",
		$symbols[ $result['status'] ] ?? '?   ',
		$result['label'],
		$result['message']
	);
}

printf(
	"\n%d OK · %d Hinweise · %d Fehler · %d übersprungen — %s Sekunden, keine Daten verändert.\n",
	$counts[ UHC_Healthcheck::PASS ],
	$counts[ UHC_Healthcheck::WARN ],
	$counts[ UHC_Healthcheck::FAIL ],
	$counts[ UHC_Healthcheck::SKIP ],
	$seconds
);

if ( $counts[ UHC_Healthcheck::FAIL ] > 0 ) {
	if ( class_exists( 'WP_CLI' ) ) {
		WP_CLI::halt( 1 );
	}
	exit( 1 );
}
