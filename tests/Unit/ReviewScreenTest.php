<?php
/**
 * ReviewScreen presenter tests.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Tests\Unit;

use AssetDrips\Admin\ReviewScreen;
use AssetDrips\Score\Tier;
use PHPUnit\Framework\TestCase;

/**
 * Pure tests for the review screen presenter helpers.
 */
final class ReviewScreenTest extends TestCase {

	/**
	 * HIGH is the brand orange; other tiers have distinct accents.
	 *
	 * @return void
	 */
	public function test_tier_accent(): void {
		$this->assertSame( '#FF4200', ReviewScreen::tier_accent( Tier::HIGH->value ) );
		$this->assertNotSame( '#FF4200', ReviewScreen::tier_accent( Tier::USED->value ) );
		$this->assertNotSame( '#FF4200', ReviewScreen::tier_accent( Tier::MEDIUM->value ) );
		$this->assertSame( '#080808', ReviewScreen::tier_accent( 'anything-else' ) );
	}

	/**
	 * Zero evidence reads as no references.
	 *
	 * @return void
	 */
	public function test_evidence_summary_empty(): void {
		$this->assertSame( 'No references found', ReviewScreen::evidence_summary( array() ) );
	}

	/**
	 * Evidence is summarised as a count plus its distinct, sorted sources.
	 *
	 * @return void
	 */
	public function test_evidence_summary_counts_and_sources(): void {
		$evidence = array(
			array( 'source' => 'postmeta' ),
			array( 'source' => 'content' ),
			array( 'source' => 'content' ),
		);

		$this->assertSame( '3 references · content, postmeta', ReviewScreen::evidence_summary( $evidence ) );
	}

	/**
	 * A single reference is not pluralised.
	 *
	 * @return void
	 */
	public function test_evidence_summary_singular(): void {
		$this->assertSame( '1 reference · acf', ReviewScreen::evidence_summary( array( array( 'source' => 'acf' ) ) ) );
	}

	/**
	 * Every tier has complete, distinct guidance copy.
	 *
	 * @return void
	 */
	public function test_tier_help_is_complete_for_every_tier(): void {
		foreach ( Tier::cases() as $case ) {
			$help = ReviewScreen::tier_help( $case->value );

			$this->assertArrayHasKey( 'icon', $help );
			$this->assertArrayHasKey( 'headline', $help );
			$this->assertArrayHasKey( 'what', $help );
			$this->assertArrayHasKey( 'how', $help );
			$this->assertNotSame( '', $help['headline'] );
			$this->assertNotSame( '', $help['what'] );
			$this->assertNotSame( '', $help['how'] );
		}
	}

	/**
	 * USED is the protected/default branch; HIGH is the self-serve branch.
	 * Their headlines must differ so the panel reads correctly per tier.
	 *
	 * @return void
	 */
	public function test_tier_help_distinguishes_used_from_high(): void {
		$high = ReviewScreen::tier_help( Tier::HIGH->value );
		$used = ReviewScreen::tier_help( Tier::USED->value );

		$this->assertNotSame( $high['headline'], $used['headline'] );
		$this->assertSame( ReviewScreen::tier_help( 'anything-else' ), $used );
	}
}
