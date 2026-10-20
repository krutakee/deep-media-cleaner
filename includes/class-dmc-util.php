<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared helpers: settings, path normalisation and reference extraction.
 *
 * How matching works
 * ------------------
 * Every reference we find anywhere is reduced to one of four keys:
 *   id     – an attachment ID (wp-image-12, "id":12, ids="1,2", _thumbnail_id …)
 *   path   – a file inside the uploads folder: "2023/05/photo" (folder + name,
 *            lower-cased, size suffixes like -300x200 / -scaled removed, no extension)
 *   stem   – a bare file name found outside an uploads URL: "photo"
 *   parent – a post containing a legacy [gallery] with no ids (uses attached images)
 * Keys are stored as md5 hashes so lookups are fast and index-friendly.
 */
class DMC_Util {

	/** Meta/option keys that very likely hold media IDs. */
	const KEY_PATTERN = '~(image|img|photo|pic|logo|icon|thumb|media|gallery|attach|file|video|audio|background|bg|banner|avatar|pdf|document|cover|poster|slide|(?:^|[/_\-])ids?$|_id$)~i';

	/** Stricter pattern for integer columns in custom tables. */
	const COLUMN_PATTERN = '~(image|img|photo|logo|icon|thumb|media|gallery|attach|video|audio|avatar|poster|cover|banner|background|bg_)~i';

	const BASE_EXTS = 'jpg jpeg jpe png gif webp avif bmp tif tiff ico svg heic heif mp4 m4v mov webm ogv avi wmv mpg mpeg 3gp mp3 m4a wav ogg oga flac aac pdf doc docx ppt pptx pps ppsx xls xlsx odt ods odp rtf hwp hwpx zip psd key ttf otf woff woff2 eot html htm txt csv json xml vtt srt epub rar 7z gz';

	private static $settings = null;
	private static $ext_re   = null;
	private static $exts     = null;
	private static $up       = null;

	/* ------------------------------------------------------------------ */
	/* Settings                                                            */
	/* ------------------------------------------------------------------ */

	public static function defaults() {
		return array(
			'recent_days'       => 7,
			'include_revisions' => 0,
			'include_trash'     => 1,
			'safe_numeric'      => 1,
			'attached_as_used'  => 0,
			'scan_theme'        => 1,
			'scan_tables'       => 1,
			'recheck'           => 1,
			'auto_purge_days'   => 0,
			'time_budget'       => 15,
			'excluded_tables'   => implode(
				"\n",
				array(
					'mclean_*',
					'*ewwwio*',
					'*smush*',
					'*shortpixel*',
					'*imagify*',
					'fbv*',
					'*filebird*',
					'realmedialibrary*',
					'rml_*',
					'wpmf*',
					'*media_folder*',
					'*folders*',
					'actionscheduler_*',
					'redirection_logs',
					'redirection_404',
					'wpmailsmtp_*',
					'wfls*',
					'wf*',
					'icl_*',
					'yoast_*',
					'litespeed_*',
					'simple_history*',
					'stream*',
					'aryo_activity_log',
					'wsal_*',
					'*activity_log*',
					'statistics_*',
				)
			),
			'excluded_dirs'     => implode(
				"\n",
				array(
					'elementor',
					'wpforms',
					'woocommerce_uploads',
					'wc-logs',
					'gravity_forms',
					'cache',
					'et-cache',
					'fonts',
					'updraft',
					'ai1wm-backups',
					'backwpup-*',
					'wpcf7_uploads',
					'litespeed',
					'sucuri',
					'wp-staging*',
					'mailpoet*',
					'wpcode*',
				)
			),
			'orphan_exts'       => 'jpg jpeg jpe png gif webp avif bmp tif tiff ico svg heic mp4 m4v mov webm ogv avi wmv mpg mpeg mp3 m4a wav ogg oga flac pdf doc docx ppt pptx pps ppsx xls xlsx odt ods odp hwp hwpx zip',
		);
	}

	public static function settings() {
		if ( null === self::$settings ) {
			$saved          = get_option( 'dmc_settings', array() );
			self::$settings = wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
		}
		return self::$settings;
	}

