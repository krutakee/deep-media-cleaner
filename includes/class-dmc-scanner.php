<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Step-based scanner. Each AJAX request runs as many steps/chunks as fit in the
 * time budget, so big sites never hit PHP time limits.
 *
 * Order matters:
 *  1. index the media library (files of every attachment)
 *  2. collect references from every place they can live
 *  3. walk the uploads folder for files that are not in the library
 *  4. decide used / unused for every library item
 */
class DMC_Scanner {

	const STATE = 'dmc_scan_state';

	private static $skip_meta_keys = array(
		'_edit_lock',
		'_edit_last',
		'_wp_old_slug',
		'_wp_old_date',
		'_encloseme',
		'_pingme',
		'_wp_trash_meta_status',
		'_wp_trash_meta_time',
		'_wp_trash_meta_comments_status',
		'_wp_desired_post_slug',
		'_wp_attached_file',
		'_wp_attachment_metadata',
		'_wp_attachment_backup_sizes',
		'_wp_attachment_image_alt',
		'_wp_attachment_context',
		'session_tokens',
		'_wp_page_template',
	);

	public static function state() {
		$s = get_option( self::STATE, array() );
		return is_array( $s ) ? $s : array();
	}

	public static function is_running() {
		$s = self::state();
		return ! empty( $s['running'] );
	}

	/**
	 * True when the results table holds a finished scan. A running, cancelled
	 * or crashed scan leaves every library item marked unused until the last
	 * step runs, so nothing may be moved from such results.
	 */
	public static function results_ready() {
		return ! self::is_running() && is_array( get_option( 'dmc_last_scan' ) );
	}

	/* ------------------------------------------------------------------ */

	public static function start() {
		global $wpdb;
		DMC_DB::install();
		DMC_Util::reset_cache();
		$t = DMC_DB::tables();
		foreach ( array( 'refs', 'attfiles', 'results' ) as $k ) {
			$wpdb->query( "TRUNCATE TABLE `{$t[$k]}`" ); // phpcs:ignore
		}
		// The old results are gone; until finish() runs there are no valid ones.
		delete_option( 'dmc_last_scan' );
		$s = DMC_Util::settings();

		$steps = array(
			array( 'k' => 'attachments', 'label' => __( 'Indexing the media library', 'deep-media-cleaner' ) ),
			array( 'k' => 'posts', 'label' => __( 'Posts, pages, snippets and other content', 'deep-media-cleaner' ) ),
			array( 'k' => 'postmeta', 'label' => __( 'Post meta (featured images, page builders, custom fields)', 'deep-media-cleaner' ) ),
			array( 'k' => 'options', 'label' => __( 'Site options, widgets and theme settings', 'deep-media-cleaner' ) ),
			array( 'k' => 'meta', 'table' => 'termmeta', 'label' => __( 'Category and tag meta', 'deep-media-cleaner' ) ),
			array( 'k' => 'meta', 'table' => 'usermeta', 'label' => __( 'User meta', 'deep-media-cleaner' ) ),
			array( 'k' => 'meta', 'table' => 'commentmeta', 'label' => __( 'Comment meta', 'deep-media-cleaner' ) ),
			array( 'k' => 'comments', 'label' => __( 'Comments', 'deep-media-cleaner' ) ),
		);
		if ( ! empty( $s['scan_tables'] ) ) {
			foreach ( self::custom_tables() as $tbl ) {
				/* translators: %s: table name */
				$steps[] = array( 'k' => 'table', 'table' => $tbl, 'label' => sprintf( __( 'Database table %s', 'deep-media-cleaner' ), $tbl ) );
			}
		}
		if ( ! empty( $s['scan_theme'] ) ) {
			$steps[] = array( 'k' => 'theme', 'label' => __( 'Theme and must-use plugin files', 'deep-media-cleaner' ) );
		}
		foreach ( self::fs_dirs() as $d ) {
			/* translators: %s: folder name */
			$steps[] = array( 'k' => 'fs', 'dir' => $d, 'label' => sprintf( __( 'Uploads folder: %s', 'deep-media-cleaner' ), '' === $d ? '/' : $d ) );
		}
		$steps[] = array( 'k' => 'compile', 'label' => __( 'Deciding what is used', 'deep-media-cleaner' ) );

		$state = array(
			'running' => true,
			'steps'   => $steps,
			'i'       => 0,
			'cursor'  => 0,
			'started' => time(),
			'note'    => '',
		);
		update_option( self::STATE, $state, false );
		DMC_Util::log( 'scan', __( 'Scan started', 'deep-media-cleaner' ) );
		return $state;
	}

	public static function cancel() {
		delete_option( self::STATE );
		DMC_Util::log( 'scan', __( 'Scan cancelled', 'deep-media-cleaner' ) );
	}

	public static function run() {
		$state = self::state();
		if ( empty( $state['running'] ) ) {
			return $state;
		}
		$s        = DMC_Util::settings();
		$budget   = max( 5, min( 120, (int) $s['time_budget'] ) );
		$deadline = microtime( true ) + $budget;
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( $budget + 60 ); // phpcs:ignore
		}
		wp_raise_memory_limit( 'admin' );

