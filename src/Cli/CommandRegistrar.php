<?php
/**
 * Registers the WP-CLI commands.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Cli;

use AssetDrips\Cli\SizesAuditCommand;
use AssetDrips\Cli\SqueezeCommand;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the AssetDrips subcommands into WP-CLI.
 *
 * Called only when WP-CLI is present, so the command classes never load outside
 * a CLI context.
 */
final class CommandRegistrar {

	/**
	 * Register every `wp assetdrips …` subcommand.
	 *
	 * @return void
	 */
	public static function register(): void {
		$review = new ReviewCommand();

		WP_CLI::add_command( 'assetdrips scan', ScanCommand::class );
		WP_CLI::add_command( 'assetdrips index', IndexCommand::class );
		WP_CLI::add_command( 'assetdrips squeeze', SqueezeCommand::class );
		WP_CLI::add_command( 'assetdrips sizes-audit', SizesAuditCommand::class );
		WP_CLI::add_command( 'assetdrips list', array( $review, 'list_results' ) );
		WP_CLI::add_command( 'assetdrips quarantine', array( $review, 'quarantine' ) );
		WP_CLI::add_command( 'assetdrips restore', array( $review, 'restore' ) );
		WP_CLI::add_command( 'assetdrips purge', array( $review, 'purge' ) );
	}
}
