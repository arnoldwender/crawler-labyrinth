<?php
/**
 * RFC 9309 multi-group robots.txt disallow injector.
 *
 * @package CrawlerLabyrinth
 */

declare(strict_types=1);

namespace CrawlerLabyrinth\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class RobotsInjector {

	public function boot(): void {
		add_filter( 'robots_txt', [ $this, 'filter_robots_txt' ], 999, 2 );
	}

	public function filter_robots_txt( string $output, bool $public = true ): string {
		unset( $public );
		$settings = Settings::all();
		if ( ! $settings['enabled'] || ! $settings['update_robots'] ) {
			return $output;
		}
		return self::inject( $output, (string) $settings['route_prefix'] );
	}

	/**
	 * Inject `Disallow: /<prefix>/` into every RFC 9309 User-agent group so named Allow: /
	 * groups (such as OAI-SearchBot or PerplexityBot) do not shadow the wildcard rule.
	 */
	public static function inject( string $output, string $prefix ): string {
		$clean_prefix = Settings::sanitize_route_prefix( $prefix );
		$rule         = 'Disallow: /' . $clean_prefix . '/';
		$normalized   = str_replace( [ "\r\n", "\r" ], "\n", $output );
		$lines        = '' === trim( $normalized ) ? [] : explode( "\n", rtrim( $normalized, "\n" ) );

		if ( [] === $lines ) {
			return "User-agent: *\n" . $rule . "\n";
		}

		$result               = [];
		$in_group             = false;
		$has_non_ua_directive = false;
		$group_has_disallow   = false;
		$found_any_ua         = false;

		foreach ( $lines as $line ) {
			$trimmed = trim( $line );
			$is_ua   = 1 === preg_match( '/^user-agent\s*:/i', $trimmed );
			$is_meta = '' === $trimmed || str_starts_with( $trimmed, '#' ) || 1 === preg_match( '/^sitemap\s*:/i', $trimmed );

			if ( $is_ua ) {
				$found_any_ua = true;
				if ( $in_group && $has_non_ua_directive && ! $group_has_disallow ) {
					$result = self::insert_before_trailing_blanks( $result, $rule );
				}
				if ( ! $in_group || $has_non_ua_directive ) {
					$in_group             = true;
					$has_non_ua_directive = false;
					$group_has_disallow   = false;
				}
				$result[] = $line;
				continue;
			}

			if ( $in_group && ! $is_meta ) {
				$has_non_ua_directive = true;
				if ( 0 === strcasecmp( $trimmed, $rule ) ) {
					$group_has_disallow = true;
				}
			} elseif ( $in_group && '' === $trimmed && $has_non_ua_directive ) {
				if ( ! $group_has_disallow ) {
					$result[] = $rule;
				}
				$in_group             = false;
				$has_non_ua_directive = false;
				$group_has_disallow   = false;
			}

			$result[] = $line;
		}

		if ( $in_group && ! $group_has_disallow ) {
			$result = self::insert_before_trailing_blanks( $result, $rule );
		}

		if ( ! $found_any_ua ) {
			$result[] = 'User-agent: *';
			$result[] = $rule;
		}

		return implode( "\n", $result ) . "\n";
	}

	/**
	 * @param list<string> $lines Existing output lines.
	 * @return list<string>
	 */
	private static function insert_before_trailing_blanks( array $lines, string $rule ): array {
		$trailing = [];
		while ( [] !== $lines ) {
			$last = $lines[ count( $lines ) - 1 ];
			if ( '' !== trim( $last ) && ! str_starts_with( trim( $last ), '#' ) ) {
				break;
			}
			array_unshift( $trailing, (string) array_pop( $lines ) );
		}
		$lines[] = $rule;
		foreach ( $trailing as $t ) {
			$lines[] = $t;
		}
		return $lines;
	}
}
