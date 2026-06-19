<?php
/**
 * RED unit tests for SizesAuditJob audit/orphan/unused logic (SIZE-01, SIZE-03, SIZE-04).
 *
 * These tests are RED until Plan 13-04 implements SizesAuditJob.  They define the
 * required behaviour; Plan 13-04 turns them GREEN.
 *
 * All WP functions are stubbed via unit-bootstrap.php.  SizesAuditJob receives:
 *   - $wpdb  — anonymous-class stub (get_col paged, get_var count, prepare, query)
 *   - $optimization_index — anonymous-class stub (update_sizes_audit recorder)
 *   - $dir_reader — optional closure (filesystem seam; no real scandir called)
 *
 * The $dir_reader closure is injected via the SizesAuditJob constructor's third
 * parameter, mirroring the $editor_factory seam on SqueezeEngine.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Tests\Unit\Squeeze;

use AssetDrips\Squeeze\SizesAuditJob;
use PHPUnit\Framework\TestCase;

/**
 * RED tests for SizesAuditJob audit_single, orphan detection, and unused definitions.
 *
 * All six test methods reference SizesAuditJob (not yet implemented), so they MUST
 * fail/error now (RED state).  No production class is created in this task.
 */
final class SizesAuditJobTest extends TestCase {

	/**
	 * Anonymous $wpdb stub — configurable get_col pages and get_var count.
	 *
	 * @var object
	 */
	private object $wpdb;

	/**
	 * Anonymous $optimization_index stub — records update_sizes_audit calls.
	 *
	 * @var object
	 */
	private object $index;

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
		$GLOBALS['_assetdrips_missing_subsizes_stub']    = array();
		$GLOBALS['_assetdrips_registered_subsizes_stub'] = array();

		// $wpdb stub: paged get_col + configurable get_var count + prepare + query collector.
		$this->wpdb = new class() {
			/** @var string */
			public string $prefix = 'wp_';
			/** @var string */
			public string $posts  = 'wp_posts';
			/** @var mixed */
			public mixed $next_var = '0';
			/** @var array<int, array<int, int>> */
			public array $col_pages = array();
			/** @var int */
			private int $col_page = 0;
			/** @var array<int, string> */
			public array $queries = array();

			/**
			 * Configure paged ID sequence for repeated get_col() calls.
			 *
			 * @param array<int, array<int, int>> $pages Each element is one page of IDs.
			 * @return void
			 */
			public function set_col_pages( array $pages ): void {
				$this->col_pages = $pages;
				$this->col_page  = 0;
			}

			/**
			 * Returns the next page of IDs (empty when all pages consumed).
			 *
			 * @param string $sql Prepared SQL.
			 * @return array<int, mixed>
			 */
			public function get_col( string $sql ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Stub.
				if ( isset( $this->col_pages[ $this->col_page ] ) ) {
					return $this->col_pages[ $this->col_page++ ];
				}
				return array();
			}

			/**
			 * Returns the configured count.
			 *
			 * @param string $sql SQL query.
			 * @return mixed
			 */
			public function get_var( string $sql ): mixed { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Stub.
				return $this->next_var;
			}

			/**
			 * Returns the SQL template with args sprintf'd in (passthrough for unit context).
			 *
			 * @param string $sql  SQL template.
			 * @param mixed  ...$args Bound values.
			 * @return string
			 */
			public function prepare( string $sql, mixed ...$args ): string {
				if ( empty( $args ) ) {
					return $sql;
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.sprintf_sprintf -- Stub passthrough; safe in unit context.
				return sprintf( str_replace( '%d', '%s', $sql ), ...$args );
			}

			/**
			 * Records a SQL query.
			 *
			 * @param string $sql SQL to execute.
			 * @return int|false
			 */
			public function query( string $sql ): int|false {
				$this->queries[] = $sql;
				return 1;
			}
		};

		// $optimization_index stub: records update_sizes_audit calls.
		$this->index = new class() {
			/** @var array<int, string> */
			public array $upserted = array();

			/**
			 * Records the audit JSON for an attachment ID.
			 *
			 * @param int    $id   Attachment post ID.
			 * @param string $json JSON-encoded audit data.
			 * @return void
			 */
			public function update_sizes_audit( int $id, string $json ): void {
				$this->upserted[ $id ] = $json;
			}
		};
	}

