<?php
/**
 * Bulk-operation AJAX handler.
 *
 * Single dispatch endpoint for all bulk ops: folder_assign, tag_add,
 * tag_remove, and meta_edit. Processes one batch per request and returns
 * a per-item {id, ok, reason?} result array.
 *
 * Security contracts:
 * - T-07-csrf: nonce read from JSON body via wp_verify_nonce() on the
 *   decoded php://input body (NOT the form-post analog that reads $_POST).
 * - T-07-cap: endpoint-level manage_options + per-item edit_post check.
 * - T-07-bac: select_all_matching re-derives ids server-side via
 *   MediaIndex::ids() from filter_args (D-03).
 * - T-07-sqli: all ids absint'd; term ids absint + %d via WP API.
 * - T-07-kses: writes through wp_update_post (internal kses/strip).
 * - T-07-massassign: only four whitelisted fields written.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Admin;

use AssetDrips\Index\MediaIndex;
use AssetDrips\Index\MediaQuery;
use AssetDrips\Squeeze\BackupManager;
use AssetDrips\Squeeze\SqueezeEngine;
use AssetDrips\Squeeze\SqueezeSettings;

defined( 'ABSPATH' ) || exit;

/**
 * Bulk-operation AJAX handler for the AssetDrips Find screen.
 *
 * Dispatches folder_assign | tag_add | tag_remove | meta_edit over a batch
 * of attachment ids. All writes go through standard WP APIs so existing
 * IndexHooks and SortHooks fire per item (BULK-03 sync for free — D-11/D-12).
 */
final class BulkActions {

	/**
	 * Capability required to access the endpoint.
	 */
	private const CAP = 'manage_options';

	/**
	 * Nonce action shared with FindScreen so the same token works for both.
	 */
	private const AJAX_NONCE = 'assetdrips_ajax';

	/**
	 * Maximum ids per batch. JS driver slices client-side; PHP enforces here.
	 */
	private const BATCH_SIZE = 50;

	/**
	 * Register the wp_ajax action hook.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'wp_ajax_assetdrips_bulk_op', array( $this, 'handle_bulk_op' ) );
	}

	/**
	 * Main AJAX handler for bulk operations.
	 *
	 * (1) Capability check (manage_options).
	 * (2) Decode JSON body and verify nonce via wp_verify_nonce() on the decoded
	 *     body. $_POST is empty for application/json requests (Pitfall 6 / T-07-csrf).
	 * (3) Whitelist op against four allowed values.
	 * (4) Resolve working-set ids: explicit list or filter-scoped server re-derive.
	 * (5) Per-item loop: absint guard, edit_post cap gate, op dispatch, result record.
	 * (6) Return per-batch result contract.
	 *
	 * @return void
	 */
	public function handle_bulk_op(): void {
		// --- (1) Endpoint-level capability check --------------------------------
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		}

		// --- (2) Decode JSON body and verify nonce ------------------------------
		// file_get_contents('php://input') is required because PHP only populates
		// $_POST for application/x-www-form-urlencoded and multipart/form-data.
		// The form-post nonce verification path that reads $_POST does not work here
		// (Pitfall 6 — use wp_verify_nonce on the decoded body instead).
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- JSON body; $_POST is empty for application/json.
		$raw   = json_decode( (string) file_get_contents( 'php://input' ), true );
		$raw   = is_array( $raw ) ? $raw : array();
		$nonce = isset( $raw['nonce'] ) ? (string) $raw['nonce'] : '';

		if ( ! wp_verify_nonce( $nonce, self::AJAX_NONCE ) ) {
			wp_send_json_error( array( 'message' => 'Invalid nonce.' ), 403 );
		}

		// --- (3) Op whitelist ---------------------------------------------------
		$op          = isset( $raw['op'] ) ? (string) $raw['op'] : '';
		$allowed_ops = array( 'folder_assign', 'tag_add', 'tag_remove', 'meta_edit', 'squeeze_restore', 'squeeze_optimize', 'squeeze_regenerate_sizes' );
		if ( ! in_array( $op, $allowed_ops, true ) ) {
			wp_send_json_error( array( 'message' => 'Unknown operation.' ), 400 );
		}

