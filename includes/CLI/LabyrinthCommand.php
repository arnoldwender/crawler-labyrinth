<?php
/**
 * WP-CLI commands (`wp crawler-labyrinth`).
 *
 * @package CrawlerLabyrinth
 */

declare(strict_types=1);

namespace CrawlerLabyrinth\CLI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use CrawlerLabyrinth\Audit\HitLogger;
use CrawlerLabyrinth\Core\Activator;
use CrawlerLabyrinth\Core\Settings;
use CrawlerLabyrinth\Generator\DecoyGenerator;
use WP_CLI;

final class LabyrinthCommand {

	/**
	 * Show current configuration, scheduled cron status, and recorded hit counters.
	 *
	 * ## EXAMPLES
	 *
	 *     wp crawler-labyrinth status
	 *
	 * @when after_wp_load
	 *
	 * @param array<int, string>   $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative options.
	 */
	public function status( array $args, array $assoc_args ): void {
		unset( $args, $assoc_args );
		$settings = Settings::all();
		$stats    = ( new HitLogger() )->get_stats();
		$next     = function_exists( 'wp_next_scheduled' ) ? wp_next_scheduled( Activator::CRON_HOOK ) : false;

		WP_CLI::line( 'Crawler Labyrinth v' . CRAWLER_LABYRINTH_VERSION );
		WP_CLI::line( 'Enabled:          ' . ( $settings['enabled'] ? 'yes' : 'no' ) );
		WP_CLI::line( 'Mode:             ' . (string) $settings['mode'] );
		WP_CLI::line( 'Route prefix:     /' . (string) $settings['route_prefix'] . '/' );
		WP_CLI::line( 'Response format:  ' . (string) $settings['response_format'] );
		WP_CLI::line( 'Strike threshold: ' . (string) $settings['strike_threshold'] );
		WP_CLI::line( 'Block status:     ' . (string) $settings['block_status'] );
		WP_CLI::line( 'Cron scheduled:   ' . ( false !== $next ? gmdate( 'Y-m-d H:i:s', (int) $next ) . ' UTC' : 'no' ) );
		WP_CLI::line( 'Total hits:       ' . (string) $stats['total_hits'] );
		WP_CLI::line( 'Decoys served:    ' . (string) $stats['decoy_served'] );
		WP_CLI::line( 'Quarantined hits: ' . (string) $stats['quarantined'] );
	}

	/**
	 * Render a deterministic decoy entry from a depth and seed.
	 *
	 * ## OPTIONS
	 *
	 * [--depth=<depth>]
	 * : Traversal depth between 1 and 16. Default: 1.
	 *
	 * [--seed=<seed>]
	 * : 4-24 lowercase alphanumeric seed. Default: archpreview1.
	 *
	 * [--format=<format>]
	 * : Output format (html or plain). Default: plain.
	 *
	 * ## EXAMPLES
	 *
	 *     wp crawler-labyrinth preview --depth=2 --seed=archpreview1 --format=plain
	 *
	 * @when after_wp_load
	 *
	 * @param array<int, string>   $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative options.
	 */
	public function preview( array $args, array $assoc_args ): void {
		unset( $args );
		$settings = Settings::all();
		$depth    = Settings::clamp_int( $assoc_args['depth'] ?? 1, 1, 16, 1 );
		$seed_raw = (string) ( $assoc_args['seed'] ?? 'archpreview1' );
		if ( 1 !== preg_match( '/^[a-z0-9]{4,24}$/i', $seed_raw ) ) {
			WP_CLI::error( 'Seed must be 4-24 alphanumeric characters.' );
			return;
		}
		$format = Settings::sanitize_format( $assoc_args['format'] ?? 'plain' );
		$decoy  = ( new DecoyGenerator() )->generate(
			$depth,
			strtolower( $seed_raw ),
			$format,
			(string) $settings['route_prefix'],
			(int) $settings['max_depth'],
			3
		);

		WP_CLI::line( $decoy['body'] );
	}

	/**
	 * Verify the HMAC-SHA256 hash chain over all audit log rows.
	 *
	 * ## EXAMPLES
	 *
	 *     wp crawler-labyrinth verify_chain
	 *
	 * @when after_wp_load
	 *
	 * @param array<int, string>   $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative options.
	 */
	public function verify_chain( array $args, array $assoc_args ): void {
		unset( $args, $assoc_args );
		$res = ( new HitLogger() )->verify_chain();
		if ( ! $res['valid'] ) {
			WP_CLI::error( sprintf( 'Chain verification failed (%s). Verified rows before failure: %d', $res['error_reason'], $res['total_verified'] ) );
			return;
		}
		WP_CLI::success( sprintf( 'HMAC chain intact across %d row(s).', $res['total_verified'] ) );
	}

	/**
	 * Pseudonymize expired audit rows older than the configured retention window.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Report how many rows would be pseudonymized without modifying the database.
	 *
	 * ## EXAMPLES
	 *
	 *     wp crawler-labyrinth purge_expired --dry-run
	 *
	 * @when after_wp_load
	 *
	 * @param array<int, string>   $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative options.
	 */
	public function purge_expired( array $args, array $assoc_args ): void {
		unset( $args );
		$dry_run = isset( $assoc_args['dry-run'] );
		$res     = ( new HitLogger() )->purge_expired( $dry_run );
		if ( $dry_run ) {
			WP_CLI::success( sprintf( 'Dry run (cutoff %s): %d row(s) eligible for pseudonymization.', $res['cutoff'], $res['candidates'] ) );
			return;
		}
		WP_CLI::success( sprintf( 'Pseudonymized %d of %d eligible row(s) older than %s.', $res['pseudonymized'], $res['candidates'], $res['cutoff'] ) );
	}
}
