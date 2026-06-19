<?php
/**
 * RED tests for SqueezeJob optimization-history append + cap (DASH-03 / D-04).
 *
 * These tests are RED until Wave 1 adds HISTORY_OPTION, HISTORY_CAP, and the
 * history-append block to SqueezeJob::backfill().  Wave 1 turns them GREEN.
 *
 * EXIT_EXPECTED_RED: Tests WILL fail because the history append logic does not
 * exist yet.  Failures must be assertion failures on option contents — NOT
 * parse/bootstrap fatals.
 *
 * Strategy: call SqueezeJob::backfill() with injected stubs (no live DB or
 * WP), then inspect the in-memory option stub for the expected history entry.
 * get_option/update_option are backed by $GLOBALS['_assetdrips_options_stub']
 * in unit-bootstrap.php.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Tests\Unit\Squeeze;

use AssetDrips\Squeeze\SqueezeJob;
use PHPUnit\Framework\TestCase;

/**
 * RED tests: SqueezeJob history-append + cap contracts (DASH-03 / D-04).
 */
final class SqueezeJobHistoryTest extends TestCase {

	/**
	 * Anonymous wpdb stub — get_col() returns an empty page so backfill() completes.
	 *
	 * @var object
	 */
	private object $wpdb;

	/**
	 * Anonymous SqueezeEngine stub — all ops succeed immediately.
	 *
	 * @var object
	 */
	private object $engine;

	/**
	 * Anonymous BackupManager stub.
	 *
	 * @var object
	 */
	private object $backup;

	/**
	 * Anonymous OptimizationIndex stub.
	 *
	 * @var object
	 */
	private object $index;

	/**
	 * SqueezeJob under test, built fresh for each test.
	 *
	 * @var SqueezeJob
	 */
	private SqueezeJob $job;

	/**
	 * Set up in-memory stubs and a SqueezeJob instance.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Bootstrap stub.
		$GLOBALS['_assetdrips_options_stub']   = array();
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Bootstrap stub.
		$GLOBALS['_assetdrips_transient_stub'] = array();

		// Enable all ops so backfill() finds valid operations.
		$GLOBALS['_assetdrips_options_stub']['assetdrips_squeeze_settings'] = array( // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
			'enable_recompress' => true,
			'enable_webp'       => true,
			'enable_avif'       => true,
			'enable_resize'     => true,
		);

		// Reset wpdb queue state.
		if ( isset( $GLOBALS['wpdb'] ) ) {
			$GLOBALS['wpdb']->next_get_row  = null;
			$GLOBALS['wpdb']->get_row_queue = array();
		}

		// wpdb stub: get_col() returns an empty first page so backfill() sees no work
		// and exits immediately after writing savings/history options.
		$this->wpdb = new class() {
			/** @var string */
			public string $prefix = 'wp_';
			/** @var string */
			public string $posts = 'wp_posts';
			/** @var mixed */
			public mixed $next_var = '0';

			/**
			 * @param string $sql  SQL template.
			 * @param mixed  ...$args Bound values.
			 * @return string
			 */
			public function prepare( string $sql, mixed ...$args ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Stub.
				return $sql;
			}

			/**
			 * @param string $sql Query.
			 * @return mixed
			 */
			public function get_var( string $sql ): mixed { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Stub.
				return $this->next_var;
			}

