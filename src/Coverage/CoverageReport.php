<?php
/**
 * Per-scan coverage report.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Coverage;

defined( 'ABSPATH' ) || exit;

/**
 * The set of coverage gaps in effect for a scan.
 *
 * Site-wide by nature in v1: a gap such as an active page builder applies to the
 * whole library. The scorer consumes this to decide whether a zero-hit
 * attachment can be cleared to HIGH, must drop to LOW, or sits at MEDIUM.
 */
final class CoverageReport {

	/**
	 * Flags keyed by code, so a condition is recorded once.
	 *
	 * @var array<string, CoverageFlag>
	 */
	private array $flags = array();

	/**
	 * Add a flag. A repeated code is ignored after the first.
	 *
	 * @param CoverageFlag $flag Coverage flag.
	 * @return void
	 */
	public function add( CoverageFlag $flag ): void {
		$this->flags[ $flag->code() ] ??= $flag;
	}

	/**
	 * All flags.
	 *
	 * @return CoverageFlag[]
	 */
	public function flags(): array {
		return array_values( $this->flags );
	}

	/**
	 * Whether any gap was recorded.
	 *
	 * @return bool
	 */
	public function has_gaps(): bool {
		return array() !== $this->flags;
	}

	/**
	 * Whether any significant gap was recorded (blocks a HIGH verdict).
	 *
	 * @return bool
	 */
	public function has_significant_gaps(): bool {
		foreach ( $this->flags as $flag ) {
			if ( $flag->is_significant() ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The significant flags only.
	 *
	 * @return CoverageFlag[]
	 */
	public function significant_flags(): array {
		return array_values(
			array_filter(
				$this->flags,
				static fn( CoverageFlag $flag ): bool => $flag->is_significant()
			)
		);
	}

	/**
	 * Plain-array representation for JSON storage.
	 *
	 * @return array<int, array<string, string>>
	 */
	public function to_array(): array {
		return array_map(
			static fn( CoverageFlag $flag ): array => $flag->to_array(),
			array_values( $this->flags )
		);
	}
}
