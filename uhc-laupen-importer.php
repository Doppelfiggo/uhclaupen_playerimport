<?php
/**
 * Plugin Name: UHC Laupen Spieler Import
 * Plugin URI:  https://whymedia.ch
 * Description: Imports Spieler (players) from a ClubDesk CSV export into the 'spieler' custom post type, sets all ACF fields, and links each player to their team post.
 * Version: 1.0.0
 * Author: whymedia.ch
 * Author URI: https://whymedia.ch
 * Text Domain: uhc-laupen-importer
 * Requires at least: 6.4
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'UHC_IMPORTER_VERSION', '1.0.0' );
define( 'UHC_IMPORTER_FILE', __FILE__ );
define( 'UHC_IMPORTER_DIR', plugin_dir_path( __FILE__ ) );

require_once UHC_IMPORTER_DIR . 'includes/class-uhc-importer.php';
require_once UHC_IMPORTER_DIR . 'includes/class-uhc-importer-settings.php';
require_once UHC_IMPORTER_DIR . 'includes/class-uhc-csv-parser.php';
require_once UHC_IMPORTER_DIR . 'includes/class-uhc-media-matcher.php';
require_once UHC_IMPORTER_DIR . 'includes/class-uhc-player-writer.php';

new UHC_Importer_Settings();
new UHC_Importer();
