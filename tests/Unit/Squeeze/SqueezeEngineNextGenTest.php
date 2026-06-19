<?php
/**
 * RED tests for SqueezeEngine::generate_webp() and generate_avif() (NGN-01,02,03,04 / D-05,D-06,D-07,D-08,D-10).
 *
 * These tests are RED until Plan 10-02 implements generate_webp() and generate_avif()
 * on SqueezeEngine. They define the required behaviour; Plan 10-02 turns them GREEN.
 *
 * EXIT_EXPECTED_RED: Tests WILL fail because generate_webp() / generate_avif() do not
 * yet exist. Failures must be call-to-undefined-method errors or assertion failures on
 * return values — NOT syntax/parse errors or bootstrap fatals.
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
 * RED tests: SqueezeEngine generate_webp / generate_avif contracts.
 */
final class SqueezeEngineNextGenTest extends TestCase {

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
	 * Anonymous BackupManager stub.
	 *
	 * @var object
	 */
	private object $backup_manager;

	/**
	 * Anonymous OptimizationIndex stub (extended with get_flags()).
	 *
	 * @var object
	 */
	private object $optimization_index;

	/**
	 * Set up stubs, temp file, and attachment metadata.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$GLOBALS['_assetdrips_transient_stub'] = array();
		$GLOBALS['_assetdrips_options_stub']   = array();

		// Seed CapabilityProbe transient: webp=true, avif=false by default.
		$GLOBALS['_assetdrips_transient_stub']['assetdrips_squeeze_caps'] = array(
			'webp'    => true,
			'avif'    => false,
			'imagick' => false,
			'gd'      => true,
		);

		// Seed a real temp file as the "attachment original file".
		$this->src_file = tempnam( sys_get_temp_dir(), 'ngn-src-' );
		file_put_contents( $this->src_file, str_repeat( 'J', 2048 ) );

		$GLOBALS['_assetdrips_attached_file_stub'][1] = $this->src_file;
		$GLOBALS['_assetdrips_post_mime_stub'][1]     = 'image/jpeg';

		// Seed sub-size metadata with two sizes.
		$sub_thumb = dirname( $this->src_file ) . '/ngn-thumb-150x150.jpg';
		$sub_med   = dirname( $this->src_file ) . '/ngn-medium-300x200.jpg';
		file_put_contents( $sub_thumb, str_repeat( 'S', 256 ) );
		file_put_contents( $sub_med, str_repeat( 'S', 512 ) );

		$GLOBALS['_assetdrips_attachment_meta_stub'][1] = array(
			'width'  => 800,
			'height' => 600,
			'sizes'  => array(
				'thumbnail' => array(
					'file'   => basename( $sub_thumb ),
					'width'  => 150,
					'height' => 150,
				),
				'medium'    => array(
					'file'   => basename( $sub_med ),
					'width'  => 300,
					'height' => 200,
				),
			),
		);

		// $wpdb stub with prepare / get_var / query / get_row.
		$this->wpdb = new class() {
			/** @var string */
			public string $prefix = 'wp_';
			/** @var mixed */
			public mixed $next_var = null;
			/** @var array<int, mixed> */
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
			 * @param string $sql Prepared SQL.
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

