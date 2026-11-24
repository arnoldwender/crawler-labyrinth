<?php
/**
 * Normalized plugin configuration, sanitizers, and bot classification.
 *
 * @package CrawlerLabyrinth
 */

declare(strict_types=1);

namespace CrawlerLabyrinth\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Settings {

	public const DEFAULT_ROUTE_PREFIX = 'crawler-trap';

	/**
	 * Reserved path segments that must never be used as a trap route prefix.
	 *
	 * @var list<string>
	 */
	private const RESERVED_PREFIXES = [
		'wp-admin',
		'wp-login',
		'wp-json',
		'wp-content',
		'wp-includes',
		'wp-cron',
		'xmlrpc',
		'feed',
		'sitemap',
		'wp-sitemap',
		'robots',
		'media',
		'well-known',
		'assets',
		'favicon',
	];

	/**
	 * Search, citation, and preview crawlers that must never be intercepted by UA matching.
	 *
	 * @var list<string>
	 */
	private const ALLOWED_SEARCH_UA_TOKENS = [
		'Googlebot',
		'Bingbot',
		'Applebot',
		'OAI-SearchBot',
		'ChatGPT-User',
		'Claude-SearchBot',
		'Claude-User',
		'PerplexityBot',
		'Perplexity-User',
		'MistralAI-User',
		'Slackbot',
		'Storebot-Google',
		'DuckDuckBot',
	];

	/**
	 * Known AI training / bulk scraper tokens for optional opt-in UA interception.
	 *
	 * @var list<string>
	 */
	private const TRAINING_ONLY_UA_TOKENS = [
		'GPTBot',
		'CCBot',
		'ClaudeBot',
		'anthropic-ai',
		'Bytespider',
		'PetalBot',
		'Diffbot',
		'Omgilibot',
		'ImagesiftBot',
		'DataForSeoBot',
		'Scrapy',
	];

