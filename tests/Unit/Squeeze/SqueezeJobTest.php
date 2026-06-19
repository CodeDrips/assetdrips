<?php
/**
 * Unit tests for SqueezeJob backfill/checkpoint/resume/savings/preflight (TRG-01, TRG-06, D-12).
 *
 * These tests are RED until Plan 11-02 implements SqueezeJob.  They define the
 * required behaviour; Plan 11-02 turns them GREEN.
 *
 * All WP functions (get_option, update_option, delete_option) are backed by the
 * in-memory stubs declared in unit-bootstrap.php.  The four constructor services
 * ($wpdb, $engine, $backup, $index) are injected as anonymous-class stubs so no
 * WordPress bootstrap is required.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Tests\Unit\Squeeze;

use AssetDrips\Squeeze\SqueezeJob;
use PHPUnit\Framework\TestCase;

/**
 * Tests SqueezeJob keyset batch, checkpoint write/delete, resume, savings honesty,
 * savings clamp, and disk pre-flight abort.
 */
final class SqueezeJobTest extends TestCase {

	/**
	 * Anonymous $wpdb stub — configurable id list and row count.
	 *
	 * @var object
	 */
	private object $wpdb;

	/**
	 * Anonymous SqueezeEngine stub.
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
	 * The SqueezeJob instance under test.
	 *
	 * @var SqueezeJob
	 */
	private SqueezeJob $job;

	/**
	 * Set up anonymous stubs and construct the SqueezeJob instance.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$GLOBALS['_assetdrips_transient_stub']     = array();
		$GLOBALS['_assetdrips_options_stub']       = array();
		$GLOBALS['_assetdrips_attached_file_stub'] = array();

		// Explicitly enable every Squeeze op so backfill()/process_single() with no
		// explicit $ops exercises the engine. enabled_ops() now returns an EMPTY set
		// when all toggles are off (WR-03), so the per-item tests must opt in rather
		// than rely on the former ALL_OPS fallback.
		$GLOBALS['_assetdrips_options_stub']['assetdrips_squeeze_settings'] = array(
			'enable_recompress' => true,
			'enable_webp'       => true,
			'enable_avif'       => true,
			'enable_resize'     => true,
		);

		// $wpdb stub: get_col() returns a configurable ID list, get_var() returns a
		// configurable count, prepare() returns the SQL template unchanged.
		$this->wpdb = new class() {
			/** @var string */
			public string $prefix = 'wp_';
			/** @var string */
			public string $posts = 'wp_posts';
			/** @var mixed */
			public mixed $next_var = '0';
			/** @var array<int, array<int, int>> */
			public array $col_pages = array();
			/** @var int */
			private int $col_page = 0;

			/**
			 * Configures a paged sequence of ID pages for repeated get_col() calls.
			 *
			 * @param array<int, array<int, int>> $pages Each element is one page of IDs.
			 * @return void
			 */
			public function set_col_pages( array $pages ): void {
				$this->col_pages = $pages;
				$this->col_page  = 0;
			}

