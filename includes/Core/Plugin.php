<?php
/**
 * Main plugin bootstrapper.
 *
 * @package CrawlerLabyrinth
 */

declare(strict_types=1);

namespace CrawlerLabyrinth\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use CrawlerLabyrinth\API\RestController;
use CrawlerLabyrinth\Admin\AdminDashboard;
use CrawlerLabyrinth\Audit\HitLogger;
use CrawlerLabyrinth\CLI\LabyrinthCommand;

final class Plugin {

	private static ?Plugin $instance = null;

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function boot(): void {
		// Ensure schema, secret, and cron schedule stay active on upgrades, not just activation.
		Activator::ensure_ready();
		add_action( Activator::CRON_HOOK, [ $this, 'run_scheduled_retention' ] );
		Activator::sync_schedule();

		( new RobotsInjector() )->boot();
		( new BaitRenderer() )->boot();
		( new TrapRouter() )->boot();

		if ( function_exists( 'is_admin' ) && is_admin() ) {
			AdminDashboard::instance()->boot();
		}

		add_action(
			'rest_api_init',
			static function (): void {
				( new RestController() )->register_routes();
			}
		);

		if ( defined( 'WP_CLI' ) && \WP_CLI && class_exists( 'WP_CLI' ) ) {
			\WP_CLI::add_command( 'crawler-labyrinth', LabyrinthCommand::class );
		}

		if ( function_exists( 'do_action' ) ) {
			do_action( 'crawler_labyrinth_ready', $this );
		}
	}

	public function run_scheduled_retention(): void {
		( new HitLogger() )->purge_expired( false );
	}
}
