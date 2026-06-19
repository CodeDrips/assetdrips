<?php
/**
 * Next-gen serving filter: rewrites image URLs to WebP/AVIF siblings via
 * wp_get_attachment_image_src, and emits a Vary: Accept response header.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Squeeze;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the wp_get_attachment_image_src filter that rewrites WordPress
 * image-API URLs to their WebP or AVIF siblings when the browser supports
 * the format and the attachment has a generated sibling on disk.
 *
 * Decision contracts (D-01/D-02, Phase 12):
 * - Serving mechanism is a PHP filter (no .htaccess / no <picture> output).
 * - AVIF is preferred over WebP when both exist and the browser supports both.
 * - If neither sibling exists the URL is returned unchanged.
 * - The `enable_serving` gate lives in Plugin::boot(), not here (D-06/D-08).
 *
 * Injectable seams (D-02, RESEARCH Pattern 2):
 * - OptimizationIndex is injected for unit-testable get_flags() reads.
 * - Accept-header source is an optional callable, defaulting to reading
 *   $_SERVER['HTTP_ACCEPT'] — tests pass a closure returning a static string.
 */
final class SqueezeServing {

	/**
	 * Optimization index for per-image flag lookups.
	 *
	 * Typed as `object` (not the concrete `OptimizationIndex`) so unit tests can
	 * inject an anonymous stub without loading the full WordPress environment —
	 * mirrors the pattern used by OptimizationIndex::__construct() for $wpdb.
	 *
	 * @var object
	 */
	private object $index;

	/**
	 * Callable that returns the current request's Accept header string.
	 *
	 * @var callable(): string
	 */
	private $accept_header_source;

	/**
	 * Construct with an explicit OptimizationIndex and optional Accept-header source.
	 *
	 * @param object        $index               Optimization index (production: OptimizationIndex;
	 *                                            tests: anonymous stub with get_flags() method).
	 * @param callable|null $accept_header_source Optional callable returning the Accept header
	 *                                            string. Defaults to reading $_SERVER['HTTP_ACCEPT'].
	 */
	public function __construct(
		object $index,
		?callable $accept_header_source = null
	) {
		$this->index                = $index;
		$this->accept_header_source = $accept_header_source
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Accept header is used only in str_contains() comparison; no SQL, eval, filesystem, or HTML output.
			?? static fn() => (string) ( $_SERVER['HTTP_ACCEPT'] ?? '' );
	}

	/**
	 * Construct from the live WordPress environment.
	 *
	 * @return self
	 */
	public static function from_wordpress(): self {
		return new self( OptimizationIndex::from_wordpress() );
	}

	/**
	 * Register the image-src filter and Vary-header action.
	 *
	 * Priority 10 on wp_get_attachment_image_src matches the WP filter contract
	 * (ARCHITECTURE §5, D-01). The send_headers action emits Vary: Accept on
	 * HTML responses (D-04).
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'wp_get_attachment_image_src', array( $this, 'filter_image_src' ), 10, 4 );
		add_action( 'send_headers', array( $this, 'emit_vary_header' ) );
	}

	/**
	 * Filter wp_get_attachment_image_src to return the next-gen sibling URL.
	 *
	 * Decision tree (D-02):
	 * 1. $image is not an array (false / icon / SVG) → return unchanged.
	 * 2. Neither has_webp nor has_avif is set → fast-exit unchanged.
	 * 3. has_avif AND Accept contains 'image/avif' → append .avif.
	 * 4. has_webp AND Accept contains 'image/webp' → append .webp.
	 * 5. Otherwise → return unchanged.
	 *
	 * @param mixed $image         Array [url, width, height, is_intermediate] or false.
	 * @param int   $attachment_id Attachment post ID.
	 * @param mixed $size          Requested image size (unused; required by filter signature).
	 * @param bool  $icon          Whether to use an icon as a fallback.
	 * @return mixed The (possibly rewritten) $image value.
	 */
	public function filter_image_src( $image, int $attachment_id, $size, bool $icon ) {
		unset( $size, $icon );

		// Guard 0: never rewrite in wp-admin or feed contexts (D-08 / D-04). The
		// filter is registered unconditionally in Plugin::boot(), which runs on every
		// request — outside the is_admin() block means "always", NOT "front-end only".
		// The Edit Image UI parses the resolved URL for its original extension, and
		// feed items must keep stable original URLs, so both contexts must pass through.
		if ( is_admin() || is_feed() ) {
			return $image;
		}

		// Guard 1: non-array (false, icon, SVG attachments) → pass through unchanged.
		if ( ! is_array( $image ) ) {
			return $image;
		}

		// Guard 2: fast-exit when neither sibling exists for this attachment.
		$flags = $this->index->get_flags( $attachment_id );
		if ( ! $flags['has_webp'] && ! $flags['has_avif'] ) {
			return $image;
		}

		$accept = ( $this->accept_header_source )();

		// Prefer AVIF when both a sibling exists and the browser supports it.
		if ( $flags['has_avif'] && str_contains( $accept, 'image/avif' ) ) {
			$image[0] = $this->swap_extension( (string) $image[0], 'avif' );
			return $image;
		}

		// Fall back to WebP when available and the browser supports it.
		if ( $flags['has_webp'] && str_contains( $accept, 'image/webp' ) ) {
			$image[0] = $this->swap_extension( (string) $image[0], 'webp' );
			return $image;
		}

		return $image;
	}

