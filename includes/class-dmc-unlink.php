<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Removes "simple" references to a media item whose file is gone.
 *
 * Simple = a single row in wp_postmeta whose WHOLE value is the reference:
 *   - exactly the media ID ("79249"), under a key that is clearly a media
 *     field (image, file, attachment, _thumbnail_id…) or an ACF field, or
 *   - exactly the URL / uploads path of one of the item's files.
 *
 * Never touched: post content, page-builder JSON, serialized arrays, lists
 * of IDs, options/settings, term/user meta, plugin tables, WordPress
 * internals. Removed rows are stored with the bin item and put back on restore.
 */
class DMC_Unlink {

	const MAX_ROWS = 200;

	/** Keys never removed even if they hold a matching value. */
	private static $never_keys = array(
		'_wp_attached_file',
		'_wp_attachment_metadata',
		'_wp_attachment_backup_sizes',
		'_wp_attachment_image_alt',
		'_edit_lock',
		'_edit_last',
		'_wp_old_slug',
		'_wp_old_date',
		'_wp_trash_meta_status',
		'_wp_trash_meta_time',
		'_price',
		'_regular_price',
		'_sale_price',
		'_stock',
		'total_sales',
	);

	private static $never_prefixes = array( '_menu_item_', '_oembed_', '_dmc_', '_transient' );

	/**
	 * Find simple references. Returns two lists:
	 *   'simple' => rows that would be removed
	 *   'other'  => short reason of one non-simple reference that stays (or '')
	 */
	public static function find( $aid ) {
		global $wpdb;
		$aid   = (int) $aid;
		$files = DMC_Util::attachment_files( $aid );
		$found = array();

		// 1) Whole value is the ID.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT pm.meta_id, pm.post_id, pm.meta_key, pm.meta_value, p.post_type FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_value = %s AND pm.post_id <> %d AND p.post_type <> 'attachment' LIMIT %d", (string) $aid, $aid, self::MAX_ROWS ) );
		foreach ( $rows as $r ) {
			if ( self::key_allowed( $r->meta_key ) && self::id_key( $r ) ) {
				$found[ (int) $r->meta_id ] = $r;
			}
		}

		// 2) Whole value is the URL / path of one of its files.
		$names = array();
		foreach ( $files as $rel ) {
			$names[ wp_basename( $rel ) ] = true;
		}
		foreach ( array_keys( $names ) as $name ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT pm.meta_id, pm.post_id, pm.meta_key, pm.meta_value, p.post_type FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_value LIKE %s AND pm.post_id <> %d AND p.post_type <> 'attachment' LIMIT %d", '%' . $wpdb->esc_like( $name ), $aid, self::MAX_ROWS ) );
			foreach ( $rows as $r ) {
				if ( self::key_allowed( $r->meta_key ) && self::is_file_value( $r->meta_value, $files ) ) {
					$found[ (int) $r->meta_id ] = $r;
				}
			}
		}
		return array_values( $found );
	}

	/**
	 * Remove the rows. They are saved on the bin item FIRST; if that fails,
	 * nothing is deleted. Returns the removed rows or a WP_Error.
	 */
	public static function remove( $aid, $bin_id ) {
		global $wpdb;
		$rows = self::find( $aid );
		if ( ! $rows ) {
			return array();
		}
		$saved = array();
		foreach ( $rows as $r ) {
			$saved[] = array(
				'meta_id'    => (int) $r->meta_id,
				'post_id'    => (int) $r->post_id,
				'meta_key'   => (string) $r->meta_key, // phpcs:ignore
				'meta_value' => (string) $r->meta_value, // phpcs:ignore
				'post_type'  => (string) $r->post_type,
			);
		}
		$t = DMC_DB::tables();
		if ( ! self::save( $t['bin'], $bin_id, $saved ) ) {
			DMC_DB::install(); // Column may be missing if the upgrade did not run yet.
			if ( ! self::save( $t['bin'], $bin_id, $saved ) ) {
				return new WP_Error( 'dmc_refs_save', __( 'Could not save the references to the recycle bin, so none were removed. The item itself was moved to the bin.', 'deep-media-cleaner' ) );
			}
		}
		$done = array();
		foreach ( $saved as $r ) {
			if ( $wpdb->delete( $wpdb->postmeta, array( 'meta_id' => $r['meta_id'] ), array( '%d' ) ) ) {
				wp_cache_delete( $r['post_id'], 'post_meta' );
				$done[] = $r;
			}
		}
		if ( count( $done ) !== count( $saved ) ) {
			self::save( $t['bin'], $bin_id, $done ); // Keep only what was really removed.
		}
		if ( $done ) {
			DMC_Util::log( 'unlink', sprintf( 'Media #%d: %d reference(s) removed', $aid, count( $done ) ) );
		}
		return $done;
	}

