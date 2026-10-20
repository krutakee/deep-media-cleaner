<?php
/**
 * Plugin Name:       Deep Media Cleaner
 * Description:       Finds media files that are not used anywhere on the site (posts, page builders, meta, options, widgets, custom database tables, theme files and the uploads folder itself) and moves them to a recycle bin you can restore from.
 * Version:           1.5.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Stan Han
 * License:           GPL-2.0-or-later
 * Text Domain:       deep-media-cleaner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DMC_VERSION', '1.5.0' );
define( 'DMC_FILE', __FILE__ );
define( 'DMC_DIR', plugin_dir_path( __FILE__ ) );
define( 'DMC_URL', plugin_dir_url( __FILE__ ) );

require_once DMC_DIR . 'includes/class-dmc-db.php';
require_once DMC_DIR . 'includes/class-dmc-util.php';
require_once DMC_DIR . 'includes/class-dmc-reason.php';
require_once DMC_DIR . 'includes/class-dmc-unlink.php';
require_once DMC_DIR . 'includes/class-dmc-scanner.php';
require_once DMC_DIR . 'includes/class-dmc-bin.php';
require_once DMC_DIR . 'includes/class-dmc-admin.php';

register_activation_hook( __FILE__, array( 'DMC_DB', 'install' ) );
register_deactivation_hook( __FILE__, array( 'DMC_DB', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'DMC_DB', 'maybe_upgrade' ) );

/**
 * Attachments in the recycle bin get this status so they disappear from the
 * media library and the front end, while keeping their post + meta intact
 * for a perfect restore.
 */
add_action(
	'init',
	function () {
		register_post_status(
			'dmc-trash',
			array(
				'label'                     => __( 'In Deep Media Cleaner bin', 'deep-media-cleaner' ),
				'public'                    => false,
				'internal'                  => true,
				'exclude_from_search'       => true,
				'show_in_admin_all_list'    => false,
				'show_in_admin_status_list' => false,
			)
		);
	}
);

add_action( 'dmc_daily', array( 'DMC_Bin', 'auto_purge' ) );

// Keep scan results in sync when media is deleted some other way.
add_action(
	'delete_attachment',
	function ( $post_id ) {
		global $wpdb;
		$t = DMC_DB::tables();
		$wpdb->delete( $t['results'], array( 'attachment_id' => (int) $post_id ), array( '%d' ) );
	}
);

if ( is_admin() ) {
	DMC_Admin::init();
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once DMC_DIR . 'includes/class-dmc-cli.php';
	WP_CLI::add_command( 'dmc', 'DMC_CLI' );
}