	public static function reset_cache() {
		self::$settings = null;
		self::$ext_re   = null;
		self::$exts     = null;
	}

	public static function lines( $text ) {
		$out = array();
		foreach ( preg_split( '/[\r\n,]+/', (string) $text ) as $l ) {
			$l = trim( $l );
			if ( '' !== $l ) {
				$out[] = $l;
			}
		}
		return $out;
	}

	public static function matches_any( $name, array $patterns ) {
		foreach ( $patterns as $p ) {
			if ( fnmatch( strtolower( $p ), strtolower( $name ) ) ) {
				return true;
			}
		}
		return false;
	}

	/* ------------------------------------------------------------------ */
	/* Uploads                                                             */
	/* ------------------------------------------------------------------ */

	public static function uploads() {
		if ( null === self::$up ) {
			$u    = wp_upload_dir( null, false );
			$path = trim( (string) wp_parse_url( $u['baseurl'], PHP_URL_PATH ), '/' );
			$alts = array_unique( array_filter( array( $path, 'wp-content/uploads' ) ) );
			usort(
				$alts,
				function ( $a, $b ) {
					return strlen( $b ) - strlen( $a );
				}
			);
			self::$up = array(
				'basedir' => untrailingslashit( wp_normalize_path( $u['basedir'] ) ),
				'baseurl' => untrailingslashit( $u['baseurl'] ),
				'alts'    => implode( '|', array_map( 'preg_quote', $alts, array_fill( 0, count( $alts ), '~' ) ) ),
			);
		}
		return self::$up;
	}

	public static function basedir() {
		$u = self::uploads();
		return $u['basedir'];
	}

	public static function url_for( $rel ) {
		$u = self::uploads();
		return $u['baseurl'] . '/' . implode( '/', array_map( 'rawurlencode', explode( '/', $rel ) ) );
	}

	/** A relative path we are willing to touch (inside uploads, not the bin). */
	public static function rel_ok( $rel ) {
		$rel = (string) $rel;
		if ( '' === $rel || '/' === $rel[0] || false !== strpos( $rel, "\0" ) || preg_match( '~(^|/)\.\.(/|$)~', $rel ) || preg_match( '~^[a-z]+:~i', $rel ) ) {
			return false;
		}
		$first = explode( '/', $rel )[0];
		return $first !== DMC_Bin::dir();
	}

