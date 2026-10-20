<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Why is this kept?" – where a reference was found, stored as a small JSON
 * descriptor and rendered as readable English with links.
 *
 * Keys:
 *   k   kind: post | pmeta | option | tmeta | umeta | cmeta | comment | table | theme
 *   tb  table without prefix (posts, postmeta, options, revslider_slides …)
 *   f   column, meta key, option name or file path
 *   p   path inside a serialized / JSON value (e.g. "custom_logo")
 *   id  object id (post, term, user, comment or table row)
 *   ot  object type (post type) when known at scan time
 *   mt  how it matched: id | path | stem | parent | similar
 *   sim the similar file name that matched (mt = similar)
 *   rc  1 = found during the re-check right before moving
 */
class DMC_Reason {

	private static $meta_labels = array(
		'_thumbnail_id'           => 'featured image',
		'_product_image_gallery'  => 'product gallery',
		'_elementor_data'         => 'Elementor content',
		'_elementor_page_settings' => 'Elementor page settings',
		'_menu_item_url'          => 'menu link',
		'thumbnail_id'            => 'image',
		'_wp_attachment_image_alt' => 'alt text',
	);

	private static $key_labels = array(
		'custom_logo'          => 'site logo',
		'site_icon'            => 'site icon',
		'header_image'         => 'header image',
		'header_image_data'    => 'header image',
		'background_image'     => 'background image',
		'widget_media_image'   => 'image widget',
		'widget_media_gallery' => 'gallery widget',
		'widget_media_video'   => 'video widget',
		'widget_media_audio'   => 'audio widget',
		'widget_text'          => 'text widget',
		'widget_custom_html'   => 'custom HTML widget',
		'widget_block'         => 'block widget',
	);

	public static function encode( array $d ) {
		foreach ( array(
			'f'   => 120,
			'p'   => 80,
			'ot'  => 40,
			'sim' => 120,
		) as $k => $max ) {
			if ( isset( $d[ $k ] ) ) {
				$d[ $k ] = mb_substr( (string) $d[ $k ], 0, $max );
			}
		}
		$s = self::json( $d );
		if ( mb_strlen( $s ) > 250 ) {
			unset( $d['p'] );
			foreach ( array( 'f', 'sim' ) as $k ) {
				if ( isset( $d[ $k ] ) ) {
					$d[ $k ] = mb_substr( $d[ $k ], 0, 60 );
				}
			}
			$s = self::json( $d );
		}
		return $s;
	}

