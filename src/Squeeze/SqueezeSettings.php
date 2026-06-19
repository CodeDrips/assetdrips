<?php
/**
 * Squeeze settings value object.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Squeeze;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable typed value object for all Squeeze settings.
 *
 * Owns safe defaults so a fresh install with no saved option returns honest,
 * safe values (D-02). Sanitizer is the entry point for any POST data; it
 * whitelists/clamps every field before a new instance is constructed (D-01).
 *
 * Single-option persistence pattern: one atomic read/write via get_option /
 * update_option with autoload=false (matches ScanProgress pattern). Does NOT
 * use the WP Settings API (D-01 — no register_setting / settings_fields anywhere
 * in the codebase).
 *
 * png_lossless is ALWAYS true in the sanitizer. The field exists in storage so
 * Phase 9 can read it, but the form never offers a "lossy PNG" toggle and the
 * sanitizer enforces this as a hard gate (FND-02, D-08).
 */
final class SqueezeSettings {

	/**
	 * WP option key for the serialized settings array.
	 */
	private const OPTION_KEY = 'assetdrips_squeeze_settings';

	/**
	 * Quality presets: key => resolved JPEG numeric quality.
	 *
	 * PNG quality (lossless deflate level) is handled separately via png_lossless.
	 * Conservative ≈88 / Balanced 82 / Aggressive ≈75 (D-08).
	 *
	 * @var array<string, int>
	 */
	public const PRESETS = array(
		'conservative' => 88,
		'balanced'     => 82,
		'aggressive'   => 75,
	);

	/**
	 * Default max dimension cap for originals (matching WP's own big image scaling).
	 */
	public const DEFAULT_MAX_DIMENSION = 2560;

	/**
	 * Default preset key (D-08).
	 */
	public const DEFAULT_PRESET = 'balanced';

	/**
	 * Default JPEG numeric quality resolved from DEFAULT_PRESET.
	 */
	public const DEFAULT_JPEG_QUALITY = 82;

	// -------------------------------------------------------------------------
	// Typed fields (PHP 8.1 readonly — immutable after construction).
	// -------------------------------------------------------------------------

	/**
	 * Active preset key: conservative|balanced|aggressive|custom.
	 *
	 * @var string
	 */
	public readonly string $preset;

	/**
	 * Resolved JPEG quality (1–100). Derived from preset for non-custom; user value for custom.
	 *
	 * @var int
	 */
	public readonly int $jpeg_quality;

	/**
	 * Whether PNG compression is lossless. Always true (FND-02 hard gate — see sanitize()).
	 *
	 * @var bool
	 */
	public readonly bool $png_lossless;

	/**
	 * Max dimension cap for originals in pixels.
	 *
	 * @var int
	 */
	public readonly int $max_dimension;

	/**
	 * Whether recompression operation is enabled.
	 *
	 * @var bool
	 */
	public readonly bool $enable_recompress;

	/**
	 * Whether WebP generation is enabled.
	 *
	 * @var bool
	 */
	public readonly bool $enable_webp;

	/**
	 * Whether AVIF generation is enabled.
	 *
	 * @var bool
	 */
	public readonly bool $enable_avif;

	/**
	 * Whether resize operation is enabled.
	 *
	 * @var bool
	 */
	public readonly bool $enable_resize;

	/**
	 * Whether auto-on-upload recompression is enabled (defaults OFF — FND-04, D-09).
	 *
	 * @var bool
	 */
	public readonly bool $auto_recompress;

	/**
	 * Whether auto-on-upload WebP generation is enabled (defaults OFF — FND-04, D-09).
	 *
	 * @var bool
	 */
	public readonly bool $auto_webp;

	/**
	 * Whether auto-on-upload AVIF generation is enabled (defaults OFF — FND-04, D-09).
	 *
	 * @var bool
	 */
	public readonly bool $auto_avif;

	/**
	 * Whether auto-on-upload resize is enabled (defaults OFF — FND-04, D-09).
	 *
	 * @var bool
	 */
	public readonly bool $auto_resize;

	/**
	 * Whether next-gen serving is enabled (placeholder; Phase 12 implements it).
	 *
	 * @var bool
	 */
	public readonly bool $enable_serving;