	// -------------------------------------------------------------------------
	// SIZE-01: audit_single() returns {missing, orphaned, scanned_at}
	// -------------------------------------------------------------------------

	/**
	 * audit_single() returns an array with a 'missing' key containing size-name strings,
	 * and a non-empty 'scanned_at' key (SIZE-01 / D-02).
	 *
	 * Seed: two missing sizes ('thumbnail', 'medium_large') via the
	 * _assetdrips_missing_subsizes_stub registry.
	 *
	 * @return void
	 */
	public function test_audit_single_missing(): void {
		$id = 42;

		// Seed missing sizes: wp_get_missing_image_subsizes returns these.
		$GLOBALS['_assetdrips_missing_subsizes_stub'][ $id ] = array(
			'thumbnail'    => array( 'width' => 150, 'height' => 150, 'crop' => true ),
			'medium_large' => array( 'width' => 768, 'height' => 0,   'crop' => false ),
		);

		// Seed get_attached_file (needed for orphan-dir guard).
		$GLOBALS['_assetdrips_attached_file_stub'][ $id ] = '/tmp/photo.jpg';

		// Seed metadata: no sizes key (ensures no orphan scanning needed).
		$GLOBALS['_assetdrips_attachment_meta_stub'][ $id ] = array( 'sizes' => array() );

		// dir_reader returning empty list (no orphans).
		$dir_reader = static function ( string $dir ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Stub.
			return array();
		};

		$job    = new SizesAuditJob( $this->wpdb, $this->index, $dir_reader );
		$result = $this->invoke_audit_single( $job, $id );

		$this->assertIsArray( $result, 'audit_single() must return an array' );
		$this->assertArrayHasKey( 'missing', $result, 'audit_single() result must have a "missing" key' );
		$this->assertArrayHasKey( 'scanned_at', $result, 'audit_single() result must have a "scanned_at" key' );
		$this->assertNotEmpty( $result['scanned_at'], 'scanned_at must be a non-empty datetime string' );
		$this->assertContains(
			'thumbnail',
			$result['missing'],
			'audit_single() must include "thumbnail" in missing (SIZE-01)'
		);
		$this->assertContains(
			'medium_large',
			$result['missing'],
			'audit_single() must include "medium_large" in missing (SIZE-01)'
		);
	}

	// -------------------------------------------------------------------------
	// SIZE-03: find_orphaned_files() exclusion rules
	// -------------------------------------------------------------------------

	/**
	 * find_orphaned_files() excludes the original file and metadata-recorded size files;
	 * only truly orphaned thumbnails appear in 'orphaned' (SIZE-03 / D-04).
	 *
	 * Injects a dir_reader returning: [original, a metadata-known size, an orphan].
	 *
	 * @return void
	 */
	public function test_orphan_detection_excludes_known(): void {
		$id = 7;

		$GLOBALS['_assetdrips_attached_file_stub'][ $id ]    = '/uploads/2024/06/photo.jpg';
		$GLOBALS['_assetdrips_attachment_meta_stub'][ $id ]  = array(
			'sizes' => array(
				'thumbnail' => array( 'file' => 'photo-150x150.jpg', 'width' => 150, 'height' => 150 ),
			),
		);
		$GLOBALS['_assetdrips_missing_subsizes_stub'][ $id ] = array();

		// dir_reader returns: original, known thumbnail, and one genuine orphan.
		$dir_reader = static function ( string $dir ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Stub.
			return array( '.', '..', 'photo.jpg', 'photo-150x150.jpg', 'photo-99x99.jpg' );
		};

		$job    = new SizesAuditJob( $this->wpdb, $this->index, $dir_reader );
		$result = $this->invoke_audit_single( $job, $id );

		$this->assertIsArray( $result['orphaned'], 'orphaned must be an array' );
		$this->assertContains(
			'photo-99x99.jpg',
			$result['orphaned'],
			'photo-99x99.jpg is an orphan — must appear in orphaned (SIZE-03)'
		);
		$this->assertNotContains(
			'photo.jpg',
			$result['orphaned'],
			'The original file must NOT appear in orphaned (SIZE-03)'
		);
		$this->assertNotContains(
			'photo-150x150.jpg',
			$result['orphaned'],
			'Metadata-known size file must NOT appear in orphaned (SIZE-03)'
		);
	}