	/**
	 * All files that belong to an attachment, relative to the uploads folder.
	 * The first entry is always the main file. Empty array = no local file.
	 */
	public static function attachment_files( $id ) {
		$main = (string) get_post_meta( $id, '_wp_attached_file', true );
		if ( '' === $main || preg_match( '~^https?://~i', $main ) ) {
			return array();
		}
		$base = self::basedir();
		$main = wp_normalize_path( $main );
		if ( 0 === strpos( $main, $base . '/' ) ) {
			$main = substr( $main, strlen( $base ) + 1 );
		}
		$main = ltrim( $main, '/' );
		$dir  = dirname( $main );
		$dir  = ( '.' === $dir ) ? '' : $dir . '/';

		$files = array( $main );
		$meta  = get_post_meta( $id, '_wp_attachment_metadata', true );
		if ( is_array( $meta ) ) {
			if ( ! empty( $meta['original_image'] ) ) {
				$files[] = $dir . $meta['original_image'];
			}
			if ( ! empty( $meta['sources'] ) && is_array( $meta['sources'] ) ) {
				foreach ( $meta['sources'] as $src ) {
					if ( ! empty( $src['file'] ) ) {
						$files[] = $dir . $src['file'];
					}
				}
			}
			if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
				foreach ( $meta['sizes'] as $size ) {
					if ( ! empty( $size['file'] ) ) {
						$files[] = $dir . $size['file'];
					}
					if ( ! empty( $size['sources'] ) && is_array( $size['sources'] ) ) {
						foreach ( $size['sources'] as $src ) {
							if ( ! empty( $src['file'] ) ) {
								$files[] = $dir . $src['file'];
							}
						}
					}
				}
			}
		}
		$backup = get_post_meta( $id, '_wp_attachment_backup_sizes', true );
		if ( is_array( $backup ) ) {
			foreach ( $backup as $b ) {
				if ( ! empty( $b['file'] ) ) {
					$files[] = $dir . $b['file'];
				}
			}
		}
		$out = array();
		foreach ( $files as $f ) {
			$f = ltrim( wp_normalize_path( $f ), '/' );
			if ( self::rel_ok( $f ) ) {
				$out[ $f ] = true;
			}
		}
		return array_keys( $out );
	}

	/* ------------------------------------------------------------------ */
	/* Normalisation                                                        */
	/* ------------------------------------------------------------------ */

	public static function exts() {
		if ( null === self::$exts ) {
			$s    = self::settings();
			$list = array_merge( preg_split( '/[\s,]+/', self::BASE_EXTS ), preg_split( '/[\s,.]+/', strtolower( $s['orphan_exts'] ) ) );
			$list = array_values( array_unique( array_filter( array_map( 'trim', $list ) ) ) );
			usort(
				$list,
				function ( $a, $b ) {
					return strlen( $b ) - strlen( $a );
				}
			);
			self::$exts = $list;
		}
		return self::$exts;
	}

	public static function ext_re() {
		if ( null === self::$ext_re ) {
			self::$ext_re = implode( '|', array_map( 'preg_quote', self::exts() ) );
		}
		return self::$ext_re;
	}

	public static function lower( $s ) {
		if ( class_exists( 'Normalizer' ) ) {
			$n = Normalizer::normalize( $s, Normalizer::FORM_C ); // macOS uploads Korean names as NFD.
			if ( false !== $n && null !== $n ) {
				$s = $n;
			}
		}
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $s, 'UTF-8' ) : strtolower( $s );
	}

	/** "2023/05/Photo-300x200.jpg" -> "photo" */
	public static function stem( $name ) {
		$name = (string) $name;
		$pos  = strrpos( $name, '/' );
		if ( false !== $pos ) {
			$name = substr( $name, $pos + 1 );
		}
		$s    = self::lower( rawurldecode( $name ) );
		$exts = array_flip( self::exts() );
		while ( preg_match( '/\.([a-z0-9]{2,5})$/', $s, $m ) && isset( $exts[ $m[1] ] ) ) {
			$s = substr( $s, 0, -strlen( $m[0] ) );
		}
		do {
			$before = $s;
			$s      = preg_replace( '/(-\d+x\d+|-scaled|-rotated|-e\d{10,}|@\dx)$/', '', $s );
		} while ( $s !== $before && '' !== $s );
		return $s;
	}

	/** "2023/05/Photo-300x200.jpg" -> "2023/05/photo" */
	public static function dir_stem( $rel ) {
		$rel = ltrim( (string) $rel, '/' );
		$dir = dirname( $rel );
		$dir = ( '.' === $dir || '' === $dir ) ? '' : self::lower( rawurldecode( $dir ) ) . '/';
		return $dir . self::stem( $rel );
	}

	public static function hash( $type, $value ) {
		return md5( $type . '|' . $value );
	}

	/* ------------------------------------------------------------------ */
	/* Reference extraction                                                */
	/* ------------------------------------------------------------------ */

	public static function add( array &$refs, $type, $value, $source ) {
		if ( 'id' === $type || 'parent' === $type ) {
			$value = (int) $value;
			if ( $value <= 0 ) {
				return;
			}
		}
		$value = (string) $value;
		if ( '' === $value ) {
			return;
		}
		$h = self::hash( $type, $value );
		if ( ! isset( $refs[ $h ] ) ) {
			$refs[ $h ] = array( $type, $value, $source );
		}
	}

	public static function add_ids( array &$refs, $chunk, $source ) {
		if ( preg_match_all( '/\d{1,10}/', (string) $chunk, $m ) ) {
			foreach ( $m[0] as $id ) {
				self::add( $refs, 'id', $id, $source );
			}
		}
	}

	private static function uchr( $m ) {
		$c = mb_convert_encoding( pack( 'H*', $m[1] ), 'UTF-8', 'UTF-16BE' );
		return ( false === $c ) ? $m[0] : $c;
	}

	/**
	 * Find media references inside any text (HTML, JSON, CSS, shortcodes …).
	 */
	public static function extract( $text, array &$refs, $source, $post_id = 0 ) {
		if ( ! is_string( $text ) || strlen( $text ) < 3 ) {
			return;
		}
		if ( strlen( $text ) > 16 * MB_IN_BYTES ) {
			$text = substr( $text, 0, 16 * MB_IN_BYTES );
		}
		if ( false !== strpos( $text, '\\/' ) ) {
			$text = str_replace( '\\/', '/', $text );
		}
		if ( false !== strpos( $text, '\\u' ) && function_exists( 'mb_convert_encoding' ) ) {
			$text = preg_replace_callback( '/\\\\u([0-9a-fA-F]{4})/', array( __CLASS__, 'uchr' ), $text );
		}
		if ( false !== strpos( $text, '&#' ) || false !== strpos( $text, '&amp;' ) || false !== strpos( $text, '&quot;' ) ) {
			$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		}

		// --- Attachment IDs -------------------------------------------------
		if ( preg_match_all( '/wp-image-(\d+)/i', $text, $m ) ) {
			foreach ( $m[1] as $id ) {
				self::add( $refs, 'id', $id, $source );
			}
		}
		if ( preg_match_all( '/(?:attachment_id|attachment-id|data-id|data-attachment-id|data-image-id|data-media-id|data-attachment)\s*=\s*["\']?(\d+)/i', $text, $m ) ) {
			foreach ( $m[1] as $id ) {
				self::add( $refs, 'id', $id, $source );
			}
		}
		if ( false !== strpos( $text, '":' ) || false !== strpos( $text, '" :' ) ) {
			$keys = 'id|ids|mediaId|mediaIds|imageId|imageIds|image_id|attachment_id|attachmentId|media_id|imageID|mediaID|featuredImage|featured_image|featured_media|thumbnail_id|logo|image|images|img|gallery|video|audio|poster|background|backgroundImage|bgImage|bg_image|file|photo|icon|avatar';
			if ( preg_match_all( '/"(?:' . $keys . ')"\s*:\s*(\[[^\]]{0,20000}\]|"?\d+"?)/i', $text, $m ) ) {
				foreach ( $m[1] as $chunk ) {
					self::add_ids( $refs, $chunk, $source );
				}
			}
		}
		if ( false !== strpos( $text, '[' ) && preg_match_all( '/\[[a-z][a-z0-9_\-]*\b[^\]]*\]/i', $text, $sc ) ) {
			foreach ( $sc[0] as $tag ) {
				if ( preg_match_all( '/\b([a-z0-9_\-]+)\s*=\s*["\']?(\d[\d,\s]*)["\']?/i', $tag, $am, PREG_SET_ORDER ) ) {
					foreach ( $am as $a ) {
						$k = strtolower( $a[1] );
						if ( 'include' === $k || preg_match( self::KEY_PATTERN, $k ) ) {
							self::add_ids( $refs, $a[2], $source );
						}
					}
				}
				// Legacy [gallery] without ids shows the images attached to the post.
				if ( $post_id && preg_match( '/^\[gallery\b/i', $tag ) && ! preg_match( '/\b(ids|include)\s*=/i', $tag ) ) {
					self::add( $refs, 'parent', $post_id, $source );
				}
			}
		}

		// --- File names / URLs -----------------------------------------------
		$ext = self::ext_re();
		if ( ! preg_match( '~\.(?:' . $ext . ')(?![a-z0-9])~i', $text ) ) {
			return;
		}
		$u       = self::uploads();
		$path_re = '~(?:' . $u['alts'] . ')/([^\s"\'<>()\[\]{}|\\\\^`,;?#*=]+?\.(?:' . $ext . '))(?![a-z0-9])~i';
		if ( preg_match_all( $path_re, $text, $m ) ) {
			foreach ( $m[1] as $rel ) {
				$rel = ltrim( preg_replace( '~/{2,}~', '/', rawurldecode( $rel ) ), '/' );
				$rel = preg_replace( '~^sites/\d+/~', '', $rel );
				self::add( $refs, 'path', self::dir_stem( $rel ), $source );
			}
			$text = preg_replace( $path_re, ' ', $text );
		}
		// Anything else that looks like a media file name (relative URLs, CDN
		// rewrites, CSS, plain text). Matched by name only = conservative.
		$bare_re = '~([^\s"\'<>()\[\]{}|\\\\^`,;?#*=/:]+?\.(?:' . $ext . '))(?![a-z0-9])~i';
		if ( preg_match_all( $bare_re, $text, $m ) ) {
			foreach ( $m[1] as $name ) {
				$stem = self::stem( $name );
				if ( '' !== $stem ) {
					self::add( $refs, 'stem', $stem, $source );
				}
			}
		}
	}

	public static function key_match( $path ) {
		return (bool) preg_match( self::KEY_PATTERN, (string) $path );
	}

	/**
	 * Walk a stored value (plain, serialized or JSON) and collect references.
	 *
	 * @param bool $numeric_any Treat a bare number as an attachment ID even if the key does not look media-related.
	 */
	public static function walk( $value, $path, array &$refs, $source, $numeric_any = false, $depth = 0 ) {
		if ( $depth > 25 ) {
			return;
		}
		if ( is_array( $value ) || is_object( $value ) ) {
			// Numbers inside serialized arrays (ACF galleries, repeaters…) inherit
			// safe mode; JSON (page builders) is decoded with $numeric_any = false.
			foreach ( (array) $value as $k => $v ) {
				self::walk( $v, $path . '/' . $k, $refs, $source, $numeric_any, $depth + 1 );
			}
			return;
		}
		$src = DMC_Reason::at_path( $source, $path );
		if ( is_int( $value ) || ( is_string( $value ) && '' !== $value && ctype_digit( $value ) ) ) {
			if ( strlen( (string) $value ) <= 10 && ( $numeric_any || self::key_match( $path ) ) ) {
				self::add( $refs, 'id', (int) $value, $src );
			}
			return;
		}
		if ( ! is_string( $value ) ) {
			return;
		}
		$t = trim( $value );
		if ( '' === $t ) {
			return;
		}
		if ( preg_match( '/^\d{1,10}(\s*,\s*\d{1,10})+$/', $t ) ) {
			if ( $numeric_any || self::key_match( $path ) ) {
				self::add_ids( $refs, $t, $src );
			}
			return;
		}
		self::extract( $value, $refs, $src );
		if ( is_serialized( $t ) ) {
			$u = @unserialize( $t, array( 'allowed_classes' => false ) ); // phpcs:ignore
			if ( false !== $u ) {
				self::walk( $u, $path, $refs, $source, $numeric_any, $depth + 1 );
			}
		} elseif ( ( '{' === $t[0] || '[' === $t[0] ) && strlen( $t ) < 16 * MB_IN_BYTES ) {
			$j = json_decode( $t, true );
			if ( is_array( $j ) ) {
				self::walk( $j, $path, $refs, $source, false, $depth + 1 );
			}
		}
	}

	/* ------------------------------------------------------------------ */
	/* Misc                                                                */
	/* ------------------------------------------------------------------ */

	public static function ignored() {
		$i = get_option( 'dmc_ignored', array() );
		if ( ! is_array( $i ) ) {
			$i = array();
		}
		return wp_parse_args(
			$i,
			array(
				'att'  => array(),
				'file' => array(),
			)
		);
	}

	public static function log( $action, $detail ) {
		$log = get_option( 'dmc_log', array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}
		array_unshift(
			$log,
			array(
				'time'   => current_time( 'mysql' ),
				'user'   => get_current_user_id(),
				'action' => $action,
				'detail' => mb_substr( (string) $detail, 0, 300 ),
			)
		);
		update_option( 'dmc_log', array_slice( $log, 0, 500 ), false );
	}
}
