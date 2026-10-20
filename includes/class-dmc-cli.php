<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Deep Media Cleaner from the command line. Handy for big sites where the
 * browser scan would take long.
 *
 * ## EXAMPLES
 *
 *     wp dmc scan
 *     wp dmc list --format=csv > unused.csv
 *     wp dmc trash --all --yes
 *     wp dmc bin
 *     wp dmc restore --all
 */
class DMC_CLI {

	/**
	 * Scan the whole site for unused media.
	 *
	 * ## EXAMPLES
	 *
	 *     wp dmc scan
	 */
	public function scan() {
		DMC_Scanner::start();
		$last = '';
		do {
			$state = DMC_Scanner::run();
			if ( ! empty( $state['running'] ) ) {
				$i     = (int) $state['i'];
				$total = count( $state['steps'] );
				$line  = sprintf( '[%d/%d] %s', min( $i + 1, $total ), $total, isset( $state['steps'][ $i ]['label'] ) ? $state['steps'][ $i ]['label'] : '' );
				if ( $line !== $last ) {
					WP_CLI::log( $line );
					$last = $line;
				}
			}
		} while ( ! empty( $state['running'] ) );
		$this->stats();
	}

	/**
	 * Show totals from the last scan.
	 */
	public function stats() {
		global $wpdb;
		$t    = DMC_DB::tables();
		$rows = $wpdb->get_results( "SELECT is_used, item_type, COUNT(*) n, COALESCE(SUM(size),0) s FROM `{$t['results']}` GROUP BY is_used, item_type ORDER BY is_used, item_type", ARRAY_A ); // phpcs:ignore
		$map  = array( 'unused', 'used', 'ignored' );
		$out  = array();
		foreach ( $rows as $r ) {
			$out[] = array(
				'status' => $map[ (int) $r['is_used'] ],
				'type'   => 'attachment' === $r['item_type'] ? 'media library' : 'not in library',
				'items'  => (int) $r['n'],
				'size'   => size_format( (int) $r['s'], 1 ),
			);
		}
		if ( ! $out ) {
			WP_CLI::warning( 'No scan results. Run `wp dmc scan` first.' );
			return;
		}
		WP_CLI\Utils\format_items( 'table', $out, array( 'status', 'type', 'items', 'size' ) );
	}

	/**
	 * List scan results.
	 *
	 * ## OPTIONS
	 *
	 * [--view=<view>]
	 * : unused, used or ignored.
	 * ---
	 * default: unused
	 * ---
	 *
	 * [--format=<format>]
	 * : table, csv, json, ids.
	 * ---
	 * default: table
	 * ---
	 *
	 * @subcommand list
	 */
	public function list_( $args, $assoc ) {
		global $wpdb;
		$t    = DMC_DB::tables();
		$map  = array(
			'unused'  => 0,
			'used'    => 1,
			'ignored' => 2,
		);
		$view = isset( $map[ $assoc['view'] ] ) ? $map[ $assoc['view'] ] : 0;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, item_type, attachment_id, rel_path, size, used_by FROM `{$t['results']}` WHERE is_used = %d ORDER BY size DESC", $view ), ARRAY_A ); // phpcs:ignore
		if ( 'ids' === $assoc['format'] ) {
			echo implode( ' ', wp_list_pluck( $rows, 'id' ) ) . "\n"; // phpcs:ignore
			return;
		}
		WP_CLI\Utils\format_items( $assoc['format'], $rows, array( 'id', 'item_type', 'attachment_id', 'rel_path', 'size', 'used_by' ) );
	}

