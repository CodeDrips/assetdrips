<?php
/**
 * Pure encoding seam over WP_Image_Editor.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Squeeze;

use AssetDrips\Index\MediaIndex;
use AssetDrips\Index\MediaRow;
use AssetDrips\Squeeze\CapabilityProbe;
use AssetDrips\Squeeze\OptimizationRecord;

defined( 'ABSPATH' ) || exit;

/**
 * Wraps WP_Image_Editor with every critical guard required by Squeeze:
 * backup-before-encode, content_hash skip (D-06), atomic metadata update,
 * and explicit out-of-hook index sync (D-09).
 *
 * SqueezeEngine is the ONLY class in Phase 9 that calls wp_get_image_editor().
 * All encode paths route through the injectable $editor_factory seam so unit
 * tests can inject a mock without loading the full WordPress image stack.
 *
 * Threat mitigations:
 *  - T-09-06: all decode/encode goes through WP_Image_Editor (never Imagick/GD directly);
 *    path resolved only via get_attached_file($id); is_wp_error guards on editor + save.
 *  - T-09-08: content_hash written directly from recompress() return path, never inside
 *    an IndexHooks callback ($in_update cannot suppress it — Pitfall 1).
 *  - T-09-09: regenerate_sizes uses wp_create_image_subsizes (does NOT fire
 *    wp_generate_attachment_metadata filter), preventing a SqueezeHooks re-queue loop.
 */
final class SqueezeEngine {

	/**
	 * Database handle.
	 *
	 * @var object
	 */
	private object $wpdb;

	/**
	 * Backup manager for backup-before-encode gate.
	 *
	 * @var object
	 */
	private object $backup_manager;

	/**
	 * Optimization index for squeeze row + content_hash writes.
	 *
	 * @var object
	 */
	private object $optimization_index;

	/**
	 * Editor factory callable: accepts a file path, returns WP_Image_Editor|WP_Error.
	 *
	 * Defaults to 'wp_get_image_editor'; replaced in unit tests by a mock factory.
	 *
	 * @var callable
	 */
	private $editor_factory; // phpcs:ignore Squiz.Commenting.VariableComment.Missing -- typed via phpdoc; callable properties cannot carry a PHP type.

	/**
	 * Per-image AVIF encode time budget in seconds (D-10).
	 *
	 * Tunable: pass a value to the constructor for tests; default 5.0 s for production.
	 *
	 * @var float
	 */
	private float $avif_time_budget;

	/**
	 * Construct with explicit dependencies.
	 *
	 * Accepts `object` for $wpdb so unit tests can inject an anonymous stub without
	 * loading the full WordPress test suite (same pattern as MediaIndex/OptimizationIndex).
	 *
	 * @param object        $wpdb               Database handle (production: \wpdb; tests: anonymous stub).
	 * @param object        $backup_manager      BackupManager instance (or anonymous stub in tests).
	 * @param object        $optimization_index  OptimizationIndex instance (or anonymous stub in tests).
	 * @param callable|null $editor_factory      Optional factory replacing wp_get_image_editor(); null uses WP default.
	 * @param float|null    $avif_time_budget    Per-image AVIF encode budget in seconds; null uses 5.0 s default (D-10).
	 */
	public function __construct(
		object $wpdb,
		object $backup_manager,
		object $optimization_index,
		?callable $editor_factory = null,
		?float $avif_time_budget = null
	) {
		$this->wpdb               = $wpdb;
		$this->backup_manager     = $backup_manager;
		$this->optimization_index = $optimization_index;
		$this->editor_factory     = $editor_factory ?? 'wp_get_image_editor';
		$this->avif_time_budget   = $avif_time_budget ?? 5.0;
	}

	/**
	 * Construct from the live WordPress environment.
	 *
	 * @return self
	 */
	public static function from_wordpress(): self {
		global $wpdb;

		return new self(
			$wpdb,
			BackupManager::from_wordpress(),
			OptimizationIndex::from_wordpress()
		);
	}

