<?php
/**
 * Unit tests for SqueezeScreen backup disk-usage section (BAK-04).
 *
 * These tests are RED until Plan 09-03 adds the backup UI section to SqueezeScreen.
 * They verify the disk-usage query sums active original_bytes and that the rendered
 * output reflects the expected value.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Tests\Unit\Admin;

use AssetDrips\Admin\SqueezeScreen;
use PHPUnit\Framework\TestCase;

/**
 * Tests the SqueezeScreen backup disk-usage display (BAK-04).
 */
final class SqueezeScreenBackupTest extends TestCase {

	/**
	 * Reset global stubs before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$GLOBALS['_assetdrips_transient_stub']      = array();
		$GLOBALS['_assetdrips_options_stub']        = array();
		$GLOBALS['_assetdrips_current_user_can_stub'] = true;
	}

	/**
	 * Build a $wpdb stub whose get_var() returns a configurable value.
	 *
	 * @param mixed $var_return Value returned by get_var().
	 * @return object
	 */
	private function make_wpdb( mixed $var_return ): object {
		return new class( $var_return ) {
			/** @var string */
			public string $prefix = 'wp_';
			/** @var mixed */
			private mixed $var_return;

			/** @param mixed $var_return Return value for get_var(). */
			public function __construct( mixed $var_return ) {
				$this->var_return = $var_return;
			}

			/**
			 * @param string $sql  SQL template.
			 * @param mixed  ...$args Bound values.
			 * @return string
			 */
			public function prepare( string $sql, mixed ...$args ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Stub.
				return $sql;
			}

			/**
			 * @param string $sql SQL query.
			 * @return mixed
			 */
			public function get_var( string $sql ): mixed { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Stub.
				return $this->var_return;
			}

			/**
			 * @param string $sql SQL query.
			 * @param string $output Output format.
			 * @return array<int, mixed>
			 */
			public function get_results( string $sql, string $output = '' ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Stub.
				return array();
			}

			/**
			 * @param string $sql SQL to execute.
			 * @return int|false
			 */
			public function query( string $sql ): int|false { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Stub.
				return 0;
			}
		};
	}

	// -------------------------------------------------------------------------
	// BAK-04: backup disk-usage query sums active original_bytes
	// -------------------------------------------------------------------------

	/**
	 * The rendered SqueezeScreen must include a backup disk-usage section that
	 * reflects the summed original_bytes from an injected $wpdb->get_var stub.
	 *
	 * Injects a $wpdb stub returning 1263616 bytes (~1234 KB) and asserts the
	 * rendered HTML contains the expected human-readable disk-usage value.
	 *
	 * @return void
	 */
	public function test_backup_section_renders_summed_original_bytes(): void {
		// 1234 * 1024 = 1263616 bytes → displayed as "1,234 KB" (or similar).
		$wpdb   = $this->make_wpdb( 1263616 );
		$screen = new SqueezeScreen( $wpdb );

		ob_start();
		$screen->render();
		$html = ob_get_clean();

		// The rendered output must contain the disk-usage figure derived from
		// the get_var() return (1263616 bytes = 1234 KB).
		$this->assertStringContainsString(
			'original_bytes',
			$screen->get_backup_usage_query(),
			'SqueezeScreen must query SUM(original_bytes) for backup disk-usage display (BAK-04)'
		);

		$this->assertStringContainsString(
			'1,234',
			$html,
			'SqueezeScreen render() must display 1,234 KB when get_var returns 1263616 bytes (BAK-04)'
		);
	}

	/**
	 * The backup section must also be present when there are no backups (sum=0).
	 *
	 * @return void
	 */
	public function test_backup_section_renders_zero_usage(): void {
		$wpdb   = $this->make_wpdb( null );  // NULL → 0 bytes.
		$screen = new SqueezeScreen( $wpdb );

		ob_start();
		$screen->render();
		$html = ob_get_clean();

		$this->assertStringContainsString(
			'backup',
			strtolower( $html ),
			'SqueezeScreen render() must include a backup section even when there are no backups (BAK-04)'
		);
	}
}
