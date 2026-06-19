<?php
/**
 * Unit tests for SqueezeCommand WP-CLI registration + flag parsing (TRG-05, D-08).
 *
 * These tests are RED until Plan 11-05 implements SqueezeCommand and wires it into
 * CommandRegistrar.  They define the required behaviour; Plan 11-05 turns them GREEN.
 *
 * Uses the source-inspection technique (ReflectionClass::getFileName() +
 * file_get_contents) so no WordPress or WP-CLI bootstrap is required — all
 * assertions are against the production class source string.  CommandRegistrar is
 * read directly from disk because it always exists.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Tests\Unit\Cli;

use PHPUnit\Framework\TestCase;

/**
 * Source-inspection tests for SqueezeCommand registration + --resume/--batch/--ops parsing.
 */
final class SqueezeCommandTest extends TestCase {

	/**
	 * Assert the SqueezeCommand class exists, then return its source for inspection.
	 *
	 * Fails with a clear message when SqueezeCommand has not been created yet (the
	 * expected RED state for this plan).
	 *
	 * @return string
	 */
	private function command_source(): string {
		$this->assertTrue(
			class_exists( \AssetDrips\Cli\SqueezeCommand::class ),
			'SqueezeCommand class must exist (src/Cli/SqueezeCommand.php) before these tests can pass (TRG-05)'
		);

		$ref = new \ReflectionClass( \AssetDrips\Cli\SqueezeCommand::class );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file read for source inspection; wp_remote_get() is for HTTP URLs.
		return (string) file_get_contents( (string) $ref->getFileName() );
	}

	/**
	 * Return the CommandRegistrar source for inspection.
	 *
	 * CommandRegistrar always exists, so this reads its file directly via reflection.
	 *
	 * @return string
	 */
	private function registrar_source(): string {
		$this->assertTrue(
			class_exists( \AssetDrips\Cli\CommandRegistrar::class ),
			'CommandRegistrar class must exist'
		);

		$ref = new \ReflectionClass( \AssetDrips\Cli\CommandRegistrar::class );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file read for source inspection; wp_remote_get() is for HTTP URLs.
		return (string) file_get_contents( (string) $ref->getFileName() );
	}

	// -------------------------------------------------------------------------
	// TRG-05: SqueezeCommand is registered in CommandRegistrar
	// -------------------------------------------------------------------------

	/**
	 * CommandRegistrar::register() must wire `wp assetdrips squeeze` to SqueezeCommand.
	 *
	 * @return void
	 */
	public function test_squeeze_command_is_registered_in_registrar(): void {
		$contents = $this->registrar_source();

		$this->assertStringContainsString(
			'assetdrips squeeze',
			$contents,
			"CommandRegistrar must register the 'assetdrips squeeze' subcommand (TRG-05)"
		);

		$this->assertStringContainsString(
			'SqueezeCommand::class',
			$contents,
			'CommandRegistrar must reference SqueezeCommand::class when adding the squeeze command (TRG-05)'
		);
	}

	// -------------------------------------------------------------------------
	// TRG-05: __invoke parses --resume, --batch, --ops from $assoc_args
	// -------------------------------------------------------------------------

	/**
	 * __invoke() must read the --resume flag from $assoc_args.
	 *
	 * @return void
	 */
	public function test_invoke_parses_resume_flag(): void {
		$contents = $this->command_source();

		$this->assertStringContainsString(
			'resume',
			$contents,
			'SqueezeCommand::__invoke() must parse the --resume flag from $assoc_args (TRG-05)'
		);
	}

	/**
	 * __invoke() must read the --batch flag from $assoc_args.
	 *
	 * @return void
	 */
	public function test_invoke_parses_batch_flag(): void {
		$contents = $this->command_source();

		$this->assertStringContainsString(
			'batch',
			$contents,
			'SqueezeCommand::__invoke() must parse the --batch flag from $assoc_args (TRG-05)'
		);
	}

	/**
	 * __invoke() must read the --ops flag from $assoc_args.
	 *
	 * @return void
	 */
	public function test_invoke_parses_ops_flag(): void {
		$contents = $this->command_source();

		$this->assertStringContainsString(
			'ops',
			$contents,
			'SqueezeCommand::__invoke() must parse the --ops flag from $assoc_args (TRG-05)'
		);
	}

	// -------------------------------------------------------------------------
	// TRG-05: command references SqueezeJob::CHECKPOINT_OPTION (not IndexBuilder)
	// -------------------------------------------------------------------------

	/**
	 * SqueezeCommand must use SqueezeJob::CHECKPOINT_OPTION for resume, NOT
	 * IndexBuilder::CHECKPOINT_OPTION (the two checkpoints must not collide).
	 *
	 * @return void
	 */
	public function test_command_uses_squeeze_job_checkpoint_option(): void {
		$contents = $this->command_source();

		$this->assertStringContainsString(
			'SqueezeJob::CHECKPOINT_OPTION',
			$contents,
			'SqueezeCommand must reference SqueezeJob::CHECKPOINT_OPTION for resume (TRG-05)'
		);

		$this->assertStringNotContainsString(
			'IndexBuilder::CHECKPOINT_OPTION',
			$contents,
			'SqueezeCommand must NOT reuse IndexBuilder::CHECKPOINT_OPTION — the squeeze checkpoint is distinct (TRG-05)'
		);
	}

	// -------------------------------------------------------------------------
	// D-08: set_time_limit(0) lifts the PHP execution cap for long backfills
	// -------------------------------------------------------------------------

	/**
	 * SqueezeCommand must call set_time_limit so a long backfill is not killed by
	 * the default PHP max_execution_time (D-08).
	 *
	 * @return void
	 */
	public function test_command_lifts_execution_time_limit(): void {
		$contents = $this->command_source();

		$this->assertStringContainsString(
			'set_time_limit',
			$contents,
			'SqueezeCommand must call set_time_limit() so long backfills are not killed by max_execution_time (D-08)'
		);
	}

	// -------------------------------------------------------------------------
	// Security (T-11-input): --ops is validated against the whitelist
	// -------------------------------------------------------------------------

	/**
	 * The --ops flag must be validated against the whitelist
	 * {recompress, webp, avif, resize}.  Any other token must be rejected so a
	 * tampered --ops value cannot reach the engine dispatch.
	 *
	 * @return void
	 */
	public function test_ops_flag_is_validated_against_whitelist(): void {
		$contents = $this->command_source();

		$this->assertStringContainsString(
			'recompress',
			$contents,
			'SqueezeCommand --ops whitelist must include recompress (T-11-input)'
		);

		$this->assertStringContainsString(
			'webp',
			$contents,
			'SqueezeCommand --ops whitelist must include webp (T-11-input)'
		);

		$this->assertStringContainsString(
			'avif',
			$contents,
			'SqueezeCommand --ops whitelist must include avif (T-11-input)'
		);

		$this->assertStringContainsString(
			'resize',
			$contents,
			'SqueezeCommand --ops whitelist must include resize (T-11-input)'
		);
	}
}
