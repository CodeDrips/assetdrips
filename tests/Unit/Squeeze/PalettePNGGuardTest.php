<?php
/**
 * Mandatory palette-PNG fixture test (PITFALLS §2 / NGN-03).
 *
 * This test is RED until Plan 10-02 implements generate_webp() with the
 * palette-PNG truecolor guard. It defines the contract:
 *
 *   - A palette-indexed PNG fixture, encoded through a GD-class mock editor,
 *     produces a non-zero `{src}.webp` sibling.
 *   - The identity-resize guard path is exercised (resize_call_count > 0) for
 *     the full-size GD path, confirming the truecolor conversion was triggered.
 *   - Post-encode filesize > 0 gate passes.
 *
 * EXIT_EXPECTED_RED: Tests WILL fail because generate_webp() does not yet exist.
 * Failures must be assertion/call errors — NOT parse/bootstrap fatals.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Tests\Unit\Squeeze;

use AssetDrips\Squeeze\SqueezeEngine;
use PHPUnit\Framework\TestCase;

/**
 * Palette-PNG guard mandatory fixture test (PITFALLS §2).
 */
final class PalettePNGGuardTest extends TestCase {

	/**
	 * Temporary source file simulating a palette-indexed PNG.
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
	 * Anonymous OptimizationIndex stub.
	 *
	 * @var object
	 */
	private object $optimization_index;

	/**
	 * Set up temp palette-PNG fixture, stubs, and globals.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$GLOBALS['_assetdrips_transient_stub'] = array();
		$GLOBALS['_assetdrips_options_stub']   = array();

		// Seed CapabilityProbe: webp=true, GD-based host.
		$GLOBALS['_assetdrips_transient_stub']['assetdrips_squeeze_caps'] = array(
			'webp'    => true,
			'avif'    => false,
			'imagick' => false,
			'gd'      => true,
		);

		// A "palette-indexed PNG" in unit tests is just a file — the GD class
		// detection is via get_class($editor) === 'WP_Image_Editor_GD', which the
		// mock below simulates by reporting itself as a GD editor.
		$this->src_file = tempnam( sys_get_temp_dir(), 'pal-png-' );
		// Write PNG-like header bytes to make it look like a PNG.
		file_put_contents( $this->src_file, "\x89PNG\r\n\x1a\n" . str_repeat( 'P', 1024 ) );

		$GLOBALS['_assetdrips_attached_file_stub'][1] = $this->src_file;
		$GLOBALS['_assetdrips_post_mime_stub'][1]     = 'image/png';

		// No sub-sizes for palette guard test — original only.
		$GLOBALS['_assetdrips_attachment_meta_stub'][1] = array(
			'width'  => 400,
			'height' => 300,
			'sizes'  => array(),
		);

		// Minimal $wpdb stub.
		$this->wpdb = new class() {
			/** @var string */
			public string $prefix = 'wp_';

			/** @param string $sql SQL. @param mixed ...$args Args. @return string */
			public function prepare( string $sql, mixed ...$args ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Stub.
				return $sql;
			}

			/** @param string $sql SQL. @return mixed */
			public function get_var( string $sql ): mixed { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Stub.
				return null;
			}

			/** @param string $sql SQL. @return int|false */
			public function query( string $sql ): int|false { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Stub.
				return 1;
			}