	/**
	 * find_orphaned_files() excludes the -scaled original (WP 5.3+ big-image fallback)
	 * stored in $meta['original_image'] (SIZE-03 / D-04).
	 *
	 * @return void
	 */
	public function test_orphan_detection_excludes_scaled(): void {
		$id = 8;

		$GLOBALS['_assetdrips_attached_file_stub'][ $id ]    = '/uploads/2024/06/photo-scaled.jpg';
		$GLOBALS['_assetdrips_attachment_meta_stub'][ $id ]  = array(
			'original_image' => 'photo.jpg',
			'sizes'          => array(),
		);
		$GLOBALS['_assetdrips_missing_subsizes_stub'][ $id ] = array();

		// dir_reader returns the scaled file + original. Both should be excluded.
		$dir_reader = static function ( string $dir ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Stub.
			return array( '.', '..', 'photo-scaled.jpg', 'photo.jpg' );
		};

		$job    = new SizesAuditJob( $this->wpdb, $this->index, $dir_reader );
		$result = $this->invoke_audit_single( $job, $id );

		$this->assertEmpty(
			$result['orphaned'],
			'The -scaled original and its original_image must NOT appear in orphaned (SIZE-03)'
		);
	}

	/**
	 * find_orphaned_files() excludes .webp and .avif sibling files (Phase 10 next-gen
	 * siblings appended with extra extensions) (SIZE-03 / D-04).
	 *
	 * The orphan regex matches {basename}-{W}x{H}.{orig_ext} only — a .webp or .avif
	 * appended-extension sibling uses a different extension and does NOT match.
	 *
	 * @return void
	 */
	public function test_orphan_detection_excludes_nextgen(): void {
		$id = 9;

		$GLOBALS['_assetdrips_attached_file_stub'][ $id ]    = '/uploads/2024/06/photo.jpg';
		$GLOBALS['_assetdrips_attachment_meta_stub'][ $id ]  = array( 'sizes' => array() );
		$GLOBALS['_assetdrips_missing_subsizes_stub'][ $id ] = array();

		// dir_reader returns next-gen siblings (.webp appended, .avif appended).
		$dir_reader = static function ( string $dir ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Stub.
			return array(
				'.',
				'..',
				'photo.jpg',
				'photo-150x150.jpg.webp',
				'photo.jpg.avif',
			);
		};

		$job    = new SizesAuditJob( $this->wpdb, $this->index, $dir_reader );
		$result = $this->invoke_audit_single( $job, $id );

		$this->assertNotContains(
			'photo-150x150.jpg.webp',
			$result['orphaned'],
			'.webp sibling must NOT be reported as orphaned (SIZE-03)'
		);
		$this->assertNotContains(
			'photo.jpg.avif',
			$result['orphaned'],
			'.avif sibling must NOT be reported as orphaned (SIZE-03)'
		);
	}