	/**
	 * Append a next-gen extension to a WordPress attachment URL.
	 *
	 * The extension is APPENDED (never replaced) so the sibling path matches the
	 * naming convention from SqueezeEngine::generate_webp()/generate_avif():
	 * `{original-path}.webp` / `{original-path}.avif` (Phase 10, D-02).
	 *
	 * Guards (RESEARCH Pattern 3, Pitfall 4):
	 * - Query strings are stripped before the extension check and restored after.
	 * - URLs already ending in .webp or .avif are returned unchanged (no double-append).
	 * - Non-raster extensions (SVG, GIF, etc.) are returned unchanged.
	 * - Only .jpg/.jpeg/.png originals receive the new sibling extension.
	 *
	 * @param string $url Original WordPress attachment URL.
	 * @param string $ext Target extension ('webp' or 'avif').
	 * @return string URL with the next-gen extension appended, or the original URL unchanged.
	 */
	public function swap_extension( string $url, string $ext ): string {
		// Strip query string before extension inspection.
		$query = '';
		$q_pos = strpos( $url, '?' );
		if ( false !== $q_pos ) {
			$query = substr( $url, $q_pos ); // Includes the leading '?'.
			$url   = substr( $url, 0, $q_pos );
		}

		$lower = strtolower( $url );

		// No-op: already a next-gen URL (prevents double-append).
		if ( str_ends_with( $lower, '.webp' ) || str_ends_with( $lower, '.avif' ) ) {
			return $url . $query;
		}

		// Only rewrite known raster originals; leave SVG/GIF/etc. alone.
		if (
			str_ends_with( $lower, '.jpg' ) ||
			str_ends_with( $lower, '.jpeg' ) ||
			str_ends_with( $lower, '.png' )
		) {
			return $url . '.' . $ext . $query;
		}

		// Non-raster or unknown extension → return unchanged.
		return $url . $query;
	}

	/**
	 * Emit a `Vary: Accept` response header on front-end HTML responses only (D-04).
	 *
	 * Gated out of admin, AJAX, and feed contexts:
	 * - is_admin() excludes wp-admin page responses (send_headers fires there too).
	 * - DOING_AJAX is defined for wp-admin AJAX requests.
	 * - is_feed() excludes RSS/Atom responses (DOING_AJAX is not set for feeds).
	 * - REST API routes are excluded naturally by the WP routing layer (the
	 *   send_headers action does not fire for REST routes).
	 *
	 * The header value is a constant literal — no user input is interpolated,
	 * so CRLF / response-splitting is impossible (T-12-04).
	 *
	 * @return void
	 */
	public function emit_vary_header(): void {
		if ( ( defined( 'DOING_AJAX' ) && DOING_AJAX ) || is_admin() || is_feed() ) {
			return;
		}

		header( 'Vary: Accept' );
	}

	/**
	 * Return the names of detected caching plugins via constant presence checks only.
	 *
	 * Detection is by `defined()` presence of each plugin's version/directory constant
	 * (D-05, RESEARCH Pattern 5, T-12-05). This method:
	 * - NEVER instantiates plugin classes
	 * - NEVER calls `is_plugin_active()`
	 * - NEVER calls any cache-plugin API or function
	 *
	 * @return string[] Human-readable names of detected cache plugins.
	 */
	public function detect_cache_plugins(): array {
		$detected = array();

		if ( defined( 'WP_ROCKET_VERSION' ) ) {
			$detected[] = 'WP Rocket';
		}

		if ( defined( 'W3TC_VERSION' ) ) {
			$detected[] = 'W3 Total Cache';
		}

		if ( defined( 'LSCWP_V' ) ) {
			$detected[] = 'LiteSpeed Cache';
		}

		if ( defined( 'CLOUDFLARE_PLUGIN_DIR' ) ) {
			$detected[] = 'Cloudflare';
		}

		return $detected;
	}
}
