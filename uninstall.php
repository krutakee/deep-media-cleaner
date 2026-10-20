<?php
/**
 * Uninstall: put everything in the recycle bin back first, so no media is
 * left hidden. Tables are only dropped when the bin is empty.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/includes/class-dmc-db.php';
require_once __DIR__ . '/includes/class-dmc-util.php';
require_once __DIR__ . '/includes/class-dmc-reason.php';
require_once __DIR__ . '/includes/class-dmc-unlink.php';
require_once __DIR__ . '/includes/class-dmc-scanner.php';
require_once __DIR__ . '/includes/class-dmc-bin.php';

global $wpdb;
$dmc_t = DMC_DB::tables();

if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $dmc_t['bin'] ) ) ) {
	foreach ( $wpdb->get_col( "SELECT id FROM `{$dmc_t['bin']}`" ) as $dmc_id ) { // phpcs:ignore
		DMC_Bin::restore( (int) $dmc_id );
	}
	$dmc_left = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$dmc_t['bin']}`" ); // phpcs:ignore
} else {
	$dmc_left = 0;
}

if ( 0 === $dmc_left ) {
	DMC_DB::drop();
	$dmc_bin = get_option( 'dmc_bin_dir' );
	if ( $dmc_bin ) {
		$dmc_path = DMC_Util::basedir() . '/' . $dmc_bin;
		@unlink( $dmc_path . '/index.php' ); // phpcs:ignore
		@unlink( $dmc_path . '/.htaccess' ); // phpcs:ignore
		@rmdir( $dmc_path ); // phpcs:ignore
	}
	foreach ( array( 'dmc_settings', 'dmc_scan_state', 'dmc_last_scan', 'dmc_log', 'dmc_ignored', 'dmc_db_version', 'dmc_bin_dir' ) as $dmc_opt ) {
		delete_option( $dmc_opt );
	}
}
// If some items could not be restored (file-name conflicts), the tables,
// settings and bin folder are kept so nothing is lost. Reinstall the plugin
// to deal with them.