		do {
			if ( $state['i'] >= count( $state['steps'] ) ) {
				$state = self::finish( $state );
				break;
			}
			$step = $state['steps'][ $state['i'] ];
			$r    = self::do_step( $step, $state['cursor'] );
			if ( ! empty( $r['done'] ) ) {
				++$state['i'];
				$state['cursor'] = 0;
			} else {
				$state['cursor'] = $r['cursor'];
			}
			$state['note'] = isset( $r['note'] ) ? $r['note'] : '';
		} while ( microtime( true ) < $deadline );

		if ( ! empty( $state['running'] ) ) {
			update_option( self::STATE, $state, false );
		}
		return $state;
	}

	private static function finish( $state ) {
		global $wpdb;
		$t     = DMC_DB::tables();
		$rows  = $wpdb->get_results( "SELECT is_used, item_type, COUNT(*) n, SUM(size) s FROM `{$t['results']}` GROUP BY is_used, item_type", ARRAY_A ); // phpcs:ignore
		$stats = array();
		foreach ( $rows as $r ) {
			$stats[ $r['is_used'] . '_' . $r['item_type'] ] = array(
				'n' => (int) $r['n'],
				's' => (int) $r['s'],
			);
		}
		update_option(
			'dmc_last_scan',
			array(
				'time'     => current_time( 'mysql' ),
				'duration' => time() - (int) $state['started'],
				'stats'    => $stats,
			),
			false
		);
		$wpdb->query( "TRUNCATE TABLE `{$t['refs']}`" ); // phpcs:ignore
		$wpdb->query( "TRUNCATE TABLE `{$t['attfiles']}`" ); // phpcs:ignore
		delete_option( self::STATE );
		DMC_Util::log( 'scan', __( 'Scan finished', 'deep-media-cleaner' ) );
		$state['running'] = false;
		$state['i']       = count( $state['steps'] );
		return $state;
	}

	private static function do_step( $step, $cursor ) {
		switch ( $step['k'] ) {
			case 'attachments':
				return self::step_attachments( (int) $cursor );
			case 'posts':
				return self::step_posts( (int) $cursor );
			case 'postmeta':
				return self::step_postmeta( (int) $cursor );
			case 'options':
				return self::step_options( (int) $cursor );
			case 'meta':
				return self::step_meta( $step['table'], (int) $cursor );
			case 'comments':
				return self::step_comments( (int) $cursor );
			case 'table':
				return self::step_table( $step['table'], $cursor );
			case 'theme':
				return self::step_theme();
			case 'fs':
				return self::step_fs( $step['dir'] );
			case 'compile':
				return self::step_compile( (int) $cursor );
		}
		return array( 'done' => true );
	}

	/* ------------------------------------------------------------------ */
	/* Helpers                                                             */
	/* ------------------------------------------------------------------ */

	private static function flush( array $refs ) {
		global $wpdb;
		if ( ! $refs ) {
			return;
		}
		$t = DMC_DB::tables();
		foreach ( array_chunk( $refs, 400, true ) as $chunk ) {
			$vals = array();
			foreach ( $chunk as $h => $r ) {
				// ref_value/source are only for display; the hash is what matters.
				if ( is_array( $r[2] ) ) {
					$r[2] = DMC_Reason::encode( $r[2] );
					$chunk[ $h ] = $r;
				}
				$vals[] = $wpdb->prepare( '(%s,%s,%s,%s)', $h, $r[0], self::clean_text( $r[1] ), self::clean_text( $r[2] ) );
			}
			$ok = $wpdb->query( "INSERT IGNORE INTO `{$t['refs']}` (ref_hash, ref_type, ref_value, source) VALUES " . implode( ',', $vals ) ); // phpcs:ignore
			if ( false === $ok ) {
				// A lost reference would make used media look unused, so retry row
				// by row and, as a last resort, keep the hash without the text.
				foreach ( $chunk as $h => $r ) {
					$one = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO `{$t['refs']}` (ref_hash, ref_type, ref_value, source) VALUES (%s,%s,%s,%s)", $h, $r[0], self::clean_text( $r[1] ), self::clean_text( $r[2] ) ) ); // phpcs:ignore
					if ( false === $one ) {
						$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO `{$t['refs']}` (ref_hash, ref_type, ref_value, source) VALUES (%s,%s,'','')", $h, $r[0] ) ); // phpcs:ignore
					}
				}
			}
		}
	}

	private static function clean_text( $s ) {
		$s = wp_check_invalid_utf8( (string) $s, true );
		$s = preg_replace( '/[\x{10000}-\x{10FFFF}]/u', '', $s ); // 4-byte chars break utf8 (non-mb4) tables.
		return mb_substr( (string) $s, 0, 250 );
	}

	private static function in_list( array $items ) {
		return "'" . implode( "','", array_map( 'esc_sql', $items ) ) . "'";
	}

	private static function excluded_post_types() {
		$s  = DMC_Util::settings();
		$ex = array( 'attachment' );
		if ( empty( $s['include_revisions'] ) ) {
			$ex[] = 'revision';
		}
		return $ex;
	}

	private static function excluded_statuses() {
		$s  = DMC_Util::settings();
		$ex = array( 'auto-draft', 'dmc-trash' );
		if ( empty( $s['include_trash'] ) ) {
			$ex[] = 'trash';
		}
		return $ex;
	}

	public static function custom_tables() {
		global $wpdb;
		$all  = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix ) . '%' ) );
		$core = array( 'posts', 'postmeta', 'options', 'comments', 'commentmeta', 'terms', 'term_taxonomy', 'term_relationships', 'termmeta', 'users', 'usermeta', 'links', 'blogs', 'blogmeta', 'site', 'sitemeta', 'signups', 'registration_log', 'blog_versions', 'dmc_refs', 'dmc_attfiles', 'dmc_results', 'dmc_bin' );
		$ex   = DMC_Util::lines( DMC_Util::settings()['excluded_tables'] );
		$out  = array();
		foreach ( $all as $tbl ) {
			$short = substr( $tbl, strlen( $wpdb->prefix ) );
			if ( in_array( $short, $core, true ) ) {
				continue;
			}
			if ( is_multisite() && is_main_site() && preg_match( '/^\d+_/', $short ) ) {
				continue; // Sub-site tables.
			}
			if ( DMC_Util::matches_any( $short, $ex ) || DMC_Util::matches_any( $tbl, $ex ) ) {
				continue;
			}
			$out[] = $tbl;
		}
		return $out;
	}

	public static function fs_dirs() {
		$base = DMC_Util::basedir();
		$ex   = DMC_Util::lines( DMC_Util::settings()['excluded_dirs'] );
		$bin  = DMC_Bin::dir();
		$out  = array( '' );
		if ( is_multisite() && is_main_site() ) {
			$ex[] = 'sites'; // Other sites' uploads live here.
		}
		if ( is_dir( $base ) ) {
			foreach ( scandir( $base ) as $e ) {
				if ( '.' === $e[0] || $e === $bin || ! is_dir( $base . '/' . $e ) || DMC_Util::matches_any( $e, $ex ) ) {
					continue;
				}
				$out[] = $e;
			}
		}
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* Steps                                                               */
	/* ------------------------------------------------------------------ */

	private static function step_attachments( $cursor ) {
		global $wpdb;
		$t    = DMC_DB::tables();
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_date, post_mime_type, post_parent FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_status <> 'dmc-trash' AND ID > %d ORDER BY ID ASC LIMIT 200", $cursor ) );
		if ( ! $rows ) {
			return array( 'done' => true );
		}
		$base = DMC_Util::basedir();
		$af   = array();
		$res  = array();
		foreach ( $rows as $r ) {
			$files = DMC_Util::attachment_files( $r->ID );
			$total = 0;
			foreach ( $files as $rel ) {
				$abs   = $base . '/' . $rel;
				$size  = is_file( $abs ) ? (int) filesize( $abs ) : 0;
				$total += $size;
				$af[]  = $wpdb->prepare( '(%d,%s,%s,%s,%d)', $r->ID, $rel, md5( 'path|' . DMC_Util::dir_stem( $rel ) ), md5( 'stem|' . DMC_Util::stem( $rel ) ), $size );
			}
			// Main file gone from disk (deleted by hand, failed migration…).
			$missing = ( isset( $files[0] ) && ! is_file( $base . '/' . $files[0] ) ) ? 1 : 0;
			$res[]   = $wpdb->prepare( "('attachment',%d,%d,%s,%s,%d,0,%d,'',%s)", $r->ID, $r->post_parent, isset( $files[0] ) ? $files[0] : '', $r->post_mime_type, $total, $missing, $r->post_date );
		}
		foreach ( array_chunk( $af, 300 ) as $c ) {
			$wpdb->query( "INSERT INTO `{$t['attfiles']}` (attachment_id, rel_path, path_hash, stem_hash, size) VALUES " . implode( ',', $c ) ); // phpcs:ignore
		}
		foreach ( array_chunk( $res, 300 ) as $c ) {
			$wpdb->query( "INSERT INTO `{$t['results']}` (item_type, attachment_id, parent_id, rel_path, mime, size, is_used, missing, used_by, item_date) VALUES " . implode( ',', $c ) ); // phpcs:ignore
		}
		$last = end( $rows );
		/* translators: %d: attachment ID */
		return array( 'done' => false, 'cursor' => (int) $last->ID, 'note' => sprintf( __( 'Up to media #%d', 'deep-media-cleaner' ), $last->ID ) );
	}

	private static function step_posts( $cursor ) {
		global $wpdb;
		$sql  = "SELECT ID, post_type, post_content, post_excerpt, post_content_filtered FROM {$wpdb->posts}
			WHERE ID > %d AND post_type NOT IN (" . self::in_list( self::excluded_post_types() ) . ')
			AND post_status NOT IN (' . self::in_list( self::excluded_statuses() ) . ')
			ORDER BY ID ASC LIMIT 100';
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $cursor ) ); // phpcs:ignore
		if ( ! $rows ) {
			return array( 'done' => true );
		}
		$refs = array();
		foreach ( $rows as $r ) {
			foreach ( array( 'post_content', 'post_excerpt', 'post_content_filtered' ) as $col ) {
				$src = array(
					'k'  => 'post',
					'tb' => 'posts',
					'f'  => $col,
					'id' => (int) $r->ID,
					'ot' => $r->post_type,
				);
				DMC_Util::extract( $r->$col, $refs, $src, (int) $r->ID );
			}
		}
		self::flush( $refs );
		$last = end( $rows );
		/* translators: %d: post ID */
		return array( 'done' => false, 'cursor' => (int) $last->ID, 'note' => sprintf( __( 'Up to post #%d', 'deep-media-cleaner' ), $last->ID ) );
	}

	private static function step_postmeta( $cursor ) {
		global $wpdb;
		$s    = DMC_Util::settings();
		$ex   = self::excluded_post_types();
		$sql  = "SELECT pm.meta_id, pm.post_id, pm.meta_key, pm.meta_value, p.post_type FROM {$wpdb->postmeta} pm
			INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			WHERE pm.meta_id > %d AND p.post_type NOT IN (" . self::in_list( $ex ) . ')
			AND p.post_status NOT IN (' . self::in_list( self::excluded_statuses() ) . ')
			ORDER BY pm.meta_id ASC LIMIT 300';
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $cursor ) ); // phpcs:ignore
		if ( ! $rows ) {
			return array( 'done' => true );
		}
		$refs = array();
		foreach ( $rows as $r ) {
			$k = (string) $r->meta_key;
			if ( in_array( $k, self::$skip_meta_keys, true ) || 0 === strpos( $k, '_oembed_' ) ) {
				continue;
			}
			$src = array(
				'k'  => 'pmeta',
				'tb' => 'postmeta',
				'f'  => $k,
				'id' => (int) $r->post_id,
				'ot' => $r->post_type,
			);
			DMC_Util::walk( $r->meta_value, $k, $refs, $src, ! empty( $s['safe_numeric'] ) );
		}
		self::flush( $refs );
		$last = end( $rows );
		/* translators: %d: row ID */
		return array( 'done' => false, 'cursor' => (int) $last->meta_id, 'note' => sprintf( __( 'Row %d', 'deep-media-cleaner' ), $last->meta_id ) );
	}

	private static function step_options( $cursor ) {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_id, option_name, option_value FROM {$wpdb->options}
				WHERE option_id > %d AND option_name NOT LIKE %s AND option_name NOT LIKE %s AND option_name NOT LIKE %s AND option_name NOT LIKE %s
				AND option_name NOT IN ('cron','rewrite_rules')
				ORDER BY option_id ASC LIMIT 200",
				$cursor,
				$wpdb->esc_like( '_transient_' ) . '%',
				$wpdb->esc_like( '_site_transient_' ) . '%',
				$wpdb->esc_like( 'mclean_' ) . '%',
				$wpdb->esc_like( 'dmc_' ) . '%'
			)
		);
		if ( ! $rows ) {
			return array( 'done' => true );
		}
		$refs = array();
		foreach ( $rows as $r ) {
			$src = array(
				'k'  => 'option',
				'tb' => 'options',
				'f'  => $r->option_name,
			);
			DMC_Util::walk( $r->option_value, $r->option_name, $refs, $src, false );
		}
		self::flush( $refs );
		$last = end( $rows );
		return array( 'done' => false, 'cursor' => (int) $last->option_id, 'note' => '' );
	}

	private static function step_meta( $table, $cursor ) {
		global $wpdb;
		$map = array(
			'termmeta'    => array( 'meta_id', 'term_id', 'term' ),
			'usermeta'    => array( 'umeta_id', 'user_id', 'user' ),
			'commentmeta' => array( 'meta_id', 'comment_id', 'comment' ),
		);
		if ( ! isset( $map[ $table ] ) ) {
			return array( 'done' => true );
		}
		list( $pk, $obj, $label ) = $map[ $table ];
		$tbl                      = $wpdb->$table;
		$rows                     = $wpdb->get_results( $wpdb->prepare( "SELECT {$pk} AS pk, {$obj} AS obj, meta_key, meta_value FROM {$tbl} WHERE {$pk} > %d ORDER BY {$pk} ASC LIMIT 300", $cursor ) ); // phpcs:ignore
		if ( ! $rows ) {
			return array( 'done' => true );
		}
		$s       = DMC_Util::settings();
		$numeric = ( 'termmeta' === $table ) && ! empty( $s['safe_numeric'] );
		$refs    = array();
		foreach ( $rows as $r ) {
			$k = (string) $r->meta_key;
			if ( in_array( $k, self::$skip_meta_keys, true ) || preg_match( '/(capabilities|user_level|user-settings|session_tokens|dashboard_quick_press|closedpostboxes|metaboxhidden|meta-box-order|manageedit|persisted_preferences)/', $k ) ) {
				continue;
			}
			$kinds = array(
				'term'    => 'tmeta',
				'user'    => 'umeta',
				'comment' => 'cmeta',
			);
			$src   = array(
				'k'  => $kinds[ $label ],
				'tb' => $table,
				'f'  => $k,
				'id' => (int) $r->obj,
			);
			DMC_Util::walk( $r->meta_value, $k, $refs, $src, $numeric );
		}
		self::flush( $refs );
		$last = end( $rows );
		return array( 'done' => false, 'cursor' => (int) $last->pk, 'note' => '' );
	}

	private static function step_comments( $cursor ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT comment_ID, comment_content FROM {$wpdb->comments} WHERE comment_ID > %d AND comment_approved NOT IN ('spam','trash') ORDER BY comment_ID ASC LIMIT 300", $cursor ) );
		if ( ! $rows ) {
			return array( 'done' => true );
		}
		$refs = array();
		foreach ( $rows as $r ) {
			DMC_Util::extract(
				$r->comment_content,
				$refs,
				array(
					'k'  => 'comment',
					'tb' => 'comments',
					'f'  => 'comment_content',
					'id' => (int) $r->comment_ID,
				)
			);
		}
		self::flush( $refs );
		$last = end( $rows );
		return array( 'done' => false, 'cursor' => (int) $last->comment_ID, 'note' => '' );
	}

	private static function step_table( $table, $cursor ) {
		global $wpdb;
		$table = str_replace( '`', '', $table );
		$cols  = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}`" ); // phpcs:ignore
		if ( ! $cols ) {
			return array( 'done' => true );
		}
		$text = array();
		$nums = array();
		$pks  = array();
		foreach ( $cols as $c ) {
			$type = strtolower( $c->Type ); // phpcs:ignore
			if ( 'PRI' === $c->Key ) { // phpcs:ignore
				$pks[] = $c;
			}
			if ( preg_match( '/char|text|json/', $type ) ) {
				$text[] = $c->Field; // phpcs:ignore
			} elseif ( preg_match( '/int/', $type ) && preg_match( DMC_Util::COLUMN_PATTERN, $c->Field ) ) { // phpcs:ignore
				$nums[] = $c->Field; // phpcs:ignore
			}
		}
		if ( ! $text && ! $nums ) {
			return array( 'done' => true );
		}
		$pk     = ( 1 === count( $pks ) && preg_match( '/int/i', $pks[0]->Type ) ) ? $pks[0]->Field : ''; // phpcs:ignore
		$fields = array_unique( array_merge( $pk ? array( $pk ) : array(), $text, $nums ) );
		$select = '`' . implode( '`,`', array_map( 'esc_sql', $fields ) ) . '`';
		$cur    = is_array( $cursor ) ? $cursor : array( 'last' => 0, 'offset' => 0 );
		$limit  = 300;
		if ( $pk ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT {$select} FROM `{$table}` WHERE `{$pk}` > %d ORDER BY `{$pk}` ASC LIMIT {$limit}", $cur['last'] ), ARRAY_A ); // phpcs:ignore
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT {$select} FROM `{$table}` LIMIT {$limit} OFFSET %d", $cur['offset'] ), ARRAY_A ); // phpcs:ignore
		}
		if ( ! $rows ) {
			return array( 'done' => true );
		}
		$short = substr( $table, strlen( $wpdb->prefix ) );
		$refs  = array();
		foreach ( $rows as $i => $row ) {
			$rid = $pk ? $row[ $pk ] : ( $cur['offset'] + $i );
			foreach ( array_merge( $text, $nums ) as $f ) {
				$src = array(
					'k'  => 'table',
					'tb' => $short,
					'f'  => $f,
					'id' => (int) $rid,
				);
				if ( in_array( $f, $nums, true ) ) {
					if ( (int) $row[ $f ] > 0 ) {
						DMC_Util::add( $refs, 'id', (int) $row[ $f ], $src );
					}
				} elseif ( null !== $row[ $f ] && '' !== $row[ $f ] ) {
					DMC_Util::walk( $row[ $f ], $f, $refs, $src, false );
				}
			}
		}
		self::flush( $refs );
		if ( count( $rows ) < $limit ) {
			return array( 'done' => true );
		}
		if ( $pk ) {
			$last        = end( $rows );
			$cur['last'] = (int) $last[ $pk ];
		} else {
			$cur['offset'] += $limit;
		}
		return array( 'done' => false, 'cursor' => $cur, 'note' => '' );
	}

	private static function step_theme() {
		$dirs = array( wp_normalize_path( get_stylesheet_directory() ), wp_normalize_path( get_template_directory() ) );
		if ( defined( 'WPMU_PLUGIN_DIR' ) ) {
			$dirs[] = wp_normalize_path( WPMU_PLUGIN_DIR ); // Must-use plugins often hard-code logos, etc.
		}
		$dirs = array_unique( $dirs );
		$refs = array();
		foreach ( $dirs as $dir ) {
			if ( ! is_dir( $dir ) ) {
				continue;
			}
			$it = new RecursiveIteratorIterator(
				new RecursiveCallbackFilterIterator(
					new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
					function ( $f ) {
						return ! ( $f->isDir() && in_array( $f->getFilename(), array( 'node_modules', '.git', 'vendor' ), true ) );
					}
				)
			);
			foreach ( $it as $f ) {
				if ( ! $f->isFile() || $f->getSize() > 2 * MB_IN_BYTES ) {
					continue;
				}
				if ( ! in_array( strtolower( $f->getExtension() ), array( 'php', 'css', 'scss', 'js', 'html', 'htm', 'json', 'twig', 'svg' ), true ) ) {
					continue;
				}
				$content = file_get_contents( $f->getPathname() ); // phpcs:ignore
				$rel     = ltrim( substr( wp_normalize_path( $f->getPathname() ), strlen( dirname( $dir ) ) ), '/' );
				DMC_Util::extract(
					(string) $content,
					$refs,
					array(
						'k' => 'theme',
						'f' => $rel,
					)
				);
			}
		}
		self::flush( $refs );
		return array( 'done' => true );
	}

	/**
	 * Find files in one uploads sub-folder that are NOT part of any library item,
	 * and record them (used or unused).
	 */
	private static function step_fs( $dir ) {
		global $wpdb;
		$t    = DMC_DB::tables();
		$s    = DMC_Util::settings();
		$base = DMC_Util::basedir();
		$abs  = ( '' === $dir ) ? $base : $base . '/' . $dir;
		if ( ! is_dir( $abs ) ) {
			return array( 'done' => true );
		}
		$exts = array_flip( preg_split( '/[\s,.]+/', strtolower( trim( $s['orphan_exts'] ) ) ) );

		if ( '' === $dir ) {
			$hashes = $wpdb->get_col( "SELECT path_hash FROM `{$t['attfiles']}` WHERE rel_path NOT LIKE '%/%'" ); // phpcs:ignore
		} else {
			$hashes = $wpdb->get_col( $wpdb->prepare( "SELECT path_hash FROM `{$t['attfiles']}` WHERE rel_path LIKE %s", $wpdb->esc_like( $dir ) . '/%' ) ); // phpcs:ignore
		}
		$own = array_flip( $hashes );

		if ( '' === $dir ) {
			$iter = new FilesystemIterator( $abs, FilesystemIterator::SKIP_DOTS );
		} else {
			$iter = new RecursiveIteratorIterator(
				new RecursiveCallbackFilterIterator(
					new RecursiveDirectoryIterator( $abs, FilesystemIterator::SKIP_DOTS ),
					function ( $f ) {
						return '.' !== substr( $f->getFilename(), 0, 1 );
					}
				)
			);
		}

		$cands = array();
		foreach ( $iter as $f ) {
			if ( ! $f->isFile() ) {
				continue;
			}
			if ( ! isset( $exts[ strtolower( $f->getExtension() ) ] ) ) {
				continue;
			}
			$rel = ltrim( substr( wp_normalize_path( $f->getPathname() ), strlen( $base ) ), '/' );
			$ph  = md5( 'path|' . DMC_Util::dir_stem( $rel ) );
			if ( isset( $own[ $ph ] ) ) {
				continue; // Belongs to a library item (main file, a size, or a leftover size).
			}
			$cands[] = array(
				'rel'   => $rel,
				'size'  => (int) $f->getSize(),
				'mtime' => (int) $f->getMTime(),
				'ph'    => $ph,
				'sh'    => md5( 'stem|' . DMC_Util::stem( $rel ) ),
			);
		}
		if ( ! $cands ) {
			return array( 'done' => true );
		}

		$found  = self::lookup_refs( array_merge( wp_list_pluck( $cands, 'ph' ), wp_list_pluck( $cands, 'sh' ) ) );
		$ign    = array_flip( DMC_Util::ignored()['file'] );
		$cutoff = time() - DAY_IN_SECONDS * max( 0, (int) $s['recent_days'] );
		$vals   = array();
		foreach ( $cands as $c ) {
			if ( isset( $ign[ $c['rel'] ] ) ) {
				$u   = 2;
				$why = __( 'Ignored by you', 'deep-media-cleaner' );
			} elseif ( isset( $found[ $c['ph'] ] ) ) {
				$u   = 1;
				$why = DMC_Reason::with_match( $found[ $c['ph'] ], 'path' );
			} elseif ( isset( $found[ $c['sh'] ] ) ) {
				$u   = 1;
				$why = DMC_Reason::with_match( $found[ $c['sh'] ], 'stem' );
			} elseif ( (int) $s['recent_days'] > 0 && $c['mtime'] > $cutoff ) {
				$u   = 1;
				$why = __( 'Recently added (protected)', 'deep-media-cleaner' );
			} else {
				$u   = 0;
				$why = '';
			}
			$ft     = wp_check_filetype( basename( $c['rel'] ) );
			$vals[] = $wpdb->prepare( "('file',0,0,%s,%s,%d,%d,%s,%s)", $c['rel'], (string) $ft['type'], $c['size'], $u, mb_substr( $why, 0, 255 ), wp_date( 'Y-m-d H:i:s', $c['mtime'] ) );
		}
		foreach ( array_chunk( $vals, 300 ) as $chunk ) {
			$wpdb->query( "INSERT INTO `{$t['results']}` (item_type, attachment_id, parent_id, rel_path, mime, size, is_used, used_by, item_date) VALUES " . implode( ',', $chunk ) ); // phpcs:ignore
		}
		return array( 'done' => true );
	}

	private static function lookup_refs( array $hashes ) {
		global $wpdb;
		$t     = DMC_DB::tables();
		$found = array();
		foreach ( array_chunk( array_values( array_unique( $hashes ) ), 500 ) as $chunk ) {
			$rows = $wpdb->get_results( "SELECT ref_hash, source FROM `{$t['refs']}` WHERE ref_hash IN (" . self::in_list( $chunk ) . ')' ); // phpcs:ignore
			foreach ( $rows as $r ) {
				$found[ $r->ref_hash ] = $r->source;
			}
		}
		return $found;
	}

	private static function step_compile( $cursor ) {
		global $wpdb;
		$t    = DMC_DB::tables();
		$s    = DMC_Util::settings();
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, attachment_id, parent_id, rel_path, item_date FROM `{$t['results']}` WHERE item_type = 'attachment' AND id > %d ORDER BY id ASC LIMIT 300", $cursor ) ); // phpcs:ignore
		if ( ! $rows ) {
			return array( 'done' => true );
		}
		$ids   = array_map( 'intval', wp_list_pluck( $rows, 'attachment_id' ) );
		$files = $wpdb->get_results( 'SELECT attachment_id, path_hash, stem_hash FROM `' . $t['attfiles'] . '` WHERE attachment_id IN (' . implode( ',', $ids ) . ')' ); // phpcs:ignore
		$by    = array();
		foreach ( $files as $f ) {
			$by[ $f->attachment_id ]['p'][] = $f->path_hash;
			$by[ $f->attachment_id ]['s'][] = $f->stem_hash;
		}
		$hashes = array();
		foreach ( $rows as $r ) {
			$hashes[] = md5( 'id|' . $r->attachment_id );
			if ( $r->parent_id ) {
				$hashes[] = md5( 'parent|' . $r->parent_id );
			}
			if ( isset( $by[ $r->attachment_id ] ) ) {
				$hashes = array_merge( $hashes, $by[ $r->attachment_id ]['p'], $by[ $r->attachment_id ]['s'] );
			}
		}
		$found = self::lookup_refs( $hashes );

		$parent_status = array();
		$parents       = array_filter( array_unique( array_map( 'intval', wp_list_pluck( $rows, 'parent_id' ) ) ) );
		if ( $parents && ! empty( $s['attached_as_used'] ) ) {
			foreach ( $wpdb->get_results( "SELECT ID, post_type, post_status FROM {$wpdb->posts} WHERE ID IN (" . implode( ',', $parents ) . ')' ) as $p ) { // phpcs:ignore
				$parent_status[ $p->ID ] = $p;
			}
		}

		$ign    = array_flip( array_map( 'intval', DMC_Util::ignored()['att'] ) );
		$cutoff = time() - DAY_IN_SECONDS * max( 0, (int) $s['recent_days'] );

		foreach ( $rows as $r ) {
			$aid = (int) $r->attachment_id;
			$u   = 0;
			$why = '';
			if ( isset( $ign[ $aid ] ) ) {
				$u   = 2;
				$why = __( 'Ignored by you', 'deep-media-cleaner' );
			} elseif ( '' === $r->rel_path ) {
				$u   = 1;
				$why = __( 'No local file (external / offloaded) – left alone', 'deep-media-cleaner' );
			} elseif ( isset( $found[ md5( 'id|' . $aid ) ] ) ) {
				$u   = 1;
				$why = DMC_Reason::with_match( $found[ md5( 'id|' . $aid ) ], 'id' );
			} else {
				if ( isset( $by[ $aid ] ) ) {
					foreach ( $by[ $aid ]['p'] as $h ) {
						if ( isset( $found[ $h ] ) ) {
							$u   = 1;
							$why = DMC_Reason::with_match( $found[ $h ], 'path' );
							break;
						}
					}
					if ( ! $u ) {
						foreach ( $by[ $aid ]['s'] as $h ) {
							if ( isset( $found[ $h ] ) ) {
								$u   = 1;
								$why = DMC_Reason::with_match( $found[ $h ], 'stem' );
								break;
							}
						}
					}
				}
				if ( ! $u && $r->parent_id && isset( $found[ md5( 'parent|' . $r->parent_id ) ] ) ) {
					$u   = 1;
					$why = DMC_Reason::with_match( $found[ md5( 'parent|' . $r->parent_id ) ], 'parent' );
				}
				if ( ! $u && (int) $s['recent_days'] > 0 && $r->item_date && strtotime( get_gmt_from_date( $r->item_date ) ) > $cutoff ) {
					$u   = 1;
					$why = __( 'Recently uploaded (protected)', 'deep-media-cleaner' );
				}
				if ( ! $u && $r->parent_id && isset( $parent_status[ $r->parent_id ] ) && ! in_array( $parent_status[ $r->parent_id ]->post_status, array( 'trash', 'auto-draft' ), true ) ) {
					$u   = 1;
					/* translators: 1: post type 2: post ID */
					$why = sprintf( __( 'Attached to %1$s #%2$d (setting)', 'deep-media-cleaner' ), $parent_status[ $r->parent_id ]->post_type, $r->parent_id );
				}
			}
			$wpdb->update(
				$t['results'],
				array(
					'is_used' => $u,
					'used_by' => mb_substr( $why, 0, 255 ),
				),
				array( 'id' => (int) $r->id ),
				array( '%d', '%s' ),
				array( '%d' )
			);
		}
		$last = end( $rows );
		return array( 'done' => false, 'cursor' => (int) $last->id, 'note' => '' );
	}

	/* ------------------------------------------------------------------ */
	/* Quick re-check right before something goes to the bin              */
	/* ------------------------------------------------------------------ */

	/**
	 * Returns a reason string if the item looks used *now* (content may have
	 * changed since the scan), or '' if it still looks unused.
	 */
	public static function quick_recheck( $row ) {
		global $wpdb;
		$types = self::in_list( array( 'attachment', 'revision' ) );
		$stats = self::in_list( array( 'auto-draft', 'dmc-trash', 'trash' ) );

		if ( 'attachment' === $row->item_type ) {
			$aid = (int) $row->attachment_id;
			$hit = $wpdb->get_row( $wpdb->prepare( "SELECT pm.post_id, p.post_type FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = '_thumbnail_id' AND pm.meta_value = %s LIMIT 1", (string) $aid ) );
			if ( $hit ) {
				return DMC_Reason::encode(
					array(
						'k'  => 'pmeta',
						'tb' => 'postmeta',
						'f'  => '_thumbnail_id',
						'id' => (int) $hit->post_id,
						'ot' => $hit->post_type,
						'mt' => 'id',
						'rc' => 1,
					)
				);
			}
			$cands = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_type, post_content FROM {$wpdb->posts} WHERE post_type NOT IN ($types) AND post_status NOT IN ($stats) AND post_content LIKE %s LIMIT 20", '%' . $wpdb->esc_like( 'wp-image-' . $aid ) . '%' ) ); // phpcs:ignore
			foreach ( $cands as $c ) {
				if ( preg_match( '/wp-image-' . $aid . '(?!\d)/', $c->post_content ) ) { // Not wp-image-1234 for #123.
					return DMC_Reason::encode(
						array(
							'k'  => 'post',
							'tb' => 'posts',
							'f'  => 'post_content',
							'id' => (int) $c->ID,
							'ot' => $c->post_type,
							'mt' => 'id',
							'rc' => 1,
						)
					);
				}
			}
		}

		$base = wp_basename( $row->rel_path );
		$name = preg_replace( '/(-\d+x\d+|-scaled|-rotated)+$/', '', pathinfo( $base, PATHINFO_FILENAME ) );
		if ( mb_strlen( $name ) < 4 ) {
			return '';
		}
		$stem    = DMC_Util::stem( $base );
		$like    = '%' . $wpdb->esc_like( $name ) . '%';
		$similar = null;

		$posts = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_type, post_content FROM {$wpdb->posts} WHERE post_type NOT IN ($types) AND post_status NOT IN ($stats) AND post_content LIKE %s LIMIT 20", $like ) ); // phpcs:ignore
		foreach ( $posts as $p ) {
			list( $exact, $tok ) = self::name_hits( $p->post_content, $name, $stem );
			$d = array(
				'k'  => 'post',
				'tb' => 'posts',
				'f'  => 'post_content',
				'id' => (int) $p->ID,
				'ot' => $p->post_type,
				'rc' => 1,
			);
			if ( $exact ) {
				return DMC_Reason::encode( $d + array( 'mt' => 'name' ) );
			}
			if ( ! $similar && '' !== $tok ) {
				$similar = $d + array(
					'mt'  => 'similar',
					'sim' => $tok,
				);
			}
		}

		$metas = $wpdb->get_results( $wpdb->prepare( "SELECT pm.post_id, pm.meta_key, pm.meta_value, p.post_type FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.post_type NOT IN ($types) AND p.post_status NOT IN ($stats) AND pm.meta_value LIKE %s LIMIT 20", $like ) ); // phpcs:ignore
		foreach ( $metas as $m ) {
			list( $exact, $tok ) = self::name_hits( $m->meta_value, $name, $stem );
			$d = array(
				'k'  => 'pmeta',
				'tb' => 'postmeta',
				'f'  => $m->meta_key,
				'id' => (int) $m->post_id,
				'ot' => $m->post_type,
				'rc' => 1,
			);
			if ( $exact ) {
				return DMC_Reason::encode( $d + array( 'mt' => 'name' ) );
			}
			if ( ! $similar && '' !== $tok ) {
				$similar = $d + array(
					'mt'  => 'similar',
					'sim' => $tok,
				);
			}
		}

		return $similar ? DMC_Reason::encode( $similar ) : '';
	}

	/**
	 * Look at every place $name occurs in $text and return [exact?, token]:
	 * exact when the surrounding file name is the same file (size variants
	 * included); otherwise the first similar token, e.g. "bulletin-2024.pdf".
	 */
	private static function name_hits( $text, $name, $stem ) {
		$text = str_replace( '\\/', '/', (string) $text );
		$len  = strlen( $name );
		$off  = 0;
		$sim  = '';
		for ( $n = 0; $n < 50; $n++ ) {
			$pos = stripos( $text, $name, $off );
			if ( false === $pos ) {
				break;
			}
			$off = $pos + $len;
			$tok = self::token_at( $text, $pos, $len );
			$is_file = (bool) preg_match( '/[^.\/]\.[a-z0-9]{2,5}$/i', $tok );
			if ( $is_file && DMC_Util::stem( $tok ) === $stem ) {
				return array( true, $tok );
			}
			if ( '' === $sim && $is_file ) {
				$sim = $tok; // Only real file names count as "similar", not words or CSS classes.
			}
		}
		return array( false, $sim );
	}

	private static function token_at( $text, $pos, $len ) {
		$delim = " \t\r\n\"'<>()[]{}|\\^`,;?#*=/:&";
		$s     = $pos;
		while ( $s > 0 && false === strpos( $delim, $text[ $s - 1 ] ) && $pos - $s < 200 ) {
			--$s;
		}
		$e   = $pos + $len;
		$max = strlen( $text );
		while ( $e < $max && false === strpos( $delim, $text[ $e ] ) && $e - $pos < 300 ) {
			++$e;
		}
		return rawurldecode( substr( $text, $s, $e - $s ) );
	}
}
