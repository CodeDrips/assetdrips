<?php
/**
 * Auto-on-upload async scheduling hook for Squeeze.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Squeeze;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the wp_generate_attachment_metadata filter that schedules a
 * single-fire async cron event (assetdrips_squeeze_single) when a new image
 * is uploaded and at least one auto_* op toggle is enabled.
 *
 * This class ONLY schedules — it never encodes, backs up, or upserts inline
 * (D-05, ARCHITECTURE Anti-Pattern 2). The heavy work is done asynchronously
 * by SqueezeJob::run_single() via the WP-Cron event.
 *
 * TRG-03: opt-in async auto-optimize on upload, never blocking the upload
 * response, deduped against the N+1 wp_generate_attachment_metadata fires and
 * third-party re-triggers (Regenerate Thumbnails, etc.).
 */
final class SqueezeHooks {

	/**
	 * Static bypass flag used by SqueezeEngine::repair_missing_sizes() to prevent
	 * on_meta() from scheduling auto-optimization cron events during additive repair.
	 *
	 * The WP function wp_update_image_subsizes() fires wp_generate_attachment_metadata
	 * with context='update'; on_meta() ignores that context (D-03 / 13-RESEARCH.md Pitfall 1).
	 * SqueezeEngine wraps its call in try/finally — setting this flag true before and
	 * false after — so the re-fire cannot inadvertently schedule an optimization cron.
	 *
	 * Access is restricted to set_bypass() so callers cannot leave the flag stuck true
	 * except through their own try/finally discipline.
	 *
	 * @var bool
	 */
	private static bool $bypass_hook = false;

	/**
	 * Set or clear the static bypass flag (D-03 / T-13-06).
	 *
	 * Call set_bypass(true) immediately before wp_update_image_subsizes() and
	 * set_bypass(false) in a finally block to guarantee the flag is always cleared.
	 *
	 * @param bool $bypass True to suppress on_meta() scheduling; false to restore normal behaviour.
	 * @return void
	 */
	public static function set_bypass( bool $bypass ): void {
		self::$bypass_hook = $bypass;
	}

	/**
	 * Register the auto-on-upload scheduling filter.
	 *
	 * Priority 20: fires AFTER IndexHooks::on_meta (priority 10) so the index
	 * row exists before we enqueue the Squeeze job (D-06).
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'wp_generate_attachment_metadata', array( $this, 'on_meta' ), 20, 3 );
	}

	/**
	 * Schedule an async single-fire Squeeze event for the uploaded attachment.
	 *
	 * Gates (all must pass before scheduling):
	 *   1. At least one per-op auto_* toggle is enabled (D-06, Pitfall 3).
	 *   2. The attachment MIME type starts with 'image/' (non-images skip Squeeze).
	 *   3. No event for this attachment is already queued (D-07, Pitfall 4 dedup).
	 *
	 * Never encodes, backs up, or upserts inline — it ONLY schedules (D-05).
	 * Always returns $meta unchanged because this is a filter, not an action (TRG-03).
	 *
	 * @param mixed  $meta    Generated attachment metadata (array when present).
	 * @param int    $id      Attachment post ID.
	 * @param string $context Generation context ('create' | 'update').
	 * @return mixed The $meta argument, unchanged.
	 */
	public function on_meta( $meta, int $id, string $context ): mixed {
		unset( $context );

		// Bypass gate (D-03 / T-13-06): SqueezeEngine::repair_missing_sizes() sets this
		// flag true before wp_update_image_subsizes() fires this filter with context='update'.
		// Returning early here prevents an inadvertent cron scheduling during additive repair.
		if ( self::$bypass_hook ) {
			return $meta;
		}

		if ( ! $this->is_auto_enabled() ) {
			return $meta;
		}

		$mime = (string) get_post_mime_type( $id );
		if ( ! str_starts_with( $mime, 'image/' ) ) {
			return $meta;
		}

		// Dedup guard (D-07, Pitfall 4): args MUST be array($id) for BOTH calls so
		// WP's serialize($args) keying matches the stored event. Passing $id without
		// wrapping it in an array causes the check to never match, duplicating events.
		if ( ! wp_next_scheduled( 'assetdrips_squeeze_single', array( $id ) ) ) {
			wp_schedule_single_event( time(), 'assetdrips_squeeze_single', array( $id ) );
		}

		return $meta;
	}

	/**
	 * Return true when at least one per-op auto_* toggle is enabled.
	 *
	 * Pitfall 3 (11-RESEARCH.md): there is NO single 'assetdrips_squeeze_auto'
	 * option. SqueezeSettings exposes four separate booleans — auto_recompress,
	 * auto_webp, auto_avif, auto_resize — all defaulting to false (FND-04, D-09).
	 *
	 * @return bool
	 */
	private function is_auto_enabled(): bool {
		$s = SqueezeSettings::load();
		return $s->auto_recompress || $s->auto_webp || $s->auto_avif || $s->auto_resize;
	}
}
