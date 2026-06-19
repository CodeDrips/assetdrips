<?php
/**
 * Server capability probe for image encoding support.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Squeeze;

defined( 'ABSPATH' ) || exit;

/**
 * Live 1×1 px encode probe that populates the server capability map.
 *
 * Capability detection for AVIF (and WebP) MUST be a live encode probe — not
 * Imagick::queryFormats() and not wp_image_editor_supports() alone. Both of
 * those return truthy values on hosts where libheif is present but has no AV1
 * encoder backend, so the encode silently produces a 0-byte or corrupt file
 * (D-05, Pitfall 1 — false-positive AVIF on shared hosts).
 *
 * The probe result is cached as a WEEK_IN_SECONDS transient (D-06). On cache
 * miss the probe runs lazily when the settings page is loaded via get(). A
 * manual "Re-check capabilities" action calls invalidate() to force a fresh
 * probe on the next get() call.
 *
 * IMPORTANT: get() must NEVER be called on hot paths (admin_init, front-end
 * requests, etc.). Invoke it only in the settings-page render() method. A
 * missing transient triggers a live image encode which is costly (Pitfall 5).
 */
final class CapabilityProbe {

	/**
	 * Transient key for the cached capability map (D-06).
	 */
	private const TRANSIENT_KEY = 'assetdrips_squeeze_caps';

	/**
	 * Transient TTL: one week. Re-probe happens on cache miss or after invalidate() (D-06).
	 */
	private const TTL = WEEK_IN_SECONDS;

	/**
	 * Returns the capability map, probing only when the cache is cold.
	 *
	 * Caches the result in a WEEK_IN_SECONDS transient on a cache miss. On a
	 * cache hit the transient value is returned directly without re-probing.
	 *
	 * IMPORTANT: Do not call this on hot admin paths or front-end requests.
	 * The probe performs live image encoding — it is expensive (Pitfall 5).
	 *
	 * @param callable|null $editor_factory Optional factory for testability (see run()).
	 * @return array{webp: bool, avif: bool, imagick: bool, gd: bool}
	 */
	public static function get( ?callable $editor_factory = null ): array {
		$cached = get_transient( self::TRANSIENT_KEY );
		if ( is_array( $cached )
			&& isset( $cached['webp'], $cached['avif'], $cached['imagick'], $cached['gd'] )
		) {
			return $cached;
		}
		$caps = self::run( $editor_factory );
		set_transient( self::TRANSIENT_KEY, $caps, self::TTL );
		return $caps;
	}

	/**
	 * Invalidates the capability cache so the next call to get() re-probes.
	 *
	 * Call this from the "Re-check capabilities" admin action handler.
	 *
	 * @return void
	 */
	public static function invalidate(): void {
		delete_transient( self::TRANSIENT_KEY );
	}

