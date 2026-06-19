<?php
/**
 * Unit tests for SqueezeEngine (CMP-01..04, RSZ-01..02).
 *
 * These tests are RED until Plan 09-02 implements SqueezeEngine. They define
 * the required behaviour; Plan 09-02 turns them GREEN.
 *
 * All WP functions are stubbed via unit-bootstrap.php. The editor factory is
 * injected as a closure so no real WP_Image_Editor is loaded. The $wpdb stub
 * is injected directly into the constructor.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Tests\Unit\Squeeze;

use AssetDrips\Squeeze\SqueezeEngine;
use PHPUnit\Framework\TestCase;

/**
 * Tests SqueezeEngine recompress / resize_original behaviour.
 */
final class SqueezeEngineTest extends TestCase {

	/**
	 * Temporary source file for encoder tests.
	 *
	 * @var string
	 */
	private string $src_file;

	/**
	 * Anonymous $wpdb stub.
	 *
	 * @var object
	 */
	private object $wpdb;

	/**
	 * Anonymous BackupManager stub (returns true for backup(), returns active records etc).
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
	 * Set up stubs and temp file.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$GLOBALS['_assetdrips_transient_stub'] = array();
		$GLOBALS['_assetdrips_options_stub']   = array();

		// Seed a real temp file as the "attachment file".
		$this->src_file = tempnam( sys_get_temp_dir(), 'sqz-src-' );
		file_put_contents( $this->src_file, str_repeat( 'J', 2048 ) );

		$GLOBALS['_assetdrips_attached_file_stub'][1] = $this->src_file;
		$GLOBALS['_assetdrips_post_mime_stub'][1]     = 'image/jpeg';

		// $wpdb stub with get_var (for content_hash read) and query (for content_hash write).
		$src_file_ref = &$this->src_file;
		$this->wpdb   = new class( $src_file_ref ) {
			/** @var string */
			public string $prefix = 'wp_';
			/** @var mixed */
			public mixed $next_var = null;
			/** @var array<int, mixed> */
			public array $queries = array();