		// --- (4) Resolve working-set ids ----------------------------------------
		// Two modes:
		// (a) Explicit ids[] list from the client — each id is absint()'d.
		// Client already batches to BATCH_SIZE before sending; PHP processes as-is.
		// (b) select_all_matching + filter_args — server re-derives authoritatively
		// via MediaIndex::ids() so a tampered client blob cannot widen the set
		// (D-03 / T-07-bac). The filter_args are INPUT to id derivation, not
		// an authorization grant. The JS driver loops via batch_offset until
		// has_more is false, so the FULL matching set is always processed (CR-02).
		$ids            = array();
		$is_select_all  = ! empty( $raw['select_all_matching'] );
		$total_matching = null; // only set in select_all_matching mode.
		$next_offset    = null;
		$has_more       = null;

		if ( ! empty( $raw['ids'] ) && is_array( $raw['ids'] ) ) {
			// Explicit list: sanitize every value and drop zeros (T-07-sqli).
			foreach ( $raw['ids'] as $raw_id ) {
				$id = absint( $raw_id );
				if ( $id > 0 ) {
					$ids[] = $id;
				}
			}
		} elseif ( $is_select_all ) {
			// Filter-scoped mode: build a MediaQuery from filter_args and call ids().
			// ids() returns the FULL matching set (no LIMIT). We then page through it
			// server-side using batch_offset so the JS driver can loop to completion
			// without ever trusting the client id blob (CR-02 fix: no hard cap at 50).
			$filter_args = isset( $raw['filter_args'] ) && is_array( $raw['filter_args'] )
				? $raw['filter_args']
				: array();

			$q = $this->build_media_query( $filter_args );

			global $wpdb;
			$all_ids        = ( new MediaIndex( $wpdb ) )->ids( $q );
			$total_matching = count( $all_ids );

			$batch_offset = isset( $raw['batch_offset'] ) ? absint( $raw['batch_offset'] ) : 0;
			$ids          = array_slice( $all_ids, $batch_offset, self::BATCH_SIZE );
			$next_offset  = $batch_offset + count( $ids );
			$has_more     = $next_offset < $total_matching;
		}

		// --- (5) Per-item loop --------------------------------------------------
		$results = array();
		$params  = $raw;

		foreach ( $ids as $attachment_id ) {
			$attachment_id = absint( $attachment_id );

			if ( $attachment_id <= 0 ) {
				$results[] = array(
					'id'     => $attachment_id,
					'ok'     => false,
					'reason' => 'Invalid attachment ID.',
				);
				continue;
			}

			if ( ! current_user_can( 'edit_post', $attachment_id ) ) {
				$results[] = array(
					'id'     => $attachment_id,
					'ok'     => false,
					'reason' => 'Insufficient permissions.',
				);
				continue;
			}

			$op_result = $this->dispatch_op( $op, $attachment_id, $params );

			if ( true === $op_result ) {
				$results[] = array(
					'id' => $attachment_id,
					'ok' => true,
				);
			} else {
				$results[] = array(
					'id'     => $attachment_id,
					'ok'     => false,
					'reason' => is_string( $op_result ) ? $op_result : 'Operation failed.',
				);
			}
		}

		// --- (6) Return per-batch result contract --------------------------------
		// For select_all_matching, include pagination fields so the JS driver can
		// loop until has_more is false, accumulating results across batches (CR-02).
		$response = array(
			'processed' => count( $ids ),
			'results'   => $results,
		);

		if ( $is_select_all ) {
			$response['total_matching'] = $total_matching;
			$response['next_offset']    = $next_offset;
			$response['has_more']       = $has_more;
		}

