<?php
/**
 * Crawler Labyrinth — Opt-In Uninstaller
 *
 * Triggered when the plugin is deleted via WordPress Admin.
 * Preserves stored options and the HMAC-chained audit table by default unless
 * `crawler_labyrinth_delete_data_on_uninstall` is explicitly enabled (`'1'`).
 *
 * @package CrawlerLabyrinth
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

( static function (): void {
	$crawler_labyrinth_network_opt_in = function_exists( 'get_site_option' )
		&& '1' === (string) get_site_option( 'crawler_labyrinth_delete_data_on_uninstall', '0' );

	$crawler_labyrinth_options = [
		'crawler_labyrinth_enabled',
		'crawler_labyrinth_route_prefix',
		'crawler_labyrinth_mode',
		'crawler_labyrinth_response_format',
		'crawler_labyrinth_link_count',
		'crawler_labyrinth_max_depth',
		'crawler_labyrinth_strike_threshold',
		'crawler_labyrinth_block_ttl_minutes',
		'crawler_labyrinth_block_status',
		'crawler_labyrinth_update_robots',
		'crawler_labyrinth_intercept_training_ua',
		'crawler_labyrinth_retention_days',
		'crawler_labyrinth_burst_limit',
		'crawler_labyrinth_delete_data_on_uninstall',
		'crawler_labyrinth_hmac_secret',
		'crawler_labyrinth_schema_version',
		'crawler_labyrinth_active_quarantine_max',
	];

	$crawler_labyrinth_clean_blog = static function ( bool $force ) use ( $crawler_labyrinth_options ): void {
		$crawler_labyrinth_blog_opt_in = $force || '1' === (string) get_option( 'crawler_labyrinth_delete_data_on_uninstall', '0' );
		if ( ! $crawler_labyrinth_blog_opt_in ) {
			return;
		}

		foreach ( $crawler_labyrinth_options as $crawler_labyrinth_option ) {
			delete_option( $crawler_labyrinth_option );
		}

		if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
			wp_clear_scheduled_hook( 'crawler_labyrinth_daily_purge' );
		}

		global $wpdb;
		if ( isset( $wpdb ) && is_object( $wpdb ) && isset( $wpdb->options ) && method_exists( $wpdb, 'prepare' ) && method_exists( $wpdb, 'query' ) ) {
			$crawler_labyrinth_pfx1 = method_exists( $wpdb, 'esc_like' ) ? $wpdb->esc_like( '_transient_crawler_labyrinth_' ) . '%' : '_transient_crawler_labyrinth_%';
			$crawler_labyrinth_pfx2 = method_exists( $wpdb, 'esc_like' ) ? $wpdb->esc_like( '_transient_timeout_crawler_labyrinth_' ) . '%' : '_transient_timeout_crawler_labyrinth_%';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- removes plugin strike and quarantine transients on opt-in uninstall.
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s', $wpdb->options, $crawler_labyrinth_pfx1, $crawler_labyrinth_pfx2 ) );
		}

		if ( isset( $wpdb ) && is_object( $wpdb ) && isset( $wpdb->prefix ) && method_exists( $wpdb, 'prepare' ) && method_exists( $wpdb, 'query' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall removes the plugin audit table on explicit operator opt-in.
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'crawler_labyrinth_hits' ) );
		}
	};

	if ( function_exists( 'is_multisite' ) && is_multisite() && function_exists( 'get_sites' ) && function_exists( 'switch_to_blog' ) && function_exists( 'restore_current_blog' ) ) {
		$crawler_labyrinth_site_ids = get_sites( [ 'fields' => 'ids', 'number' => 0 ] );
		if ( is_array( $crawler_labyrinth_site_ids ) ) {
			foreach ( $crawler_labyrinth_site_ids as $crawler_labyrinth_site_id ) {
				switch_to_blog( (int) $crawler_labyrinth_site_id );
				$crawler_labyrinth_clean_blog( $crawler_labyrinth_network_opt_in );
				restore_current_blog();
			}
		}
		if ( $crawler_labyrinth_network_opt_in && function_exists( 'delete_site_option' ) ) {
			foreach ( $crawler_labyrinth_options as $crawler_labyrinth_option ) {
				delete_site_option( $crawler_labyrinth_option );
			}
		}
		return;
	}

	$crawler_labyrinth_clean_blog( $crawler_labyrinth_network_opt_in );
	if ( $crawler_labyrinth_network_opt_in && function_exists( 'delete_site_option' ) ) {
		foreach ( $crawler_labyrinth_options as $crawler_labyrinth_option ) {
			delete_site_option( $crawler_labyrinth_option );
		}
	}
} )();