	private static function json( $d ) {
		return (string) wp_json_encode( $d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}

	public static function decode( $s ) {
		$s = (string) $s;
		if ( '' === $s || '{' !== $s[0] ) {
			return null;
		}
		$d = json_decode( $s, true );
		return ( is_array( $d ) && isset( $d['k'] ) ) ? $d : null;
	}

	/** Add the match type (and extras) to a stored source. */
	public static function with_match( $source, $mt, array $extra = array() ) {
		$d = self::decode( $source );
		if ( ! $d ) {
			// Source from an older scan: plain text.
			return 'stem' === $mt ? $source . ' (file name match)' : (string) $source;
		}
		$d['mt'] = $mt;
		return self::encode( array_merge( $d, $extra ) );
	}

	/** Add the path inside a serialized/JSON value to a source descriptor. */
	public static function at_path( $source, $path ) {
		if ( ! is_array( $source ) || false === strpos( (string) $path, '/' ) ) {
			return $source;
		}
		$segs = array();
		foreach ( array_slice( explode( '/', $path ), 1 ) as $seg ) {
			if ( '' !== $seg && ! ctype_digit( $seg ) ) {
				$segs[] = $seg;
			}
		}
		if ( $segs ) {
			$source['p'] = implode( '/', array_slice( $segs, -2 ) );
		}
		return $source;
	}

	/** Same descriptor without match / re-check marks: just "where". */
	public static function where_only( $s ) {
		$d = self::decode( $s );
		if ( ! $d ) {
			return (string) $s;
		}
		unset( $d['mt'], $d['sim'], $d['rc'] );
		return self::encode( $d );
	}

	/** Just the object ("Post #12 “Title” · Edit"), falling back to the full location. */
	public static function object_only( $s, $html = true ) {
		$d  = self::decode( $s );
		$id = ( $d && isset( $d['id'] ) ) ? (int) $d['id'] : 0;
		if ( $d && $id ) {
			switch ( $d['k'] ) {
				case 'post':
				case 'pmeta':
					return self::post_label( $id, $html );
				case 'tmeta':
					return self::term_label( $id, $html );
				case 'umeta':
					return self::user_label( $id, $html );
				case 'comment':
				case 'cmeta':
					return self::comment_label( $id, $html );
			}
		}
		return self::render( self::where_only( $s ), $html );
	}

	public static function similar_name( $s ) {
		$d = self::decode( $s );
		return ( $d && isset( $d['sim'] ) ) ? (string) $d['sim'] : '';
	}

	public static function is_soft( $s ) {
		$d = self::decode( $s );
		return $d && isset( $d['mt'] ) && in_array( $d['mt'], array( 'similar', 'stem' ), true );
	}

	/** Prime the post cache for a list of stored reasons (one query per page). */
	public static function prime( array $reasons ) {
		$ids = array();
		foreach ( $reasons as $s ) {
			$d = self::decode( $s );
			if ( $d && in_array( $d['k'], array( 'post', 'pmeta' ), true ) && ! empty( $d['id'] ) ) {
				$ids[] = (int) $d['id'];
			}
		}
		if ( $ids && function_exists( '_prime_post_caches' ) ) {
			_prime_post_caches( array_unique( $ids ), false, false );
		}
	}

	/* ------------------------------------------------------------------ */

	public static function html( $s ) {
		return self::render( $s, true );
	}

	public static function text( $s ) {
		return self::render( $s, false );
	}

	private static function render( $s, $html ) {
		global $wpdb;
		$d = self::decode( $s );
		if ( ! $d ) {
			return $html ? esc_html( (string) $s ) : (string) $s;
		}
		$code  = function ( $x ) use ( $html ) {
			return $html ? '<code>' . esc_html( $x ) . '</code>' : $x;
		};
		$sep   = ' › ';
		$f     = isset( $d['f'] ) ? (string) $d['f'] : '';
		$p     = isset( $d['p'] ) ? (string) $d['p'] : '';
		$id    = isset( $d['id'] ) ? (int) $d['id'] : 0;
		$table = isset( $d['tb'] ) ? $wpdb->prefix . $d['tb'] : '';
		$path  = $p ? $sep . implode( $sep, array_map( $code, explode( '/', $p ) ) ) : '';
		$loc   = '';
		$obj   = '';

		switch ( $d['k'] ) {
			case 'post':
				$loc = $code( $table ) . $sep . $code( $f ) . $path;
				$obj = self::post_label( $id, $html );
				break;
			case 'pmeta':
				$loc = $code( $table ) . $sep . $code( $f ) . $path . self::friendly( $f, $p, $html );
				$obj = self::post_label( $id, $html );
				break;
			case 'option':
				$loc = $code( $table ) . $sep . $code( $f ) . $path . self::friendly( $f, $p, $html );
				break;
			case 'tmeta':
				$loc = $code( $table ) . $sep . $code( $f ) . $path . self::friendly( $f, $p, $html );
				$obj = self::term_label( $id, $html );
				break;
			case 'umeta':
				$loc = $code( $table ) . $sep . $code( $f ) . $path;
				$obj = self::user_label( $id, $html );
				break;
			case 'cmeta':
			case 'comment':
				$loc = $code( $table ) . $sep . $code( $f ) . $path;
				$obj = self::comment_label( $id, $html );
				break;
			case 'table':
				$loc = $code( $table ) . $sep . 'column ' . $code( $f ) . $path;
				$obj = $id ? 'row #' . $id : '';
				break;
			case 'theme':
				$loc = ( 0 === strpos( $f, 'mu-plugins/' ) ? 'Must-use plugin file ' : 'Theme file ' ) . $code( $f );
				break;
			default:
				$loc = $code( $f );
		}

		$out = $loc . ( '' !== $obj ? ' · ' . $obj : '' );
		$mt  = isset( $d['mt'] ) ? $d['mt'] : '';
		if ( 'parent' === $mt ) {
			$out .= ' · [gallery] without ids shows the images attached to it';
		} elseif ( 'stem' === $mt ) {
			$out .= self::warn( 'File name match only (different folder or no folder)', $html );
		} elseif ( 'similar' === $mt ) {
			$out .= self::warn( 'Similar name only: ', $html ) . $code( isset( $d['sim'] ) ? $d['sim'] : '' );
		}
		if ( ! empty( $d['rc'] ) ) {
			$out .= $html ? ' <span class="dmc-rc">(found just before moving)</span>' : ' (found just before moving)';
		}
		return $out;
	}

	private static function warn( $text, $html ) {
		return $html ? ' · <span class="dmc-warn">⚠ ' . esc_html( $text ) . '</span>' : ' · ⚠ ' . $text;
	}

	private static function friendly( $f, $p, $html ) {
		$label = '';
		if ( isset( self::$meta_labels[ $f ] ) ) {
			$label = self::$meta_labels[ $f ];
		} else {
			foreach ( array_reverse( array_merge( array( $f ), $p ? explode( '/', $p ) : array() ) ) as $k ) {
				if ( isset( self::$key_labels[ $k ] ) ) {
					$label = self::$key_labels[ $k ];
					break;
				}
			}
		}
		if ( '' === $label ) {
			return '';
		}
		return $html ? ' <span class="dmc-friendly">(' . esc_html( $label ) . ')</span>' : ' (' . $label . ')';
	}

	private static function quoted( $title ) {
		$title = trim( wp_strip_all_tags( (string) $title ) );
		return '“' . ( '' !== $title ? $title : '(no title)' ) . '”';
	}

	private static function link( $text, $url, $html ) {
		if ( ! $html ) {
			return $text;
		}
		$out = esc_html( $text );
		if ( $url ) {
			$out .= ' · <a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">Edit</a>';
		}
		return $out;
	}

	private static function post_label( $id, $html ) {
		if ( ! $id ) {
			return '';
		}
		$post = get_post( $id );
		if ( ! $post ) {
			return 'Post #' . $id . ' (no longer exists)';
		}
		$type  = get_post_type_object( $post->post_type );
		$label = ( $type && ! empty( $type->labels->singular_name ) ) ? $type->labels->singular_name : $post->post_type;
		$text  = $label . ' #' . $id . ' ' . self::quoted( $post->post_title );
		if ( ! in_array( $post->post_status, array( 'publish', 'inherit' ), true ) ) {
			$st    = get_post_status_object( $post->post_status );
			$text .= ' [' . ( $st && $st->label ? $st->label : $post->post_status ) . ']';
		}
		return self::link( $text, get_edit_post_link( $id, 'raw' ), $html );
	}

	private static function term_label( $id, $html ) {
		$term = $id ? get_term( $id ) : null;
		if ( ! $term || is_wp_error( $term ) ) {
			return $id ? 'Term #' . $id : '';
		}
		$tax   = get_taxonomy( $term->taxonomy );
		$label = ( $tax && ! empty( $tax->labels->singular_name ) ) ? $tax->labels->singular_name : $term->taxonomy;
		$url   = get_edit_term_link( $term );
		return self::link( $label . ' #' . $id . ' ' . self::quoted( $term->name ), is_string( $url ) ? $url : '', $html );
	}

	private static function user_label( $id, $html ) {
		$u = $id ? get_userdata( $id ) : false;
		if ( ! $u ) {
			return $id ? 'User #' . $id : '';
		}
		return self::link( 'User #' . $id . ' ' . self::quoted( $u->display_name ), get_edit_user_link( $id ), $html );
	}

	private static function comment_label( $id, $html ) {
		if ( ! $id ) {
			return '';
		}
		return self::link( 'Comment #' . $id, admin_url( 'comment.php?action=editcomment&c=' . $id ), $html );
	}
}
