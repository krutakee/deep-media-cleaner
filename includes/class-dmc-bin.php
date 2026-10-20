<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Recycle bin.
 *
 * Library items: every file (main, sizes, original, edit backups, leftover
 * sizes) is moved into the bin folder and the attachment gets the hidden
 * "dmc-trash" status. The post and all of its meta stay in the database, so a
 * restore is exact – same ID, same title/alt/caption, same links.
 *
 * Files that were never in the library are simply moved into the bin folder.
 */
class DMC_Bin {

	public static function dir() {
		$name = get_option( 'dmc_bin_dir' );
		if ( ! $name ) {
			$name = 'dmc-recycle-bin-' . strtolower( wp_generate_password( 10, false ) );
			update_option( 'dmc_bin_dir', $name, false );
		}
		return $name;
	}

	public static function path( $sub = '' ) {
		return DMC_Util::basedir() . '/' . self::dir() . ( '' !== $sub ? '/' . $sub : '' );
	}

	public static function ensure_dir() {
		$p = self::path();
		if ( ! is_dir( $p ) ) {
			wp_mkdir_p( $p );
		}
		if ( ! file_exists( $p . '/index.php' ) ) {
			file_put_contents( $p . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore
		}
		if ( ! file_exists( $p . '/.htaccess' ) ) {
			file_put_contents( $p . '/.htaccess', "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" ); // phpcs:ignore
		}
	}

	private static function move( $src, $dst ) {
		if ( ! wp_mkdir_p( dirname( $dst ) ) ) {
			return false;
		}
		if ( @rename( $src, $dst ) ) { // phpcs:ignore
			return true;
		}
		if ( @copy( $src, $dst ) ) { // phpcs:ignore
			if ( @unlink( $src ) ) { // phpcs:ignore
				return true;
			}
			@unlink( $dst ); // phpcs:ignore
		}
		return false;
	}

	private static function rrmdir( $dir ) {
		$root = realpath( self::path() );
		$real = realpath( $dir );
		if ( ! $root || ! $real || 0 !== strpos( $real, $root . DIRECTORY_SEPARATOR ) ) {
			return; // Never delete anything outside the bin.
		}
		$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $real, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $it as $f ) {
			if ( $f->isDir() ) {
				@rmdir( $f->getPathname() ); // phpcs:ignore
			} else {
				@unlink( $f->getPathname() ); // phpcs:ignore
			}
		}
		@rmdir( $real ); // phpcs:ignore
	}

	/** Main files + leftover size variants (e.g. from an old theme) of an attachment. */
	public static function attachment_bundle( $id ) {
		global $wpdb;
		$files = DMC_Util::attachment_files( $id );
		if ( ! $files ) {
			return $files;
		}
		$dir    = dirname( $files[0] );
		$dir    = ( '.' === $dir ) ? '' : $dir;
		$absdir = DMC_Util::basedir() . ( '' !== $dir ? '/' . $dir : '' );
		$bases  = array();
		foreach ( $files as $f ) {
			if ( dirname( $f ) !== dirname( $files[0] ) ) {
				continue;
			}
			$fn           = preg_replace( '/(-\d+x\d+|-scaled|-rotated)+$/', '', pathinfo( $f, PATHINFO_FILENAME ) );
			$bases[ $fn ] = true;
		}
		$known = array_flip( $files );
		if ( is_dir( $absdir ) ) {
			foreach ( scandir( $absdir ) as $e ) {
				if ( '.' === $e[0] || ! is_file( $absdir . '/' . $e ) ) {
					continue;
				}
				$rel = ( '' !== $dir ? $dir . '/' : '' ) . $e;
				if ( isset( $known[ $rel ] ) ) {
					continue;
				}
				foreach ( array_keys( $bases ) as $b ) {
					if ( preg_match( '/^' . preg_quote( $b, '/' ) . '(-\d+x\d+)?(\.[a-z0-9]{2,5}){1,2}$/i', $e ) ) {
						$other = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s AND post_id <> %d LIMIT 1", $rel, $id ) );
						if ( ! $other ) {
							$files[]       = $rel;
							$known[ $rel ] = true;
						}
						break;
					}
				}
			}
		}
		return $files;
	}

	/* ------------------------------------------------------------------ */

	/** Move one scan-result row to the bin. Returns bin id or WP_Error. */
	/** Similar-name hit seen by the last re-check (moved anyway; shown as a note). */
	public static $last_note = '';

