<?php
/**
 * Unit tests for SqueezeHooks auto-on-upload scheduling (TRG-03, D-06, D-07).
 *
 * These tests are RED until Plan 11-04 implements SqueezeHooks.  They define the
 * required behaviour; Plan 11-04 turns them GREEN.
 *
 * Uses the source-inspection technique (ReflectionClass::getFileName() +
 * file_get_contents) so no WordPress bootstrap is required — all assertions are
 * against the production class source string.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Tests\Unit\Squeeze;

use PHPUnit\Framework\TestCase;

/**
 * Source-inspection tests for SqueezeHooks scheduling/dedup/return-meta behaviour.
 */
final class SqueezeHooksTest extends TestCase {

	/**
	 * Assert the SqueezeHooks class exists, then return its source for inspection.
	 *
	 * Fails with a clear message when SqueezeHooks has not been created yet (the
	 * expected RED state for this plan).
	 *
	 * @return string
	 */
	private function hooks_source(): string {
		$this->assertTrue(
			class_exists( \AssetDrips\Squeeze\SqueezeHooks::class ),
			'SqueezeHooks class must exist (src/Squeeze/SqueezeHooks.php) before these tests can pass (TRG-03)'
		);

		$ref = new \ReflectionClass( \AssetDrips\Squeeze\SqueezeHooks::class );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file read for source inspection; wp_remote_get() is for HTTP URLs.
		return (string) file_get_contents( (string) $ref->getFileName() );
	}

	// -------------------------------------------------------------------------
	// TRG-03 + D-06: on_meta registered at priority 20 on wp_generate_attachment_metadata
	// -------------------------------------------------------------------------

	/**
	 * SqueezeHooks::register() must add on_meta to 'wp_generate_attachment_metadata'
	 * at priority 20 (fires AFTER IndexHooks at priority 10).
	 *
	 * @return void
	 */
	public function test_on_meta_is_a_filter_registered_at_priority_20(): void {
		$contents = $this->hooks_source();

		$this->assertStringContainsString(
			"'wp_generate_attachment_metadata'",
			$contents,
			"register() must add_filter('wp_generate_attachment_metadata', ...) (TRG-03)"
		);

		$this->assertStringContainsString(
			', 20,',
			$contents,
			'wp_generate_attachment_metadata must be registered at priority 20 (TRG-03, D-06)'
		);
	}

	// -------------------------------------------------------------------------
	// D-06: is_auto_enabled() uses per-op auto_* fields, NOT a single option
	// -------------------------------------------------------------------------

	/**
	 * The is_auto_enabled() method must check all four per-op auto_* fields on
	 * SqueezeSettings, NOT a hypothetical single 'assetdrips_squeeze_auto' option.
	 *
	 * Pitfall 3 (11-RESEARCH.md): SqueezeSettings has separate auto_recompress,
	 * auto_webp, auto_avif, auto_resize booleans — no single toggle.
	 *
	 * @return void
	 */
	public function test_is_auto_enabled_uses_per_op_auto_fields_not_single_option(): void {
		$contents = $this->hooks_source();

		$this->assertStringContainsString(
			'auto_recompress',
			$contents,
			'SqueezeHooks must read the auto_recompress field from SqueezeSettings (D-06)'
		);

		$this->assertStringContainsString(
			'auto_webp',
			$contents,
			'SqueezeHooks must read the auto_webp field from SqueezeSettings (D-06)'
		);

		$this->assertStringContainsString(
			'auto_avif',
			$contents,
			'SqueezeHooks must read the auto_avif field from SqueezeSettings (D-06)'
		);

		$this->assertStringContainsString(
			'auto_resize',
			$contents,
			'SqueezeHooks must read the auto_resize field from SqueezeSettings (D-06)'
		);
	}

	// -------------------------------------------------------------------------
	// TRG-03 + D-07: dedup guard uses array($id) args for both WP cron calls
	// -------------------------------------------------------------------------

	/**
	 * The dedup guard must pass array($id) as the args to BOTH wp_next_scheduled
	 * and wp_schedule_single_event so the event keys match.
	 *
	 * Pitfall 4 (11-RESEARCH.md): WP stores cron events keyed by serialize($args).
	 * If the dedup check passes $id without wrapping it in an array, the check never
	 * matches the scheduled event — causing duplicate scheduling.
	 *
	 * @return void
	 */
	public function test_dedup_guard_uses_array_args_for_both_cron_calls(): void {
		$contents = $this->hooks_source();

		$this->assertStringContainsString(
			"wp_next_scheduled( 'assetdrips_squeeze_single', array( \$id ) )",
			$contents,
			'wp_next_scheduled must pass array($id) as the args to match the scheduled event (TRG-03, D-07, Pitfall 4)'
		);

		$this->assertStringContainsString(
			"wp_schedule_single_event( time(), 'assetdrips_squeeze_single', array( \$id ) )",
			$contents,
			'wp_schedule_single_event must use array($id) args so the dedup key matches (TRG-03, D-07, Pitfall 4)'
		);
	}

	// -------------------------------------------------------------------------
	// TRG-03: on_meta returns $meta unchanged (it is a filter)
	// -------------------------------------------------------------------------

	/**
	 * The on_meta() filter callback is registered on a filter hook and MUST return
	 * $meta unchanged. Forgetting to return $meta would strip attachment metadata.
	 *
	 * @return void
	 */
	public function test_on_meta_returns_meta_unchanged(): void {
		$contents = $this->hooks_source();

		$this->assertStringContainsString(
			'return $meta',
			$contents,
			'on_meta() must return $meta unchanged — it is a filter, not an action (TRG-03)'
		);
	}
}