	/**
	 * Move unused items to the recycle bin.
	 *
	 * ## OPTIONS
	 *
	 * [<id>...]
	 * : Result IDs (from `wp dmc list`).
	 *
	 * [--all]
	 * : Every unused item.
	 *
	 * [--yes]
	 * : Do not ask for confirmation.
	 */
	public function trash( $args, $assoc ) {
		global $wpdb;
		$t   = DMC_DB::tables();
		$ids = array_map( 'absint', $args );
		if ( ! empty( $assoc['all'] ) ) {
			$ids = array_map( 'absint', $wpdb->get_col( "SELECT id FROM `{$t['results']}` WHERE is_used = 0 ORDER BY id" ) ); // phpcs:ignore
		}
		if ( ! $ids ) {
			WP_CLI::error( 'Nothing to do. Pass result IDs or --all.' );
		}
		WP_CLI::confirm( sprintf( 'Move %d item(s) to the recycle bin?', count( $ids ) ), $assoc );
		$recheck = ! empty( DMC_Util::settings()['recheck'] );
		$bar     = WP_CLI\Utils\make_progress_bar( 'Moving to bin', count( $ids ) );
		$ok      = 0;
		foreach ( $ids as $id ) {
			$r = DMC_Bin::trash_result( $id, $recheck );
			if ( is_wp_error( $r ) ) {
				WP_CLI::warning( "#{$id}: " . $r->get_error_message() );
			} else {
				++$ok;
			}
			$bar->tick();
		}
		$bar->finish();
		WP_CLI::success( "{$ok} item(s) moved to the recycle bin." );
	}

	/**
	 * List what is in the recycle bin.
	 *
	 * [--format=<format>]
	 * : table, csv, json.
	 * ---
	 * default: table
	 * ---
	 */
	public function bin( $args, $assoc ) {
		global $wpdb;
		$t    = DMC_DB::tables();
		$rows = $wpdb->get_results( "SELECT id, item_type, attachment_id, rel_path, size, trashed_at FROM `{$t['bin']}` ORDER BY id DESC", ARRAY_A ); // phpcs:ignore
		WP_CLI\Utils\format_items( $assoc['format'], $rows, array( 'id', 'item_type', 'attachment_id', 'rel_path', 'size', 'trashed_at' ) );
	}

	/**
	 * Restore items from the recycle bin.
	 *
	 * ## OPTIONS
	 *
	 * [<bin-id>...]
	 * : Bin IDs (from `wp dmc bin`).
	 *
	 * [--all]
	 * : Restore everything.
	 */
	public function restore( $args, $assoc ) {
		$ids = $this->bin_ids( $args, $assoc );
		$ok  = 0;
		foreach ( $ids as $id ) {
			$r = DMC_Bin::restore( $id );
			if ( is_wp_error( $r ) ) {
				WP_CLI::warning( "#{$id}: " . $r->get_error_message() );
				continue;
			}
			if ( is_string( $r ) ) {
				WP_CLI::warning( "#{$id}: {$r}" );
			}
			++$ok;
		}
		WP_CLI::success( "{$ok} item(s) restored." );
	}

	/**
	 * Permanently delete items from the recycle bin.
	 *
	 * ## OPTIONS
	 *
	 * [<bin-id>...]
	 * : Bin IDs (from `wp dmc bin`).
	 *
	 * [--all]
	 * : Empty the whole bin.
	 *
	 * [--yes]
	 * : Do not ask for confirmation.
	 *
	 * @subcommand delete
	 */
	public function delete_( $args, $assoc ) {
		$ids = $this->bin_ids( $args, $assoc );
		WP_CLI::confirm( sprintf( 'Permanently delete %d item(s)? This cannot be undone.', count( $ids ) ), $assoc );
		$ok = 0;
		foreach ( $ids as $id ) {
			$r = DMC_Bin::delete( $id );
			if ( is_wp_error( $r ) ) {
				WP_CLI::warning( "#{$id}: " . $r->get_error_message() );
			} else {
				++$ok;
			}
		}
		WP_CLI::success( "{$ok} item(s) deleted permanently." );
	}

	private function bin_ids( $args, $assoc ) {
		global $wpdb;
		$t   = DMC_DB::tables();
		$ids = array_map( 'absint', $args );
		if ( ! empty( $assoc['all'] ) ) {
			$ids = array_map( 'absint', $wpdb->get_col( "SELECT id FROM `{$t['bin']}` ORDER BY id" ) ); // phpcs:ignore
		}
		if ( ! $ids ) {
			WP_CLI::error( 'Nothing to do. Pass bin IDs or --all.' );
		}
		return $ids;
	}
}