			/**
			 * No assetdrips_media row in unit context — always null for flag reads.
			 *
			 * @param string $sql    Prepared SQL.
			 * @param string $output Output format constant.
			 * @return mixed
			 */
			public function get_row( string $sql, string $output = '' ): mixed { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Stub.
				return null;
			}
		};

		// BackupManager stub — Phase 10 tests assert it is NEVER called (D-04 additive-no-harm).
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

		// OptimizationIndex stub — includes get_flags() for is_oversized preservation (D-08).
		$this->optimization_index = new class() {
			/** @var array<int, array<string, mixed>> */
			public array $upsert_calls = array();
			/** @var array<int, array<string, mixed>> */
			public array $flag_calls = array();
			/** @var array<int, string> */
			public array $hash_calls = array();
			/** @var array<int, int> */
			public array $get_flags_calls = array();
			/**
			 * Configurable return for get_flags(). Default: all false.
			 * Seed to test is_oversized preservation: set flags_return['is_oversized'] = true.
			 *
			 * @var array{has_webp: bool, has_avif: bool, is_oversized: bool}
			 */
			public array $flags_return = array(
				'has_webp'     => false,
				'has_avif'     => false,
				'is_oversized' => false,
			);

			/**
			 * @param array<string, mixed> $row Squeeze row fields.
			 * @return void
			 */
			public function upsert( array $row ): void {
				$this->upsert_calls[] = $row;
			}

			/**
			 * @param int  $id      Attachment ID.
			 * @param bool $webp    Has WebP.
			 * @param bool $avif    Has AVIF.
			 * @param bool $ovr     Is oversized.
			 * @return void
			 */
			public function update_media_index_flags( int $id, bool $webp, bool $avif, bool $ovr ): void {
				$this->flag_calls[] = array(
					'id'        => $id,
					'webp'      => $webp,
					'avif'      => $avif,
					'oversized' => $ovr,
				);
			}

			/**
			 * @param int    $id   Attachment ID.
			 * @param string $hash SHA-1 hex string.
			 * @return void
			 */
			public function update_content_hash( int $id, string $hash ): void {
				$this->hash_calls[] = array(
					'id'   => $id,
					'hash' => $hash,
				);
			}

			/**
			 * @param int    $id     Attachment ID.
			 * @param string $status New status.
			 * @return void
			 */
			public function update_status( int $id, string $status ): void {} // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Stub.

			/**
			 * Read the three flag columns for the given attachment (D-08).
			 * Returns configurable flags_return array; default all-false.
			 *
			 * @param int $id Attachment ID.
			 * @return array{has_webp: bool, has_avif: bool, is_oversized: bool}
			 */
			public function get_flags( int $id ): array {
				$this->get_flags_calls[] = $id;
				return $this->flags_return;
			}
		};
	}

	/**
	 * Remove temp files and siblings.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		// Clean up source and siblings for the original.
		foreach ( array( '', '.webp', '.avif' ) as $ext ) {
			if ( file_exists( $this->src_file . $ext ) ) {
				@unlink( $this->src_file . $ext ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Cleanup.
			}
		}
		// Clean up sub-size files.
		$dir = dirname( $this->src_file );
		foreach ( glob( $dir . '/ngn-*' ) ?: array() as $f ) {
			@unlink( $f ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Cleanup.
		}
	}

	/**
	 * Build a mock WebP editor whose save($path, $mime) writes $write_bytes bytes to $path.
	 *
	 * @param bool $write_bytes Whether to write non-zero bytes (true) or empty file (0-byte case).
	 * @return object
	 */
	private function make_webp_editor( bool $write_bytes = true ): object {
		return new class( $write_bytes ) {
			/** @var int */
			public int $save_call_count = 0;
			/** @var int */
			public int $resize_call_count = 0;
			/** @var bool */
			private bool $write;

			/** @param bool $write Whether to write bytes to the output path. */
			public function __construct( bool $write ) {
				$this->write = $write;
			}

			/** @return array{width: int, height: int} */
			public function get_size(): array {
				return array(
					'width'  => 800,
					'height' => 600,
				);
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
			 * @param string $path Output path (the sibling file to write).
			 * @param string $mime MIME type (e.g. 'image/webp').
			 * @return array<string, mixed>|\WP_Error
			 */
			public function save( string $path, string $mime = '' ): array|\WP_Error { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Stub; $mime accepted to match real WP_Image_Editor signature.
				++$this->save_call_count;
				if ( $this->write ) {
					file_put_contents( $path, str_repeat( 'W', 512 ) );
					return array(
						'path'     => $path,
						'file'     => basename( $path ),
						'filesize' => 512,
					);
				}
				// 0-byte scenario: create empty file to simulate zero output.
				file_put_contents( $path, '' );
				return array(
					'path'     => $path,
					'file'     => basename( $path ),
					'filesize' => 0,
				);
			}
		};
	}

	// -------------------------------------------------------------------------
	// NGN-01 / test_webp_*: generate_webp() contracts
	// -------------------------------------------------------------------------

	/**
	 * generate_webp() returns ok=true when the probe reports webp=true and the
	 * mock editor writes non-zero bytes to the sibling path.
	 *
	 * @return void
	 */
	public function test_webp_returns_ok_true_when_probe_positive_and_editor_writes_bytes(): void {
		$editor  = $this->make_webp_editor( true );
		$factory = static function ( string $path ) use ( $editor ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Stub.
			return $editor;
		};

		$engine = new SqueezeEngine( $this->wpdb, $this->backup_manager, $this->optimization_index, $factory );
		$result = $engine->generate_webp( 1 );

		$this->assertTrue( $result['ok'] ?? false, 'generate_webp() must return ok=true on success' );
		$this->assertArrayHasKey( 'bytes', $result, 'generate_webp() must return bytes key on success' );
		$this->assertArrayHasKey( 'webp_path', $result, 'generate_webp() must return webp_path key on success' );
	}

	/**
	 * generate_webp() writes a {src}.webp sibling for the original AND for every
	 * registered sub-size in attachment metadata (NGN-01 / D-02).
	 *
	 * @return void
	 */
	public function test_webp_writes_sibling_for_original_and_all_sub_sizes(): void {
		$save_paths = array();
		$factory    = function ( string $path ) use ( &$save_paths ): object { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Stub; captures path for assertions.
			return new class( $path, $save_paths ) {
				/** @var string */
				private string $src;
				/** @var array<int, string> */
				private array $captured_paths;

				/**
				 * @param string            $src    Source path.
				 * @param array<int,string> $paths  Reference to collected save paths array.
				 */
				public function __construct( string $src, array &$paths ) {
					$this->src            = $src;
					$this->captured_paths = &$paths;
				}

				/** @return array{width: int, height: int} */
				public function get_size(): array {
					return array(
						'width'  => 300,
						'height' => 200,
					); }

				/** @param int $w Width. @param int $h Height. @param bool $c Crop. */
				public function resize( int $w, int $h, bool $c ): void {} // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Stub.

				/**
				 * @param string $path Output path.
				 * @param string $mime MIME type.
				 * @return array<string,mixed>
				 */
				public function save( string $path, string $mime = '' ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Stub.
					$this->captured_paths[] = $path;
					file_put_contents( $path, str_repeat( 'W', 128 ) );
					return array(
						'path'     => $path,
						'file'     => basename( $path ),
						'filesize' => 128,
					);
				}
			};
		};

		$engine = new SqueezeEngine( $this->wpdb, $this->backup_manager, $this->optimization_index, $factory );
		$engine->generate_webp( 1 );

		// Must have written at least 3 sibling files: original + 2 sub-sizes.
		$this->assertGreaterThanOrEqual( 3, count( $save_paths ), 'generate_webp() must write siblings for original + all registered sub-sizes' );
		$this->assertStringEndsWith( '.webp', $save_paths[0], 'First sibling path must end with .webp (original)' );
	}

	/**
	 * generate_webp() returns ok=false + skipped=true when the CapabilityProbe
	 * transient reports webp=false (NGN-01 capability gate).
	 *
	 * @return void
	 */
	public function test_webp_returns_skipped_when_probe_reports_webp_false(): void {
		// Override the probe transient to report webp unavailable.
		$GLOBALS['_assetdrips_transient_stub']['assetdrips_squeeze_caps'] = array(
			'webp'    => false,
			'avif'    => false,
			'imagick' => false,
			'gd'      => true,
		);

		$factory = static function ( string $path ): never { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Stub; must not be called when capability unavailable.
			throw new \RuntimeException( 'Editor factory must not be called when WebP capability is unavailable' );
		};

		$engine = new SqueezeEngine( $this->wpdb, $this->backup_manager, $this->optimization_index, $factory );
		$result = $engine->generate_webp( 1 );

		$this->assertFalse( $result['ok'] ?? true, 'generate_webp() must return ok=false when probe webp=false' );
		$this->assertTrue( $result['skipped'] ?? false, 'generate_webp() must return skipped=true when probe webp=false' );
	}

	// -------------------------------------------------------------------------
	// NGN-02 / test_avif_*: generate_avif() contracts
	// -------------------------------------------------------------------------

	/**
	 * generate_avif() returns ok=false + skipped=true AND records avif_skipped in
	 * ops_completed when the CapabilityProbe reports avif=false (NGN-02 / D-05).
	 *
	 * @return void
	 */
	public function test_avif_returns_skipped_and_records_avif_skipped_when_probe_reports_avif_false(): void {
		// Probe already seeds avif=false in setUp() — no override needed.
		$factory = static function ( string $path ): never { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Stub.
			throw new \RuntimeException( 'Editor factory must not be called when AVIF capability is unavailable' );
		};

		$engine = new SqueezeEngine( $this->wpdb, $this->backup_manager, $this->optimization_index, $factory );
		$result = $engine->generate_avif( 1 );

		$this->assertFalse( $result['ok'] ?? true, 'generate_avif() must return ok=false when probe avif=false' );
		$this->assertTrue( $result['skipped'] ?? false, 'generate_avif() must return skipped=true when probe avif=false' );

		// Verify avif_skipped appears in upsert ops_completed.
		$found_avif_skipped = false;
		foreach ( $this->optimization_index->upsert_calls as $call ) {
			if ( isset( $call['ops_completed'] ) && str_contains( (string) $call['ops_completed'], 'avif_skipped' ) ) {
				$found_avif_skipped = true;
				break;
			}
		}
		$this->assertTrue( $found_avif_skipped, 'generate_avif() must record avif_skipped in ops_completed upsert when probe avif=false' );
	}

	/**
	 * generate_avif() degrades to skipped (not error) when the mock editor save
	 * produces a 0-byte AVIF file (NGN-02 / D-06 zero_byte defensive posture).
	 *
	 * @return void
	 */
	public function test_avif_zero_byte_output_degrades_to_avif_skipped_not_error(): void {
		// Enable avif in probe so the capability gate passes.
		$GLOBALS['_assetdrips_transient_stub']['assetdrips_squeeze_caps'] = array(
			'webp'    => true,
			'avif'    => true,
			'imagick' => true,
			'gd'      => false,
		);

		// Editor writes 0-byte file.
		$editor  = $this->make_webp_editor( false );
		$factory = static function ( string $path ) use ( $editor ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Stub.
			return $editor;
		};

		$engine = new SqueezeEngine( $this->wpdb, $this->backup_manager, $this->optimization_index, $factory );
		$result = $engine->generate_avif( 1 );

		// Must not return ok=false with a fatal error reason; must degrade gracefully.
		$this->assertFalse( $result['ok'] ?? true, 'generate_avif() must return ok=false on 0-byte output' );
		$this->assertTrue( $result['skipped'] ?? false, 'generate_avif() must return skipped=true on 0-byte output (degrade, not error)' );
	}

	// -------------------------------------------------------------------------
	// NGN-03 / D-08 / test_additive_*: is_oversized preservation
	// -------------------------------------------------------------------------

	/**
	 * On successful WebP generation, update_media_index_flags is called with
	 * has_webp=true AND the previously-seeded is_oversized value is preserved,
	 * NOT clobbered (NGN-03 / D-08).
	 *
	 * @return void
	 */
	public function test_additive_webp_success_preserves_is_oversized_when_updating_flags(): void {
		// Seed is_oversized=true in the optimization_index stub flags return.
		$this->optimization_index->flags_return = array(
			'has_webp'     => false,
			'has_avif'     => false,
			'is_oversized' => true, // This value must be preserved after WebP generation.
		);

		$editor  = $this->make_webp_editor( true );
		$factory = static function ( string $path ) use ( $editor ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Stub.
			return $editor;
		};

		$engine = new SqueezeEngine( $this->wpdb, $this->backup_manager, $this->optimization_index, $factory );
		$result = $engine->generate_webp( 1 );

		$this->assertTrue( $result['ok'] ?? false, 'generate_webp() must succeed for is_oversized preservation test' );

		// update_media_index_flags must have been called with is_oversized=true preserved.
		$found_preservation = false;
		foreach ( $this->optimization_index->flag_calls as $call ) {
			if ( true === ( $call['webp'] ?? false ) && true === ( $call['oversized'] ?? false ) ) {
				$found_preservation = true;
				break;
			}
		}
		$this->assertTrue(
			$found_preservation,
			'update_media_index_flags() must be called with has_webp=true AND is_oversized preserved (not clobbered to false)'
		);

		// BackupManager must NOT have been called (D-04 additive-no-harm).
		$this->assertSame( 0, $this->backup_manager->backup_call_count, 'generate_webp() must never call BackupManager::backup() (D-04)' );
	}

	// -------------------------------------------------------------------------
	// NGN-03 / D-07 / test_zero_byte_*: 0-byte full-size sibling handling
	// -------------------------------------------------------------------------

	/**
	 * A 0-byte full-size WebP output must result in the sibling being unlinked
	 * and has_webp staying false (NGN-03 / D-07).
	 *
	 * @return void
	 */
	public function test_zero_byte_full_size_output_unlinks_sibling_and_has_webp_stays_false(): void {
		// Editor writes 0-byte file.
		$editor  = $this->make_webp_editor( false );
		$factory = static function ( string $path ) use ( $editor ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Stub.
			return $editor;
		};

		$engine = new SqueezeEngine( $this->wpdb, $this->backup_manager, $this->optimization_index, $factory );
		$result = $engine->generate_webp( 1 );

		// Sibling must not exist (deleted) after 0-byte guard.
		$this->assertFileDoesNotExist( $this->src_file . '.webp', '0-byte .webp sibling must be deleted after post-encode guard' );

		// has_webp must remain false (never set to true for a 0-byte file).
		$has_webp_true_found = false;
		foreach ( $this->optimization_index->flag_calls as $call ) {
			if ( true === ( $call['webp'] ?? false ) ) {
				$has_webp_true_found = true;
				break;
			}
		}
		$this->assertFalse( $has_webp_true_found, 'update_media_index_flags() must NOT set has_webp=true when the full-size sibling was 0-byte' );
	}

	// -------------------------------------------------------------------------
	// NGN-04 / test_additive_*: BackupManager must NOT be called
	// -------------------------------------------------------------------------

	/**
	 * generate_webp() NEVER invokes the BackupManager stub — generation is
	 * purely additive, never destructive (NGN-04 / D-04).
	 *
	 * @return void
	 */
	public function test_additive_webp_never_calls_backup_manager(): void {
		$editor  = $this->make_webp_editor( true );
		$factory = static function ( string $path ) use ( $editor ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Stub.
			return $editor;
		};

		$engine = new SqueezeEngine( $this->wpdb, $this->backup_manager, $this->optimization_index, $factory );
		$engine->generate_webp( 1 );

		$this->assertSame(
			0,
			$this->backup_manager->backup_call_count,
			'generate_webp() must NEVER call BackupManager::backup() — generation is additive (NGN-04 / D-04)'
		);
	}

	// -------------------------------------------------------------------------
	// D-10 / test_time_budget_*: AVIF per-image time budget abort
	// -------------------------------------------------------------------------

	/**
	 * generate_avif() aborts to avif_skipped when a mock encode exceeds the
	 * per-image time budget (D-10).
	 *
	 * This test uses a slow editor that sleeps briefly to simulate budget overrun.
	 * The time budget must be configurable or very short to keep the test fast.
	 *
	 * @return void
	 */
	public function test_time_budget_avif_aborts_to_skipped_on_encode_overrun(): void {
		// Enable avif in probe so the capability gate passes.
		$GLOBALS['_assetdrips_transient_stub']['assetdrips_squeeze_caps'] = array(
			'webp'    => true,
			'avif'    => true,
			'imagick' => true,
			'gd'      => false,
		);

		// Slow editor: sleeps 0.1s to exceed a very tight time budget.
		$slow_editor = new class() {
			/** @var int */
			public int $save_call_count = 0;

			/** @return array{width: int, height: int} */
			public function get_size(): array {
				return array(
					'width'  => 800,
					'height' => 600,
				); }

			/** @param int $w Width. @param int $h Height. @param bool $c Crop. */
			public function resize( int $w, int $h, bool $c ): void {} // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Stub.

			/**
			 * Slow save — usleep(120000) = 120 ms to exceed tight budget.
			 *
			 * @param string $path Output path.
			 * @param string $mime MIME type.
			 * @return array<string,mixed>
			 */
			public function save( string $path, string $mime = '' ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Stub.
				++$this->save_call_count;
				usleep( 120_000 ); // 120 ms — forces budget overrun.
				file_put_contents( $path, str_repeat( 'A', 128 ) );
				return array(
					'path'     => $path,
					'file'     => basename( $path ),
					'filesize' => 128,
				);
			}
		};

		$factory = static function ( string $path ) use ( $slow_editor ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Stub.
			return $slow_editor;
		};

		$engine = new SqueezeEngine( $this->wpdb, $this->backup_manager, $this->optimization_index, $factory, 0.05 );
		$result = $engine->generate_avif( 1, 0.05 );

		$this->assertFalse( $result['ok'] ?? true, 'generate_avif() must return ok=false when time budget is exceeded' );
		$this->assertTrue( $result['skipped'] ?? false, 'generate_avif() must return skipped=true on time budget overrun (not a fatal error)' );
	}
}
