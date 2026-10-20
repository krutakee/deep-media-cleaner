<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DMC_Admin {

	const CAP  = 'manage_options';
	const SLUG = 'deep-media-cleaner';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_ajax_dmc_scan', array( __CLASS__, 'ajax_scan' ) );
		add_action( 'wp_ajax_dmc_action', array( __CLASS__, 'ajax_action' ) );
		add_action( 'admin_post_dmc_save_settings', array( __CLASS__, 'save_settings' ) );
		add_action( 'admin_post_dmc_export', array( __CLASS__, 'export_csv' ) );
		add_filter( 'manage_media_columns', array( __CLASS__, 'media_column' ) );
		add_action( 'manage_media_custom_column', array( __CLASS__, 'media_column_value' ), 10, 2 );
		add_filter( 'plugin_action_links_' . plugin_basename( DMC_FILE ), array( __CLASS__, 'action_links' ) );
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Open', 'deep-media-cleaner' ) . '</a>' );
		return $links;
	}

	public static function url( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'upload.php' ) );
	}

	public static function menu() {
		add_media_page( __( 'Deep Media Cleaner', 'deep-media-cleaner' ), __( 'Deep Media Cleaner', 'deep-media-cleaner' ), self::CAP, self::SLUG, array( __CLASS__, 'render' ) );
	}

	public static function assets( $hook ) {
		if ( 'media_page_' . self::SLUG !== $hook ) {
			return;
		}
		// Version = plugin version + file time, so browsers and caching plugins
		// always load the new files after an update.
		$ver = function ( $f ) {
			$t = @filemtime( DMC_DIR . $f ); // phpcs:ignore
			return DMC_VERSION . ( $t ? '.' . $t : '' );
		};
		wp_enqueue_style( 'dmc-admin', DMC_URL . 'assets/admin.css', array(), $ver( 'assets/admin.css' ) );
		wp_enqueue_script( 'dmc-admin', DMC_URL . 'assets/admin.js', array( 'jquery' ), $ver( 'assets/admin.js' ), true );
		$state = DMC_Scanner::state();
		wp_localize_script(
			'dmc-admin',
			'DMC',
			array(
				'ajax'    => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'dmc' ),
				'running' => ! empty( $state['running'] ),
				'url'     => self::url(),
				'i18n'    => array(
					'confirmAll'    => __( "Move ALL unused items to the recycle bin?\n\nThey can be restored from the bin later.", 'deep-media-cleaner' ),
					'confirmForce'  => __( "Move this item to the recycle bin anyway?\n\nIt was kept only because a similar file name appears somewhere. Check the place shown first. You can restore it from the bin.", 'deep-media-cleaner' ),
					'confirmMissing' => __( "Move the selected library entries to the recycle bin?\n\nTheir files are already gone from the server. Any place that still links to them keeps a broken link. You can restore the entries from the bin.", 'deep-media-cleaner' ),
					'confirmUnlink' => __( "Remove simple references and move the selected items to the recycle bin?\n\nOnly custom fields whose whole value is this item's ID or file URL are removed (for example a featured image or a form upload field). Page content, page-builder layouts, settings and plugin tables are never edited.\n\nRestoring an item from the bin puts its references back.", 'deep-media-cleaner' ),
					'detailsHead'   => __( 'Details', 'deep-media-cleaner' ),
					'groups'        => array(
						'in_use'     => __( 'Not moved – still in use', 'deep-media-cleaner' ),
						'error'      => __( 'Could not be done', 'deep-media-cleaner' ),
						'moved_note' => __( 'Moved to the bin – just so you know', 'deep-media-cleaner' ),
						'removed'    => __( 'References removed', 'deep-media-cleaner' ),
						'left'       => __( 'References left alone (not simple – edit these yourself if needed)', 'deep-media-cleaner' ),
						'would'      => __( 'Would be removed', 'deep-media-cleaner' ),
						'keep'       => __( 'Would be left alone (not simple)', 'deep-media-cleaner' ),
						'info'       => __( 'Notes', 'deep-media-cleaner' ),
					),
					'doneLines'     => array(
						/* translators: 1: number moved 2: number not moved */
						'trash'   => __( '%1$s moved to the bin · %2$s not moved', 'deep-media-cleaner' ),
						/* translators: 1: number restored 2: number not restored */
						'restore' => __( '%1$s restored · %2$s not restored', 'deep-media-cleaner' ),
						/* translators: 1: number deleted 2: number not deleted */
						'delete'  => __( '%1$s deleted permanently · %2$s not deleted', 'deep-media-cleaner' ),
						/* translators: 1: number checked */
						'preview' => __( '%1$s checked – nothing was changed', 'deep-media-cleaner' ),
					),
					'inUseHint'     => __( 'Files that are still in use were not touched. You will find them in the Used tab.', 'deep-media-cleaner' ),
					'confirmDelete' => __( "Delete the selected items permanently?\n\nThis cannot be undone.", 'deep-media-cleaner' ),
					'confirmEmpty'  => __( "Empty the recycle bin?\n\nEverything in it will be deleted permanently. This cannot be undone.", 'deep-media-cleaner' ),
					'noneSelected'  => __( 'Select at least one item first.', 'deep-media-cleaner' ),
					'working'       => __( 'Working…', 'deep-media-cleaner' ),
					'error'         => __( 'Request failed. Check that you are still logged in, then try again.', 'deep-media-cleaner' ),
					/* translators: %s: HTTP status */
					'serverError'   => __( 'The server returned an error (HTTP %s). Details:', 'deep-media-cleaner' ),
					'phpNotices'    => __( 'PHP notices printed during the request (not fatal):', 'deep-media-cleaner' ),
					'scanFailed'    => __( 'The scan stopped because the server kept failing. Lower “Work per request” in Settings, then reload this page — the scan continues where it stopped.', 'deep-media-cleaner' ),
					'retrying'      => __( 'Server did not answer, retrying', 'deep-media-cleaner' ),
					'remaining'     => __( 'remaining', 'deep-media-cleaner' ),
					'stop'          => __( 'Stop', 'deep-media-cleaner' ),
					'stopping'      => __( 'Stopping after the current batch…', 'deep-media-cleaner' ),
					'calculating'   => __( 'Estimating time left…', 'deep-media-cleaner' ),
					/* translators: %s: duration */
					'timeLeft'      => __( 'About %s left', 'deep-media-cleaner' ),
					/* translators: %s: duration */
					'elapsed'       => __( '%s elapsed', 'deep-media-cleaner' ),
					'skipped'       => __( 'skipped', 'deep-media-cleaner' ),
					'min'           => __( 'm', 'deep-media-cleaner' ),
					'sec'           => __( 's', 'deep-media-cleaner' ),
					'leaveWarning'  => __( 'Work is still running. Leaving now stops it after the current batch.', 'deep-media-cleaner' ),
					'stoppedEarly'  => __( 'Stopped by you. The rest was not touched.', 'deep-media-cleaner' ),
					'finished'      => __( 'Finished', 'deep-media-cleaner' ),
					'stoppedTitle'  => __( 'Stopped', 'deep-media-cleaner' ),
					/* translators: 1: number done 2: number skipped */
					'doneLine'      => __( '%1$s done · %2$s skipped', 'deep-media-cleaner' ),
					'skippedHead'   => __( 'Skipped items and why', 'deep-media-cleaner' ),
					'notesHead'     => __( 'Notes', 'deep-media-cleaner' ),
					'usedHint'      => __( 'Items found to be in use were left in place and moved to the Used tab, with where they are used.', 'deep-media-cleaner' ),
					'showUsed'      => __( 'Open the Used tab', 'deep-media-cleaner' ),
					'copyList'      => __( 'Copy list', 'deep-media-cleaner' ),
					'copied'        => __( 'Copied', 'deep-media-cleaner' ),
					'closeRefresh'  => __( 'Close and refresh', 'deep-media-cleaner' ),
					'titles'        => array(
						'trash'       => __( 'Moving items to the recycle bin', 'deep-media-cleaner' ),
						'trash_force' => __( 'Moving items to the recycle bin (no re-check)', 'deep-media-cleaner' ),
						'trash_missing' => __( 'Moving library entries with missing files to the bin', 'deep-media-cleaner' ),
						'trash_missing_unlink' => __( 'Removing simple references and moving to the bin', 'deep-media-cleaner' ),
						'trash_missing_unlink_all' => __( 'Removing simple references and moving all to the bin', 'deep-media-cleaner' ),
						'preview_unlink' => __( 'Checking references (nothing is changed)', 'deep-media-cleaner' ),
						'trash_missing_all' => __( 'Moving all library entries with missing files to the bin', 'deep-media-cleaner' ),
						'trash_all'   => __( 'Moving all unused items to the recycle bin', 'deep-media-cleaner' ),
						'ignore'      => __( 'Adding to the ignore list', 'deep-media-cleaner' ),
						'unignore'    => __( 'Removing from the ignore list', 'deep-media-cleaner' ),
						'restore'     => __( 'Restoring items', 'deep-media-cleaner' ),
						'restore_all' => __( 'Restoring everything in the bin', 'deep-media-cleaner' ),
						'delete'      => __( 'Deleting permanently', 'deep-media-cleaner' ),
						'empty'       => __( 'Emptying the recycle bin', 'deep-media-cleaner' ),
					),
				),
			)
		);
	}

	/**
	 * Keep AJAX answers valid JSON even when PHP prints warnings, and turn a
	 * fatal error into a readable message instead of a dead request.
	 */
	private static function guard_json() {
		static $on = false;
		if ( $on ) {
			return;
		}
		$on = true;
		ob_start(
			function ( $buf ) {
				$p = strpos( $buf, '{"success"' );
				if ( false === $p || 0 === $p ) {
					return $buf;
				}
				// Stray output (PHP notices from any plugin) before our JSON:
				// drop it from the response but keep it for diagnosis.
				$json = json_decode( substr( $buf, $p ), true );
				if ( ! is_array( $json ) ) {
					return substr( $buf, $p );
				}
				$json['debug'] = mb_substr( trim( wp_strip_all_tags( substr( $buf, 0, $p ) ) ), 0, 600 );
				return wp_json_encode( $json );
			}
		);
		register_shutdown_function(
			function () {
				$e = error_get_last();
				if ( ! $e || ! in_array( $e['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR ), true ) ) {
					return;
				}
				while ( ob_get_level() > 0 ) {
					ob_end_clean();
				}
				if ( ! headers_sent() ) {
					http_response_code( 200 );
					header( 'Content-Type: application/json; charset=utf-8' );
				}
				echo wp_json_encode( // phpcs:ignore
					array(
						'success' => false,
						'data'    => array(
							/* translators: 1: PHP error 2: file 3: line */
							'message' => sprintf( __( 'Server error: %1$s (%2$s line %3$d). Please send this message to the plugin author.', 'deep-media-cleaner' ), $e['message'], wp_basename( $e['file'] ), $e['line'] ),
						),
					)
				);
			}
		);
	}

	private static function check( $ajax = true ) {
		if ( ! current_user_can( self::CAP ) ) {
			if ( $ajax ) {
				wp_send_json_error( array( 'message' => __( 'Permission denied.', 'deep-media-cleaner' ) ), 403 );
			}
			wp_die( esc_html__( 'Permission denied.', 'deep-media-cleaner' ) );
		}
		if ( $ajax ) {
			check_ajax_referer( 'dmc', 'nonce' );
			self::guard_json();
		}
	}

	/* ------------------------------------------------------------------ */
	/* AJAX                                                                */
	/* ------------------------------------------------------------------ */

	public static function ajax_scan() {
		self::check();
		$op = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : 'step';
		if ( 'start' === $op ) {
			DMC_Scanner::start();
		} elseif ( 'cancel' === $op ) {
			DMC_Scanner::cancel();
			wp_send_json_success( array( 'running' => false ) );
		}
		$state = DMC_Scanner::run();
		$total = isset( $state['steps'] ) ? count( $state['steps'] ) : 1;
		$i     = isset( $state['i'] ) ? (int) $state['i'] : $total;
		wp_send_json_success(
			array(
				'running' => ! empty( $state['running'] ),
				'pct'     => $total ? floor( 100 * min( $i, $total ) / $total ) : 100,
				'label'   => ( ! empty( $state['running'] ) && isset( $state['steps'][ $i ] ) ) ? $state['steps'][ $i ]['label'] : __( 'Done', 'deep-media-cleaner' ),
				'note'    => isset( $state['note'] ) ? $state['note'] : '',
				'step'    => min( $i + 1, $total ) . ' / ' . $total,
			)
		);
	}

	public static function ajax_action() {
		global $wpdb;
		self::check();
		$op  = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';
		$ids = isset( $_POST['ids'] ) ? array_filter( array_map( 'absint', (array) wp_unslash( $_POST['ids'] ) ) ) : array();
		$t   = DMC_DB::tables();
		$s   = DMC_Util::settings();

		if ( ! DMC_Scanner::results_ready() && in_array( $op, array( 'trash', 'trash_force', 'trash_missing', 'trash_missing_unlink', 'trash_all', 'trash_missing_all', 'trash_missing_unlink_all', 'ignore', 'unignore' ), true ) ) {
			wp_send_json_error( array( 'message' => DMC_Scanner::is_running() ? __( 'A scan is running. Wait for it to finish.', 'deep-media-cleaner' ) : __( 'The last scan did not finish. Run a new scan first.', 'deep-media-cleaner' ) ) );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 ); // phpcs:ignore
		}
		$ok       = 0;
		$messages = array();
		$deadline = microtime( true ) + 20;

		switch ( $op ) {
			case 'trash_missing':
			case 'trash_missing_unlink':
				foreach ( $ids as $id ) {
					if ( microtime( true ) > $deadline ) {
						break;
					}
					if ( self::trash_missing_one( $id, 'trash_missing_unlink' === $op, $messages ) ) {
						++$ok;
					}
				}
				break;

			case 'preview_unlink':
				foreach ( $ids as $id ) {
					self::preview_one( $id, $messages );
					++$ok; // Nothing is changed; every item counts as handled.
				}
				break;

			case 'trash':
			case 'trash_force':
				foreach ( $ids as $id ) {
					$name = self::result_label( $id );
					$r    = DMC_Bin::trash_result( $id, 'trash' === $op && ! empty( $s['recheck'] ) );
					if ( is_wp_error( $r ) ) {
						$messages[] = self::msg( $name, $r );
					} else {
						++$ok;
						self::similar_note( $name, $messages );
					}
				}
				break;

			case 'trash_all':
			case 'trash_missing_all':
			case 'trash_missing_unlink_all':
				// Missing files: every library item whose file is gone, used or not
				// (the user confirmed; references will show a broken link).
				$missing = 'trash_all' !== $op;
				$unlink  = 'trash_missing_unlink_all' === $op;
				$base_w  = $missing ? "missing = 1 AND item_type = 'attachment' AND is_used <> 2" : 'is_used = 0';
				$skip    = isset( $_POST['skip'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['skip'] ) ) : array();
				$where   = $skip ? ' AND id NOT IN (' . implode( ',', array_merge( array( 0 ), $skip ) ) . ')' : '';
				$type  = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : '';
				if ( in_array( $type, array( 'attachment', 'file' ), true ) ) {
					$where .= $wpdb->prepare( ' AND item_type = %s', $type );
				}
				$limit = isset( $_POST['limit'] ) ? max( 1, min( 40, absint( $_POST['limit'] ) ) ) : 40; // phpcs:ignore
				$rows   = $wpdb->get_col( "SELECT id FROM `{$t['results']}` WHERE {$base_w} {$where} ORDER BY id ASC LIMIT {$limit}" ); // phpcs:ignore
				$failed = array();
				foreach ( $rows as $id ) {
					if ( microtime( true ) > $deadline ) {
						break;
					}
					if ( $missing ) {
						if ( self::trash_missing_one( (int) $id, $unlink, $messages ) ) {
							++$ok;
						} else {
							$failed[] = (int) $id;
						}
						continue;
					}
					$name = self::result_label( (int) $id );
					$r    = DMC_Bin::trash_result( (int) $id, ! empty( $s['recheck'] ) );
					if ( is_wp_error( $r ) ) {
						$messages[] = self::msg( $name, $r );
						$failed[]   = (int) $id;
					} else {
						++$ok;
						self::similar_note( $name, $messages );
					}
				}
				$remaining = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$t['results']}` WHERE {$base_w} {$where}" ); // phpcs:ignore
				wp_send_json_success(
					array(
						'ok'        => $ok,
						'failed'    => $failed,
						'remaining' => max( 0, $remaining - count( $failed ) ),
						'messages'  => $messages,
					)
				);
				break;

			case 'ignore':
			case 'unignore':
				$ign  = DMC_Util::ignored();
				$rows = $ids ? $wpdb->get_results( 'SELECT * FROM `' . $t['results'] . '` WHERE id IN (' . implode( ',', $ids ) . ')' ) : array(); // phpcs:ignore
				foreach ( $rows as $r ) {
					$key = ( 'attachment' === $r->item_type ) ? 'att' : 'file';
					$val = ( 'att' === $key ) ? (int) $r->attachment_id : $r->rel_path;
					if ( 'ignore' === $op ) {
						if ( ! in_array( $val, $ign[ $key ], true ) ) {
							$ign[ $key ][] = $val;
						}
						$wpdb->update( $t['results'], array( 'is_used' => 2, 'used_by' => __( 'Ignored by you', 'deep-media-cleaner' ) ), array( 'id' => $r->id ) );
					} else {
						$ign[ $key ] = array_values( array_diff( $ign[ $key ], array( $val ) ) );
						$wpdb->update( $t['results'], array( 'is_used' => 0, 'used_by' => '' ), array( 'id' => $r->id ) );
					}
					++$ok;
				}
				update_option( 'dmc_ignored', $ign, false );
				break;

			case 'restore':
				foreach ( $ids as $id ) {
					$name = self::bin_label( $id );
					$r    = DMC_Bin::restore( $id );
					if ( is_wp_error( $r ) ) {
						$messages[] = self::msg( $name, $r );
					} else {
						++$ok;
						if ( is_string( $r ) ) {
							$messages[] = self::msg( $name, $r );
						}
					}
				}
				break;

			case 'delete':
				foreach ( $ids as $id ) {
					$name = self::bin_label( $id );
					$r    = DMC_Bin::delete( $id );
					if ( is_wp_error( $r ) ) {
						$messages[] = self::msg( $name, $r );
					} else {
						++$ok;
					}
				}
				break;

			case 'empty':
			case 'restore_all':
				$skip   = isset( $_POST['skip'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['skip'] ) ) : array();
				$bwhere = $skip ? ' WHERE id NOT IN (' . implode( ',', array_merge( array( 0 ), $skip ) ) . ')' : '';
				$limit = isset( $_POST['limit'] ) ? max( 1, min( 40, absint( $_POST['limit'] ) ) ) : 40; // phpcs:ignore
				$rows   = $wpdb->get_col( "SELECT id FROM `{$t['bin']}` {$bwhere} ORDER BY id ASC LIMIT {$limit}" ); // phpcs:ignore
				$failed = array();
				foreach ( $rows as $id ) {
					if ( microtime( true ) > $deadline ) {
						break;
					}
					$name = self::bin_label( (int) $id );
					$r    = ( 'empty' === $op ) ? DMC_Bin::delete( (int) $id ) : DMC_Bin::restore( (int) $id );
					if ( is_wp_error( $r ) ) {
						$messages[] = self::msg( $name, $r );
						$failed[]   = (int) $id;
					} else {
						++$ok;
					}
				}
				$remaining = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$t['bin']}` {$bwhere}" ); // phpcs:ignore
				wp_send_json_success(
					array(
						'ok'        => $ok,
						'failed'    => $failed,
						'remaining' => max( 0, $remaining - count( $failed ) ),
						'messages'  => $messages,
					)
				);
				break;

			default:
				wp_send_json_error( array( 'message' => 'Unknown action' ) );
		}
		wp_send_json_success(
			array(
				'ok'       => $ok,
				'messages' => $messages,
			)
		);
	}

	/**
	 * Collapsible "How this works" box for each tab.
	 */
	private static function help( $view ) {
		$p = function ( $t ) {
			return '<p>' . $t . '</p>';
		};
		$li = function ( array $items ) {
			return '<ul>' . implode( '', array_map( function ( $i ) {
				return '<li>' . $i . '</li>';
			}, $items ) ) . '</ul>';
		};
		$b = function ( $t ) {
			return '<strong>' . esc_html( $t ) . '</strong>';
		};
		$e = 'esc_html__';

		switch ( $view ) {
			case 'used':
				$title = __( 'How to read “Why it is kept”', 'deep-media-cleaner' );
				$body  = $p( $e( 'Each item shows the first place it was found: the database table, then the column, custom field or setting, then the post, page or category it belongs to, with an Edit link.', 'deep-media-cleaner' ) )
					. $li(
						array(
							$b( __( 'No mark', 'deep-media-cleaner' ) ) . ' – ' . $e( 'found by its exact ID or full file path. Reliable.', 'deep-media-cleaner' ),
							$b( __( '⚠ File name match only', 'deep-media-cleaner' ) ) . ' – ' . $e( 'the file name appears without its folder, for example in theme CSS or in a link copied from another site. Usually in use, but worth a look.', 'deep-media-cleaner' ),
							$b( __( '⚠ Similar name only', 'deep-media-cleaner' ) ) . ' – ' . $e( 'a different file with a similar name was found, such as bulletin-2024.pdf for bulletin.pdf. This does not stop an item from being moved – it is only mentioned in the summary so you can take a look.', 'deep-media-cleaner' ),
							$b( __( '(found just before moving)', 'deep-media-cleaner' ) ) . ' – ' . $e( 'the scan missed it, but the last check right before moving found it, so the item was left in place.', 'deep-media-cleaner' ),
							$b( __( '[gallery] without ids', 'deep-media-cleaner' ) ) . ' – ' . $e( 'an old-style gallery shows every image attached to that post, so those images count as used.', 'deep-media-cleaner' ),
							$b( __( 'File missing', 'deep-media-cleaner' ) ) . ' – ' . $e( 'the item is referenced, but its file is no longer on the server. See the Missing files tab.', 'deep-media-cleaner' ),
						)
					);
				break;

			case 'missing':
				$title = __( 'About missing files', 'deep-media-cleaner' );
				$body  = $p( $b( __( 'What “File missing” means', 'deep-media-cleaner' ) ) . ' – ' . $e( 'The Media Library still has an entry, but its file is no longer on the server – for example after a folder was deleted by FTP or a migration did not copy everything. Visitors who open these links get a “not found” error.', 'deep-media-cleaner' ) )
					. $li(
						array(
							$b( __( 'Preview references', 'deep-media-cleaner' ) ) . ' – ' . $e( 'shows what “Remove simple references” would remove and what it would leave alone. Nothing is changed.', 'deep-media-cleaner' ),
							$b( __( 'Move to bin', 'deep-media-cleaner' ) ) . ' – ' . $e( 'removes the broken library entry. Places that still reference it (shown on the right) keep a broken link until you edit or delete them.', 'deep-media-cleaner' ),
							$b( __( 'Remove simple references + move to bin', 'deep-media-cleaner' ) ) . ' – ' . $e( 'also removes references that are nothing but a pointer to this item: a custom field whose whole value is its ID or file URL, such as a featured image, an ACF image or file field, or a form entry’s upload field.', 'deep-media-cleaner' ),
						)
					)
					. $p( $b( __( 'Never edited:', 'deep-media-cleaner' ) ) . ' ' . $e( 'post and page text, page-builder layouts (Elementor and others), settings and widgets, lists of several images, category and user data, and plugin tables. Those references stay as they are and are listed in the summary so you can fix them yourself.', 'deep-media-cleaner' ) )
					. $p( $b( __( 'Everything can be undone:', 'deep-media-cleaner' ) ) . ' ' . $e( 'restoring an item from the recycle bin also puts back the references removed with it.', 'deep-media-cleaner' ) );
				break;

			case 'ignored':
				$title = __( 'About the ignore list', 'deep-media-cleaner' );
				$body  = $p( $e( 'Ignored items are never listed as unused and are never moved, even after a new scan. Use it for files that are needed in ways a scan cannot see – for example links in emails or printed QR codes, or file names built by code.', 'deep-media-cleaner' ) );
				break;

			default: // Unused views.
				$title = __( 'How this works', 'deep-media-cleaner' );
				$body  = $li(
					array(
						$e( 'These items were not found anywhere the scan looks: post and page content, custom fields, page builders, settings and widgets, plugin tables and theme files.', 'deep-media-cleaner' ),
						$b( __( 'Move to bin', 'deep-media-cleaner' ) ) . ' – ' . $e( 'hides a library item from the site and the Media Library and moves its files to a private folder. Nothing is deleted until you empty the bin, and restoring brings back the same item with the same ID, title and links.', 'deep-media-cleaner' ),
						$e( 'Right before moving, each item is checked once more. If it has started being used since the scan, it is skipped and listed in the Used tab with the reason. A file that only has a similar name somewhere is still moved, and the summary mentions where.', 'deep-media-cleaner' ),
						$e( 'A scan cannot see links from outside the site (emails, other websites, printed QR codes) or file names built by code. If you know an item is needed, choose Ignore.', 'deep-media-cleaner' ),
						$e( 'Make a backup first, and check the site for a few days before emptying the bin.', 'deep-media-cleaner' ),
					)
				);
		}
		echo '<details class="dmc-help"' . ( 'missing' === $view ? ' open' : '' ) . '><summary>' . esc_html( $title ) . '</summary>' . $body . '</details>'; // phpcs:ignore -- built from escaped parts.
	}

	/** Help box for the recycle bin tab. */
	private static function help_bin() {
		echo '<details class="dmc-help"><summary>' . esc_html__( 'About the recycle bin', 'deep-media-cleaner' ) . '</summary><ul>'
			. '<li>' . esc_html__( 'Library items in the bin are hidden from the Media Library and the site. Their files are kept in a private folder.', 'deep-media-cleaner' ) . '</li>'
			. '<li><strong>' . esc_html__( 'Restore', 'deep-media-cleaner' ) . '</strong> – ' . esc_html__( 'puts the item back exactly as it was: same ID, title, alt text and links, plus any references removed with it.', 'deep-media-cleaner' ) . '</li>'
			. '<li><strong>' . esc_html__( 'Delete permanently', 'deep-media-cleaner' ) . '</strong> – ' . esc_html__( 'removes the files and the library entry for good. If automatic emptying is turned on in Settings, old items are deleted after that many days.', 'deep-media-cleaner' ) . '</li>'
			. '<li>' . esc_html__( 'Deactivating the plugin keeps the bin as it is. Deleting the plugin restores everything in the bin first.', 'deep-media-cleaner' ) . '</li>'
			. '</ul></details>'; // phpcs:ignore
	}

	private static function missing_row( $id ) {
		global $wpdb;
		$t   = DMC_DB::tables();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$t['results']}` WHERE id = %d", $id ) ); // phpcs:ignore
		return ( $row && 'attachment' === $row->item_type && ! empty( $row->missing ) ) ? $row : null;
	}

	/** Missing-file item: move to bin and (optionally) remove simple references. */
	private static function trash_missing_one( $id, $unlink, array &$messages ) {
		$row  = self::missing_row( $id );
		$name = self::result_label( $id );
		if ( ! $row ) {
			$messages[] = self::msg( $name, new WP_Error( 'dmc_not_missing', __( 'Only library items whose file is missing can be handled here. Run a new scan if this looks wrong.', 'deep-media-cleaner' ) ) );
			return false;
		}
		$aid = (int) $row->attachment_id;
		$bid = DMC_Bin::trash_result( (int) $id, false );
		if ( is_wp_error( $bid ) ) {
			$messages[] = self::msg( $name, $bid );
			return false;
		}
		if ( $unlink && is_int( $bid ) ) {
			$removed = DMC_Unlink::remove( $aid, $bid );
			if ( is_wp_error( $removed ) ) {
				$messages[] = self::msg( $name, $removed );
				$removed    = array();
			}
			foreach ( $removed as $r ) {
				$messages[] = self::note( $name, 'removed', DMC_Unlink::reason( $r ) );
			}
			$left = DMC_Scanner::quick_recheck(
				(object) array(
					'item_type'     => 'attachment',
					'attachment_id' => $aid,
					'rel_path'      => $row->rel_path,
				)
			);
			if ( '' !== $left && ! DMC_Reason::is_soft( $left ) ) {
				$messages[] = self::note( $name, 'left', $left );
			}
		}
		return true;
	}

	/** Show what "Remove simple references" would do. Changes nothing. */
	private static function preview_one( $id, array &$messages ) {
		$row  = self::missing_row( $id );
		$name = self::result_label( $id );
		if ( ! $row ) {
			$messages[] = self::msg( $name, __( 'Not a library item with a missing file – nothing to preview.', 'deep-media-cleaner' ) );
			return;
		}
		$rows    = DMC_Unlink::find( (int) $row->attachment_id );
		$covered = false;
		$kept    = DMC_Reason::decode( $row->used_by );
		foreach ( $rows as $r ) {
			$messages[] = self::note( $name, 'would', DMC_Unlink::reason( (array) $r ) );
			if ( $kept && 'pmeta' === $kept['k'] && (int) $kept['id'] === (int) $r->post_id && $kept['f'] === $r->meta_key ) {
				$covered = true;
			}
		}
		if ( $row->used_by && ! $covered && ! DMC_Reason::is_soft( $row->used_by ) ) {
			$messages[] = self::note( $name, 'keep', $row->used_by );
		}
		if ( ! $rows ) {
			$messages[] = self::msg( $name, __( 'No simple references found. Moving it to the bin removes only the broken library entry.', 'deep-media-cleaner' ) );
		}
	}

	private static function similar_note( $name, array &$messages ) {
		if ( '' === DMC_Bin::$last_note ) {
			return;
		}
		$sim  = DMC_Reason::similar_name( DMC_Bin::$last_note );
		/* translators: 1: a different file name 2: where it is used */
		$line = __( 'This file was not used. A different file with a similar name, %1$s, is used in %2$s', 'deep-media-cleaner' );
		$messages[] = array(
			'name' => $name,
			'kind' => 'moved_note',
			'text' => sprintf( $line, $sim, DMC_Reason::object_only( DMC_Bin::$last_note, false ) ),
			'html' => sprintf( esc_html( $line ), '<code>' . esc_html( $sim ) . '</code>', DMC_Reason::object_only( DMC_Bin::$last_note, true ) ),
		);
	}

	private static function note( $name, $kind, $reason ) {
		return array(
			'name' => $name,
			'kind' => $kind, // removed | left | would | keep
			'text' => DMC_Reason::text( DMC_Reason::where_only( $reason ) ),
			'html' => DMC_Reason::html( DMC_Reason::where_only( $reason ) ),
		);
	}

	/** One line of the job summary: file + reason (plain text and HTML). */
	private static function msg( $name, $r ) {
		$kind = 'info';
		if ( is_wp_error( $r ) ) {
			$data   = $r->get_error_data();
			$reason = ( is_array( $data ) && ! empty( $data['reason'] ) ) ? $data['reason'] : $r->get_error_message();
			$kind   = 'dmc_used' === $r->get_error_code() ? 'in_use' : 'error';
		} elseif ( is_scalar( $r ) ) {
			$reason = (string) $r;
		} else {
			$reason = '';
		}
		if ( 'in_use' === $kind ) {
			// The heading already says "still in use"; show just where.
			$reason = DMC_Reason::where_only( $reason );
		}
		return array(
			'name' => $name,
			'kind' => $kind,
			'text' => DMC_Reason::text( $reason ),
			'html' => DMC_Reason::html( $reason ),
		);
	}

	private static function result_label( $id ) {
		global $wpdb;
		$t   = DMC_DB::tables();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT attachment_id, rel_path FROM `{$t['results']}` WHERE id = %d", $id ) ); // phpcs:ignore
		return self::label( $row );
	}

	private static function bin_label( $id ) {
		global $wpdb;
		$t   = DMC_DB::tables();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT attachment_id, rel_path FROM `{$t['bin']}` WHERE id = %d", $id ) ); // phpcs:ignore
		return self::label( $row );
	}

	private static function label( $row ) {
		if ( ! $row ) {
			return '?';
		}
		if ( $row->rel_path ) {
			return $row->rel_path;
		}
		return '#' . (int) $row->attachment_id;
	}

	/* ------------------------------------------------------------------ */
	/* Settings + CSV                                                      */
	/* ------------------------------------------------------------------ */

	public static function save_settings() {
		self::check( false );
		check_admin_referer( 'dmc_settings' );
		if ( isset( $_POST['reset'] ) ) {
			delete_option( 'dmc_settings' );
		} else {
			$in  = wp_unslash( $_POST );
			$def = DMC_Util::defaults();
			$out = array();
			foreach ( array( 'include_revisions', 'include_trash', 'safe_numeric', 'attached_as_used', 'scan_theme', 'scan_tables', 'recheck' ) as $k ) {
				$out[ $k ] = empty( $in[ $k ] ) ? 0 : 1;
			}
			$out['recent_days']     = isset( $in['recent_days'] ) ? max( 0, min( 3650, (int) $in['recent_days'] ) ) : $def['recent_days'];
			$out['auto_purge_days'] = isset( $in['auto_purge_days'] ) ? max( 0, min( 3650, (int) $in['auto_purge_days'] ) ) : 0;
			$out['time_budget']     = isset( $in['time_budget'] ) ? max( 5, min( 120, (int) $in['time_budget'] ) ) : 15;
			foreach ( array( 'excluded_tables', 'excluded_dirs' ) as $k ) {
				$out[ $k ] = isset( $in[ $k ] ) ? implode( "\n", array_map( 'sanitize_text_field', DMC_Util::lines( $in[ $k ] ) ) ) : $def[ $k ];
			}
			$exts               = isset( $in['orphan_exts'] ) ? strtolower( (string) $in['orphan_exts'] ) : $def['orphan_exts'];
			$out['orphan_exts'] = implode( ' ', array_unique( array_filter( preg_split( '/[^a-z0-9]+/', $exts ) ) ) );
			update_option( 'dmc_settings', $out, false );
		}
		DMC_Util::reset_cache();
		wp_safe_redirect( self::url( array( 'tab' => 'settings', 'saved' => 1 ) ) );
		exit;
	}

	public static function export_csv() {
		global $wpdb;
		self::check( false );
		check_admin_referer( 'dmc_export' );
		$t    = DMC_DB::tables();
		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'unused';
		$map  = array(
			'unused'  => 0,
			'used'    => 1,
			'ignored' => 2,
		);
		if ( 'missing' === $view ) {
			$rows = $wpdb->get_results( "SELECT * FROM `{$t['results']}` WHERE missing = 1 AND is_used <> 2 ORDER BY rel_path ASC" ); // phpcs:ignore
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$t['results']}` WHERE is_used = %d ORDER BY size DESC", isset( $map[ $view ] ) ? $map[ $view ] : 0 ) ); // phpcs:ignore
		}
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=media-' . $view . '-' . gmdate( 'Y-m-d' ) . '.csv' );
		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" ); // UTF-8 BOM so Excel shows Korean names correctly.
		fputcsv( $out, array( 'type', 'media_id', 'path', 'url', 'bytes', 'date', 'used_by' ) );
		foreach ( $rows as $r ) {
			fputcsv( $out, array( $r->item_type, $r->attachment_id, $r->rel_path, $r->rel_path ? DMC_Util::url_for( $r->rel_path ) : '', $r->size, $r->item_date, DMC_Reason::text( $r->used_by ) ) );
		}
		fclose( $out ); // phpcs:ignore
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* Media library column                                                */
	/* ------------------------------------------------------------------ */

	public static function media_column( $cols ) {
		if ( current_user_can( self::CAP ) ) {
			$cols['dmc_usage'] = __( 'Usage (last scan)', 'deep-media-cleaner' );
		}
		return $cols;
	}

	public static function media_column_value( $col, $id ) {
		global $wpdb;
		static $ready = null;
		if ( 'dmc_usage' !== $col ) {
			return;
		}
		if ( null === $ready ) {
			$ready = DMC_Scanner::results_ready();
		}
		if ( ! $ready ) {
			echo '<span class="description">' . esc_html__( 'Not scanned', 'deep-media-cleaner' ) . '</span>';
			return;
		}
		$t   = DMC_DB::tables();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT is_used, used_by FROM `{$t['results']}` WHERE item_type = 'attachment' AND attachment_id = %d", $id ) ); // phpcs:ignore
		if ( ! $row ) {
			echo '<span class="description">' . esc_html__( 'Not scanned', 'deep-media-cleaner' ) . '</span>';
		} elseif ( 0 === (int) $row->is_used ) {
			echo '<strong style="color:#b32d2e">' . esc_html__( 'Unused', 'deep-media-cleaner' ) . '</strong>';
		} elseif ( 2 === (int) $row->is_used ) {
			echo esc_html__( 'Ignored', 'deep-media-cleaner' );
		} else {
			echo '<span style="color:#00703c">' . esc_html__( 'Used', 'deep-media-cleaner' ) . '</span><br><small>' . DMC_Reason::html( $row->used_by ) . '</small>'; // phpcs:ignore
		}
	}

	/* ------------------------------------------------------------------ */
	/* Pages                                                               */
	/* ------------------------------------------------------------------ */

	public static function render() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'results'; // phpcs:ignore
		$tabs = array(
			'results'  => __( 'Scan & results', 'deep-media-cleaner' ),
			'bin'      => __( 'Recycle bin', 'deep-media-cleaner' ),
			'settings' => __( 'Settings', 'deep-media-cleaner' ),
			'log'      => __( 'Activity log', 'deep-media-cleaner' ),
		);
		$bin  = DMC_Bin::count();
		echo '<div class="wrap dmc-wrap"><h1>' . esc_html__( 'Deep Media Cleaner', 'deep-media-cleaner' ) . '</h1>';
		echo '<div id="dmc-notice"></div>';
		echo '<nav class="nav-tab-wrapper">';
		foreach ( $tabs as $k => $label ) {
			if ( 'bin' === $k && $bin && $bin->n ) {
				$label .= ' (' . number_format_i18n( $bin->n ) . ')';
			}
			printf( '<a href="%s" class="nav-tab %s">%s</a>', esc_url( self::url( array( 'tab' => $k ) ) ), $k === $tab ? 'nav-tab-active' : '', esc_html( $label ) );
		}
		echo '</nav>';
		switch ( $tab ) {
			case 'bin':
				self::page_bin();
				break;
			case 'settings':
				self::page_settings();
				break;
			case 'log':
				self::page_log();
				break;
			default:
				self::page_results();
		}
		echo '</div>';
	}

	private static function thumb( $r ) {
		$img = '';
		if ( 'attachment' === $r->item_type && 0 === strpos( (string) $r->mime, 'image/' ) ) {
			$img = wp_get_attachment_image_url( (int) $r->attachment_id, 'thumbnail' );
		} elseif ( 'file' === $r->item_type && preg_match( '/\.(jpe?g|png|gif|webp|avif|svg|bmp|ico)$/i', $r->rel_path ) ) {
			$img = DMC_Util::url_for( $r->rel_path );
		}
		if ( $img ) {
			return '<img src="' . esc_url( $img ) . '" alt="" loading="lazy" class="dmc-thumb">';
		}
		$icon = 'dashicons-media-default';
		if ( preg_match( '#^video/#', (string) $r->mime ) ) {
			$icon = 'dashicons-media-video';
		} elseif ( preg_match( '#^audio/#', (string) $r->mime ) ) {
			$icon = 'dashicons-media-audio';
		} elseif ( preg_match( '#pdf|word|document|sheet|presentation#', (string) $r->mime ) ) {
			$icon = 'dashicons-media-document';
		}
		return '<span class="dashicons ' . $icon . ' dmc-icon"></span>';
	}

	private static function page_results() {
		global $wpdb;
		$t     = DMC_DB::tables();
		$last  = get_option( 'dmc_last_scan' );
		$state = DMC_Scanner::state();
		$stats = ( is_array( $last ) && isset( $last['stats'] ) ) ? $last['stats'] : array();
		$g     = function ( $k, $f ) use ( $stats ) {
			return isset( $stats[ $k ][ $f ] ) ? (int) $stats[ $k ][ $f ] : 0;
		};

		// Live counts (they change as items are trashed / ignored).
		$live = array();
		foreach ( $wpdb->get_results( "SELECT is_used, item_type, COUNT(*) n, COALESCE(SUM(size),0) s FROM `{$t['results']}` GROUP BY is_used, item_type" ) as $r ) { // phpcs:ignore
			$live[ $r->is_used . '_' . $r->item_type ] = $r;
		}
		$lc = function ( $u, $type, $f ) use ( $live ) {
			$n = 0;
			foreach ( (array) $type as $ty ) {
				$n += isset( $live[ $u . '_' . $ty ] ) ? (int) $live[ $u . '_' . $ty ]->$f : 0;
			}
			return $n;
		};

		echo '<div class="dmc-box dmc-scanbox">';
		echo '<div class="dmc-scan-main">';
		if ( $last ) {
			/* translators: 1: date 2: duration */
			echo '<p>' . sprintf( esc_html__( 'Last scan: %1$s (took %2$s).', 'deep-media-cleaner' ), esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last['time'] ) ), esc_html( human_time_diff( 0, max( 1, (int) $last['duration'] ) ) ) ) . '</p>';
			echo '<p class="dmc-summary"><strong>' . esc_html( number_format_i18n( $lc( 0, array( 'attachment', 'file' ), 'n' ) ) ) . '</strong> ' . esc_html__( 'unused items', 'deep-media-cleaner' ) . ' · <strong>' . esc_html( size_format( $lc( 0, array( 'attachment', 'file' ), 's' ), 1 ) ) . '</strong> ' . esc_html__( 'can be freed', 'deep-media-cleaner' ) . '</p>';
		} else {
			echo '<p>' . esc_html__( 'No scan yet. Scanning only reads your site — nothing is moved or deleted until you choose to.', 'deep-media-cleaner' ) . '</p>';
		}
		echo '<p><button type="button" class="button button-primary button-hero" id="dmc-scan"' . ( ! empty( $state['running'] ) ? ' disabled' : '' ) . '>' . esc_html( $last ? __( 'Scan again', 'deep-media-cleaner' ) : __( 'Scan site', 'deep-media-cleaner' ) ) . '</button></p>';
		echo '<div id="dmc-progress" class="dmc-progress" hidden><div class="dmc-bar"><span style="width:0"></span></div><p><strong class="dmc-step-label"></strong> <span class="dmc-step-count"></span> <span class="dmc-step-note description"></span></p><button type="button" class="button-link" id="dmc-cancel">' . esc_html__( 'Cancel scan', 'deep-media-cleaner' ) . '</button></div>';
		echo '</div>';
		echo '<div class="dmc-scan-side"><p><strong>' . esc_html__( 'Before you clean up', 'deep-media-cleaner' ) . '</strong></p><ul><li>' . esc_html__( 'Make a backup of the database and the uploads folder.', 'deep-media-cleaner' ) . '</li><li>' . esc_html__( 'Items you remove go to the recycle bin first and can be restored.', 'deep-media-cleaner' ) . '</li><li>' . esc_html__( 'Check the site for a few days before emptying the bin.', 'deep-media-cleaner' ) . '</li></ul></div>';
		echo '</div>';

		if ( ! $last || ! empty( $state['running'] ) ) {
			return;
		}

		$view   = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'unused'; // phpcs:ignore
		$views  = array(
			'unused'     => array( __( 'Unused – all', 'deep-media-cleaner' ), 0, '', $lc( 0, array( 'attachment', 'file' ), 'n' ) ),
			'unused_lib' => array( __( 'Unused – media library', 'deep-media-cleaner' ), 0, 'attachment', $lc( 0, 'attachment', 'n' ) ),
			'unused_fs'  => array( __( 'Unused – files not in library', 'deep-media-cleaner' ), 0, 'file', $lc( 0, 'file', 'n' ) ),
			'used'       => array( __( 'Used', 'deep-media-cleaner' ), 1, '', $lc( 1, array( 'attachment', 'file' ), 'n' ) ),
			'ignored'    => array( __( 'Ignored', 'deep-media-cleaner' ), 2, '', $lc( 2, array( 'attachment', 'file' ), 'n' ) ),
			'missing'    => array( __( 'Missing files', 'deep-media-cleaner' ), null, 'attachment', (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$t['results']}` WHERE missing = 1 AND is_used <> 2" ) ), // phpcs:ignore
		);
		if ( ! isset( $views[ $view ] ) ) {
			$view = 'unused';
		}
		list( , $is_used, $type ) = $views[ $view ];
		$search                   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore
		$orderby                  = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'size'; // phpcs:ignore
		$order_sql                = array(
			'size' => 'size DESC',
			'date' => 'item_date DESC',
			'name' => 'rel_path ASC',
		);
		$order_sql                = isset( $order_sql[ $orderby ] ) ? $order_sql[ $orderby ] : 'size DESC';
		$per                      = 50;
		$paged                    = max( 1, isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 ); // phpcs:ignore

		$where = null === $is_used ? 'missing = 1 AND is_used <> 2' : $wpdb->prepare( 'is_used = %d', $is_used );
		if ( $type ) {
			$where .= $wpdb->prepare( ' AND item_type = %s', $type );
		}
		if ( '' !== $search ) {
			$where .= $wpdb->prepare( ' AND rel_path LIKE %s', '%' . $wpdb->esc_like( $search ) . '%' );
		}
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$t['results']}` WHERE {$where}" ); // phpcs:ignore
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$t['results']}` WHERE {$where} ORDER BY {$order_sql} LIMIT %d OFFSET %d", $per, ( $paged - 1 ) * $per ) ); // phpcs:ignore

		DMC_Reason::prime( wp_list_pluck( $rows, 'used_by' ) );
		echo '<ul class="subsubsub">';
		$links = array();
		foreach ( $views as $k => $v ) {
			$links[] = sprintf( '<li><a href="%s" class="%s">%s <span class="count">(%s)</span></a>', esc_url( self::url( array( 'view' => $k ) ) ), $k === $view ? 'current' : '', esc_html( $v[0] ), esc_html( number_format_i18n( $v[3] ) ) );
		}
		echo implode( ' | </li>', $links ) . '</li></ul>'; // phpcs:ignore
		self::help( $view );

		echo '<form method="get" class="dmc-search"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '"><input type="hidden" name="view" value="' . esc_attr( $view ) . '">';
		echo '<input type="search" name="s" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( 'Search file name', 'deep-media-cleaner' ) . '"> ';
		echo '<select name="orderby">';
		foreach ( array(
			'size' => __( 'Largest first', 'deep-media-cleaner' ),
			'date' => __( 'Newest first', 'deep-media-cleaner' ),
			'name' => __( 'By path', 'deep-media-cleaner' ),
		) as $k => $l ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $k ), selected( $orderby, $k, false ), esc_html( $l ) );
		}
		echo '</select> <button class="button">' . esc_html__( 'Filter', 'deep-media-cleaner' ) . '</button></form>';

		echo '<div class="tablenav top dmc-actions">';
		if ( 0 === $is_used ) {
			echo '<button type="button" class="button" data-dmc-bulk="trash">' . esc_html__( 'Move selected to bin', 'deep-media-cleaner' ) . '</button> ';
			echo '<button type="button" class="button" data-dmc-bulk="ignore">' . esc_html__( 'Ignore selected', 'deep-media-cleaner' ) . '</button> ';
			if ( $total ) {
				/* translators: %s: number of items */
				echo '<button type="button" class="button dmc-danger" id="dmc-trash-all" data-type="' . esc_attr( $type ) . '" data-total="' . (int) $lc( 0, $type ? $type : array( 'attachment', 'file' ), 'n' ) . '">' . esc_html( sprintf( __( 'Move all %s to bin', 'deep-media-cleaner' ), number_format_i18n( $lc( 0, $type ? $type : array( 'attachment', 'file' ), 'n' ) ) ) ) . '</button> ';
			}
		} elseif ( null === $is_used ) {
			echo '<button type="button" class="button" data-dmc-bulk="preview_unlink">' . esc_html__( 'Preview references', 'deep-media-cleaner' ) . '</button> ';
			echo '<button type="button" class="button" data-dmc-bulk="trash_missing">' . esc_html__( 'Move selected to bin', 'deep-media-cleaner' ) . '</button> ';
			echo '<button type="button" class="button" data-dmc-bulk="trash_missing_unlink">' . esc_html__( 'Remove simple references + move selected to bin', 'deep-media-cleaner' ) . '</button> ';
			if ( $total ) {
				/* translators: %s: number of items */
				echo '<button type="button" class="button dmc-danger" id="dmc-trash-missing-all" data-total="' . (int) $total . '">' . esc_html( sprintf( __( 'Move all %s to bin', 'deep-media-cleaner' ), number_format_i18n( $total ) ) ) . '</button> ';
				/* translators: %s: number of items */
				echo '<button type="button" class="button dmc-danger" id="dmc-unlink-missing-all" data-total="' . (int) $total . '">' . esc_html( sprintf( __( 'Remove simple references + move all %s to bin', 'deep-media-cleaner' ), number_format_i18n( $total ) ) ) . '</button> ';
			}
			echo '<button type="button" class="button" data-dmc-bulk="ignore">' . esc_html__( 'Ignore selected', 'deep-media-cleaner' ) . '</button> ';
		} elseif ( 2 === $is_used ) {
			echo '<button type="button" class="button" data-dmc-bulk="unignore">' . esc_html__( 'Stop ignoring selected', 'deep-media-cleaner' ) . '</button> ';
		}
		$csv_view = null === $is_used ? 'missing' : ( 0 === $is_used ? 'unused' : ( 1 === $is_used ? 'used' : 'ignored' ) );
		echo '<a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=dmc_export&view=' . $csv_view ), 'dmc_export' ) ) . '">' . esc_html__( 'Export CSV', 'deep-media-cleaner' ) . '</a>';
		self::pagination( $total, $per, $paged );
		echo '</div>';

		echo '<table class="widefat striped dmc-table"><thead><tr><td class="check-column"><input type="checkbox" class="dmc-check-all"></td><th class="dmc-col-thumb"></th><th>' . esc_html__( 'File', 'deep-media-cleaner' ) . '</th><th>' . esc_html__( 'Size', 'deep-media-cleaner' ) . '</th><th>' . esc_html__( 'Date', 'deep-media-cleaner' ) . '</th><th>' . esc_html( 0 === $is_used ? __( 'Notes', 'deep-media-cleaner' ) : __( 'Why it is kept', 'deep-media-cleaner' ) ) . '</th></tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="6">' . esc_html( 0 === $is_used ? __( 'No unused media found in this view.', 'deep-media-cleaner' ) : __( 'Nothing here.', 'deep-media-cleaner' ) ) . '</td></tr>';
		}
		foreach ( $rows as $r ) {
			$is_att = 'attachment' === $r->item_type;
			$url    = $r->rel_path ? DMC_Util::url_for( $r->rel_path ) : '';
			echo '<tr data-id="' . (int) $r->id . '">';
			echo '<th class="check-column"><input type="checkbox" class="dmc-cb" value="' . (int) $r->id . '"></th>';
			echo '<td class="dmc-col-thumb">' . self::thumb( $r ) . '</td>'; // phpcs:ignore
			echo '<td><strong>' . esc_html( $r->rel_path ? wp_basename( $r->rel_path ) : '#' . $r->attachment_id ) . '</strong>';
			echo ' <span class="dmc-badge ' . ( $is_att ? 'dmc-badge-lib' : 'dmc-badge-file' ) . '">' . esc_html( $is_att ? __( 'Library', 'deep-media-cleaner' ) . ' #' . $r->attachment_id : __( 'Not in library', 'deep-media-cleaner' ) ) . '</span>';
			if ( ! empty( $r->missing ) ) {
				echo ' <span class="dmc-badge dmc-badge-missing" title="' . esc_attr__( 'The file is no longer on the server. Only the Media Library entry is left.', 'deep-media-cleaner' ) . '">' . esc_html__( 'File missing', 'deep-media-cleaner' ) . '</span>';
			}
			echo '<br><span class="description">' . esc_html( $r->rel_path ) . '</span>';
			echo '<div class="row-actions">';
			$acts = array();
			if ( $url ) {
				$acts[] = '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html__( 'View file', 'deep-media-cleaner' ) . '</a>';
			}
			if ( $is_att ) {
				$acts[] = '<a href="' . esc_url( get_edit_post_link( (int) $r->attachment_id ) ) . '">' . esc_html__( 'Edit', 'deep-media-cleaner' ) . '</a>';
			}
			if ( 0 === (int) $r->is_used ) {
				$acts[] = '<a href="#" data-dmc-row="trash">' . esc_html__( 'Move to bin', 'deep-media-cleaner' ) . '</a>';
				$acts[] = '<a href="#" data-dmc-row="ignore">' . esc_html__( 'Ignore', 'deep-media-cleaner' ) . '</a>';
			} elseif ( 2 === (int) $r->is_used ) {
				$acts[] = '<a href="#" data-dmc-row="unignore">' . esc_html__( 'Stop ignoring', 'deep-media-cleaner' ) . '</a>';
			} elseif ( ! empty( $r->missing ) && 1 === (int) $r->is_used ) {
				$acts[] = '<a href="#" data-dmc-row="preview_unlink">' . esc_html__( 'Preview references', 'deep-media-cleaner' ) . '</a>';
				$acts[] = '<a href="#" class="dmc-danger-link" data-dmc-row="trash_missing">' . esc_html__( 'Move to bin (file missing)', 'deep-media-cleaner' ) . '</a>';
				$acts[] = '<a href="#" class="dmc-danger-link" data-dmc-row="trash_missing_unlink">' . esc_html__( 'Remove references + move to bin', 'deep-media-cleaner' ) . '</a>';
			} elseif ( DMC_Reason::is_soft( $r->used_by ) ) {
				$acts[] = '<a href="#" class="dmc-danger-link" data-dmc-row="trash_force">' . esc_html__( 'Move to bin anyway', 'deep-media-cleaner' ) . '</a>';
			}
			echo implode( ' | ', $acts ) . '</div></td>'; // phpcs:ignore
			echo '<td>' . ( ! empty( $r->missing ) && ! (int) $r->size ? '—' : esc_html( size_format( (int) $r->size, 1 ) ) ) . '</td>';
			echo '<td>' . esc_html( $r->item_date ? mysql2date( get_option( 'date_format' ), $r->item_date ) : '' ) . '</td>';
			if ( 0 === (int) $r->is_used && $is_att && $r->parent_id ) {
				/* translators: %d: post ID */
				$note = esc_html( sprintf( __( 'Attached to #%d but not shown anywhere', 'deep-media-cleaner' ), $r->parent_id ) );
			} else {
				$note = DMC_Reason::html( $r->used_by );
			}
			echo '<td class="dmc-note">' . $note . '</td>'; // phpcs:ignore -- escaped by DMC_Reason::html().
			echo '</tr>';
		}
		echo '</tbody></table>';
		echo '<div class="tablenav bottom">';
		self::pagination( $total, $per, $paged );
		echo '</div>';
	}

	private static function pagination( $total, $per, $paged ) {
		$pages = (int) ceil( $total / $per );
		echo '<div class="tablenav-pages"><span class="displaying-num">' . esc_html( sprintf( /* translators: %s: count */ _n( '%s item', '%s items', $total, 'deep-media-cleaner' ), number_format_i18n( $total ) ) ) . '</span>';
		if ( $pages > 1 ) {
			echo '<span class="pagination-links">' . paginate_links( // phpcs:ignore
				array(
					'base'      => add_query_arg( 'paged', '%#%' ),
					'format'    => '',
					'current'   => $paged,
					'total'     => $pages,
					'prev_text' => '‹',
					'next_text' => '›',
				)
			) . '</span>';
		}
		echo '</div>';
	}

	private static function page_bin() {
		global $wpdb;
		$t     = DMC_DB::tables();
		$per   = 50;
		$paged = max( 1, isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 ); // phpcs:ignore
		$cnt   = DMC_Bin::count();
		$total = (int) $cnt->n;
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$t['bin']}` ORDER BY id DESC LIMIT %d OFFSET %d", $per, ( $paged - 1 ) * $per ) ); // phpcs:ignore
		$s     = DMC_Util::settings();

		echo '<div class="dmc-box"><p>';
		/* translators: 1: count 2: size */
		echo esc_html( sprintf( __( '%1$s items in the bin, using %2$s.', 'deep-media-cleaner' ), number_format_i18n( $total ), size_format( (int) $cnt->s, 1 ) ) ) . ' ';
		if ( (int) $s['auto_purge_days'] > 0 ) {
			/* translators: %d: days */
			echo esc_html( sprintf( __( 'Items older than %d days are deleted automatically.', 'deep-media-cleaner' ), (int) $s['auto_purge_days'] ) );
		} else {
			echo esc_html__( 'Items stay here until you delete them (automatic emptying is off in Settings).', 'deep-media-cleaner' );
		}
		echo '</p><p class="description">' . esc_html__( 'Media library items in the bin are hidden from the library and the site. Restoring brings back the same item with the same ID, title, alt text and links.', 'deep-media-cleaner' ) . '</p></div>';

		echo '<div class="tablenav top dmc-actions">';
		echo '<button type="button" class="button" data-dmc-bulk="restore">' . esc_html__( 'Restore selected', 'deep-media-cleaner' ) . '</button> ';
		echo '<button type="button" class="button dmc-danger" data-dmc-bulk="delete">' . esc_html__( 'Delete selected permanently', 'deep-media-cleaner' ) . '</button> ';
		if ( $total ) {
			echo '<button type="button" class="button" id="dmc-restore-all" data-total="' . (int) $total . '">' . esc_html__( 'Restore everything', 'deep-media-cleaner' ) . '</button> ';
			echo '<button type="button" class="button dmc-danger" id="dmc-empty" data-total="' . (int) $total . '">' . esc_html__( 'Empty bin', 'deep-media-cleaner' ) . '</button>';
		}
		self::pagination( $total, $per, $paged );
		echo '</div>';

		self::help_bin();
		echo '<table class="widefat striped dmc-table"><thead><tr><td class="check-column"><input type="checkbox" class="dmc-check-all"></td><th>' . esc_html__( 'Original location', 'deep-media-cleaner' ) . '</th><th>' . esc_html__( 'Files', 'deep-media-cleaner' ) . '</th><th>' . esc_html__( 'Size', 'deep-media-cleaner' ) . '</th><th>' . esc_html__( 'Moved to bin', 'deep-media-cleaner' ) . '</th></tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="5">' . esc_html__( 'The recycle bin is empty.', 'deep-media-cleaner' ) . '</td></tr>';
		}
		foreach ( $rows as $r ) {
			$files = json_decode( $r->files, true );
			$user  = $r->trashed_by ? get_userdata( (int) $r->trashed_by ) : false;
			echo '<tr data-id="' . (int) $r->id . '"><th class="check-column"><input type="checkbox" class="dmc-cb" value="' . (int) $r->id . '"></th>';
			echo '<td><strong>' . esc_html( wp_basename( $r->rel_path ) ) . '</strong> <span class="dmc-badge ' . ( 'attachment' === $r->item_type ? 'dmc-badge-lib' : 'dmc-badge-file' ) . '">' . esc_html( 'attachment' === $r->item_type ? __( 'Library', 'deep-media-cleaner' ) . ' #' . $r->attachment_id : __( 'Not in library', 'deep-media-cleaner' ) ) . '</span><br><span class="description">' . esc_html( $r->rel_path ) . '</span>';
			$refs = empty( $r->refs ) ? array() : json_decode( $r->refs, true );
			if ( is_array( $refs ) && $refs ) {
				/* translators: %d: number of references */
				echo '<br><span class="dmc-badge dmc-badge-refs" title="' . esc_attr__( 'These custom-field references were removed with the item and will be put back if you restore it.', 'deep-media-cleaner' ) . '">' . esc_html( sprintf( _n( '%d reference removed – put back on restore', '%d references removed – put back on restore', count( $refs ), 'deep-media-cleaner' ), count( $refs ) ) ) . '</span>';
			}
			echo '<div class="row-actions"><a href="#" data-dmc-row="restore">' . esc_html__( 'Restore', 'deep-media-cleaner' ) . '</a> | <a href="#" class="dmc-danger-link" data-dmc-row="delete">' . esc_html__( 'Delete permanently', 'deep-media-cleaner' ) . '</a></div></td>';
			echo '<td>' . esc_html( number_format_i18n( is_array( $files ) ? count( $files ) : 0 ) ) . '</td>';
			echo '<td>' . esc_html( size_format( (int) $r->size, 1 ) ) . '</td>';
			echo '<td>' . esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $r->trashed_at ) ) . ( $user ? '<br><span class="description">' . esc_html( $user->display_name ) . '</span>' : '' ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	private static function page_settings() {
		$s = DMC_Util::settings();
		if ( isset( $_GET['saved'] ) ) { // phpcs:ignore
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved. Run a new scan to apply them.', 'deep-media-cleaner' ) . '</p></div>';
		}
		$cb = function ( $k, $label, $help = '' ) use ( $s ) {
			printf(
				'<label><input type="checkbox" name="%1$s" value="1" %2$s> %3$s</label>%4$s<br>',
				esc_attr( $k ),
				checked( ! empty( $s[ $k ] ), true, false ),
				esc_html( $label ),
				$help ? '<p class="description dmc-help">' . esc_html( $help ) . '</p>' : ''
			);
		};
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'dmc_settings' );
		echo '<input type="hidden" name="action" value="dmc_save_settings"><table class="form-table" role="presentation">';

		echo '<tr><th>' . esc_html__( 'What counts as "used"', 'deep-media-cleaner' ) . '</th><td>';
		$cb( 'safe_numeric', __( 'Safe mode: treat any number stored in post/term meta as a possible media ID', 'deep-media-cleaner' ), __( 'Recommended. Catches custom fields (ACF image/gallery/repeater fields, theme options) that store only an ID, including inside serialized arrays. May keep a few unused items.', 'deep-media-cleaner' ) );
		$cb( 'include_trash', __( 'Count media used by posts in the WordPress trash', 'deep-media-cleaner' ), __( 'Recommended, so restoring a trashed post does not leave broken images.', 'deep-media-cleaner' ) );
		$cb( 'include_revisions', __( 'Count media used only by old revisions', 'deep-media-cleaner' ) );
		$cb( 'attached_as_used', __( 'Treat every item attached to a post as used, even if the post does not show it', 'deep-media-cleaner' ), __( 'Off by default: being "attached" only means it was uploaded from that post.', 'deep-media-cleaner' ) );
		echo '</td></tr>';

		echo '<tr><th><label for="dmc-recent">' . esc_html__( 'Protect recent uploads', 'deep-media-cleaner' ) . '</label></th><td><input id="dmc-recent" type="number" min="0" max="3650" name="recent_days" value="' . esc_attr( $s['recent_days'] ) . '" class="small-text"> ' . esc_html__( 'days', 'deep-media-cleaner' ) . '<p class="description">' . esc_html__( 'Media uploaded within this many days is never listed as unused (you may still be writing the post). 0 = off.', 'deep-media-cleaner' ) . '</p></td></tr>';

		echo '<tr><th>' . esc_html__( 'Where to look', 'deep-media-cleaner' ) . '</th><td>';
		$cb( 'scan_tables', __( 'Scan custom database tables created by plugins (sliders, forms, page builders…)', 'deep-media-cleaner' ) );
		$cb( 'scan_theme', __( 'Scan the active theme and must-use plugins (CSS, PHP, JS) for hard-coded uploads', 'deep-media-cleaner' ) );
		echo '</td></tr>';

		echo '<tr><th><label for="dmc-tables">' . esc_html__( 'Skip these tables', 'deep-media-cleaner' ) . '</label></th><td><textarea id="dmc-tables" name="excluded_tables" rows="6" class="large-text code">' . esc_textarea( $s['excluded_tables'] ) . '</textarea><p class="description">' . esc_html__( 'One per line, without the table prefix; * wildcards allowed. These are logs, caches and media-organiser/optimiser tables that list every file and would make everything look used (Media Cleaner Pro’s own tables are already here).', 'deep-media-cleaner' ) . '</p>';
		$tables = DMC_Scanner::custom_tables();
		if ( $tables ) {
			echo '<details><summary>' . esc_html__( 'Tables that will be scanned', 'deep-media-cleaner' ) . ' (' . count( $tables ) . ')</summary><p class="description code">' . esc_html( implode( ', ', $tables ) ) . '</p></details>';
		}
		echo '</td></tr>';

		echo '<tr><th><label for="dmc-dirs">' . esc_html__( 'Skip these upload folders', 'deep-media-cleaner' ) . '</label></th><td><textarea id="dmc-dirs" name="excluded_dirs" rows="5" class="large-text code">' . esc_textarea( $s['excluded_dirs'] ) . '</textarea><p class="description">' . esc_html__( 'Top-level folders inside uploads that belong to plugins (generated CSS, form uploads, backups). Files there are never listed.', 'deep-media-cleaner' ) . '</p></td></tr>';

		echo '<tr><th><label for="dmc-exts">' . esc_html__( 'File types to check', 'deep-media-cleaner' ) . '</label></th><td><input id="dmc-exts" type="text" name="orphan_exts" value="' . esc_attr( $s['orphan_exts'] ) . '" class="large-text code"><p class="description">' . esc_html__( 'For files that are in the uploads folder but not in the media library.', 'deep-media-cleaner' ) . '</p></td></tr>';

		echo '<tr><th>' . esc_html__( 'Safety', 'deep-media-cleaner' ) . '</th><td>';
		$cb( 'recheck', __( 'Re-check each item right before moving it to the bin', 'deep-media-cleaner' ), __( 'Catches media you used after the last scan. Slightly slower.', 'deep-media-cleaner' ) );
		echo '</td></tr>';

		echo '<tr><th><label for="dmc-purge">' . esc_html__( 'Empty the bin automatically', 'deep-media-cleaner' ) . '</label></th><td>' . esc_html__( 'after', 'deep-media-cleaner' ) . ' <input id="dmc-purge" type="number" min="0" max="3650" name="auto_purge_days" value="' . esc_attr( $s['auto_purge_days'] ) . '" class="small-text"> ' . esc_html__( 'days (0 = never)', 'deep-media-cleaner' ) . '</td></tr>';

		echo '<tr><th><label for="dmc-budget">' . esc_html__( 'Work per request', 'deep-media-cleaner' ) . '</label></th><td><input id="dmc-budget" type="number" min="5" max="120" name="time_budget" value="' . esc_attr( $s['time_budget'] ) . '" class="small-text"> ' . esc_html__( 'seconds', 'deep-media-cleaner' ) . '<p class="description">' . esc_html__( 'Lower this if scans fail on slow hosting.', 'deep-media-cleaner' ) . '</p></td></tr>';

		echo '</table><p class="submit"><button class="button button-primary">' . esc_html__( 'Save settings', 'deep-media-cleaner' ) . '</button> <button class="button" name="reset" value="1" onclick="return confirm(\'' . esc_js( __( 'Reset all settings to defaults?', 'deep-media-cleaner' ) ) . '\')">' . esc_html__( 'Reset to defaults', 'deep-media-cleaner' ) . '</button></p></form>';
	}

	private static function page_log() {
		$log = get_option( 'dmc_log', array() );
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'When', 'deep-media-cleaner' ) . '</th><th>' . esc_html__( 'Who', 'deep-media-cleaner' ) . '</th><th>' . esc_html__( 'Action', 'deep-media-cleaner' ) . '</th><th>' . esc_html__( 'Item', 'deep-media-cleaner' ) . '</th></tr></thead><tbody>';
		if ( ! $log ) {
			echo '<tr><td colspan="4">' . esc_html__( 'Nothing logged yet.', 'deep-media-cleaner' ) . '</td></tr>';
		}
		$labels = array(
			'scan'    => __( 'Scan', 'deep-media-cleaner' ),
			'trash'   => __( 'Moved to bin', 'deep-media-cleaner' ),
			'restore' => __( 'Restored', 'deep-media-cleaner' ),
			'delete'  => __( 'Deleted permanently', 'deep-media-cleaner' ),
		);
		foreach ( (array) $log as $l ) {
			$u = ! empty( $l['user'] ) ? get_userdata( (int) $l['user'] ) : false;
			echo '<tr><td>' . esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $l['time'] ) ) . '</td><td>' . esc_html( $u ? $u->display_name : __( 'System', 'deep-media-cleaner' ) ) . '</td><td>' . esc_html( isset( $labels[ $l['action'] ] ) ? $labels[ $l['action'] ] : $l['action'] ) . '</td><td>' . esc_html( $l['detail'] ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}
}
