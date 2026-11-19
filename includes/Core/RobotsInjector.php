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

	public function filter_robots_txt( string $output, mixed $public = true ): string {
		unset( $public );
		$settings = Settings::all();
		if ( ! $settings['enabled'] || ! $settings['update_robots'] ) {
			return $output;
		}
		return self::inject( $output, (string) $settings['route_prefix'], Settings::base_path() );
	}

	/**
	 * Inject `Disallow: /<prefix>/` into every RFC 9309 User-agent group so named Allow: /
	 * groups (such as OAI-SearchBot or PerplexityBot) do not shadow the wildcard rule.
	 */
	public static function inject( string $output, string $prefix, ?string $base_path = null ): string {
		$clean_prefix = Settings::sanitize_route_prefix( $prefix );
		$raw_base     = $base_path ?? Settings::base_path();
		$trimmed_base = trim( $raw_base, '/' );
		$base         = '' === $trimmed_base ? '' : '/' . $trimmed_base;
		$rule         = 'Disallow: ' . $base . '/' . $clean_prefix . '/';
		$eol          = str_contains( $output, "\r\n" ) ? "\r\n" : "\n";
		$normalized   = str_replace( [ "\r\n", "\r" ], "\n", $output );
		$lines        = '' === trim( $normalized ) ? [] : explode( "\n", rtrim( $normalized, "\n" ) );

		if ( [] === $lines ) {
			return 'User-agent: *' . $eol . $rule . $eol;
		}

		$result               = [];
		$in_group             = false;
		$has_non_ua_directive = false;
		$group_has_disallow   = false;
		$found_any_ua         = false;

		foreach ( $lines as $line ) {
			$trimmed       = trim( $line );
			$no_comment    = trim( (string) preg_replace( '/\s*#.*$/', '', $trimmed ) );
			$is_ua         = 1 === preg_match( '/^user-agent\s*:/i', $no_comment );
			$is_sitemap    = 1 === preg_match( '/^sitemap\s*:/i', $no_comment );
			$is_blank      = '' === $trimmed;
			$is_directive  = '' !== $no_comment && ! $is_ua && ! $is_sitemap;

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

			if ( $is_sitemap ) {
				if ( $in_group && ! $group_has_disallow ) {
					$result = self::insert_before_trailing_blanks( $result, $rule );
				}
				$in_group             = false;
				$has_non_ua_directive = false;
				$group_has_disallow   = false;
				$result[]             = $line;
				continue;
			}

			if ( $in_group && $is_directive ) {
				$has_non_ua_directive = true;
				$canonical_directive  = (string) preg_replace( '/^(allow|disallow)\s*:\s*/i', '$1: ', $no_comment );
				if ( 0 === strcasecmp( $canonical_directive, $rule ) ) {
					$group_has_disallow = true;
				}
			} elseif ( $in_group && $is_blank ) {
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

		return implode( $eol, $result ) . $eol;
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
