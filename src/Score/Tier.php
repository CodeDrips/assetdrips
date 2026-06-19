<?php
/**
 * Confidence tier for an attachment.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Score;

defined( 'ABSPATH' ) || exit;

// phpcs:disable PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext -- False positive: PHPCompatibility 9.x predates PHP 8.1 enums and misreads $this in enum methods.

/**
 * The four verdicts AssetDrips assigns to an attachment.
 *
 * The tier is the product. It encodes how much we trust a deletion decision:
 *  - USED   — at least one scanner found a reference. Never a deletion candidate.
 *  - HIGH   — zero references and no coverage gaps. Safe to self-serve delete.
 *  - MEDIUM — zero references but a minor gap or a recent upload. Review first.
 *  - LOW    — zero references but a significant blind spot. Human action only.
 *
 * Only HIGH is ever offered as self-serve deletion.
 */
enum Tier: string {

	case USED   = 'USED';
	case HIGH   = 'HIGH';
	case MEDIUM = 'MEDIUM';
	case LOW    = 'LOW';

	/**
	 * Confidence (0-100) in this verdict, for display and storage.
	 *
	 * USED is near-certain on a single concrete hit. HIGH is strong but not
	 * absolute, since the v1 build does not run the rendered crawl or LLM passes.
	 *
	 * @return int
	 */
	public function confidence(): int {
		return match ( $this ) {
			self::USED   => 100,
			self::HIGH   => 85,
			self::MEDIUM => 50,
			self::LOW    => 25,
		};
	}

	/**
	 * Whether this tier may be offered as a self-serve deletion. Only HIGH.
	 *
	 * @return bool
	 */
	public function is_self_serve(): bool {
		return self::HIGH === $this;
	}

	/**
	 * Whether this tier is a deletion candidate at all (anything but USED).
	 *
	 * @return bool
	 */
	public function is_candidate(): bool {
		return self::USED !== $this;
	}

	/**
	 * Whether this tier requires explicit human action before deletion.
	 *
	 * @return bool
	 */
	public function requires_human_action(): bool {
		return self::MEDIUM === $this || self::LOW === $this;
	}
}