	public static function trash_result( $result_id, $recheck = true ) {
		global $wpdb;
		self::$last_note = '';
		$t   = DMC_DB::tables();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$t['results']}` WHERE id = %d", $result_id ) ); // phpcs:ignore
		if ( ! $row ) {
			return new WP_Error( 'dmc_missing', __( 'Item not found. Run a new scan.', 'deep-media-cleaner' ) );
		}
		if ( $recheck ) {
			$why = DMC_Scanner::quick_recheck( $row );
			if ( '' !== $why && DMC_Reason::is_soft( $why ) ) {
				// Only a similar file name (e.g. photo-1.jpg for photo.pdf). The scan
				// already found no exact use, so move it and just mention it.
				self::$last_note = $why;
				$why             = '';
			}
			if ( '' !== $why ) {
				$wpdb->update( $t['results'], array( 'is_used' => 1, 'used_by' => $why ), array( 'id' => $row->id ) );
				return new WP_Error( 'dmc_used', DMC_Reason::text( $why ), array( 'reason' => $why ) );
			}
		}
		$r = ( 'attachment' === $row->item_type ) ? self::trash_attachment( (int) $row->attachment_id ) : self::trash_file( $row->rel_path );
		if ( ! is_wp_error( $r ) ) {
			$wpdb->delete( $t['results'], array( 'id' => (int) $row->id ) );
		}
		return $r;
	}

	public static function trash_attachment( $id ) {
		global $wpdb;
		$t    = DMC_DB::tables();
		$post = get_post( $id );
		if ( ! $post || 'attachment' !== $post->post_type ) {
			return new WP_Error( 'dmc_missing', __( 'This media item no longer exists.', 'deep-media-cleaner' ) );
		}
		if ( 'dmc-trash' === $post->post_status ) {
			return new WP_Error( 'dmc_already', __( 'Already in the bin.', 'deep-media-cleaner' ) );
		}
		self::ensure_dir();
		$base     = DMC_Util::basedir();
		$files    = self::attachment_bundle( $id );
		$existing = array();
		$size     = 0;
		foreach ( $files as $rel ) {
			if ( DMC_Util::rel_ok( $rel ) && is_file( $base . '/' . $rel ) ) {
				$existing[] = $rel;
				$size      += (int) filesize( $base . '/' . $rel );
			}
		}
		$wpdb->insert(
			$t['bin'],
			array(
				'item_type'       => 'attachment',
				'attachment_id'   => $id,
				'original_status' => $post->post_status,
				'rel_path'        => isset( $files[0] ) ? $files[0] : '',
				'files'           => wp_json_encode( $existing ),
				'size'            => $size,
				'trashed_at'      => current_time( 'mysql' ),
				'trashed_by'      => get_current_user_id(),
			)
		);
		$bid = (int) $wpdb->insert_id;
		if ( ! $bid ) {
			return new WP_Error( 'dmc_db', __( 'Could not write to the bin table.', 'deep-media-cleaner' ) );
		}
		$moved = array();
		foreach ( $existing as $rel ) {
			if ( self::move( $base . '/' . $rel, self::path( $bid . '/' . $rel ) ) ) {
				$moved[] = $rel;
				continue;
			}
			foreach ( $moved as $m ) { // Roll back.
				self::move( self::path( $bid . '/' . $m ), $base . '/' . $m );
			}
			$wpdb->delete( $t['bin'], array( 'id' => $bid ) );
			self::rrmdir( self::path( (string) $bid ) );
			/* translators: %s: file path */
			return new WP_Error( 'dmc_move', sprintf( __( 'Could not move %s (check folder permissions).', 'deep-media-cleaner' ), $rel ) );
		}
		$wpdb->update( $wpdb->posts, array( 'post_status' => 'dmc-trash' ), array( 'ID' => $id ) );
		clean_post_cache( $id );
		/* translators: 1: attachment ID 2: path */
		DMC_Util::log( 'trash', sprintf( __( 'Media #%1$d %2$s', 'deep-media-cleaner' ), $id, isset( $files[0] ) ? $files[0] : '' ) );
		return $bid;
	}

	public static function trash_file( $rel ) {
		global $wpdb;
		$t    = DMC_DB::tables();
		$base = DMC_Util::basedir();
		if ( ! DMC_Util::rel_ok( $rel ) ) {
			return new WP_Error( 'dmc_path', __( 'Invalid path.', 'deep-media-cleaner' ) );
		}
		$abs  = $base . '/' . $rel;
		$real = realpath( $abs );
		if ( ! $real || ! is_file( $real ) || 0 !== strpos( wp_normalize_path( $real ), wp_normalize_path( realpath( $base ) ) . '/' ) ) {
			return new WP_Error( 'dmc_missing', __( 'File not found.', 'deep-media-cleaner' ) );
		}
		$other = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s LIMIT 1", $rel ) );
		if ( $other ) {
			return new WP_Error( 'dmc_registered', __( 'This file now belongs to a media library item. Run a new scan.', 'deep-media-cleaner' ) );
		}
		self::ensure_dir();
		$size = (int) filesize( $abs );
		$wpdb->insert(
			$t['bin'],
			array(
				'item_type'  => 'file',
				'rel_path'   => $rel,
				'files'      => wp_json_encode( array( $rel ) ),
				'size'       => $size,
				'trashed_at' => current_time( 'mysql' ),
				'trashed_by' => get_current_user_id(),
			)
		);
		$bid = (int) $wpdb->insert_id;
		if ( ! $bid ) {
			return new WP_Error( 'dmc_db', __( 'Could not write to the bin table.', 'deep-media-cleaner' ) );
		}
		if ( ! self::move( $abs, self::path( $bid . '/' . $rel ) ) ) {
			$wpdb->delete( $t['bin'], array( 'id' => $bid ) );
			/* translators: %s: file path */
			return new WP_Error( 'dmc_move', sprintf( __( 'Could not move %s (check folder permissions).', 'deep-media-cleaner' ), $rel ) );
		}
		DMC_Util::log( 'trash', $rel );
		return $bid;
	}

	/* ------------------------------------------------------------------ */

	public static function restore( $bid ) {
		global $wpdb;
		$t   = DMC_DB::tables();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$t['bin']}` WHERE id = %d", $bid ) ); // phpcs:ignore
		if ( ! $row ) {
			return new WP_Error( 'dmc_missing', __( 'Bin item not found.', 'deep-media-cleaner' ) );
		}
		$base  = DMC_Util::basedir();
		$files = json_decode( $row->files, true );
		$files = is_array( $files ) ? $files : array();
		foreach ( $files as $rel ) {
			if ( ! DMC_Util::rel_ok( $rel ) ) {
				return new WP_Error( 'dmc_path', __( 'Invalid path in bin record.', 'deep-media-cleaner' ) );
			}
			if ( file_exists( $base . '/' . $rel ) && file_exists( self::path( $row->id . '/' . $rel ) ) ) {
				/* translators: %s: file path */
				return new WP_Error( 'dmc_conflict', sprintf( __( 'A different file now exists at %s. Rename or remove it, then restore again.', 'deep-media-cleaner' ), $rel ) );
			}
		}
		foreach ( $files as $rel ) {
			$src = self::path( $row->id . '/' . $rel );
			if ( file_exists( $src ) && ! self::move( $src, $base . '/' . $rel ) ) {
				/* translators: %s: file path */
				return new WP_Error( 'dmc_move', sprintf( __( 'Could not move %s back (check folder permissions).', 'deep-media-cleaner' ), $rel ) );
			}
		}
		$note = '';
		if ( 'attachment' === $row->item_type ) {
			$post = get_post( (int) $row->attachment_id );
			if ( $post && 'attachment' === $post->post_type ) {
				$wpdb->update( $wpdb->posts, array( 'post_status' => $row->original_status ? $row->original_status : 'inherit' ), array( 'ID' => (int) $row->attachment_id ) );
				clean_post_cache( (int) $row->attachment_id );
				if ( ! empty( $row->refs ) ) {
					$back = DMC_Unlink::restore( $row->refs );
					if ( $back ) {
						/* translators: %d: number of references */
						$note = sprintf( _n( '%d removed reference was put back.', '%d removed references were put back.', $back, 'deep-media-cleaner' ), $back );
					}
				}
			} else {
				$note = __( 'Files restored, but the media library entry no longer exists.', 'deep-media-cleaner' );
			}
		}
		$wpdb->delete( $t['bin'], array( 'id' => (int) $row->id ) );
		self::rrmdir( self::path( (string) $row->id ) );
		DMC_Util::log( 'restore', ( $row->attachment_id ? '#' . $row->attachment_id . ' ' : '' ) . $row->rel_path );
		return $note ? $note : true;
	}

	public static function delete( $bid ) {
		global $wpdb;
		$t   = DMC_DB::tables();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$t['bin']}` WHERE id = %d", $bid ) ); // phpcs:ignore
		if ( ! $row ) {
			return new WP_Error( 'dmc_missing', __( 'Bin item not found.', 'deep-media-cleaner' ) );
		}
		if ( is_dir( self::path( (string) $row->id ) ) ) {
			self::rrmdir( self::path( (string) $row->id ) );
		}
		if ( 'attachment' === $row->item_type && $row->attachment_id ) {
			$post = get_post( (int) $row->attachment_id );
			if ( $post && 'attachment' === $post->post_type && 'dmc-trash' === $post->post_status ) {
				// The files are already gone from the bin. Stop WordPress from deleting
				// anything at the original paths – a new upload may now use that name.
				add_filter( 'wp_delete_file', '__return_empty_string', 9999 );
				wp_delete_attachment( (int) $row->attachment_id, true );
				remove_filter( 'wp_delete_file', '__return_empty_string', 9999 );
			}
		}
		$wpdb->delete( $t['bin'], array( 'id' => (int) $row->id ) );
		DMC_Util::log( 'delete', ( $row->attachment_id ? '#' . $row->attachment_id . ' ' : '' ) . $row->rel_path );
		return true;
	}

	public static function auto_purge() {
		global $wpdb;
		$days = (int) DMC_Util::settings()['auto_purge_days'];
		if ( $days <= 0 ) {
			return;
		}
		$t   = DMC_DB::tables();
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM `{$t['bin']}` WHERE trashed_at < %s ORDER BY id ASC LIMIT 500", gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - $days * DAY_IN_SECONDS ) ) ); // phpcs:ignore
		foreach ( $ids as $id ) {
			self::delete( (int) $id );
		}
	}

	public static function count() {
		global $wpdb;
		$t = DMC_DB::tables();
		return $wpdb->get_row( "SELECT COUNT(*) n, COALESCE(SUM(size),0) s FROM `{$t['bin']}`" ); // phpcs:ignore
	}
}