	/**
	 * Construct from a raw data array. Missing keys fall back to safe defaults (D-02, Pitfall 3).
	 *
	 * @param array<string, mixed> $data Field values; any missing key uses the class default.
	 */
	public function __construct( array $data ) {
		$this->preset            = (string) ( $data['preset']            ?? self::DEFAULT_PRESET );
		$this->jpeg_quality      = (int)    ( $data['jpeg_quality']      ?? self::DEFAULT_JPEG_QUALITY );
		$this->png_lossless      = (bool)   ( $data['png_lossless']      ?? true );
		$this->max_dimension     = (int)    ( $data['max_dimension']     ?? self::DEFAULT_MAX_DIMENSION );
		$this->enable_recompress = (bool)   ( $data['enable_recompress'] ?? false );
		$this->enable_webp       = (bool)   ( $data['enable_webp']       ?? false );
		$this->enable_avif       = (bool)   ( $data['enable_avif']       ?? false );
		$this->enable_resize     = (bool)   ( $data['enable_resize']     ?? false );
		$this->auto_recompress   = (bool)   ( $data['auto_recompress']   ?? false );
		$this->auto_webp         = (bool)   ( $data['auto_webp']         ?? false );
		$this->auto_avif         = (bool)   ( $data['auto_avif']         ?? false );
		$this->auto_resize       = (bool)   ( $data['auto_resize']       ?? false );
		$this->enable_serving    = (bool)   ( $data['enable_serving']    ?? false );
	}

	/**
	 * Load settings from the WP option. Returns safe defaults when no option is saved (D-02).
	 *
	 * @return self
	 */
	public static function load(): self {
		$raw = get_option( self::OPTION_KEY, array() );
		return new self( is_array( $raw ) ? $raw : array() );
	}

	/**
	 * Persist settings to the WP option. Not autoloaded (matches ScanProgress pattern — D-01).
	 *
	 * @param self $settings Settings instance to persist.
	 * @return void
	 */
	public static function save( self $settings ): void {
		update_option( self::OPTION_KEY, self::to_array( $settings ), false );
	}

	/**
	 * Sanitize raw POST data into a new settings instance.
	 *
	 * Security contracts (T-08-03):
	 * - Preset whitelisted via in_array strict; unknown value falls back to DEFAULT_PRESET.
	 * - jpeg_quality clamped to [1, 100] with (int) cast.
	 * - max_dimension clamped to [100, 10000] with (int) cast.
	 * - Every toggle cast via !empty() — unchecked checkbox (absent from POST) becomes false.
	 * - png_lossless is ALWAYS forced true regardless of POST input (FND-02 hard gate).
	 *
	 * @param array<string, mixed> $raw Raw POST data.
	 * @return self Sanitized settings instance.
	 */
	public static function sanitize( array $raw ): self {
		// Whitelist preset value.
		$preset = in_array( $raw['preset'] ?? '', array( 'conservative', 'balanced', 'aggressive', 'custom' ), true )
			? (string) $raw['preset']
			: self::DEFAULT_PRESET;

		// Resolve numeric quality: for non-custom presets the constant wins; custom uses POST value clamped.
		if ( 'custom' === $preset ) {
			$jpeg_quality = max( 1, min( 100, (int) ( $raw['jpeg_quality'] ?? self::DEFAULT_JPEG_QUALITY ) ) );
		} else {
			$jpeg_quality = self::PRESETS[ $preset ];
		}

		return new self(
			array(
				'preset'            => $preset,
				'jpeg_quality'      => $jpeg_quality,
				// FND-02 hard gate: lossy compression is never applied to PNGs.
				// png_lossless is always forced true here regardless of POST input.
				// Phase 9 reads this field; no UI toggle is offered (D-08, A3).
				'png_lossless'      => true,
				'max_dimension'     => max( 100, min( 10000, (int) ( $raw['max_dimension'] ?? self::DEFAULT_MAX_DIMENSION ) ) ),
				'enable_recompress' => ! empty( $raw['enable_recompress'] ),
				'enable_webp'       => ! empty( $raw['enable_webp'] ),
				'enable_avif'       => ! empty( $raw['enable_avif'] ),
				'enable_resize'     => ! empty( $raw['enable_resize'] ),
				'auto_recompress'   => ! empty( $raw['auto_recompress'] ),
				'auto_webp'         => ! empty( $raw['auto_webp'] ),
				'auto_avif'         => ! empty( $raw['auto_avif'] ),
				'auto_resize'       => ! empty( $raw['auto_resize'] ),
				'enable_serving'    => ! empty( $raw['enable_serving'] ),
			)
		);
	}

	/**
	 * Serialize a settings instance to a plain array for option storage.
	 *
	 * @param self $s Settings instance.
	 * @return array<string, mixed>
	 */
	private static function to_array( self $s ): array {
		return array(
			'preset'            => $s->preset,
			'jpeg_quality'      => $s->jpeg_quality,
			'png_lossless'      => $s->png_lossless,
			'max_dimension'     => $s->max_dimension,
			'enable_recompress' => $s->enable_recompress,
			'enable_webp'       => $s->enable_webp,
			'enable_avif'       => $s->enable_avif,
			'enable_resize'     => $s->enable_resize,
			'auto_recompress'   => $s->auto_recompress,
			'auto_webp'         => $s->auto_webp,
			'auto_avif'         => $s->auto_avif,
			'auto_resize'       => $s->auto_resize,
			'enable_serving'    => $s->enable_serving,
		);
	}
}