	private static function save( $table, $bin_id, array $rows ) {
		global $wpdb;
		$json = wp_json_encode( $rows );
		if ( false === $json ) {
			return false;
		}
		$wpdb->update( $table, array( 'refs' => $json ), array( 'id' => (int) $bin_id ) );
		// Read back: a missing column or failed write must not pass silently.
		$check = $wpdb->get_var( $wpdb->prepare( "SELECT refs FROM `{$table}` WHERE id = %d", (int) $bin_id ) ); // phpcs:ignore
		return $check === $json;
	}

	/** Put removed rows back (called on restore). Returns how many came back. */
	public static function restore( $json ) {
		global $wpdb;
		$rows = json_decode( (string) $json, true );
		if ( ! is_array( $rows ) ) {
			return 0;
		}
		$n = 0;
		foreach ( $rows as $r ) {
			if ( empty( $r['post_id'] ) || ! get_post( (int) $r['post_id'] ) ) {
				continue; // The post itself was deleted meanwhile.
			}
			$taken = $wpdb->get_var( $wpdb->prepare( "SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_id = %d", (int) $r['meta_id'] ) );
			$data  = array(
				'post_id'    => (int) $r['post_id'],
				'meta_key'   => (string) $r['meta_key'], // phpcs:ignore
				'meta_value' => (string) $r['meta_value'], // phpcs:ignore
			);
			if ( ! $taken ) {
				$data = array( 'meta_id' => (int) $r['meta_id'] ) + $data; // Same row as before.
			}
			if ( $wpdb->insert( $wpdb->postmeta, $data ) ) {
				wp_cache_delete( (int) $r['post_id'], 'post_meta' );
				++$n;
			}
		}
		return $n;
	}

	/** Reason descriptor for a removed row (for messages). */
	public static function reason( array $r ) {
		return DMC_Reason::encode(
			array(
				'k'  => 'pmeta',
				'tb' => 'postmeta',
				'f'  => $r['meta_key'],
				'id' => (int) $r['post_id'],
				'ot' => $r['post_type'],
			)
		);
	}

	/* ------------------------------------------------------------------ */

	private static function key_allowed( $key ) {
		$key = (string) $key;
		if ( in_array( $key, self::$never_keys, true ) ) {
			return false;
		}
		foreach ( self::$never_prefixes as $p ) {
			if ( 0 === strpos( $key, $p ) ) {
				return false;
			}
		}
		return true;
	}

	/** A bare number only counts when the key is clearly a media field. */
	private static function id_key( $r ) {
		if ( '_thumbnail_id' === $r->meta_key || preg_match( DMC_Util::KEY_PATTERN, $r->meta_key ) ) {
			return true;
		}
		// ACF stores "_<field>" => "field_xxxx" next to the value.
		$ref = get_post_meta( (int) $r->post_id, '_' . $r->meta_key, true );
		return is_string( $ref ) && 0 === strpos( $ref, 'field_' );
	}

	/** True when the whole value is a URL or uploads path of one of $files. */
	public static function is_file_value( $value, array $files ) {
		$v = trim( (string) $value );
		if ( '' === $v || strlen( $v ) > 2000 || preg_match( '/[\s"\'<>{}\[\]]/', $v ) ) {
			return false; // Not a single bare URL/path (HTML, JSON, lists…).
		}
		$v = DMC_Util::lower( rawurldecode( preg_replace( '/[?#].*$/', '', $v ) ) );
		foreach ( $files as $rel ) {
			$rel = DMC_Util::lower( ltrim( $rel, '/' ) );
			if ( $v === $rel || substr( $v, -strlen( '/' . $rel ) ) === '/' . $rel ) {
				return true;
			}
		}
		return false;
	}
}
