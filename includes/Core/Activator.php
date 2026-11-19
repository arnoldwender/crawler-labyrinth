<?php
/**
 * Activation, schema upgrades, and retention cron scheduling.
 *
 * @package CrawlerLabyrinth
 */

declare(strict_types=1);

namespace CrawlerLabyrinth\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use CrawlerLabyrinth\Audit\HitLogger;
use CrawlerLabyrinth\Security\TokenSigner;

final class Activator {

	public const CRON_HOOK      = 'crawler_labyrinth_daily_purge';
	public const SCHEMA_VERSION = '1.0.0';

	public static function activate(): void {
		self::ensure_ready();
		self::sync_schedule();
	}

	public static function deactivate(): void {
		if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
		}
	}

	/**
	 * Ensure table, HMAC secret, and default options exist even after updates without re-activation.
	 */
	public static function ensure_ready(): void {
		TokenSigner::ensure_secret();

		$stored_schema = (string) get_option( 'crawler_labyrinth_schema_version', '' );
		if ( self::SCHEMA_VERSION !== $stored_schema || ! HitLogger::table_exists() ) {
			HitLogger::create_table();
			update_option( 'crawler_labyrinth_schema_version', self::SCHEMA_VERSION, false );
		}
	}

	/**
	 * Sync the daily retention cron on plugins_loaded so active installs always keep it scheduled.
	 */
	public static function sync_schedule(): void {
		if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_schedule_event' ) ) {
			return;
		}

		$days      = Settings::clamp_int( get_option( 'crawler_labyrinth_retention_days', 30 ), 0, 365, 30 );
		$scheduled = wp_next_scheduled( self::CRON_HOOK );

		if ( $days > 0 && false === $scheduled ) {
			$offset = defined( 'HOUR_IN_SECONDS' ) ? (int) HOUR_IN_SECONDS : 3600;
			wp_schedule_event( time() + $offset, 'daily', self::CRON_HOOK );
			return;
		}

		if ( 0 === $days && false !== $scheduled && function_exists( 'wp_unschedule_event' ) ) {
			wp_unschedule_event( (int) $scheduled, self::CRON_HOOK );
		}
	}
}