		wp_send_json_success( $response );
	}

	/**
	 * Build a MediaQuery from a filter_args array (mirrors FindScreen's GET→MediaQuery mapping exactly).
	 *
	 * Keys mirror the §6 filter contract that FindScreen sends to the JS driver:
	 * - 's', 'type', 'subtype', 'orientation', 'used', 'folder', 'tag', 'missing_alt'
	 * - 'size_min', 'size_max', 'w_min', 'w_max', 'h_min', 'h_max' (NOTE: GET keys w_min/h_min → width_min/height_min)
	 * - 'uploader', 'date_from', 'date_to', 'orderby', 'order'
	 *
	 * Do NOT set page/per_page — ids() ignores LIMIT.
	 * Do NOT use dynamic properties (mime, has_alt) that do not exist on MediaQuery;
	 * the real fields are type/subtype and missing_alt (PHP 8.2 dynamic-prop deprecation).
	 *
	 * @param array<string, mixed> $filter_args Raw filter args from the client.
	 * @return MediaQuery
	 */
	private function build_media_query( array $filter_args ): MediaQuery {
		$q              = new MediaQuery();
		$q->search      = isset( $filter_args['s'] ) ? (string) $filter_args['s'] : '';
		$q->type        = isset( $filter_args['type'] ) ? (string) $filter_args['type'] : '';
		$q->subtype     = isset( $filter_args['subtype'] ) ? (string) $filter_args['subtype'] : '';
		$q->orientation = isset( $filter_args['orientation'] ) ? (string) $filter_args['orientation'] : '';
		$q->used        = isset( $filter_args['used'] ) ? (string) $filter_args['used'] : '';
		$q->folder      = isset( $filter_args['folder'] ) ? (string) $filter_args['folder'] : '';
		$q->tag         = isset( $filter_args['tag'] ) ? absint( $filter_args['tag'] ) : 0;
		$q->missing_alt = ! empty( $filter_args['missing_alt'] );
		$q->size_min    = isset( $filter_args['size_min'] ) ? absint( $filter_args['size_min'] ) : 0;
		$q->size_max    = isset( $filter_args['size_max'] ) ? absint( $filter_args['size_max'] ) : 0;
		// NOTE: GET keys are w_min/w_max/h_min/h_max; MediaQuery fields are width_*/height_*.
		$q->width_min  = isset( $filter_args['w_min'] ) ? absint( $filter_args['w_min'] ) : 0;
		$q->width_max  = isset( $filter_args['w_max'] ) ? absint( $filter_args['w_max'] ) : 0;
		$q->height_min = isset( $filter_args['h_min'] ) ? absint( $filter_args['h_min'] ) : 0;
		$q->height_max = isset( $filter_args['h_max'] ) ? absint( $filter_args['h_max'] ) : 0;
		$q->uploader   = isset( $filter_args['uploader'] ) ? absint( $filter_args['uploader'] ) : 0;
		$q->date_from  = isset( $filter_args['date_from'] ) ? (string) $filter_args['date_from'] : '';
		$q->date_to    = isset( $filter_args['date_to'] ) ? (string) $filter_args['date_to'] : '';
		$q->orderby       = isset( $filter_args['orderby'] ) ? (string) $filter_args['orderby'] : 'uploaded_at';
		$q->order         = isset( $filter_args['order'] ) ? (string) $filter_args['order'] : 'desc';
		// Whitelist squeeze_state to prevent silent scope-expansion on select-all-matching (CR-02).
		$raw_squeeze_state = isset( $filter_args['squeeze_state'] ) ? (string) $filter_args['squeeze_state'] : '';
		$allowed_squeeze_states = array( 'not-optimized', 'oversized', 'missing-webp', 'has-backup' );
		$q->squeeze_state = in_array( $raw_squeeze_state, $allowed_squeeze_states, true ) ? $raw_squeeze_state : '';
		return $q;
	}

	/**
	 * Dispatch to the correct op handler.
	 *
	 * Returns true on success, or an error string/false on failure.
	 *
	 * @param string               $op            Op name (folder_assign|tag_add|tag_remove|meta_edit).
	 * @param int                  $attachment_id Attachment post ID (already absint'd and cap-checked).
	 * @param array<string, mixed> $params        Full raw payload from the JSON body.
	 * @return true|string
	 */
	private function dispatch_op( string $op, int $attachment_id, array $params ) {
		switch ( $op ) {
			case 'folder_assign':
				return $this->op_folder_assign( $attachment_id, $params );

			case 'tag_add':
				return $this->op_tag_add( $attachment_id, $params );

			case 'tag_remove':
				return $this->op_tag_remove( $attachment_id, $params );

			case 'meta_edit':
				return $this->op_meta_edit( $attachment_id, $params );

			case 'squeeze_restore':
				return $this->op_squeeze_restore( $attachment_id );

			case 'squeeze_optimize':
				return $this->op_squeeze_optimize( $attachment_id );

			case 'squeeze_regenerate_sizes':
				return $this->op_squeeze_regenerate_sizes( $attachment_id );

			default:
				return 'Unknown operation.';
		}
	}

	// -------------------------------------------------------------------------
	// Op: folder_assign
	// -------------------------------------------------------------------------

	/**
	 * Assign one attachment to a folder (or clear to Uncategorized).
	 *
	 * Uses replace-set (append=false) because each attachment belongs to exactly
	 * one folder (D-13). Fires SortHooks::on_set_object_terms → folder_id UPDATE
	 * in assetdrips_media (BULK-03 sync for free).
	 *
	 * @param int                  $attachment_id Attachment ID.
	 * @param array<string, mixed> $params        Payload — expects 'folder_id' (int or 0 for Uncategorized).
	 * @return true|string
	 */
	private function op_folder_assign( int $attachment_id, array $params ) {
		// WR-04: require folder_id key to be present in the payload.
		// A missing key (malformed request) must NOT silently clear all folder
		// assignments. Only an explicit empty/zero value is the Uncategorized sentinel.
		if ( ! array_key_exists( 'folder_id', $params ) ) {
			return 'Missing folder_id.';
		}

		$term_id = absint( $params['folder_id'] );

		if ( $term_id > 0 ) {
			// Assign to the specified folder (replace-set, single-folder per D-13).
			$result = wp_set_object_terms( $attachment_id, array( $term_id ), 'assetdrips_folder', false );
		} else {
			// Explicit Uncategorized clear (folder_id key present but blank/'0'/0).
			$result = wp_set_object_terms( $attachment_id, array(), 'assetdrips_folder', false );
		}

		if ( is_wp_error( $result ) ) {
			return $result->get_error_message();
		}

		return true;
	}

	// -------------------------------------------------------------------------
	// Op: tag_add
	// -------------------------------------------------------------------------

	/**
	 * Append a tag to an attachment (preserves existing tags).
	 *
	 * MUST use append=true (D-14). Using append=false would wipe all existing
	 * tags (Pitfall 4) — this diverges intentionally from TagFields::save_tag_field()
	 * which uses append=false for single-item replace-set.
	 *
	 * @param int                  $attachment_id Attachment ID.
	 * @param array<string, mixed> $params        Payload — expects 'tag_id' (positive int).
	 * @return true|string
	 */
	private function op_tag_add( int $attachment_id, array $params ) {
		$tag_id = absint( $params['tag_id'] ?? 0 );

		if ( $tag_id <= 0 ) {
			return 'Invalid tag ID.';
		}

		// append=true: preserves any other tags already assigned to this item (D-14).
		$result = wp_set_object_terms( $attachment_id, array( $tag_id ), 'assetdrips_tag', true );

		if ( is_wp_error( $result ) ) {
			return $result->get_error_message();
		}

		return true;
	}

	// -------------------------------------------------------------------------
	// Op: tag_remove
	// -------------------------------------------------------------------------

	/**
	 * Remove a specific tag from an attachment without affecting other tags.
	 *
	 * Uses wp_remove_object_terms() to remove only the specified term — no other tags
	 * are touched (D-14).
	 *
	 * @param int                  $attachment_id Attachment ID.
	 * @param array<string, mixed> $params        Payload — expects 'tag_id' (positive int).
	 * @return true|string
	 */
	private function op_tag_remove( int $attachment_id, array $params ) {
		$tag_id = absint( $params['tag_id'] ?? 0 );

		if ( $tag_id <= 0 ) {
			return 'Invalid tag ID.';
		}

		$result = wp_remove_object_terms( $attachment_id, array( $tag_id ), 'assetdrips_tag' );

		if ( is_wp_error( $result ) ) {
			return $result->get_error_message();
		}

		return true;
	}

	// -------------------------------------------------------------------------
	// Op: meta_edit
	// -------------------------------------------------------------------------

	/**
	 * Bulk-edit metadata fields: title, caption, description, and/or alt text.
	 *
	 * Rules (D-09 / D-10 / D-11):
	 * - Blank submitted value → field is left unchanged (non-destructive).
	 * - fill_empty_only=true → field is skipped when the item already has a value.
	 * - Writes title/caption/description via wp_update_post (fires IndexHooks::on_update).
	 * - Writes alt via update_post_meta (fires IndexHooks::on_alt → has_alt updated).
	 * - Never calls update_post_meta with '' (Pitfall 7 — D-09 blank=unchanged).
	 * - wp_update_post is called ONLY when at least one field is being written
	 *   (count($post_data) > 1, i.e. more than just the required ID key).
	 *
	 * Security: writes route through wp_update_post → internal kses/strip_all_tags
	 * (T-07-kses). No direct wpdb writes.
	 *
	 * @param int                  $attachment_id Attachment ID.
	 * @param array<string, mixed> $params        Payload — expects title/caption/description/alt (string), fill_empty_only (bool).
	 * @return true|string
	 */
	private function op_meta_edit( int $attachment_id, array $params ) {
		$title       = isset( $params['title'] ) ? (string) $params['title'] : '';
		$caption     = isset( $params['caption'] ) ? (string) $params['caption'] : '';
		$description = isset( $params['description'] ) ? (string) $params['description'] : '';
		$alt         = isset( $params['alt'] ) ? (string) $params['alt'] : '';
		$fill_empty  = ! empty( $params['fill_empty_only'] );

		// Lazy-fetch the current post only when fill_empty_only is set and there are
		// text fields to evaluate.
		$post = null;
		if ( $fill_empty && ( '' !== $title || '' !== $caption || '' !== $description ) ) {
			$post = get_post( $attachment_id );
			// WR-01 / D-10: fail closed when the attachment cannot be read.
			// Treating a null post as "no current value" would silently overwrite — wrong.
			if ( null === $post ) {
				return 'Attachment not found.';
			}
		}

		// ---- Build $post_data conditionally (D-09 blank = unchanged) -----------
		// The ID key is always present; wp_update_post requires it.
		// Additional keys are added only when the submitted value is non-empty
		// AND (when fill_empty_only) the current value is empty.
		$post_data = array( 'ID' => $attachment_id );

		if ( '' !== $title ) {
			$skip = $fill_empty && null !== $post && '' !== (string) $post->post_title;
			if ( ! $skip ) {
				$post_data['post_title'] = $title;
			}
		}

		if ( '' !== $caption ) {
			$skip = $fill_empty && null !== $post && '' !== (string) $post->post_excerpt;
			if ( ! $skip ) {
				$post_data['post_excerpt'] = $caption;
			}
		}

		if ( '' !== $description ) {
			$skip = $fill_empty && null !== $post && '' !== (string) $post->post_content;
			if ( ! $skip ) {
				$post_data['post_content'] = $description;
			}
		}

		// Only call wp_update_post if there is at least one field beyond the ID
		// (D-09 — no gratuitous writes, avoids spurious hook fire overhead).
		if ( count( $post_data ) > 1 ) {
			$result = wp_update_post( $post_data, true );
			if ( is_wp_error( $result ) ) {
				return $result->get_error_message();
			}
		}

		// ---- Alt text (separate WP API — update_post_meta) ---------------------
		// Never write '' — D-09 blank=unchanged; Pitfall 7 (empty-string is a no-op
		// that can leave has_alt stale and requires delete_post_meta to clear, which
		// is deferred to a future phase).
		if ( '' !== $alt ) {
			$skip_alt = false;

			if ( $fill_empty ) {
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Single scalar read, no JOIN; necessary for fill_empty_only correctness (D-10).
				$current_alt = (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
				if ( '' !== $current_alt ) {
					$skip_alt = true;
				}
			}

			if ( ! $skip_alt ) {
				// Fires updated_post_meta → IndexHooks::on_alt → upsert_structural
				// (has_alt updated immediately — BULK-03 sync for free, D-11).
				update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt );
			}
		}

		return true;
	}

	// -------------------------------------------------------------------------
	// Op: squeeze_restore
	// -------------------------------------------------------------------------

	/**
	 * Restore a backup for one attachment.
	 *
	 * Returns true on success, or an error string on failure.
	 * Per-item failures are recorded without aborting the batch (D-11).
	 *
	 * Security: no additional nonce/cap checks added here — handle_bulk_op()
	 * already enforces manage_options (endpoint-level), the batch nonce, per-item
	 * edit_post, and absint on IDs; the new op inherits all of them automatically.
	 *
	 * @param int $attachment_id Attachment ID (already absint'd and cap-checked by handle_bulk_op()).
	 * @return true|string
	 */
	private function op_squeeze_restore( int $attachment_id ) {
		$manager = BackupManager::from_wordpress();
		if ( ! $manager->has_backup( $attachment_id ) ) {
			return 'No active backup for this attachment.';
		}
		try {
			$manager->restore_all( $attachment_id );
			return true;
		} catch ( \Throwable $e ) {
			// Catch \Throwable (not just \RuntimeException) because BackupManager::restore_all()
			// re-throws the original \Throwable after rollback (WR-03). Catching only
			// \RuntimeException lets non-RuntimeException errors (e.g. \Error) propagate
			// uncaught, terminating the batch and losing in-progress results.
			return $e->getMessage();
		}
	}

	// -------------------------------------------------------------------------
	// Op: squeeze_optimize
	// -------------------------------------------------------------------------

	/**
	 * Run the settings-enabled Squeeze ops for one attachment.
	 *
	 * Mirrors op_squeeze_restore() shape: resolve services fresh per item,
	 * run engine ops gated on their enable_* toggles, isolate per-item failures
	 * with a \Throwable catch so a single failure does not abort the batch.
	 *
	 * Security: no additional nonce/cap checks here — handle_bulk_op() already
	 * enforces manage_options (endpoint-level), the batch nonce, per-item edit_post,
	 * and absint on IDs. This op inherits all gates unchanged (TRG-02 / TRG-04 / D-13).
	 *
	 * CRITICAL — no double-backup/upsert: SqueezeEngine methods invoke BackupManager and
	 * OptimizationIndex internally (D-04 corrected). Do NOT duplicate those calls here.
	 *
	 * @param int $attachment_id Attachment ID (already absint'd and cap-checked by handle_bulk_op()).
	 * @return true|string
	 */
	private function op_squeeze_optimize( int $attachment_id ) {
		// Services resolved fresh per item, same pattern as op_squeeze_restore().
		$engine   = SqueezeEngine::from_wordpress();
		$settings = SqueezeSettings::load();

		try {
			// Run ops in D-04 ordering; each gated on its enable_* toggle (D-03).
			// SqueezeEngine handles backup and index writes internally — do NOT call
			// BackupManager::backup() or OptimizationIndex::upsert() here (Pitfalls 1/2).
			if ( $settings->enable_recompress ) {
				$engine->recompress( $attachment_id );
			}
			if ( $settings->enable_webp ) {
				$engine->generate_webp( $attachment_id );
			}
			if ( $settings->enable_avif ) {
				$engine->generate_avif( $attachment_id );
			}
			if ( $settings->enable_resize ) {
				$engine->resize_original( $attachment_id );
			}
			return true;
		} catch ( \Throwable $e ) {
			// Catch \Throwable (not just \RuntimeException) because engine methods may
			// re-throw the original \Throwable after internal rollback (WR-03 pattern).
			// Per-item failure must not abort the batch (T-11-isolate).
			return $e->getMessage();
		}
	}

	// -------------------------------------------------------------------------
	// Op: squeeze_regenerate_sizes
	// -------------------------------------------------------------------------

	/**
	 * Additively repair missing registered image sizes for one attachment.
	 *
	 * Delegates to SqueezeEngine::repair_missing_sizes() which calls
	 * wp_update_image_subsizes() — fills only the missing registered sizes,
	 * never clobbers custom crops, never touches the original file (D-07).
	 *
	 * Returns true on success, the reason string on ok=false, or the
	 * exception message on \Throwable — per-item failures do not abort
	 * the batch (mirrors op_squeeze_restore() shape).
	 *
	 * Security: no additional nonce/cap checks added here — handle_bulk_op()
	 * already enforces manage_options (endpoint-level), the batch nonce, per-item
	 * edit_post, and absint on IDs; squeeze_regenerate_sizes inherits all gates
	 * automatically (SIZE-02 / T-13-12).
	 *
	 * @param int $attachment_id Attachment ID (already absint'd and cap-checked by handle_bulk_op()).
	 * @return true|string
	 */
	private function op_squeeze_regenerate_sizes( int $attachment_id ) {
		$engine = SqueezeEngine::from_wordpress();
		try {
			$result = $engine->repair_missing_sizes( $attachment_id );
			return true === ( $result['ok'] ?? false ) ? true : ( $result['reason'] ?? 'repair_failed' );
		} catch ( \Throwable $e ) {
			// Catch \Throwable so a single-item failure (filesystem error, WP_Error
			// propagated as exception) does not abort the resumable batch (T-13-14).
			return $e->getMessage();
		}
	}
}
