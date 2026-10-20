<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DMC_DB {

	const VERSION = '3';

	public static function tables() {
		global $wpdb;
		$p = $wpdb->prefix . 'dmc_';
		return array(
			'refs'     => $p . 'refs',
			'attfiles' => $p . 'attfiles',
			'results'  => $p . 'results',
			'bin'      => $p . 'bin',
		);
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$c = $wpdb->get_charset_collate();
		$t = self::tables();

		dbDelta(
			"CREATE TABLE {$t['refs']} (
ref_hash char(32) NOT NULL,
ref_type varchar(10) NOT NULL DEFAULT '',
ref_value varchar(255) NOT NULL DEFAULT '',
source varchar(255) NOT NULL DEFAULT '',
PRIMARY KEY  (ref_hash)
) $c;"
		);

		dbDelta(
			"CREATE TABLE {$t['attfiles']} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
rel_path text NOT NULL,
path_hash char(32) NOT NULL DEFAULT '',
stem_hash char(32) NOT NULL DEFAULT '',
size bigint(20) unsigned NOT NULL DEFAULT 0,
PRIMARY KEY  (id),
KEY attachment_id (attachment_id),
KEY path_hash (path_hash),
KEY stem_hash (stem_hash)
) $c;"
		);

		dbDelta(
			"CREATE TABLE {$t['results']} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
item_type varchar(20) NOT NULL DEFAULT 'attachment',
attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
parent_id bigint(20) unsigned NOT NULL DEFAULT 0,
rel_path text NOT NULL,
mime varchar(100) NOT NULL DEFAULT '',
size bigint(20) unsigned NOT NULL DEFAULT 0,
is_used tinyint(1) NOT NULL DEFAULT 0,
missing tinyint(1) NOT NULL DEFAULT 0,
used_by varchar(255) NOT NULL DEFAULT '',
item_date datetime NULL DEFAULT NULL,
PRIMARY KEY  (id),
KEY attachment_id (attachment_id),
KEY is_used (is_used,item_type),
KEY missing (missing),
KEY size (size)
) $c;"
		);

		dbDelta(
			"CREATE TABLE {$t['bin']} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
item_type varchar(20) NOT NULL DEFAULT 'attachment',
attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
original_status varchar(20) NOT NULL DEFAULT 'inherit',
rel_path text NOT NULL,
files longtext NOT NULL,
refs longtext NULL,
size bigint(20) unsigned NOT NULL DEFAULT 0,
trashed_at datetime NULL DEFAULT NULL,
trashed_by bigint(20) unsigned NOT NULL DEFAULT 0,
PRIMARY KEY  (id),
KEY trashed_at (trashed_at)
) $c;"
		);

		update_option( 'dmc_db_version', self::VERSION, false );
		DMC_Bin::ensure_dir();

		if ( ! wp_next_scheduled( 'dmc_daily' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'dmc_daily' );
		}
	}

	public static function maybe_upgrade() {
		if ( get_option( 'dmc_db_version' ) !== self::VERSION ) {
			self::install();
		}
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( 'dmc_daily' );
		delete_option( 'dmc_scan_state' );
	}

	public static function drop() {
		global $wpdb;
		foreach ( self::tables() as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore
		}
	}
}
