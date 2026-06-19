<?php
/**
 * RED unit tests for SqueezeEngine::repair_missing_sizes() (SIZE-02 / D-03 / D-07).
 *
 * These tests are RED until Plan 13-03 implements repair_missing_sizes() on
 * SqueezeEngine.  They define the required behaviour; Plan 13-03 turns them GREEN.
 *
 * All WP functions are stubbed via unit-bootstrap.php.
 * wp_update_image_subsizes is seeded via $GLOBALS['_assetdrips_update_subsizes_stub'].
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Tests\Unit\Squeeze;

use AssetDrips\Squeeze\SqueezeEngine;
use PHPUnit\Framework\TestCase;

/**
 * RED tests for SqueezeEngine::repair_missing_sizes() additive-repair contract (SIZE-02).
 */
final class SqueezeEngineRepairTest extends TestCase {

	/**
	 * Anonymous $wpdb stub — query collector + prepare passthrough.
	 *
	 * @var object
	 */
	private object $wpdb;

	/**
	 * Anonymous BackupManager stub.
	 *
	 * @var object
	 */
	private object $backup_manager;

	/**
	 * Anonymous OptimizationIndex stub.
	 *
	 * @var object
	 */
	private object $optimization_index;

	/**
	 * Set up stubs and seed global registries.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$GLOBALS['_assetdrips_transient_stub']           = array();
		$GLOBALS['_assetdrips_options_stub']             = array();
		$GLOBALS['_assetdrips_attached_file_stub']       = array();
		$GLOBALS['_assetdrips_attachment_meta_stub']     = array();
		$GLOBALS['_assetdrips_update_subsizes_stub']     = array();

		// $wpdb stub.
		$this->wpdb = new class() {
			/** @var string */
			public string $prefix = 'wp_';
			/** @var mixed */
			public mixed $next_var = null;
			/** @var array<int, string> */
			public array $queries = array();

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
				return $this->next_var;
			}

			/**
			 * @param string $sql SQL to execute.
			 * @return int|false
			 */
			public function query( string $sql ): int|false {
				$this->queries[] = $sql;
				return 1;
			}
		};

		// BackupManager stub.
		$this->backup_manager = new class() {
			/**
			 * @param int $attachment_id Attachment ID.
			 * @return bool
			 */
			public function has_backup( int $attachment_id ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Stub.
				return false;
			}
		};

		// OptimizationIndex stub.
		$this->optimization_index = new class() {
			/** @var array<int, mixed> */
			public array $upsert_calls = array();

			/**
			 * @param array<string, mixed> $row Row data.
			 * @return void
			 */
			public function upsert( array $row ): void {
				$this->upsert_calls[] = $row;
			}
		};
	}

	// -------------------------------------------------------------------------
	// SIZE-02: repair_missing_sizes() returns ok=true on success
	// -------------------------------------------------------------------------

	/**
	 * repair_missing_sizes() returns ['ok' => true, 'regenerated' => N] when
	 * wp_update_image_subsizes() returns a sizes array (SIZE-02 / D-07).
	 *
	 * Seed: two sizes ('thumbnail', 'medium') in the update stub to simulate
	 * wp_update_image_subsizes returning the updated meta with sizes.
	 *
	 * @return void
	 */
	public function test_repair_missing_sizes(): void {
		$id = 15;

		// Seed wp_update_image_subsizes to return a result with two sizes.
		$GLOBALS['_assetdrips_update_subsizes_stub'][ $id ] = array(
			'sizes' => array(
				'thumbnail' => array( 'file' => 'photo-150x150.jpg', 'width' => 150, 'height' => 150 ),
				'medium'    => array( 'file' => 'photo-300x300.jpg', 'width' => 300, 'height' => 300 ),
			),
		);

		$engine = new SqueezeEngine(
			$this->wpdb,
			$this->backup_manager,
			$this->optimization_index
		);

		$result = $engine->repair_missing_sizes( $id );

		$this->assertIsArray( $result, 'repair_missing_sizes() must return an array' );
		$this->assertTrue( $result['ok'], 'repair_missing_sizes() must return ok=true on success (SIZE-02)' );
		$this->assertArrayHasKey(
			'regenerated',
			$result,
			'repair_missing_sizes() must include "regenerated" key on success (SIZE-02)'
		);
		$this->assertSame(
			2,
			$result['regenerated'],
			'regenerated must equal the count of sizes in the returned meta (SIZE-02)'
		);
	}

	// -------------------------------------------------------------------------
	// SIZE-02: repair_missing_sizes() returns ok=false on WP_Error
	// -------------------------------------------------------------------------

	/**
	 * repair_missing_sizes() returns ['ok' => false, 'reason' => error_code] when
	 * wp_update_image_subsizes() returns a WP_Error (e.g. both meta and file missing)
	 * (SIZE-02 / D-07 / Pitfall 3).
	 *
	 * @return void
	 */
	public function test_repair_missing_sizes_error(): void {
		$id = 16;

		// Seed a WP_Error — simulates attachment with no file or metadata.
		$GLOBALS['_assetdrips_update_subsizes_stub'][ $id ] = new \WP_Error( 'no_file', 'Attachment file not found.' );

		$engine = new SqueezeEngine(
			$this->wpdb,
			$this->backup_manager,
			$this->optimization_index
		);

		$result = $engine->repair_missing_sizes( $id );

		$this->assertIsArray( $result, 'repair_missing_sizes() must return an array even on error' );
		$this->assertFalse( $result['ok'], 'repair_missing_sizes() must return ok=false when WP_Error (SIZE-02)' );
		$this->assertArrayHasKey(
			'reason',
			$result,
			'repair_missing_sizes() must include "reason" key on WP_Error (SIZE-02)'
		);
		$this->assertSame(
			'no_file',
			$result['reason'],
			'reason must equal the WP_Error error code (SIZE-02, Pitfall 3)'
		);
	}
}