	/**
	 * Canonical list of all option names managed by the plugin.
	 *
	 * @return list<string>
	 */
	public static function option_names(): array {
		return [
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
	}

	/**
	 * Normalized WordPress home URL base path (`''` for root installs, `'/blog'` for subdirs).
	 */
	public static function base_path(): string {
		if ( ! function_exists( 'home_url' ) ) {
			return '';
		}
		$home    = (string) home_url( '/' );
		$path    = function_exists( 'wp_parse_url' ) ? (string) wp_parse_url( $home, PHP_URL_PATH ) : '';
		$trimmed = trim( $path, '/' );
		return '' === $trimmed ? '' : '/' . $trimmed;
	}

	/**
	 * Return all normalized settings from WordPress options.
	 *
	 * @return array<string, int|string|bool>
	 */
	public static function all(): array {
		return [
			'enabled'               => '1' === (string) get_option( 'crawler_labyrinth_enabled', '1' ),
			'route_prefix'          => self::sanitize_route_prefix( (string) get_option( 'crawler_labyrinth_route_prefix', self::DEFAULT_ROUTE_PREFIX ) ),
			'mode'                  => self::sanitize_mode( (string) get_option( 'crawler_labyrinth_mode', 'hybrid' ) ),
			'response_format'       => self::sanitize_format( (string) get_option( 'crawler_labyrinth_response_format', 'html' ) ),
			'link_count'            => self::clamp_int( get_option( 'crawler_labyrinth_link_count', 1 ), 1, 5, 1 ),
			'max_depth'             => self::clamp_int( get_option( 'crawler_labyrinth_max_depth', 8 ), 1, 16, 8 ),
			'strike_threshold'      => self::clamp_int( get_option( 'crawler_labyrinth_strike_threshold', 5 ), 1, 100, 5 ),
			'block_ttl_minutes'     => self::clamp_int( get_option( 'crawler_labyrinth_block_ttl_minutes', 360 ), 5, 10080, 360 ),
			'block_status'          => self::sanitize_block_status( get_option( 'crawler_labyrinth_block_status', 429 ) ),
			'update_robots'         => '1' === (string) get_option( 'crawler_labyrinth_update_robots', '1' ),
			'intercept_training_ua' => '1' === (string) get_option( 'crawler_labyrinth_intercept_training_ua', '0' ),
			'retention_days'        => self::clamp_int( get_option( 'crawler_labyrinth_retention_days', 30 ), 0, 365, 30 ),
			'burst_limit'           => self::sanitize_burst_limit( get_option( 'crawler_labyrinth_burst_limit', 120 ) ),
			'delete_on_uninstall'   => '1' === (string) get_option( 'crawler_labyrinth_delete_data_on_uninstall', '0' ),
		];
	}

	public static function sanitize_bool_flag( mixed $value ): string {
		return ( '1' === (string) $value || true === $value ) ? '1' : '0';
	}

	public static function sanitize_route_prefix( mixed $raw ): string {
		if ( ! is_scalar( $raw ) ) {
			return self::DEFAULT_ROUTE_PREFIX;
		}
		$trimmed = strtolower( trim( (string) $raw, " \t\n\r\0\x0B/" ) );
		if ( '' === $trimmed || str_contains( $trimmed, '.' ) || str_contains( $trimmed, '/' ) ) {
			return self::DEFAULT_ROUTE_PREFIX;
		}
		$clean = (string) preg_replace( '/[^a-z0-9-]/', '', $trimmed );
		$clean = trim( $clean, '-' );
		if ( strlen( $clean ) < 3 || strlen( $clean ) > 32 ) {
			return self::DEFAULT_ROUTE_PREFIX;
		}
		foreach ( self::RESERVED_PREFIXES as $reserved ) {
			if ( $clean === $reserved || str_starts_with( $clean, $reserved . '-' ) ) {
				return self::DEFAULT_ROUTE_PREFIX;
			}
		}
		return $clean;
	}

	public static function sanitize_mode( mixed $raw ): string {
		$val = is_scalar( $raw ) ? strtolower( trim( (string) $raw ) ) : '';
		return in_array( $val, [ 'hybrid', 'labyrinth', 'block', 'observe' ], true ) ? $val : 'hybrid';
	}

	public static function sanitize_burst_limit( mixed $raw ): int {
		if ( ! is_numeric( $raw ) ) {
			return 120;
		}
		$val = (int) $raw;
		if ( $val <= 0 ) {
			return 0;
		}
		if ( $val < 5 ) {
			return 5;
		}
		if ( $val > 10000 ) {
			return 10000;
		}
		return $val;
	}

	public static function sanitize_format( mixed $raw ): string {
		$val = is_scalar( $raw ) ? strtolower( trim( (string) $raw ) ) : '';
		return in_array( $val, [ 'html', 'plain' ], true ) ? $val : 'html';
	}

	public static function sanitize_block_status( mixed $raw ): int {
		$val = is_numeric( $raw ) ? (int) $raw : 429;
		return 403 === $val ? 403 : 429;
	}

	public static function clamp_int( mixed $raw, int $min, int $max, int $fallback ): int {
		if ( ! is_numeric( $raw ) ) {
			return $fallback;
		}
		$val = (int) $raw;
		if ( $val < $min ) {
			return $min;
		}
		if ( $val > $max ) {
			return $max;
		}
		return $val;
	}

	/**
	 * Match AI training / scraper User-Agents while explicitly sparing search and citation crawlers.
	 */
	public static function is_training_only_ua( string $user_agent ): bool {
		if ( '' === trim( $user_agent ) ) {
			return false;
		}

		$allowed_tokens = self::ALLOWED_SEARCH_UA_TOKENS;
		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters( 'crawler_labyrinth_allowed_bots', $allowed_tokens );
			if ( is_array( $filtered ) ) {
				$allowed_tokens = array_values(
					array_filter(
						$filtered,
						static fn( mixed $item ): bool => is_string( $item ) && '' !== trim( $item )
					)
				);
			}
		}

		foreach ( $allowed_tokens as $allowed ) {
			if ( false !== stripos( $user_agent, $allowed ) ) {
				return false;
			}
		}

		$training_tokens = self::TRAINING_ONLY_UA_TOKENS;
		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters( 'crawler_labyrinth_training_bots', $training_tokens );
			if ( is_array( $filtered ) ) {
				$training_tokens = array_values(
					array_filter(
						$filtered,
						static fn( mixed $item ): bool => is_string( $item ) && '' !== trim( $item )
					)
				);
			}
		}

		foreach ( $training_tokens as $token ) {
			if ( false !== stripos( $user_agent, $token ) ) {
				return true;
			}
		}
		return false;
	}
}