	/**
	 * find_orphaned_files() returns an empty array when the attachment directory does
	 * not exist locally (e.g. cloud-storage or deleted directory) (SIZE-03 / Pitfall 4).
	 *
	 * @return void
	 */
	public function test_orphan_detection_missing_dir(): void {
		$id = 10;

		// get_attached_file returns a path whose directory does not exist.
		$GLOBALS['_assetdrips_attached_file_stub'][ $id ]    = '/nonexistent/path/to/photo.jpg';
		$GLOBALS['_assetdrips_attachment_meta_stub'][ $id ]  = array( 'sizes' => array() );
		$GLOBALS['_assetdrips_missing_subsizes_stub'][ $id ] = array();

		// No dir_reader needed; the is_dir guard should prevent the call.
		$job    = new SizesAuditJob( $this->wpdb, $this->index );
		$result = $this->invoke_audit_single( $job, $id );

		$this->assertSame(
			array(),
			$result['orphaned'],
			'When directory is non-existent, orphaned must be an empty array (SIZE-03, Pitfall 4)'
		);
	}

	// -------------------------------------------------------------------------
	// SIZE-04: unused size definitions derivable from audit aggregate
	// -------------------------------------------------------------------------

	/**
	 * Given a set of per-attachment audit rows and a registered-sizes stub, the set of
	 * registered sizes used by zero attachments is computed correctly (SIZE-04 / D-05).
	 *
	 * This exercises the helper-level computation (not the full scan run) — the
	 * SizesAuditJob must expose a method or the computation must be derivable from
	 * the audit data. The test asserts a static/public helper on SizesAuditJob that
	 * computes unused definitions from a set of audit rows.
	 *
	 * @return void
	 */
	public function test_unused_definitions(): void {
		// Seed registered sizes: three sizes defined.
		$GLOBALS['_assetdrips_registered_subsizes_stub'] = array(
			'thumbnail'    => array( 'width' => 150, 'height' => 150, 'crop' => true ),
			'medium'       => array( 'width' => 300, 'height' => 300, 'crop' => false ),
			'medium_large' => array( 'width' => 768, 'height' => 0,   'crop' => false ),
		);

		// Simulate audit rows: every attachment has 'thumbnail' and 'medium' in their
		// metadata (i.e. not missing). 'medium_large' is absent from all attachments.
		// An "unused" definition means no attachment reports generating that size
		// (it is in every attachment's missing list).
		$audit_rows = array(
			array( 'missing' => array( 'medium_large' ), 'orphaned' => array(), 'scanned_at' => '2026-06-12 12:00:00' ),
			array( 'missing' => array( 'medium_large' ), 'orphaned' => array(), 'scanned_at' => '2026-06-12 12:00:01' ),
		);

		// SizesAuditJob must expose a method to compute unused definitions.
		// The method takes an array of decoded audit rows and returns size-name strings.
		$job   = new SizesAuditJob( $this->wpdb, $this->index );
		$unused = $job->compute_unused_definitions( $audit_rows );

		$this->assertIsArray( $unused, 'compute_unused_definitions() must return an array' );
		$this->assertContains(
			'medium_large',
			$unused,
			'medium_large is missing for ALL attachments — must be in unused definitions (SIZE-04)'
		);
		$this->assertNotContains(
			'thumbnail',
			$unused,
			'thumbnail is used (not missing) — must NOT be in unused definitions (SIZE-04)'
		);
		$this->assertNotContains(
			'medium',
			$unused,
			'medium is used (not missing) — must NOT be in unused definitions (SIZE-04)'
		);
	}

	// -------------------------------------------------------------------------
	// Helper: invoke audit_single() via reflection (private method).
	// -------------------------------------------------------------------------

	/**
	 * Invoke the private audit_single() method via reflection.
	 *
	 * @param SizesAuditJob $job The job instance.
	 * @param int           $id  Attachment ID.
	 * @return array<string, mixed>
	 */
	private function invoke_audit_single( SizesAuditJob $job, int $id ): array {
		$ref    = new \ReflectionClass( $job );
		$method = $ref->getMethod( 'audit_single' );
		$method->setAccessible( true );
		return (array) $method->invoke( $job, $id );
	}
}
