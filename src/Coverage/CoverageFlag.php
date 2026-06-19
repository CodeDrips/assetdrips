<?php
/**
 * A single coverage-gap flag.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Coverage;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable record of one thing the v1 scanners cannot see into.
 *
 * Coverage gaps are how AssetDrips stays honest: when a site runs a page builder
 * we do not parse, a plugin that stores references in custom tables, or offloaded
 * media, any unreferenced attachment cannot be cleared to HIGH — the evidence is
 * simply incomplete. A SIGNIFICANT flag forces such attachments to LOW; a MINOR
 * flag tempers confidence without blocking.
 */
final class CoverageFlag {

	/**
	 * Severity: a real blind spot that must prevent a HIGH verdict.
	 */
	public const SIGNIFICANT = 'significant';

	/**
	 * Severity: a partial blind spot that lowers, but does not block, confidence.
	 */
	public const MINOR = 'minor';

	/**
	 * Category: an active page builder whose data v1 does not parse.
	 */
	public const BUILDER = 'builder';

	/**
	 * Category: a plugin that stores references in custom tables we do not scan.
	 */
	public const CUSTOM_TABLE = 'custom_table';

	/**
	 * Category: media offloaded or served from outside the uploads directory.
	 */
	public const OFFLOADED = 'offloaded';

	/**
	 * Category: anything else that reduces coverage confidence.
	 */
	public const OTHER = 'other';

	/**
	 * Stable machine code, e.g. "builder.elementor".
	 *
	 * @var string
	 */
	private string $code;

	/**
	 * One of the category constants.
	 *
	 * @var string
	 */
	private string $category;

	/**
	 * One of the severity constants.
	 *
	 * @var string
	 */
	private string $severity;

	/**
	 * Human-readable label.
	 *
	 * @var string
	 */
	private string $label;

	/**
	 * Optional specifics (plugin slug, theme name, host).
	 *
	 * @var string
	 */
	private string $detail;

	/**
	 * Construct a coverage flag.
	 *
	 * @param string $code     Stable machine code.
	 * @param string $category One of the category constants.
	 * @param string $severity One of the severity constants.
	 * @param string $label    Human-readable label.
	 * @param string $detail   Optional specifics.
	 */
	public function __construct( string $code, string $category, string $severity, string $label, string $detail = '' ) {
		$this->code     = $code;
		$this->category = $category;
		$this->severity = $severity;
		$this->label    = $label;
		$this->detail   = $detail;
	}

	/**
	 * Stable machine code.
	 *
	 * @return string
	 */
	public function code(): string {
		return $this->code;
	}

	/**
	 * Category constant.
	 *
	 * @return string
	 */
	public function category(): string {
		return $this->category;
	}

	/**
	 * Severity constant.
	 *
	 * @return string
	 */
	public function severity(): string {
		return $this->severity;
	}

	/**
	 * Human-readable label.
	 *
	 * @return string
	 */
	public function label(): string {
		return $this->label;
	}

	/**
	 * Optional specifics.
	 *
	 * @return string
	 */
	public function detail(): string {
		return $this->detail;
	}

	/**
	 * Whether this flag is significant enough to block a HIGH verdict.
	 *
	 * @return bool
	 */
	public function is_significant(): bool {
		return self::SIGNIFICANT === $this->severity;
	}

	/**
	 * Plain-array representation for JSON storage.
	 *
	 * @return array<string, string>
	 */
	public function to_array(): array {
		return array(
			'code'     => $this->code,
			'category' => $this->category,
			'severity' => $this->severity,
			'label'    => $this->label,
			'detail'   => $this->detail,
		);
	}
}