			/** @param string $src_file_ref Source file reference (unused but kept for context). */
			public function __construct( string &$src_file_ref ) {} // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Stub.

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
			public function query( string $sql ): int|false { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Stub.
				$this->queries[] = $sql;
				return 1;
			}
		};

		// BackupManager stub: backup() returns true, restore_backup_file_only() tracks calls.
		$this->backup_manager = new class() {
			/** @var int */
			public int $backup_call_count = 0;
			/** @var int */
			public int $restore_file_only_call_count = 0;

			/**
			 * @param int    $id   Attachment ID.
			 * @param string $path Source path.
			 * @param string $op   Operation type.
			 * @return bool
			 */
			public function backup( int $id, string $path, string $op ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Stub.
				++$this->backup_call_count;
				return true;
			}

			/**
			 * @param int $id Attachment ID.
			 * @return bool
			 */
			public function restore_backup_file_only( int $id ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Stub.
				++$this->restore_file_only_call_count;
				return true;
			}
		};

		// OptimizationIndex stub.
		$this->optimization_index = new class() {
			/** @var array<int, array<string, mixed>> */
			public array $upsert_calls = array();
			/** @var array<int, array<string, mixed>> */
			public array $flag_calls = array();
			/** @var array<int, string> */
			public array $hash_calls = array();

			/**
			 * @param array<string, mixed> $row Squeeze row fields (attachment_id required).
			 * @return void
			 */
			public function upsert( array $row ): void {
				$this->upsert_calls[] = $row;
			}

			/**
			 * @param int    $id   Attachment ID.
			 * @param bool   $webp Has WebP.
			 * @param bool   $avif Has AVIF.
			 * @param bool   $ovr  Is oversized.
			 * @return void
			 */
			public function update_media_index_flags( int $id, bool $webp, bool $avif, bool $ovr ): void {
				$this->flag_calls[] = array( 'id' => $id, 'webp' => $webp, 'avif' => $avif, 'oversized' => $ovr );
			}

			/**
			 * @param int    $id   Attachment ID.
			 * @param string $hash SHA-1 hex string.
			 * @return void
			 */
			public function update_content_hash( int $id, string $hash ): void {
				$this->hash_calls[] = array( 'id' => $id, 'hash' => $hash );
			}

			/**
			 * @param int    $id     Attachment ID.
			 * @param string $status New status.
			 * @return void
			 */
			public function update_status( int $id, string $status ): void {} // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Stub.
		};
	}

	/**
	 * Remove temp files.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		if ( file_exists( $this->src_file ) ) {
			@unlink( $this->src_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Cleanup.
		}
	}

	/**
	 * Build a mock editor with call-tracking for set_quality and save.
	 *
	 * @param bool $is_jpeg Whether the save result should simulate JPEG output.
	 * @return object
	 */
	private function make_mock_editor( bool $is_jpeg = true ): object {
		$src_file = $this->src_file;
		return new class( $src_file, $is_jpeg ) {
			/** @var array<int, int> */
			public array $quality_calls = array();
			/** @var int */
			public int $save_call_count = 0;
			/** @var int */
			public int $resize_call_count = 0;
			/** @var string */
			private string $src;
			/** @var bool */
			private bool $jpeg;

			/**
			 * @param string $src  Source file path.
			 * @param bool   $jpeg Whether this is a JPEG mock.
			 */
			public function __construct( string $src, bool $jpeg ) {
				$this->src  = $src;
				$this->jpeg = $jpeg;
			}

			/**
			 * @param int $q Quality level.
			 * @return void
			 */
			public function set_quality( int $q ): void {
				$this->quality_calls[] = $q;
			}

			/**
			 * @param int  $w    Max width.
			 * @param int  $h    Max height.
			 * @param bool $crop Crop flag.
			 * @return void
			 */
			public function resize( int $w, int $h, bool $crop ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Stub.
				++$this->resize_call_count;
			}

			/**
			 * @return array{width: int, height: int}
			 */
			public function get_size(): array {
				return array( 'width' => 3000, 'height' => 2000 );
			}

			/**
			 * @param string $path Output path.
			 * @return array<string, mixed>
			 */
			public function save( string $path ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Stub; just tracks call count.
				++$this->save_call_count;
				// Write some bytes so filesize() returns > 0.
				file_put_contents( $this->src, str_repeat( $this->jpeg ? 'J' : 'P', 512 ) );
				return array(
					'path'      => $this->src,
					'file'      => basename( $this->src ),
					'width'     => 1600,
					'height'    => 1200,
					'mime-type' => $this->jpeg ? 'image/jpeg' : 'image/png',
					'filesize'  => 512,
				);
			}
		};
	}

	// -------------------------------------------------------------------------
	// CMP-01: recompress() calls set_quality(jpeg_quality) on JPEG
	// -------------------------------------------------------------------------

	/**
	 * recompress() calls set_quality with the configured JPEG quality then save()
	 * when processing a JPEG attachment.
	 *
	 * @return void
	 */
	public function test_recompress_calls_set_quality_for_jpeg(): void {
		$mock_editor = $this->make_mock_editor( true );
		$factory     = static function ( string $path ) use ( $mock_editor ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Stub.
			return $mock_editor;
		};

		$engine = new SqueezeEngine( $this->wpdb, $this->backup_manager, $this->optimization_index, $factory );
		$result = $engine->recompress( 1, array() );

		$this->assertNotEmpty( $mock_editor->quality_calls, 'recompress() must call set_quality() on a JPEG editor' );
		$this->assertGreaterThan( 0, $mock_editor->save_call_count, 'recompress() must call save() on the editor' );
		$this->assertTrue( $result['ok'] ?? false, 'recompress() must return ok=true on success' );
	}

	// -------------------------------------------------------------------------
	// CMP-02: recompress() calls set_quality(9) on PNG
	// -------------------------------------------------------------------------

	/**
	 * recompress() calls set_quality(9) when processing a PNG attachment for
	 * max deflate compression.
	 *
	 * @return void
	 */
	public function test_recompress_calls_set_quality_9_for_png(): void {
		$GLOBALS['_assetdrips_post_mime_stub'][1] = 'image/png';

		$mock_editor = $this->make_mock_editor( false );
		$factory     = static function ( string $path ) use ( $mock_editor ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Stub.
			return $mock_editor;
		};

		$engine = new SqueezeEngine( $this->wpdb, $this->backup_manager, $this->optimization_index, $factory );
		$engine->recompress( 1, array() );

		$this->assertContains( 9, $mock_editor->quality_calls, 'recompress() must call set_quality(9) on a PNG editor for max deflate' );
	}

	// -------------------------------------------------------------------------
	// CMP-03: recompress() returns skipped=true when sha1_file matches content_hash
	// -------------------------------------------------------------------------

	/**
	 * recompress() returns skipped=true when the file's SHA-1 hash matches the
	 * stored content_hash guard — prevents double-optimization.
	 *
	 * @return void
	 */
	public function test_recompress_returns_skipped_when_content_hash_matches(): void {
		// Seed the wpdb stub to return the current file's hash as the stored hash.
		$this->wpdb->next_var = sha1_file( $this->src_file );

		$factory = static function ( string $path ): never { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Stub; must not be called.
			throw new \RuntimeException( 'Editor factory must not be called when content_hash matches' );
		};

		$engine = new SqueezeEngine( $this->wpdb, $this->backup_manager, $this->optimization_index, $factory );
		$result = $engine->recompress( 1, array() );

		$this->assertFalse( $result['ok'] ?? true, 'recompress() must return ok=false when skipping' );
		$this->assertTrue( $result['skipped'] ?? false, 'recompress() must return skipped=true when content_hash matches' );
		$this->assertSame( 'already_optimized', $result['reason'] ?? '', 'recompress() must report reason=already_optimized' );
	}

	// -------------------------------------------------------------------------
	// CMP-03: recompress() writes content_hash of post-encode file
	// -------------------------------------------------------------------------

	/**
	 * recompress() writes the content_hash of the post-encode file to
	 * OptimizationIndex::update_content_hash() after a successful encode.
	 *
	 * @return void
	 */
	public function test_recompress_writes_content_hash_after_encode(): void {
		$mock_editor = $this->make_mock_editor( true );
		$factory     = static function ( string $path ) use ( $mock_editor ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Stub.
			return $mock_editor;
		};

		$engine = new SqueezeEngine( $this->wpdb, $this->backup_manager, $this->optimization_index, $factory );
		$engine->recompress( 1, array() );

		$this->assertNotEmpty( $this->optimization_index->hash_calls, 'recompress() must write content_hash after encode' );
		$this->assertSame( 1, $this->optimization_index->hash_calls[0]['id'] ?? null, 'content_hash must be written for attachment_id=1' );
		// The stored hash must be a 40-char SHA-1 hex string.
		$hash = $this->optimization_index->hash_calls[0]['hash'] ?? '';
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{40}$/', $hash, 'content_hash must be a 40-char SHA-1 hex string' );
	}

	// -------------------------------------------------------------------------
	// CMP-04: recompress() calls upsert_structural() and OptimizationIndex::upsert()
	// -------------------------------------------------------------------------

	/**
	 * recompress() calls OptimizationIndex::upsert() after a successful encode.
	 *
	 * @return void
	 */
	public function test_recompress_calls_optimization_index_upsert(): void {
		$mock_editor = $this->make_mock_editor( true );
		$factory     = static function ( string $path ) use ( $mock_editor ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Stub.
			return $mock_editor;
		};

		$engine = new SqueezeEngine( $this->wpdb, $this->backup_manager, $this->optimization_index, $factory );
		$engine->recompress( 1, array() );

		$this->assertCount( 1, $this->optimization_index->upsert_calls, 'recompress() must call OptimizationIndex::upsert() exactly once on success' );
		$this->assertSame( 1, $this->optimization_index->upsert_calls[0]['attachment_id'] ?? null, 'upsert() row must contain correct attachment_id' );
	}

	// -------------------------------------------------------------------------
	// RSZ-01: resize_original() returns was_oversized=false when within cap
	// -------------------------------------------------------------------------

	/**
	 * resize_original() returns was_oversized=false when the image's longest
	 * edge is within the max dimension cap — no resize performed.
	 *
	 * @return void
	 */
	public function test_resize_original_returns_not_oversized_when_within_cap(): void {
		// Mock editor returns 200×300 (both under cap of 2560).
		$small_editor = new class() {
			/** @return array{width: int, height: int} */
			public function get_size(): array { return array( 'width' => 200, 'height' => 300 ); }

			/** @param string $path Output path. */
			public function save( string $path ): array { return array( 'width' => 200, 'height' => 300, 'filesize' => 500 ); } // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Stub.

			/** @param int $w Width. @param int $h Height. @param bool $c Crop. */
			public function resize( int $w, int $h, bool $c ): void {} // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Stub.

			/** @param int $q Quality. */
			public function set_quality( int $q ): void {} // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Stub.
		};

		$factory = static function ( string $path ) use ( $small_editor ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Stub.
			return $small_editor;
		};

		$engine = new SqueezeEngine( $this->wpdb, $this->backup_manager, $this->optimization_index, $factory );
		$result = $engine->resize_original( 1, 2560 );

		$this->assertTrue( $result['ok'] ?? false, 'resize_original() must return ok=true for non-oversized image' );
		$this->assertFalse( $result['was_oversized'] ?? true, 'resize_original() must return was_oversized=false when within cap' );
	}

	// -------------------------------------------------------------------------
	// RSZ-01: resize_original() calls resize()+save()+wp_update_attachment_metadata
	//         and clears is_oversized flag on oversized image
	// -------------------------------------------------------------------------

	/**
	 * resize_original() calls resize(), save(), and wp_update_attachment_metadata()
	 * when the image is oversized; also clears the is_oversized flag via
	 * OptimizationIndex::update_media_index_flags().
	 *
	 * @return void
	 */
	public function test_resize_original_calls_resize_save_and_updates_metadata_for_oversized(): void {
		// Mock editor returns 3000×2000 (longest edge > 2560 cap).
		$big_editor = $this->make_mock_editor( true );

		$factory = static function ( string $path ) use ( $big_editor ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Stub.
			return $big_editor;
		};

		$GLOBALS['_assetdrips_attachment_meta_stub'][1] = array(
			'width'  => 3000,
			'height' => 2000,
			'sizes'  => array(),
		);

		$engine = new SqueezeEngine( $this->wpdb, $this->backup_manager, $this->optimization_index, $factory );
		$result = $engine->resize_original( 1, 2560 );

		$this->assertTrue( $result['ok'] ?? false, 'resize_original() must return ok=true for oversized image' );
		$this->assertTrue( $result['was_oversized'] ?? false, 'resize_original() must return was_oversized=true' );
		$this->assertGreaterThan( 0, $big_editor->resize_call_count, 'resize_original() must call $editor->resize() for oversized image' );
		$this->assertGreaterThan( 0, $big_editor->save_call_count, 'resize_original() must call $editor->save() for oversized image' );

		// wp_update_attachment_metadata() must have been called (seeded into global stub).
		$meta = $GLOBALS['_assetdrips_attachment_meta_stub'][1] ?? null;
		$this->assertNotNull( $meta, 'wp_update_attachment_metadata() must be called after resize' );

		// is_oversized flag must be cleared (false, false, false).
		$has_flag_clear = false;
		foreach ( $this->optimization_index->flag_calls as $call ) {
			if ( 1 === $call['id'] && false === $call['oversized'] ) {
				$has_flag_clear = true;
				break;
			}
		}
		$this->assertTrue( $has_flag_clear, 'resize_original() must clear is_oversized via update_media_index_flags(false,false,false)' );
	}

	// -------------------------------------------------------------------------
	// CR-03: recompress() calls restore_backup_file_only() when save() fails
	// -------------------------------------------------------------------------

	/**
	 * recompress() must call BackupManager::restore_backup_file_only() when
	 * WP_Image_Editor::save() returns a WP_Error — ensures a corrupted original
	 * is rolled back to the backup immediately on save failure.
	 *
	 * @return void
	 */
	public function test_recompress_calls_restore_backup_file_only_on_save_failure(): void {
		$src_file = $this->src_file;
		$failing_editor = new class( $src_file ) {
			/** @var string */
			private string $src;

			/** @param string $src Source file path. */
			public function __construct( string $src ) {
				$this->src = $src;
			}

			/** @param int $q Quality. */
			public function set_quality( int $q ): void {} // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Stub.

			/** @param string $path Output path. @return \WP_Error */
			public function save( string $path ): \WP_Error { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Stub; returns WP_Error to simulate save failure.
				return new \WP_Error( 'save_failed', 'Disk full' );
			}
		};

		$factory = static function ( string $path ) use ( $failing_editor ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Stub.
			return $failing_editor;
		};

		$engine = new SqueezeEngine( $this->wpdb, $this->backup_manager, $this->optimization_index, $factory );
		$result = $engine->recompress( 1, array() );

		$this->assertFalse( $result['ok'] ?? true, 'recompress() must return ok=false when save fails' );
		$this->assertSame( 'save_failed', $result['reason'] ?? '', 'recompress() must return reason=save_failed' );
		$this->assertSame( 1, $this->backup_manager->restore_file_only_call_count, 'recompress() must call restore_backup_file_only() exactly once on save failure' );
	}

	// -------------------------------------------------------------------------
	// CR-03: resize_original() calls restore_backup_file_only() when save() fails
	// -------------------------------------------------------------------------

	/**
	 * resize_original() must call BackupManager::restore_backup_file_only() when
	 * WP_Image_Editor::save() returns a WP_Error on an oversized image.
	 *
	 * @return void
	 */
	public function test_resize_original_calls_restore_backup_file_only_on_save_failure(): void {
		$src_file = $this->src_file;
		$failing_editor = new class( $src_file ) {
			/** @var string */
			private string $src;

			/** @param string $src Source file path. */
			public function __construct( string $src ) {
				$this->src = $src;
			}

			/** @return array{width: int, height: int} */
			public function get_size(): array {
				return array( 'width' => 3000, 'height' => 2000 );
			}

			/** @param int $w Width. @param int $h Height. @param bool $c Crop. */
			public function resize( int $w, int $h, bool $c ): void {} // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Stub.

			/** @param string $path Output path. @return \WP_Error */
			public function save( string $path ): \WP_Error { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Stub; returns WP_Error to simulate save failure.
				return new \WP_Error( 'save_failed', 'Disk full' );
			}
		};

		$factory = static function ( string $path ) use ( $failing_editor ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Stub.
			return $failing_editor;
		};

		$engine = new SqueezeEngine( $this->wpdb, $this->backup_manager, $this->optimization_index, $factory );
		$result = $engine->resize_original( 1, 2560 );

		$this->assertFalse( $result['ok'] ?? true, 'resize_original() must return ok=false when save fails' );
		$this->assertSame( 'save_failed', $result['reason'] ?? '', 'resize_original() must return reason=save_failed' );
		$this->assertSame( 1, $this->backup_manager->restore_file_only_call_count, 'resize_original() must call restore_backup_file_only() exactly once on save failure' );
	}
}