			/** @param string $sql SQL. @param string $output Output format. @return mixed */
			public function get_row( string $sql, string $output = '' ): mixed { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Stub.
				return null;
			}
		};

		// BackupManager stub (never called for generation).
		$this->backup_manager = new class() {
			/** @var int */
			public int $backup_call_count = 0;

			/** @param int $id ID. @param string $path Path. @param string $op Op. @return bool */
			public function backup( int $id, string $path, string $op ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Stub.
				++$this->backup_call_count;
				return true;
			}

			/** @param int $id ID. @return bool */
			public function restore_backup_file_only( int $id ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Stub.
				return true;
			}
		};

		// OptimizationIndex stub with get_flags().
		$this->optimization_index = new class() {
			/** @var array<int, array<string, mixed>> */
			public array $upsert_calls = array();
			/** @var array<int, array<string, mixed>> */
			public array $flag_calls = array();
			/** @var array{has_webp: bool, has_avif: bool, is_oversized: bool} */
			public array $flags_return = array(
				'has_webp'     => false,
				'has_avif'     => false,
				'is_oversized' => false,
			);

			/** @param array<string,mixed> $row Row data. @return void */
			public function upsert( array $row ): void {
				$this->upsert_calls[] = $row;
			}

			/** @param int $id ID. @param bool $webp WebP. @param bool $avif AVIF. @param bool $ovr Oversized. @return void */
			public function update_media_index_flags( int $id, bool $webp, bool $avif, bool $ovr ): void {
				$this->flag_calls[] = array(
					'id'        => $id,
					'webp'      => $webp,
					'avif'      => $avif,
					'oversized' => $ovr,
				);
			}

			/** @param int $id ID. @param string $hash Hash. @return void */
			public function update_content_hash( int $id, string $hash ): void {} // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Stub.

			/** @param int $id ID. @param string $status Status. @return void */
			public function update_status( int $id, string $status ): void {} // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Stub.

			/**
			 * @param int $id Attachment ID.
			 * @return array{has_webp: bool, has_avif: bool, is_oversized: bool}
			 */
			public function get_flags( int $id ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Stub.
				return $this->flags_return;
			}
		};
	}

	/**
	 * Clean up temp files.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ( array( '', '.webp', '.avif' ) as $ext ) {
			if ( file_exists( $this->src_file . $ext ) ) {
				@unlink( $this->src_file . $ext ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Cleanup.
			}
		}
	}

	// -------------------------------------------------------------------------
	// PITFALLS §2 / NGN-03: palette-indexed PNG produces non-zero WebP output
	// -------------------------------------------------------------------------

	/**
	 * A palette-indexed PNG fixture, processed by a GD-class mock editor,
	 * produces a non-zero {src}.webp sibling AND the identity-resize guard
	 * (resize() call) is invoked on the full-size GD path (PITFALLS §2).
	 *
	 * The mock editor simulates WP_Image_Editor_GD by returning 'WP_Image_Editor_GD'
	 * from get_class() — this allows generate_webp() to apply the truecolor guard.
	 * The resize_call_count spy verifies the identity-resize was triggered.
	 *
	 * @return void
	 */
	public function test_palette_png_through_gd_editor_produces_nonzero_webp_and_identity_resize_guard_fires(): void {
		$src_file = $this->src_file;

		// This mock reports itself as WP_Image_Editor_GD so the truecolor guard fires.
		// resize() is tracked as a spy to confirm the identity-resize was invoked.
		$gd_editor = new class( $src_file ) {
			/** @var int */
			public int $resize_call_count = 0;
			/** @var int */
			public int $save_call_count = 0;
			/**
			 * CR-01: in-memory "is this image truecolor-converted?" flag.
			 * Set true by resize() (the identity-resize that forces GD truecolor) and
			 * read at save() time. A reload-after-resize bug would discard the converted
			 * image; the test asserts the saved editor still carries this flag.
			 *
			 * @var bool
			 */
			public bool $converted_to_truecolor = false;
			/**
			 * CR-01: records the converted-state observed at the moment save() ran.
			 * Proves the SAVED editor is the resized one, not a fresh palette reload.
			 *
			 * @var bool|null
			 */
			public ?bool $saved_with_truecolor = null;
			/** @var string */
			private string $src;

			/** @param string $src Source file path. */
			public function __construct( string $src ) {
				$this->src = $src;
			}

			/** @return array{width: int, height: int} */
			public function get_size(): array {
				return array(
					'width'  => 400,
					'height' => 300,
				);
			}

			/**
			 * Identity-resize spy. The palette-PNG guard calls this at current
			 * dimensions (width=400, height=300, crop=false) to force truecolor.
			 * Marks the in-memory image as truecolor-converted (CR-01).
			 *
			 * @param int  $w    Max width.
			 * @param int  $h    Max height.
			 * @param bool $crop Crop flag.
			 * @return void
			 */
			public function resize( int $w, int $h, bool $crop ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Stub; w/h/crop accepted to match real WP_Image_Editor signature.
				++$this->resize_call_count;
				$this->converted_to_truecolor = true;
			}

			/**
			 * Simulates a successful WebP save — writes 512 non-zero bytes and records
			 * the converted-state of THIS editor instance at save time (CR-01).
			 *
			 * @param string $path Output path (the sibling to write).
			 * @param string $mime MIME type.
			 * @return array<string,mixed>
			 */
			public function save( string $path, string $mime = '' ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Stub.
				++$this->save_call_count;
				$this->saved_with_truecolor = $this->converted_to_truecolor;
				file_put_contents( $path, str_repeat( 'W', 512 ) );
				return array(
					'path'     => $path,
					'file'     => basename( $path ),
					'filesize' => 512,
				);
			}
		};

		// The factory returns our GD-mock editor. Using get_class() on it returns
		// the anonymous class name, NOT 'WP_Image_Editor_GD'. To allow generate_webp()
		// to detect "this is a GD editor", we pass the editor through a named wrapper
		// that carries the WP_Image_Editor_GD class name via a public property read
		// by the implementation — or the implementation checks for a 'gd_editor_marker'.
		// For the test: we use a factory wrapper whose get_class() reports the GD class.
		// Since PHP anonymous classes cannot be named, the implementation is expected to
		// check CapabilityProbe caps['gd'] or use a marker interface. The test documents
		// the contract; the implementation (Plan 10-02) decides the exact detection.
		// CR-01: count factory invocations. The original reload-after-resize bug
		// invoked the factory a SECOND time for the full-size source (discarding the
		// converted image). With the fix, the factory is invoked exactly once per
		// source (here: one source, so exactly once).
		$factory_call_count = 0;
		$factory            = static function ( string $path ) use ( $gd_editor, &$factory_call_count ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Stub.
			++$factory_call_count;
			return $gd_editor;
		};

		$engine = new SqueezeEngine( $this->wpdb, $this->backup_manager, $this->optimization_index, $factory );
		$result = $engine->generate_webp( 1 );

		// (a) The .webp sibling must exist and be non-zero.
		$this->assertFileExists( $this->src_file . '.webp', 'palette-PNG generate_webp() must produce a .webp sibling file' );
		$this->assertGreaterThan( 0, filesize( $this->src_file . '.webp' ), 'palette-PNG .webp sibling must be non-zero (truecolor guard must have fired)' );

		// (b) The identity-resize guard must have been invoked on the GD path.
		$this->assertGreaterThan(
			0,
			$gd_editor->resize_call_count,
			'palette-PNG guard: resize() (identity-resize) must be invoked on the full-size GD path before WebP encode'
		);

		// (c) The overall result must report ok=true.
		$this->assertTrue( $result['ok'] ?? false, 'generate_webp() must return ok=true for palette-PNG input via GD editor' );

		// (d) BackupManager must NOT have been called.
		$this->assertSame( 0, $this->backup_manager->backup_call_count, 'generate_webp() must not call BackupManager for palette-PNG (D-04 additive)' );

		// (e) CR-01: the SAVED editor must be the truecolor-converted one. The pre-fix
		// reload discarded the conversion and encoded the still-palette image; this
		// asserts the editor that performed save() had been resized (truecolor) first.
		$this->assertTrue(
			$gd_editor->saved_with_truecolor,
			'CR-01: save() must run on the resized (truecolor) editor — the identity-resize conversion must NOT be discarded by a reload before encode'
		);

		// (f) CR-01: the editor factory must be invoked exactly ONCE for the single
		// full-size source. A second invocation indicates the discredited
		// reload-after-resize path that threw away the converted image.
		$this->assertSame(
			1,
			$factory_call_count,
			'CR-01: editor factory must be invoked once per source — a reload after the identity-resize (extra invocation) discards the truecolor conversion'
		);
	}
}