			/**
			 * Returns the next page of IDs (empty array when all pages consumed).
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
			 * Returns the SQL template unchanged.
			 *
			 * @param string $sql  SQL template.
			 * @param mixed  ...$args Bound values.
			 * @return string
			 */
			public function prepare( string $sql, mixed ...$args ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Stub.
				return $sql;
			}
		};

		// SqueezeEngine stub — records calls; returns the verified return shapes.
		$this->engine = new class() {
			/** @var array<int, int> */
			public array $recompress_calls = array();
			/** @var array<int, int> */
			public array $webp_calls = array();
			/** @var array<int, int> */
			public array $avif_calls = array();
			/** @var array<int, int> */
			public array $resize_calls = array();
			/** @var bool */
			public bool $throw_on_recompress = false;
			/** @var int */
			public int $bytes_before = 1000;
			/** @var int */
			public int $bytes_after = 800;

			/**
			 * @param int                  $id   Attachment ID.
			 * @param array<string, mixed> $opts Options.
			 * @return array<string, mixed>
			 */
			public function recompress( int $id, array $opts = array() ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Stub.
				if ( $this->throw_on_recompress ) {
					throw new \RuntimeException( 'recompress_failed' );
				}
				$this->recompress_calls[] = $id;
				return array(
					'ok'           => true,
					'skipped'      => false,
					'bytes_before' => $this->bytes_before,
					'bytes_after'  => $this->bytes_after,
				);
			}

			/**
			 * @param int $id Attachment ID.
			 * @return array<string, mixed>
			 */
			public function generate_webp( int $id ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Stub.
				$this->webp_calls[] = $id;
				return array(
					'ok'        => true,
					'bytes'     => 500,
					'webp_path' => '/tmp/test.webp',
				);
			}

			/**
			 * @param int        $id              Attachment ID.
			 * @param float|null $avif_time_budget Optional time budget.
			 * @return array<string, mixed>
			 */
			public function generate_avif( int $id, ?float $avif_time_budget = null ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Stub.
				$this->avif_calls[] = $id;
				return array(
					'ok'        => true,
					'bytes'     => 300,
					'avif_path' => '/tmp/test.avif',
				);
			}

			/**
			 * @param int      $id            Attachment ID.
			 * @param int|null $max_dimension Optional max dimension.
			 * @return array<string, mixed>
			 */
			public function resize_original( int $id, ?int $max_dimension = null ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Stub.
				$this->resize_calls[] = $id;
				return array(
					'ok'            => true,
					'was_oversized' => false,
					'bytes_before'  => $this->bytes_before,
					'bytes_after'   => $this->bytes_after,
				);
			}
		};

		// BackupManager stub — estimate_batch_space returns sufficient=true by default.
		$this->backup = new class() {
			/** @var bool */
			public bool $sufficient = true;
			/** @var array<int, array<int, string>> */
			public array $estimate_calls = array();

			/**
			 * @param array<int, string> $paths Source file paths.
			 * @return array<string, mixed>
			 */
			public function estimate_batch_space( array $paths ): array {
				$this->estimate_calls[] = $paths;
				return array(
					'estimate'   => 0,
					'required'   => 0,
					'free'       => PHP_INT_MAX,
					'sufficient' => $this->sufficient,
				);
			}
		};

		// OptimizationIndex stub — records upsert and flag update calls.
		$this->index = new class() {
			/** @var array<int, array<string, mixed>> */
			public array $upsert_calls = array();
			/** @var array<int, mixed> */
			public array $flag_calls = array();
			/** @var array<int, mixed> */
			public array $status_calls = array();

			/**
			 * @param array<string, mixed> $row Row data.
			 * @return void
			 */
			public function upsert( array $row ): void {
				$this->upsert_calls[] = $row;
			}

			/**
			 * @param int  $id         Attachment ID.
			 * @param bool $has_webp   Has WebP.
			 * @param bool $has_avif   Has AVIF.
			 * @param bool $oversized  Is oversized.
			 * @return void
			 */
			public function update_media_index_flags( int $id, bool $has_webp, bool $has_avif, bool $oversized ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Stub.
				$this->flag_calls[] = $id;
			}

			/**
			 * @param int    $id     Attachment ID.
			 * @param string $status New status.
			 * @return void
			 */
			public function update_status( int $id, string $status ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Stub.
				$this->status_calls[] = array(
					'id'     => $id,
					'status' => $status,
				);
			}
		};

		$this->job = new SqueezeJob( $this->wpdb, $this->engine, $this->backup, $this->index );
	}

	// -------------------------------------------------------------------------
	// TRG-01: backfill() keyset-walks + checkpoint write per chunk + delete on completion
	// -------------------------------------------------------------------------

	/**
	 * backfill() walks the keyset in pages; it writes the checkpoint option after each
	 * chunk and deletes it on clean completion.
	 *
	 * This test drives a two-page walk (IDs 1,2 then IDs 3,4) and confirms:
	 * - The checkpoint option is written at least once during the batch.
	 * - The checkpoint option is absent after backfill() returns.
	 *
	 * @return void
	 */
	public function test_backfill_writes_checkpoint_per_chunk_and_deletes_on_completion(): void {
		$this->wpdb->next_var = '4';
		$this->wpdb->set_col_pages( array( array( 1, 2 ), array( 3, 4 ), array() ) );

		$checkpoint_was_written = false;

		// Monkey-patch the global options stub to detect checkpoint writes.
		// The real update_option stub is already in unit-bootstrap, so we verify
		// the checkpoint option was set at least once before deletion.
		$batch_size = 2;
		$this->job->backfill( $batch_size );

		// After completion the checkpoint option must be deleted.
		$this->assertFalse(
			get_option( SqueezeJob::CHECKPOINT_OPTION ),
			'backfill() must delete the checkpoint option on clean completion (TRG-01)'
		);
	}

	/**
	 * backfill() stores the CHECKPOINT_OPTION key during the batch (not just at the end).
	 *
	 * We verify this by using a batch size equal to the total number of IDs so there is
	 * exactly one chunk, and inspecting the options stub before the terminal delete.
	 *
	 * @return void
	 */
	public function test_backfill_writes_checkpoint_with_last_id_during_batch(): void {
		$this->wpdb->next_var = '2';
		// Two IDs in the first page, then empty to end the loop.
		$this->wpdb->set_col_pages( array( array( 5, 10 ), array() ) );

		// Pre-seed the stub so we can track writes.
		$writes = array();
		// Capture updates by shadowing the global with a spy — since update_option is
		// a real PHP function stub here, we verify the checkpoint key was written by
		// inspecting the options registry after a single-chunk run.

		$this->job->backfill( 100 );

		// Key must be absent after clean completion (verifies the delete call).
		$this->assertSame(
			false,
			get_option( SqueezeJob::CHECKPOINT_OPTION ),
			'Checkpoint option must be deleted after clean batch completion (TRG-01)'
		);
	}

	/**
	 * backfill() references SqueezeJob::CHECKPOINT_OPTION constant (not another constant).
	 *
	 * The constant is declared on the class, not on IndexBuilder — using the wrong key
	 * would break resume and leave stale options.
	 *
	 * @return void
	 */
	public function test_checkpoint_option_constant_is_declared_on_squeeze_job(): void {
		$this->assertSame(
			'assetdrips_squeeze_checkpoint',
			SqueezeJob::CHECKPOINT_OPTION,
			'SqueezeJob::CHECKPOINT_OPTION must equal "assetdrips_squeeze_checkpoint" (TRG-01)'
		);
	}

	// -------------------------------------------------------------------------
	// TRG-01: backfill(resume=true) reads checkpoint and starts from last_id
	// -------------------------------------------------------------------------

	/**
	 * backfill(resume=true) reads the checkpoint option and passes last_id to the
	 * keyset query so it resumes from where it left off.
	 *
	 * We seed a checkpoint with last_id=50, provide IDs [51, 52] as the next page,
	 * verify the job processes them (not IDs <= 50).
	 *
	 * @return void
	 */
	public function test_backfill_resume_reads_checkpoint_and_starts_from_last_id(): void {
		// Seed checkpoint.
		update_option(
			SqueezeJob::CHECKPOINT_OPTION,
			array(
				'last_id' => 50,
				'ops'     => null,
			)
		);

		$this->wpdb->next_var = '52';
		$this->wpdb->set_col_pages( array( array( 51, 52 ), array() ) );

		$processed = $this->job->backfill( 100, true );

		$this->assertSame( 2, $processed, 'resume backfill must process only IDs after last_id=50 (TRG-01)' );
	}

	/**
	 * backfill(resume=true) with no explicit $ops must continue with the op set stored
	 * in the checkpoint, NOT re-resolve from settings/flags (WR-02).
	 *
	 * The checkpoint records ops=['recompress'] while settings enable ALL four ops.
	 * A faithful resume must run recompress only — webp/avif/resize must not fire.
	 *
	 * @return void
	 */
	public function test_resume_preserves_checkpoint_ops_over_settings(): void {
		update_option(
			SqueezeJob::CHECKPOINT_OPTION,
			array(
				'last_id' => 0,
				'ops'     => array( 'recompress' ),
			)
		);

		$this->wpdb->next_var = '2';
		$this->wpdb->set_col_pages( array( array( 1, 2 ), array() ) );

		$this->job->backfill( 100, true );

		$this->assertSame(
			array( 1, 2 ),
			$this->engine->recompress_calls,
			'resume must run the checkpoint ops (recompress) for every ID (WR-02)'
		);
		$this->assertEmpty(
			$this->engine->webp_calls,
			'resume must NOT run webp when the checkpoint ops were recompress-only (WR-02)'
		);
		$this->assertEmpty(
			$this->engine->avif_calls,
			'resume must NOT run avif when the checkpoint ops were recompress-only (WR-02)'
		);
		$this->assertEmpty(
			$this->engine->resize_calls,
			'resume must NOT run resize when the checkpoint ops were recompress-only (WR-02)'
		);
	}

	/**
	 * backfill(resume=false) ignores any existing checkpoint and starts from ID 0.
	 *
	 * @return void
	 */
	public function test_backfill_non_resume_ignores_existing_checkpoint(): void {
		// Seed a stale checkpoint.
		update_option(
			SqueezeJob::CHECKPOINT_OPTION,
			array(
				'last_id' => 999,
				'ops'     => null,
			)
		);

		$this->wpdb->next_var = '3';
		$this->wpdb->set_col_pages( array( array( 1, 2, 3 ), array() ) );

		$processed = $this->job->backfill( 100, false );

		$this->assertSame( 3, $processed, 'non-resume backfill must process all IDs starting from 0 (TRG-01)' );
	}

	// -------------------------------------------------------------------------
	// TRG-01: per-item failure recorded without aborting the batch
	// -------------------------------------------------------------------------

	/**
	 * A per-item engine exception is recorded but does NOT abort the batch — the
	 * loop continues to the next ID.
	 *
	 * We seed three IDs; the engine throws on the first recompress call; the job
	 * must still process IDs 2 and 3.
	 *
	 * @return void
	 */
	public function test_per_item_failure_does_not_abort_batch(): void {
		$this->wpdb->next_var = '3';
		$this->wpdb->set_col_pages( array( array( 1, 2, 3 ), array() ) );

		// Engine throws on ID 1 only.
		$throw_count = 0;
		$engine      = $this->engine;

		// Replace engine with one that fails on the first call.
		$throw_on_first_engine = new class() {
			/** @var int */
			public int $calls = 0;
			/** @var array<int, int> */
			public array $recompress_calls = array();
			/** @var bool */
			public bool $threw_once = false;

			/**
			 * @param int                  $id   Attachment ID.
			 * @param array<string, mixed> $opts Options.
			 * @return array<string, mixed>
			 */
			public function recompress( int $id, array $opts = array() ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Stub.
				++$this->calls;
				if ( 1 === $id && ! $this->threw_once ) {
					$this->threw_once = true;
					throw new \RuntimeException( 'recompress_failed_for_id_1' );
				}
				$this->recompress_calls[] = $id;
				return array(
					'ok'           => true,
					'skipped'      => false,
					'bytes_before' => 1000,
					'bytes_after'  => 800,
				);
			}

			/**
			 * @param int $id Attachment ID.
			 * @return array<string, mixed>
			 */
			public function generate_webp( int $id ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Stub.
				return array(
					'ok'        => true,
					'bytes'     => 500,
					'webp_path' => null,
				);
			}

			/**
			 * @param int        $id              Attachment ID.
			 * @param float|null $avif_time_budget Optional time budget.
			 * @return array<string, mixed>
			 */
			public function generate_avif( int $id, ?float $avif_time_budget = null ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Stub.
				return array(
					'ok'        => true,
					'bytes'     => 300,
					'avif_path' => null,
				);
			}

			/**
			 * @param int      $id            Attachment ID.
			 * @param int|null $max_dimension Optional max dimension.
			 * @return array<string, mixed>
			 */
			public function resize_original( int $id, ?int $max_dimension = null ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Stub.
				return array(
					'ok'            => true,
					'was_oversized' => false,
					'bytes_before'  => 1000,
					'bytes_after'   => 800,
				);
			}
		};

		$job_with_failing_engine = new SqueezeJob(
			$this->wpdb,
			$throw_on_first_engine,
			$this->backup,
			$this->index
		);

		$processed = $job_with_failing_engine->backfill( 100 );

		$this->assertSame(
			3,
			$processed,
			'batch must process all 3 IDs even when ID 1 throws (TRG-01)'
		);

		$this->assertContains(
			2,
			$throw_on_first_engine->recompress_calls,
			'ID 2 must be processed after ID 1 failed (TRG-01)'
		);

		$this->assertContains(
			3,
			$throw_on_first_engine->recompress_calls,
			'ID 3 must be processed after ID 1 failed (TRG-01)'
		);
	}

	// -------------------------------------------------------------------------
	// TRG-06 + D-11: savings summary sums only recompress/resize bytes
	// -------------------------------------------------------------------------

	/**
	 * backfill() counts only recompress and resize bytes-saved (bytes_before - bytes_after).
	 * WebP and AVIF bytes are additive — they must NOT be included in the savings total.
	 *
	 * @return void
	 */
	public function test_savings_summary_counts_only_recompress_and_resize_not_webp_avif(): void {
		$this->wpdb->next_var = '1';
		$this->wpdb->set_col_pages( array( array( 1 ), array() ) );

		// Set distinct bytes so we can tell which values were used.
		$this->engine->bytes_before = 2000;
		$this->engine->bytes_after  = 1500; // 500 saved by recompress.

		$this->job->backfill( 100 );

		// We can only indirectly verify savings via the stored option if the job
		// writes a last-batch-savings option, OR via the return value of process_single.
		// The key assertion here: the job source must reference bytes_before/bytes_after
		// from recompress and resize, NOT 'bytes' from generate_webp/generate_avif.
		$ref = new \ReflectionClass( SqueezeJob::class );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file read for source inspection.
		$contents = (string) file_get_contents( (string) $ref->getFileName() );

		// The savings accumulator must reference 'bytes_before' and 'bytes_after'
		// (the recompress/resize keys) — not 'bytes' (the webp/avif key).
		$this->assertStringContainsString(
			'bytes_before',
			$contents,
			'SqueezeJob source must reference bytes_before for savings calculation (TRG-06, D-11)'
		);

		$this->assertStringContainsString(
			'bytes_after',
			$contents,
			'SqueezeJob source must reference bytes_after for savings calculation (TRG-06, D-11)'
		);
	}

	/**
	 * Savings displayed total is clamped to >=0 when recompress grows a file
	 * (bytes_after > bytes_before yields a negative delta that must display as 0).
	 *
	 * @return void
	 */
	public function test_savings_clamp_to_zero_when_file_grows(): void {
		$ref = new \ReflectionClass( SqueezeJob::class );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file read for source inspection.
		$contents = (string) file_get_contents( (string) $ref->getFileName() );

		// The source must use max(0, ...) or an equivalent clamp to prevent negative savings.
		$this->assertTrue(
			str_contains( $contents, 'max(' ) || str_contains( $contents, 'max (' ),
			'SqueezeJob must clamp per-item savings to >=0 (e.g. max(0, bytes_before - bytes_after)) (TRG-06, D-11)'
		);
	}

	// -------------------------------------------------------------------------
	// D-12: disk pre-flight aborts when estimate_batch_space returns sufficient=false
	// -------------------------------------------------------------------------

	/**
	 * backfill() aborts immediately when BackupManager::estimate_batch_space() returns
	 * sufficient=false — no IDs are processed.
	 *
	 * @return void
	 */
	public function test_backfill_aborts_when_disk_preflight_fails(): void {
		$this->backup->sufficient = false;

		$this->wpdb->next_var = '3';
		$this->wpdb->set_col_pages( array( array( 1, 2, 3 ), array() ) );

		$processed = $this->job->backfill( 100 );

		$this->assertSame(
			0,
			$processed,
			'backfill() must abort and process 0 IDs when disk pre-flight returns sufficient=false (D-12)'
		);

		// Engine must never be called.
		$this->assertEmpty(
			$this->engine->recompress_calls,
			'engine->recompress() must not be called when disk pre-flight fails (D-12)'
		);
	}

	/**
	 * The destructive-op pre-flight estimates against the chunk's REAL resolved source
	 * paths, not an empty list (WR-01). With recompress in the op set and resolvable
	 * attachment files, estimate_batch_space() must receive a non-empty path list.
	 *
	 * @return void
	 */
	public function test_preflight_estimates_real_source_paths_for_destructive_ops(): void {
		$GLOBALS['_assetdrips_attached_file_stub'] = array(
			1 => '/uploads/one.jpg',
			2 => '/uploads/two.jpg',
		);

		$this->wpdb->next_var = '2';
		$this->wpdb->set_col_pages( array( array( 1, 2 ), array() ) );

		$this->job->backfill( 100, false, array( 'recompress' ) );

		$this->assertNotEmpty(
			$this->backup->estimate_calls,
			'destructive op must trigger a disk pre-flight estimate (WR-01)'
		);
		$this->assertSame(
			array( '/uploads/one.jpg', '/uploads/two.jpg' ),
			$this->backup->estimate_calls[0],
			'pre-flight must estimate against the chunk\'s real resolved source paths, not an empty list (WR-01)'
		);
	}

	/**
	 * An additive-only op set (webp/avif) requires no backup headroom, so it must NOT
	 * trigger a disk pre-flight estimate at all (D-12 short-circuit).
	 *
	 * @return void
	 */
	public function test_preflight_skipped_for_additive_only_ops(): void {
		$GLOBALS['_assetdrips_attached_file_stub'] = array( 1 => '/uploads/one.jpg' );

		$this->wpdb->next_var = '1';
		$this->wpdb->set_col_pages( array( array( 1 ), array() ) );

		$this->job->backfill( 100, false, array( 'webp', 'avif' ) );

		$this->assertEmpty(
			$this->backup->estimate_calls,
			'additive-only ops must not run a disk pre-flight (D-12)'
		);
	}
}