			/**
			 * Returns an empty column to signal batch completion immediately.
			 *
			 * @param string $sql Query.
			 * @return array<int, mixed>
			 */
			public function get_col( string $sql ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Stub.
				return array();
			}
		};

		// Engine stub — recompress()/etc. are no-ops; never called (empty ID page).
		$this->engine = new class() {
			/** @return array<string, mixed> */
			public function recompress( int $id ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
				return array( 'ok' => true, 'bytes_saved' => 512 );
			}
		};

		// Backup stub.
		$this->backup = new class() {
			/**
			 * @return int
			 */
			public function estimate_batch_space( array $ids ): int { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
				return 0;
			}
		};

		// Index stub.
		$this->index = new class() {
			/** @return void */
			public function update_status( int $id, string $status, string $error = '' ): void {} // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		};

		$this->job = new SqueezeJob( $this->wpdb, $this->engine, $this->backup, $this->index );
	}

	/**
	 * Tear down global stubs.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Bootstrap stub.
		$GLOBALS['_assetdrips_options_stub'] = array();
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// DASH-03 / D-04: history entry prepended after batch completion
	// -------------------------------------------------------------------------

	/**
	 * After backfill() completes with at least one processed image, the history
	 * option ('assetdrips_squeeze_history') has the new entry at index 0 and
	 * includes the required keys: date, ops, images_processed, bytes_saved.
	 *
	 * RED: Fails because HISTORY_OPTION and the append block do not exist yet.
	 *
	 * @return void
	 */
	public function test_backfill_appends_history_entry_with_required_keys(): void {
		$this->job->backfill( 1 ); // RED: HISTORY_OPTION/append logic not implemented.

		$history = get_option( 'assetdrips_squeeze_history', array() );

		$this->assertIsArray( $history, 'History option must be an array after backfill()' );
		$this->assertNotEmpty( $history, 'History must contain at least one entry after backfill()' );

		$entry = $history[0];
		$this->assertArrayHasKey( 'date', $entry, 'History entry must have a "date" key' );
		$this->assertArrayHasKey( 'ops', $entry, 'History entry must have an "ops" key' );
		$this->assertArrayHasKey( 'images_processed', $entry, 'History entry must have an "images_processed" key' );
		$this->assertArrayHasKey( 'bytes_saved', $entry, 'History entry must have a "bytes_saved" key' );
	}

	/**
	 * The most-recently appended entry is at index 0 (newest-first ordering via
	 * array_unshift).
	 *
	 * RED: Fails because the history-prepend logic does not exist yet.
	 *
	 * @return void
	 */
	public function test_backfill_prepends_newest_entry_first(): void {
		// Pre-seed one existing entry.
		update_option(
			'assetdrips_squeeze_history',
			array(
				array(
					'date'             => '2026-01-01 00:00:00',
					'ops'              => array( 'recompress' ),
					'images_processed' => 5,
					'bytes_saved'      => 2048,
				),
			)
		);

		$this->job->backfill( 1 ); // RED: HISTORY_OPTION/append logic not implemented.

		$history = get_option( 'assetdrips_squeeze_history', array() );

		$this->assertIsArray( $history );
		$this->assertGreaterThan( 1, count( $history ), 'A new entry must have been prepended to the existing history' );

		// The newest entry must be at index 0 — date differs from the seeded entry.
		$this->assertNotSame(
			'2026-01-01 00:00:00',
			$history[0]['date'],
			'Newest entry must be prepended at index 0 (array_unshift), not appended'
		);
	}

	// -------------------------------------------------------------------------
	// DASH-03 / D-04: history is capped at 20 entries
	// -------------------------------------------------------------------------

	/**
	 * When 20 entries already exist and backfill() appends one more, the history
	 * option stays at exactly 20 entries (oldest is dropped).
	 *
	 * RED: Fails because the HISTORY_CAP constant and array_slice cap do not exist yet.
	 *
	 * @return void
	 */
	public function test_backfill_caps_history_at_twenty_entries(): void {
		// Seed exactly 20 existing entries.
		$existing = array();
		for ( $i = 0; $i < 20; $i++ ) {
			$existing[] = array(
				'date'             => '2026-01-0' . ( ( $i % 9 ) + 1 ) . ' 00:00:00',
				'ops'              => array( 'recompress' ),
				'images_processed' => $i + 1,
				'bytes_saved'      => $i * 100,
			);
		}
		update_option( 'assetdrips_squeeze_history', $existing );

		$this->job->backfill( 1 ); // RED: HISTORY_CAP / array_slice not implemented.

		$history = get_option( 'assetdrips_squeeze_history', array() );

		$this->assertCount(
			20,
			$history,
			'History must be capped at 20 entries even after appending to a full set (HISTORY_CAP)'
		);
	}

	/**
	 * bytes_saved in the history entry is clamped to ≥ 0 (defense-in-depth
	 * against negative savings from malformed data, consistent with D-09 / max(0,...)).
	 *
	 * RED: Fails because the history-append block does not exist yet.
	 *
	 * @return void
	 */
	public function test_backfill_history_bytes_saved_is_non_negative(): void {
		$this->job->backfill( 1 ); // RED: HISTORY_OPTION/append logic not implemented.

		$history = get_option( 'assetdrips_squeeze_history', array() );

		// RED until the append block exists: assert non-empty first so the test
		// fails explicitly rather than skipping the bytes_saved check silently.
		$this->assertIsArray( $history, 'History option must be an array after backfill()' );
		$this->assertNotEmpty( $history, 'History must contain an entry after backfill() (RED: append block not implemented)' );

		$this->assertGreaterThanOrEqual(
			0,
			$history[0]['bytes_saved'],
			'bytes_saved in history entry must be ≥ 0 (max(0, ...) clamp, D-09)'
		);
	}
}