	/**
	 * Recompress a JPEG or PNG attachment in place using WP_Image_Editor.
	 *
	 * Sequence (D-04 / D-06 / CMP-01..04):
	 *  1. Resolve path; call clearstatcache (Pitfall 2).
	 *  2. content_hash guard — return skipped when SHA-1 matches stored value (CMP-03).
	 *  3. Backup FIRST via BackupManager::backup() — return backup_failed if false (D-03).
	 *  4. Load editor via $editor_factory; branch on MIME for set_quality().
	 *  5. save() overwrites the file in place; is_wp_error guard.
	 *  6. clearstatcache; write content_hash post-encode (D-06 / D-09).
	 *  7. Explicit MediaIndex::upsert_structural() outside hook scope (D-09).
	 *  8. OptimizationIndex::upsert() recording bytes_before/after.
	 *
	 * @param int                  $attachment_id Attachment post ID.
	 * @param array<string, mixed> $options       Optional override values (currently unused; reserved for future force-flag).
	 * @return array<string, mixed> Result array; keys: ok, skipped, reason, bytes_before, bytes_after.
	 */
	public function recompress( int $attachment_id, array $options = array() ): array {
		unset( $options ); // Reserved for future use.

		// Load image-editor class files for cron/CLI contexts where admin is not loaded (Pitfall 3).
		if ( file_exists( ABSPATH . 'wp-admin/includes/image.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$path = get_attached_file( $attachment_id );
		if ( ! is_string( $path ) || '' === $path ) {
			return array(
				'ok'     => false,
				'reason' => 'no_file',
			);
		}

		// Pitfall 2: flush stale stat cache before any hash/filesize read.
		clearstatcache( true, $path );

		// content_hash guard (CMP-03 / D-06): skip when SHA-1 matches the stored hash.
		$media_table = \AssetDrips\Db\Schema::media_table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Scoped content_hash read; table name is a Schema constant (never input) and value is bound via prepare().
		$stored_hash = $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT content_hash FROM {$media_table} WHERE attachment_id = %d",
				$attachment_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$current_hash = sha1_file( $path );
		if ( is_string( $stored_hash ) && '' !== $stored_hash && $stored_hash === $current_hash ) {
			return array(
				'ok'      => false,
				'skipped' => true,
				'reason'  => 'already_optimized',
			);
		}

		// Guard file existence before filesize() — a missing file silently returns 0
		// which would corrupt the bytes_before record (WR-04).
		if ( ! file_exists( $path ) ) {
			return array(
				'ok'     => false,
				'reason' => 'file_missing',
			);
		}
		$bytes_before = (int) filesize( $path );

		// Backup FIRST — never encode without a verified backup (D-03 / D-07).
		$backup_ok = $this->backup_manager->backup( $attachment_id, $path, 'recompress' );
		if ( ! $backup_ok ) {
			return array(
				'ok'      => false,
				'skipped' => false,
				'reason'  => 'backup_failed',
			);
		}

		// Load the editor via the injectable factory (T-09-06: never Imagick/GD directly).
		$editor = ( $this->editor_factory )( $path );
		if ( is_wp_error( $editor ) ) {
			return array(
				'ok'     => false,
				'reason' => 'editor_unavailable',
			);
		}

		// Set quality based on MIME type (CMP-01 / CMP-02).
		$mime     = (string) get_post_mime_type( $attachment_id );
		$settings = SqueezeSettings::load();

		if ( str_contains( $mime, 'jpeg' ) ) {
			$editor->set_quality( $settings->jpeg_quality );
		} elseif ( str_contains( $mime, 'png' ) ) {
			// PNG max deflate: set_quality(9) maps to maximum lossless compression (CMP-02).
			$editor->set_quality( 9 );
		}

		// Overwrite the original in place (D-04).
		$result = $editor->save( $path );
		if ( is_wp_error( $result ) ) {
			// Best-effort file restore: rename backup back over the (possibly partially-written)
			// original so the attachment is not left in a corrupted state (CR-03 / D-03).
			$this->backup_manager->restore_backup_file_only( $attachment_id );
			return array(
				'ok'     => false,
				'reason' => 'save_failed',
			);
		}

		// Pitfall 2: flush stat cache after save so sha1_file/filesize read the new bytes.
		clearstatcache( true, $path );

		$bytes_after = isset( $result['filesize'] ) && (int) $result['filesize'] > 0
			? (int) $result['filesize']
			: (int) filesize( $path );

		// Write content_hash OUTSIDE hook scope (D-06 / T-09-08).
		// Guard against sha1_file() returning false on an unreadable file (WR-01):
		// (string) false === '' would write an empty hash, disabling the skip-on-match
		// guard and causing the attachment to be re-encoded on every subsequent call.
		$post_hash = sha1_file( $path );
		if ( false !== $post_hash ) {
			$this->optimization_index->update_content_hash( $attachment_id, $post_hash );
		}

		// Explicit structural index sync (D-09): must not rely on IndexHooks firing.
		MediaIndex::from_wordpress()->upsert_structural(
			MediaRow::from_attachment(
				$attachment_id,
				basename( $path ),
				'',   // title resolved fresh by upsert_structural; index re-derives on read.
				'',   // alt.
				'',   // caption.
				'',   // description.
				$mime,
				array(), // meta — let index re-read from WP.
				$bytes_after,
				0,    // uploaded_by.
				'',   // uploaded_at.
				current_time( 'mysql' )
			)
		);

		// Record squeeze row with bytes_before/after (CMP-04).
		$this->optimization_index->upsert(
			array(
				'attachment_id'   => $attachment_id,
				'status'          => \AssetDrips\Squeeze\OptimizationRecord::COMPLETE,
				'original_bytes'  => $bytes_before,
				'optimized_bytes' => $bytes_after,
			)
		);

		return array(
			'ok'           => true,
			'bytes_before' => $bytes_before,
			'bytes_after'  => $bytes_after,
		);
	}

	/**
	 * Resize an oversized attachment's original proportionally to fit within max_dimension.
	 *
	 * Sequence (D-10 / RSZ-01):
	 *  1. Load editor; read size.
	 *  2. If longest edge <= max_dimension, return was_oversized=false (no-op).
	 *  3. Backup FIRST.
	 *  4. resize($max, $max, false) + save() in place.
	 *  5. Build full metadata array; wp_update_attachment_metadata().
	 *  6. Explicit upsert_structural() (D-09).
	 *  7. Clear is_oversized flag via update_media_index_flags($id, false, false, false).
	 *
	 * @param int      $attachment_id Attachment post ID.
	 * @param int|null $max_dimension Max pixel cap; null uses SqueezeSettings::$max_dimension.
	 * @return array<string, mixed> Result array; keys: ok, was_oversized, bytes_before, bytes_after, reason.
	 */
	public function resize_original( int $attachment_id, ?int $max_dimension = null ): array {
		// Load image-editor class files for cron/CLI contexts (Pitfall 3).
		if ( file_exists( ABSPATH . 'wp-admin/includes/image.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$path = get_attached_file( $attachment_id );
		if ( ! is_string( $path ) || '' === $path ) {
			return array(
				'ok'     => false,
				'reason' => 'no_file',
			);
		}

		$editor = ( $this->editor_factory )( $path );
		if ( is_wp_error( $editor ) ) {
			return array(
				'ok'     => false,
				'reason' => 'editor_unavailable',
			);
		}

		$size         = $editor->get_size();
		$width        = (int) ( $size['width'] ?? 0 );
		$height       = (int) ( $size['height'] ?? 0 );
		$max          = $max_dimension ?? SqueezeSettings::load()->max_dimension;
		$longest_edge = max( $width, $height );

		// Within cap: no resize needed (RSZ-01 not-oversized path).
		if ( $longest_edge <= $max ) {
			return array(
				'ok'            => true,
				'was_oversized' => false,
			);
		}

		// Guard file existence before filesize() — a missing file silently returns 0
		// which would corrupt the bytes_before record (WR-04).
		if ( ! file_exists( $path ) ) {
			return array(
				'ok'            => false,
				'was_oversized' => true,
				'reason'        => 'file_missing',
			);
		}
		$bytes_before = (int) filesize( $path );

		// Backup FIRST — never encode without a verified backup (D-03 / D-07).
		$backup_ok = $this->backup_manager->backup( $attachment_id, $path, 'resize' );
		if ( ! $backup_ok ) {
			return array(
				'ok'            => false,
				'was_oversized' => true,
				'reason'        => 'backup_failed',
			);
		}

		// Proportional resize to fit within $max × $max bounds; $crop=false (D-10).
		$editor->resize( $max, $max, false );
		$result = $editor->save( $path );

		if ( is_wp_error( $result ) ) {
			// Best-effort file restore: rename backup back over the (possibly partially-written)
			// original so the attachment is not left in a corrupted state (CR-03 / D-03).
			$this->backup_manager->restore_backup_file_only( $attachment_id );
			return array(
				'ok'            => false,
				'was_oversized' => true,
				'reason'        => 'save_failed',
			);
		}

		// Flush stat cache after save (Pitfall 2).
		clearstatcache( true, $path );

		$bytes_after = isset( $result['filesize'] ) && (int) $result['filesize'] > 0
			? (int) $result['filesize']
			: (int) filesize( $path );

		$new_width  = (int) ( $result['width'] ?? $max );
		$new_height = (int) ( $result['height'] ?? $max );

		// Atomically update _wp_attachment_metadata with the full array (D-10 / Pitfall §6).
		$existing_meta             = (array) wp_get_attachment_metadata( $attachment_id );
		$existing_meta['width']    = $new_width;
		$existing_meta['height']   = $new_height;
		$existing_meta['filesize'] = $bytes_after;
		wp_update_attachment_metadata( $attachment_id, $existing_meta );

		// Explicit structural sync (D-09) — belt-and-suspenders over the hook.
		$mime = (string) get_post_mime_type( $attachment_id );
		MediaIndex::from_wordpress()->upsert_structural(
			MediaRow::from_attachment(
				$attachment_id,
				basename( $path ),
				'',
				'',
				'',
				'',
				$mime,
				$existing_meta,
				$bytes_after,
				0,
				'',
				current_time( 'mysql' )
			)
		);

		// Clear is_oversized flag — original is now within cap (D-10).
		$this->optimization_index->update_media_index_flags( $attachment_id, false, false, false );

		return array(
			'ok'            => true,
			'was_oversized' => true,
			'bytes_before'  => $bytes_before,
			'bytes_after'   => $bytes_after,
		);
	}

	/**
	 * Regenerate all registered sub-sizes from the (restored) original on disk.
	 *
	 * Uses wp_create_image_subsizes() (NOT wp_generate_attachment_metadata) — the
	 * distinction prevents SqueezeHooks from re-queuing the attachment for
	 * optimization immediately after restore (T-09-09 / D-08).
	 *
	 * Merges the returned sizes array into the existing metadata and persists the
	 * full array via wp_update_attachment_metadata() so width/height/filesize
	 * reflect the restored original.
	 *
	 * @param int $attachment_id Attachment post ID.
	 * @return array<string, mixed> Result; keys: ok, regenerated (count of sizes).
	 */
	public function regenerate_sizes( int $attachment_id ): array {
		// Load image-editor class files for cron/CLI contexts (Pitfall 3).
		if ( file_exists( ABSPATH . 'wp-admin/includes/image.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$path = get_attached_file( $attachment_id );
		if ( ! is_string( $path ) || '' === $path ) {
			return array(
				'ok'     => false,
				'reason' => 'no_file',
			);
		}
		// Regenerate registered sub-sizes from the restored original (D-08).
		// wp_create_image_subsizes does NOT fire wp_generate_attachment_metadata filter (T-09-09).
		$sizes = wp_create_image_subsizes( $path, $attachment_id );

		// Merge into the full existing metadata.
		$existing_meta = (array) wp_get_attachment_metadata( $attachment_id );

		// Re-derive dimensions from the restored file on disk, if editor is available.
		if ( is_string( $path ) && '' !== $path && file_exists( $path ) ) {
			$editor = ( $this->editor_factory )( $path );
			if ( ! is_wp_error( $editor ) ) {
				$sz                      = $editor->get_size();
				$existing_meta['width']  = (int) ( $sz['width'] ?? 0 );
				$existing_meta['height'] = (int) ( $sz['height'] ?? 0 );
			}
			clearstatcache( true, $path );
			$existing_meta['filesize'] = (int) filesize( $path );
		}

		$existing_meta['sizes'] = is_array( $sizes ) ? $sizes : array();

		wp_update_attachment_metadata( $attachment_id, $existing_meta );

		return array(
			'ok'          => true,
			'regenerated' => count( $existing_meta['sizes'] ),
		);
	}

	/**
	 * Additively repair missing registered sub-sizes for an attachment (SIZE-02 / D-03 / D-07).
	 *
	 * Uses wp_update_image_subsizes() — the only safe additive WP 5.3+ API that fills only
	 * the registered sizes absent from the attachment's metadata, never clobbers existing
	 * custom crops, and never touches the original file (D-07).
	 *
	 * CONTRAST with regenerate_sizes(): that method uses wp_create_image_subsizes() (full
	 * regen, no filter fire).  This method uses wp_update_image_subsizes(), which fires
	 * the wp_generate_attachment_metadata filter with context='update'.  SqueezeHooks::on_meta()
	 * ignores the context parameter (D-03 / 13-RESEARCH.md Pitfall 1), so this method guards
	 * against inadvertent cron scheduling by wrapping the call in a try/finally that sets
	 * SqueezeHooks::set_bypass(true) before and set_bypass(false) after — T-13-06.
	 *
	 * NEVER calls wp_create_image_subsizes() or wp_generate_attachment_metadata() here.
	 *
	 * @param int $attachment_id Attachment post ID.
	 * @return array<string, mixed> On success: ['ok' => true, 'regenerated' => int].
	 *                              On WP_Error: ['ok' => false, 'reason' => string (error code)].
	 */
	public function repair_missing_sizes( int $attachment_id ): array {
		// Load image-admin functions for cron/CLI contexts (wp_update_image_subsizes lives in
		// wp-admin/includes/image.php — same guard used by regenerate_sizes()).
		if ( file_exists( ABSPATH . 'wp-admin/includes/image.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		// Suppress SqueezeHooks::on_meta() scheduling during the additive repair
		// (D-03 / T-13-06). The finally block guarantees the flag is always cleared.
		SqueezeHooks::set_bypass( true );
		try {
			$result = wp_update_image_subsizes( $attachment_id );
		} finally {
			SqueezeHooks::set_bypass( false );
		}

		if ( is_wp_error( $result ) ) {
			return array(
				'ok'     => false,
				'reason' => $result->get_error_code(),
			);
		}

		return array(
			'ok'          => true,
			'regenerated' => count( $result['sizes'] ?? array() ),
		);
	}

	/**
	 * Generate WebP sibling files for an attachment's original and all registered sub-sizes.
	 *
	 * Sequence (NGN-01 / D-01 / D-02 / D-03 / D-04 / D-07 / D-08):
	 *  1. Gate on CapabilityProbe::get()['webp'] — return skipped if unavailable.
	 *  2. Resolve original path via get_attached_file().
	 *  3. Build $sources = [original, ...sub-sizes] mirroring cleanup_alternates() (D-01/D-02).
	 *  4. For each source: load fresh editor, apply palette-PNG truecolor guard (GD only,
	 *     full-size only), save() to {source}.webp, apply mandatory filesize>0 gate (D-07).
	 *  5. Post-loop: read is_oversized via get_flags() (D-08), write flags preserving
	 *     has_avif + is_oversized; upsert squeeze row; return result.
	 *
	 * NEVER calls BackupManager. NEVER mutates the original or _wp_attachment_metadata (D-04).
	 *
	 * Threat mitigations:
	 *  - T-10-10: source paths resolved only via get_attached_file(int $id) + dirname/sizes
	 *    metadata — never a caller-supplied path; siblings are append-extension only.
	 *  - T-10-11: has_webp set true only after file_exists($sibling) && filesize($sibling) > 0
	 *    on the FULL-SIZE original (D-08).
	 *  - T-10-14: no BackupManager call; no _wp_attachment_metadata mutation.
	 *
	 * @param int $attachment_id Attachment post ID.
	 * @return array<string, mixed> Result; keys: ok, bytes, webp_path — or ok+skipped+reason on skip.
	 */
	public function generate_webp( int $attachment_id ): array {
		$caps = CapabilityProbe::get( $this->editor_factory );
		if ( ! $caps['webp'] ) {
			return array(
				'ok'      => false,
				'skipped' => true,
				'reason'  => 'webp_unsupported',
			);
		}

		// Load image-editor class files for cron/CLI contexts where admin is not loaded (Pitfall 3).
		if ( file_exists( ABSPATH . 'wp-admin/includes/image.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$original_path = get_attached_file( $attachment_id );
		if ( ! is_string( $original_path ) || '' === $original_path ) {
			return array(
				'ok'     => false,
				'reason' => 'no_file',
			);
		}

		// Build $sources: original + all registered sub-sizes (D-02).
		// Pattern mirrors cleanup_alternates() lines 471-481 — authoritative for D-01/D-02.
		$sources = array( $original_path );
		$meta    = (array) wp_get_attachment_metadata( $attachment_id );
		if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
			$dir = dirname( $original_path );
			foreach ( $meta['sizes'] as $size_data ) {
				if ( ! empty( $size_data['file'] ) ) {
					// CR-02 / T-10-10: bind to basename() so a metadata 'file' value
					// containing path separators cannot escape the size directory.
					$sources[] = $dir . '/' . basename( (string) $size_data['file'] );
				}
			}
		}

		$full_size_ok = false;
		$total_bytes  = 0;

		foreach ( $sources as $idx => $source_path ) {
			$sibling      = $source_path . '.webp'; // D-01: append-extension naming matching cleanup_alternates().
			$is_full_size = ( 0 === $idx );

			// Load a fresh editor for each (source, format) combination (PITFALLS §3).
			$editor = ( $this->editor_factory )( $source_path );
			if ( is_wp_error( $editor ) ) {
				continue;
			}

			// D-07 / PITFALLS §2: palette-PNG truecolor guard — full-size original ONLY.
			// Sub-sizes are safe because WP's GD resize auto-converts to truecolor.
			// Detection: CapabilityProbe::get()['gd'] indicates GD is the active backend.
			// Identity-resize at current dimensions forces truecolor conversion in-place
			// (WP_Image_Editor_GD::resize() builds the image via imagecreatetruecolor and
			// stores it in $this->image). CR-01: keep THIS editor and save it — do NOT
			// reload from disk, which would discard the conversion and re-encode the
			// still-palette original (0-byte / colour-broken WebP on GD hosts).
			if ( $is_full_size && $caps['gd'] ) {
				$sz = $editor->get_size();
				$editor->resize( (int) ( $sz['width'] ?? 1 ), (int) ( $sz['height'] ?? 1 ), false );
			}

			$result = $editor->save( $sibling, 'image/webp' );

			// Pitfall 2: flush stat cache so filesize() reads the freshly written sibling.
			clearstatcache( true, $sibling );

			// D-07 / D-06: mandatory post-encode 0-byte verification — hard safety net.
			if ( is_wp_error( $result ) || ! file_exists( $sibling ) || (int) filesize( $sibling ) === 0 ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink -- Best-effort cleanup; 0-byte sibling must be removed.
				@unlink( $sibling );
				continue;
			}

			if ( $is_full_size ) {
				$full_size_ok = true;
			}
			$total_bytes += (int) filesize( $sibling );
		}

		// D-08: Read current is_oversized and has_avif via get_flags() before writing flags.
		// Preserves both is_oversized and the existing has_avif value — only sets has_webp.
		$existing_flags = $this->optimization_index->get_flags( $attachment_id );
		$is_oversized   = (bool) ( $existing_flags['is_oversized'] ?? false );
		$has_avif       = (bool) ( $existing_flags['has_avif'] ?? false );
		$this->optimization_index->update_media_index_flags( $attachment_id, $full_size_ok, $has_avif, $is_oversized );

		// Record in squeeze row (webp_bytes + ops_completed).
		$this->optimization_index->upsert(
			array(
				'attachment_id' => $attachment_id,
				'status'        => OptimizationRecord::COMPLETE,
				'webp_bytes'    => $total_bytes,
				'ops_completed' => json_encode( $full_size_ok ? array( 'webp' ) : array( 'webp_skipped' ) ),
			)
		);

		return array(
			'ok'        => $full_size_ok,
			'bytes'     => $total_bytes,
			// WR-02: only advertise a path when the full-size sibling was actually written.
			// On a failed full-size encode the sibling was unlinked, so the path would
			// point at a missing file for callers that read webp_path without checking ok.
			'webp_path' => $full_size_ok ? $original_path . '.webp' : null,
		);
	}

	/**
	 * Generate AVIF sibling files for an attachment's original and all registered sub-sizes.
	 *
	 * Sequence (NGN-02 / D-01 / D-02 / D-05 / D-06 / D-08 / D-10):
	 *  1. Gate on CapabilityProbe::get()['avif'] — return skipped (no error) if unavailable (D-05).
	 *  2. Resolve original path; build $sources mirroring generate_webp().
	 *  3. For each source: inject heic:speed via image_editor_save_pre filter; start microtime
	 *     budget (D-10); save() to {source}.avif; check budget after save (abort to avif_skipped
	 *     on overrun); apply mandatory filesize>0 gate (D-06 — degrade, not error, on 0 bytes).
	 *  4. Post-loop: read flags, write has_avif preserving has_webp + is_oversized (D-08); upsert.
	 *
	 * NEVER calls BackupManager. NEVER mutates the original or _wp_attachment_metadata (D-04).
	 * Batch-safe: no set_time_limit(), no global state beyond scoped add_filter/remove_filter.
	 *
	 * Threat mitigations:
	 *  - T-10-12: per-image microtime budget + heic:speed preset; method is batch-safe.
	 *  - T-10-13: gates on CapabilityProbe::get()['avif'] live-probe — never queryFormats().
	 *  - T-10-14: no BackupManager call; no _wp_attachment_metadata mutation.
	 *
	 * @param int        $attachment_id  Attachment post ID.
	 * @param float|null $avif_time_budget Per-image time budget in seconds; null uses constructor default (D-10).
	 * @return array<string, mixed> Result; keys: ok, bytes, avif_path — or ok+skipped+reason on skip.
	 */
	public function generate_avif( int $attachment_id, ?float $avif_time_budget = null ): array {
		$budget = $avif_time_budget ?? $this->avif_time_budget;
		$caps   = CapabilityProbe::get( $this->editor_factory );

		// Load image-editor class files for cron/CLI contexts where admin is not loaded (Pitfall 3).
		if ( file_exists( ABSPATH . 'wp-admin/includes/image.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		// WR-05: resolve the file FIRST. A fileless attachment must not receive a
		// COMPLETE squeeze row claiming avif_skipped — it has no file to skip.
		$original_path = get_attached_file( $attachment_id );
		if ( ! is_string( $original_path ) || '' === $original_path ) {
			return array(
				'ok'     => false,
				'reason' => 'no_file',
			);
		}

		// D-05: Gate on live CapabilityProbe result — NEVER queryFormats()/wp_image_editor_supports().
		// If AVIF is unavailable, record avif_skipped (single upsert) and return gracefully with NO
		// error (NGN-02). Resolved after the file check so this only fires for a real attachment file.
		if ( ! $caps['avif'] ) {
			$this->optimization_index->upsert(
				array(
					'attachment_id' => $attachment_id,
					'status'        => OptimizationRecord::COMPLETE,
					'ops_completed' => json_encode( array( 'avif_skipped' ) ),
				)
			);
			return array(
				'ok'      => false,
				'skipped' => true,
				'reason'  => 'avif_unsupported',
			);
		}

		// Build $sources: original + all registered sub-sizes (D-02 / cleanup_alternates() pattern).
		$sources = array( $original_path );
		$meta    = (array) wp_get_attachment_metadata( $attachment_id );
		if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
			$dir = dirname( $original_path );
			foreach ( $meta['sizes'] as $size_data ) {
				if ( ! empty( $size_data['file'] ) ) {
					// CR-02 / T-10-10: bind to basename() so a metadata 'file' value
					// containing path separators cannot escape the size directory.
					$sources[] = $dir . '/' . basename( (string) $size_data['file'] );
				}
			}
		}

		$full_size_ok      = false;
		$total_bytes       = 0;
		$budget_hit        = false;
		$zero_byte_full    = false; // D-06: tracks whether the full-size AVIF was 0-byte (degraded to skipped).
		$editor_error_full = false; // WR-04: tracks a full-size editor-factory failure (degrade to avif_skipped).

		foreach ( $sources as $idx => $source_path ) {
			$sibling      = $source_path . '.avif'; // D-01: append-extension naming.
			$is_full_size = ( 0 === $idx );

			// Load a fresh editor for each (source, format) combination (PITFALLS §3).
			$editor = ( $this->editor_factory )( $source_path );
			if ( is_wp_error( $editor ) ) {
				// D-06 / WR-04: per-image error degrades to skipped — route the full-size
				// failure through the same degrade branch as 0-byte output so all
				// "no AVIF produced" outcomes return a consistent skipped=true shape.
				if ( $is_full_size ) {
					$editor_error_full = true;
				}
				continue;
			}

			// D-07 / PITFALLS §2: palette-PNG truecolor guard — full-size original ONLY,
			// mirroring generate_webp(). Identity-resize forces GD truecolor conversion
			// in-place; CR-01: keep THIS editor and encode it (no reload-from-disk, which
			// would discard the conversion and risk a 0-byte / colour-broken AVIF on GD).
			if ( $is_full_size && $caps['gd'] ) {
				$sz = $editor->get_size();
				$editor->resize( (int) ( $sz['width'] ?? 1 ), (int) ( $sz['height'] ?? 1 ), false );
			}

			// D-10 / WR-03: inject heic:speed directly on the live editor's Imagick object
			// when the backend exposes it. The previously-used image_editor_save_pre filter
			// was a no-op: that filter passes the image *data stream*, never the editor or
			// the underlying Imagick instance, so setOption() was never reachable. The
			// microtime budget below is the operative runaway-encode guard; this preset is a
			// best-effort speed hint applied only when the Imagick object is genuinely reachable.
			$this->maybe_set_heic_speed( $editor );

			// D-10: Start per-image microtime budget.
			// WR-06: wrap save() in try/finally so the budget is always measured even if
			// save() throws (Imagick can throw on broken libheif); a thrown save degrades
			// to avif_skipped via the catch rather than aborting the whole batch.
			$t_start = microtime( true );
			try {
				$result = $editor->save( $sibling, 'image/avif' );
			} catch ( \Throwable $e ) {
				unset( $e );
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink -- Best-effort cleanup; save() threw mid-encode.
				@unlink( $sibling );
				if ( $is_full_size ) {
					$zero_byte_full = true; // Degrade the whole op to avif_skipped (consistent shape).
				}
				continue;
			}

			// D-10: Check time budget AFTER save() returns (save is synchronous, so the
			// budget is a best-effort post-hoc guard — true preemption is not achievable
			// in synchronous PHP; WR-06).
			if ( ( microtime( true ) - $t_start ) > $budget ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink -- Best-effort cleanup; time-budget abort.
				@unlink( $sibling );
				$budget_hit = true;
				break; // Stop processing remaining sources (D-10: abort on overrun).
			}

			// Pitfall 2: flush stat cache so filesize() reads the freshly written sibling.
			clearstatcache( true, $sibling );

			// D-06: mandatory post-encode 0-byte verification — degrade to skipped, not error.
			if ( is_wp_error( $result ) || ! file_exists( $sibling ) || (int) filesize( $sibling ) === 0 ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink -- Best-effort cleanup; 0-byte sibling must be removed.
				@unlink( $sibling );
				if ( $is_full_size ) {
					$zero_byte_full = true; // D-06: full-size 0-byte → degrade the whole op to avif_skipped.
				}
				continue;
			}

			if ( $is_full_size ) {
				$full_size_ok = true;
			}
			$total_bytes += (int) filesize( $sibling );
		}

		if ( $budget_hit || $zero_byte_full || $editor_error_full ) {
			$this->optimization_index->upsert(
				array(
					'attachment_id' => $attachment_id,
					'status'        => OptimizationRecord::COMPLETE,
					'ops_completed' => json_encode( array( 'avif_skipped' ) ),
				)
			);
			if ( $budget_hit ) {
				$reason = 'avif_time_budget_exceeded';
			} elseif ( $editor_error_full ) {
				$reason = 'avif_editor_unavailable';
			} else {
				$reason = 'avif_zero_byte_output';
			}
			return array(
				'ok'      => false,
				'skipped' => true,
				'reason'  => $reason,
			);
		}

		// D-08: Read current has_webp and is_oversized via get_flags() before writing flags.
		// Preserves both has_webp and is_oversized — only sets has_avif.
		$existing_flags = $this->optimization_index->get_flags( $attachment_id );
		$is_oversized   = (bool) ( $existing_flags['is_oversized'] ?? false );
		$has_webp       = (bool) ( $existing_flags['has_webp'] ?? false );
		$this->optimization_index->update_media_index_flags( $attachment_id, $has_webp, $full_size_ok, $is_oversized );

		// Record in squeeze row (avif_bytes + ops_completed).
		$this->optimization_index->upsert(
			array(
				'attachment_id' => $attachment_id,
				'status'        => OptimizationRecord::COMPLETE,
				'avif_bytes'    => $total_bytes,
				'ops_completed' => json_encode( $full_size_ok ? array( 'avif' ) : array( 'avif_skipped' ) ),
			)
		);

		return array(
			'ok'        => $full_size_ok,
			'bytes'     => $total_bytes,
			// WR-02: only advertise a path when the full-size sibling was actually written.
			// On a failed full-size encode the sibling was unlinked, so the path would
			// point at a missing file for callers that read avif_path without checking ok.
			'avif_path' => $full_size_ok ? $original_path . '.avif' : null,
		);
	}

	/**
	 * Best-effort heic:speed preset injection on the live editor's Imagick object (D-10 / WR-03).
	 *
	 * The previous implementation registered an image_editor_save_pre filter, but that
	 * WordPress filter passes the raw image data stream — never the WP_Image_Editor
	 * instance or the underlying Imagick object — so the setOption() call was a silent
	 * no-op in production. This helper instead inspects the live editor for a reachable
	 * Imagick instance and sets the option directly when one is exposed. When the backend
	 * is GD (or otherwise does not expose Imagick), this is a harmless no-op and the
	 * microtime budget in generate_avif() remains the operative runaway-encode guard.
	 *
	 * Routing through the editor object (rather than instantiating Imagick directly)
	 * preserves the T-09-06 "all encode goes through WP_Image_Editor" contract.
	 *
	 * @param object $editor Live WP_Image_Editor instance from the editor factory.
	 * @return void
	 */
	private function maybe_set_heic_speed( object $editor ): void {
		$imagick = null;

		// Some WP_Image_Editor_Imagick builds expose the Imagick handle directly.
		if ( property_exists( $editor, 'image' ) && $editor->image instanceof \Imagick ) {
			$imagick = $editor->image;
		} elseif ( method_exists( $editor, 'getImagick' ) ) {
			$candidate = $editor->getImagick();
			if ( $candidate instanceof \Imagick ) {
				$imagick = $candidate;
			}
		}

		if ( $imagick instanceof \Imagick ) {
			$imagick->setOption( 'heic:speed', '6' );
		}
	}

	/**
	 * Delete WebP and AVIF alternate files for an attachment (D-05).
	 *
	 * Computes expected .webp and .avif sibling paths for the original and all
	 * registered sizes, then @unlink()s each. No-op-safe: @unlink suppresses the
	 * warning when a file does not exist (Phase 10 has not generated any yet).
	 *
	 * @param int $attachment_id Attachment post ID.
	 * @return void
	 */
	public function cleanup_alternates( int $attachment_id ): void {
		$path = get_attached_file( $attachment_id );
		if ( ! is_string( $path ) || '' === $path ) {
			return;
		}

		// Collect all paths whose alternates should be removed.
		$paths_to_clean = array( $path );

		$meta = (array) wp_get_attachment_metadata( $attachment_id );
		if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
			$dir = dirname( $path );
			foreach ( $meta['sizes'] as $size_data ) {
				if ( ! empty( $size_data['file'] ) ) {
					// CR-02 / T-10-10: bind to basename() so a metadata 'file' value
					// containing path separators cannot escape the size directory on delete.
					$paths_to_clean[] = $dir . '/' . basename( (string) $size_data['file'] );
				}
			}
		}

		foreach ( $paths_to_clean as $orig_path ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink -- Best-effort cleanup; file may not exist if Phase 10 has not generated any alternates yet.
			@unlink( $orig_path . '.webp' );
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink -- Best-effort cleanup; file may not exist.
			@unlink( $orig_path . '.avif' );
		}
	}
}