	/**
	 * Runs the live encode probe and returns the capability map.
	 *
	 * Implementation notes:
	 * - Requires WP image-editor classes via require_once for cron/CLI safety (A2).
	 * - Creates a 1×1 PNG source file in the system temp directory via wp_tempnam().
	 * - Reloads a fresh WP_Image_Editor instance between WebP and AVIF probes to
	 *   avoid Imagick state leakage after save() (A1).
	 * - Caps are set true ONLY when: !is_wp_error($result) AND file_exists($out)
	 *   AND filesize($out) > 0. The filesize > 0 gate defeats hosts where libheif
	 *   has no AV1 backend and Imagick writes a 0-byte output (D-05).
	 * - Imagick exceptions (from bad codec config) are caught per-format to prevent
	 *   a single bad encode from crashing the whole probe (Pitfall 2).
	 * - All temp files (source + both outputs) are cleaned up in a try/finally
	 *   block with @unlink so no orphaned files remain after a PHP fatal (Pitfall 2,
	 *   threat T-08-04).
	 * - The optional $editor_factory replaces the wp_get_image_editor() call for
	 *   unit-test injection without a live WordPress install (A1, RESEARCH line 933).
	 *
	 * @param callable|null $editor_factory Optional factory that replaces wp_get_image_editor().
	 *                                      Signature: callable(string $path): \WP_Image_Editor|\WP_Error.
	 *                                      When null, wp_get_image_editor() is used.
	 * @return array{webp: bool, avif: bool, imagick: bool, gd: bool}
	 */
	public static function run( ?callable $editor_factory = null ): array {
		// Load image-editor class files for cron/CLI contexts where admin is not loaded (A2).
		if ( file_exists( ABSPATH . 'wp-admin/includes/image.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		if ( file_exists( ABSPATH . 'wp-admin/includes/class-wp-image-editor.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-image-editor.php';
		}
		if ( file_exists( ABSPATH . 'wp-admin/includes/class-wp-image-editor-imagick.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-image-editor-imagick.php';
		}
		if ( file_exists( ABSPATH . 'wp-admin/includes/class-wp-image-editor-gd.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-image-editor-gd.php';
		}

		$caps = array(
			'webp'    => false,
			'avif'    => false,
			'imagick' => extension_loaded( 'imagick' ),
			'gd'      => extension_loaded( 'gd' ),
		);

		// Resolve the editor factory: use provided callable or fall back to wp_get_image_editor.
		$factory = $editor_factory ?? 'wp_get_image_editor';

		// Create the 1×1 px PNG source file in the system temp directory.
		// wp_tempnam() ensures a unique, safe path outside the uploads directory (T-08-04).
		$tmp_src = wp_tempnam( 'squeeze-probe', sys_get_temp_dir() ) . '.png';

		$tmp_webp = $tmp_src . '.webp';
		$tmp_avif = $tmp_src . '.avif';

		try {
			// Minimal 1×1 transparent PNG byte string — no GD/Imagick needed to create
			// the source. Used as a fallback on Imagick-only hosts where GD is absent
			// (FND-05: probe must produce a valid source to detect WebP/AVIF support).
			$png_1x1 = base64_decode( // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Inline hardcoded constant; not obfuscation.
				'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='
			);

			// Generate the 1×1 source PNG via GD when available.
			if ( extension_loaded( 'gd' ) ) {
				$img = imagecreatetruecolor( 1, 1 );
				if ( false !== $img ) {
					imagepng( $img, $tmp_src );
					imagedestroy( $img );
				}
			}

			// Fallback for Imagick-only hosts (no GD): write the hardcoded 1×1 PNG so
			// the WebP/AVIF encode probe can still run and report capabilities honestly.
			if ( ! file_exists( $tmp_src ) ) {
				file_put_contents( $tmp_src, $png_1x1 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Temp-file write in sys_get_temp_dir(); no WP filesystem abstraction available in this context.
			}

			if ( ! file_exists( $tmp_src ) ) {
				// Cannot run probe without a source file; return conservative defaults.
				return $caps;
			}

			// ---- WebP probe ----
			try {
				$editor = $factory( $tmp_src );
				if ( ! is_wp_error( $editor ) ) {
					$result       = $editor->save( $tmp_webp, 'image/webp' );
					$caps['webp'] = ! is_wp_error( $result )
									&& file_exists( $tmp_webp )
									&& filesize( $tmp_webp ) > 0;
				}
			} catch ( \Exception $e ) {
				// Imagick can throw on bad codec config; treat as webp=false.
				$caps['webp'] = false;
				error_log( 'AssetDrips CapabilityProbe WebP exception: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug-level host diagnostics only.
			}

			// ---- AVIF probe — reload editor to avoid WebP state leakage (A1) ----
			try {
				$editor = $factory( $tmp_src );
				if ( ! is_wp_error( $editor ) ) {
					$result       = $editor->save( $tmp_avif, 'image/avif' );
					$caps['avif'] = ! is_wp_error( $result )
									&& file_exists( $tmp_avif )
									&& filesize( $tmp_avif ) > 0;
				}
			} catch ( \Exception $e ) {
				// Imagick throws ImagickException on hosts with read-only libheif; treat as avif=false.
				$caps['avif'] = false;
				error_log( 'AssetDrips CapabilityProbe AVIF exception: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug-level host diagnostics only.
			}
		} finally {
			// Clean up all temp files regardless of exceptions or early returns (T-08-04, Pitfall 2).
			@unlink( $tmp_src );  // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best-effort temp-file cleanup; file may not exist if source creation failed.
			@unlink( $tmp_webp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best-effort temp-file cleanup.
			@unlink( $tmp_avif ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best-effort temp-file cleanup.
		}

		return $caps;
	}
}
