<?php
/**
 * Request trap router, hybrid strike counter, and quarantine responder.
 *
 * @package CrawlerLabyrinth
 */

declare(strict_types=1);

namespace CrawlerLabyrinth\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use CrawlerLabyrinth\Audit\HitLogger;
use CrawlerLabyrinth\Generator\DecoyGenerator;
use CrawlerLabyrinth\Security\TokenSigner;

final class TrapRouter {

	/**
	 * System and SEO paths that must never be trapped or quarantined.
	 *
	 * @var list<string>
	 */
	private const EXCLUDED_PREFIXES = [
		'/wp-admin',
		'/wp-login.php',
		'/wp-json',
		'/wp-cron.php',
		'/xmlrpc.php',
		'/robots.txt',
		'/.well-known',
		'/wp-sitemap',
		'/sitemap',
		'/wp-content',
		'/wp-includes',
		'/feed',
	];

	public function boot(): void {
		add_action( 'init', [ $this, 'intercept_request' ], 1 );
	}

	/**
	 * Live WordPress request hook.
	 */
	public function intercept_request(): void {
		if ( ( defined( 'WP_CLI' ) && \WP_CLI ) || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
			return;
		}
		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
			return;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return;
		}
		if ( function_exists( 'is_admin' ) && is_admin() ) {
			return;
		}

		$is_admin = function_exists( 'current_user_can' ) && current_user_can( 'manage_options' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- raw URI path is decoded and validated against strict route patterns; sanitize_text_field strips %xx octets.
		$uri = isset( $_SERVER['REQUEST_URI'] ) && is_scalar( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( (string) $_SERVER['REQUEST_URI'] ) : '/';
		$ua  = isset( $_SERVER['HTTP_USER_AGENT'] ) && is_scalar( $_SERVER['HTTP_USER_AGENT'] )
			? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_USER_AGENT'] ) )
			: '';
		$ip  = TokenSigner::client_ip();

		$result = $this->handle_request( $uri, $ua, $ip, $is_admin );
		if ( ! $result['handled'] ) {
			return;
		}

		self::mark_uncacheable();
		$this->emit_http_response( $result );
		exit;
	}

	/**
	 * Evaluate an incoming request and return either pass-through or a decoy/quarantine response.
	 *
	 * @return array{
	 *     handled: bool,
	 *     status: int,
	 *     headers: array<string, string>,
	 *     body: string,
	 *     action: string,
	 *     strikes: int
	 * }
	 */
	public function handle_request(
		string $request_uri,
		string $user_agent,
		string $client_ip,
		bool $is_admin_user = false
	): array {
		$passthrough = [
			'handled' => false,
			'status'  => 200,
			'headers' => [],
			'body'    => '',
			'action'  => 'none',
			'strikes' => 0,
		];

		$settings = Settings::all();
		if ( ! $settings['enabled'] || $is_admin_user ) {
			return $passthrough;
		}

		if ( 1 !== preg_match( '#^https?://#i', $request_uri ) ) {
			$request_uri = '/' . ltrim( $request_uri, '/' );
		}
		$raw_path = (string) wp_parse_url( $request_uri, PHP_URL_PATH );
		$decoded  = rawurldecode( '' !== $raw_path ? $raw_path : '/' );
		$path     = (string) preg_replace( '#/+#', '/', $decoded );
		if ( '' === $path || '/' !== $path[0] ) {
			$path = '/' . ltrim( $path, '/' );
		}

		$base_path = Settings::base_path();
		if ( '' !== $base_path ) {
			if ( $path === $base_path ) {
				$path = '/';
			} elseif ( str_starts_with( $path, $base_path . '/' ) ) {
				$path = substr( $path, strlen( $base_path ) );
			} else {
				return $passthrough;
			}
		}

		$lower_path = strtolower( $path );
		if ( '/index.php' === $lower_path ) {
			$path = '/';
		} elseif ( str_starts_with( $lower_path, '/index.php/' ) ) {
			$path = substr( $path, 10 );
			if ( '' === $path ) {
				$path = '/';
			}
		}

		$prefix       = (string) $settings['route_prefix'];
		$regex        = '#^/' . preg_quote( $prefix, '#' ) . '(?:/([a-zA-Z0-9_-]{1,96}))?/?$#i';
		$trap_matched = 1 === preg_match( $regex, $path, $matches );

		if ( ! $trap_matched && self::is_excluded_path( $path ) ) {
			return $passthrough;
		}

		$secret    = TokenSigner::ensure_secret();
		$now       = time();
		$utc_day   = gmdate( 'Y-m-d', $now );
		$ip_digest = TokenSigner::ip_digest( $client_ip, $utc_day, $secret );
		$ua_digest = TokenSigner::ua_digest( $user_agent, $secret );
		$q_key     = 'crawler_labyrinth_q_' . substr( $ip_digest, 0, 24 );
		$s_key     = 'crawler_labyrinth_s_' . substr( $ip_digest, 0, 24 );

		$block_status = (int) $settings['block_status'];
		$ttl_seconds  = (int) $settings['block_ttl_minutes'] * 60;
		$mode         = (string) $settings['mode'];

		// Check existing quarantine state first (including prior UTC days if TTL spans midnight).
		if ( 'observe' !== $mode ) {
			$active_quarantine = $this->find_active_quarantine( $q_key, $client_ip, $secret, $ttl_seconds, $now );
			if ( null !== $active_quarantine ) {
				self::mark_uncacheable();
				$retry_after = max( 60, $active_quarantine['until'] - $now );
				return $this->build_block_response( $block_status, $retry_after, $active_quarantine['strikes'] );
			}
		}

		$ua_matched = ! $trap_matched && (bool) $settings['intercept_training_ua'] && Settings::is_training_only_ua( $user_agent );

		if ( ! $trap_matched && ! $ua_matched ) {
			return $passthrough;
		}

		self::mark_uncacheable();
		$raw_token     = $trap_matched ? (string) ( $matches[1] ?? '' ) : 'ua-guard';
		$token_for_log = '' !== $raw_token ? $raw_token : 'root';

		$burst_limit = (int) ( $settings['burst_limit'] ?? 120 );
		if ( $burst_limit > 0 && $this->is_burst_exceeded( $burst_limit, $now ) ) {
			if ( function_exists( 'do_action' ) ) {
				do_action( 'crawler_labyrinth_trap_triggered', 'burst_shed', $token_for_log, 0, $ip_digest );
			}
			return $this->build_block_response( 429, 60, 0, 'burst-shed' );
		}

		$strikes = ( function_exists( 'get_transient' ) ? (int) get_transient( $s_key ) : 0 ) + 1;
		if ( function_exists( 'set_transient' ) ) {
			set_transient( $s_key, $strikes, max( 300, $ttl_seconds ) );
		}

		$parsed = ( $trap_matched && '' !== $raw_token ) ? TokenSigner::verify( $raw_token, $secret ) : null;
		if ( null !== $parsed && $parsed['depth'] > (int) $settings['max_depth'] ) {
			$parsed = null;
		}

		if ( ! $trap_matched ) {
			$entry_vector = 'ua_guard';
		} elseif ( null !== $parsed ) {
			$entry_vector = 'signed_bait';
		} else {
			$entry_vector = 'robots_probe';
		}
		$meta_json = (string) ( wp_json_encode(
			[
				'entry_vector' => $entry_vector,
				'strikes'      => $strikes,
			]
		) ?: '{}' );

		$threshold  = (int) $settings['strike_threshold'];
		$quarantine = ( 'block' === $mode ) || ( 'hybrid' === $mode && $strikes > $threshold );

		$logger = new HitLogger();
		if ( $quarantine ) {
			$quarantine_until = $now + $ttl_seconds;
			if ( function_exists( 'set_transient' ) ) {
				set_transient(
					$q_key,
					[
						'until'   => $quarantine_until,
						'strikes' => $strikes,
					],
					$ttl_seconds
				);
			}
			$prev_max = (int) get_option( 'crawler_labyrinth_active_quarantine_max', 0 );
			if ( $quarantine_until > $prev_max ) {
				update_option( 'crawler_labyrinth_active_quarantine_max', $quarantine_until, true );
			}
			$logger->record( $ip_digest, $ua_digest, $user_agent, $token_for_log, 'quarantined', $meta_json );

			if ( function_exists( 'do_action' ) ) {
				do_action( 'crawler_labyrinth_trap_triggered', 'quarantined', $token_for_log, $strikes, $ip_digest );
			}

			return $this->build_block_response( $block_status, $ttl_seconds, $strikes );
		}

		$depth = null !== $parsed ? $parsed['depth'] : 1;
		$seed  = null !== $parsed
			? $parsed['seed']
			: substr( hash_hmac( 'sha256', 'entry|' . $path, $secret ), 0, 12 );

		$decoy = ( new DecoyGenerator() )->generate(
			$depth,
			$seed,
			(string) $settings['response_format'],
			$prefix,
			(int) $settings['max_depth'],
			3,
			$secret
		);

		$is_observe   = 'observe' === $mode;
		$action_taken = $is_observe ? 'observed' : 'decoy_served';
		$header_state = $is_observe ? 'observed' : 'decoy';

		$logger->record( $ip_digest, $ua_digest, $user_agent, $token_for_log, $action_taken, $meta_json );

		if ( function_exists( 'do_action' ) ) {
			do_action( 'crawler_labyrinth_trap_triggered', $action_taken, $token_for_log, $strikes, $ip_digest );
		}

		return [
			'handled' => true,
			'status'  => 200,
			'headers' => [
				'Content-Type'           => $decoy['content_type'],
				'X-Robots-Tag'           => 'noindex, nofollow, noarchive, nosnippet, noai, noimageai',
				'Cache-Control'          => 'no-store, no-cache, must-revalidate, private',
				'X-Content-Type-Options' => 'nosniff',
				'X-Crawler-Labyrinth'    => $header_state,
			],
			'body'    => $decoy['body'],
			'action'  => $action_taken,
			'strikes' => $strikes,
		];
	}

	/**
	 * Track global trap traffic per 60-second UTC bucket to shed flood load without touching MySQL.
	 */
	private function is_burst_exceeded( int $limit, int $now ): bool {
		if ( ! function_exists( 'get_transient' ) || ! function_exists( 'set_transient' ) ) {
			return false;
		}
		$bucket = intdiv( $now, 60 );
		$raw    = get_transient( 'crawler_labyrinth_burst_win' );
		$count  = ( is_array( $raw ) && (int) ( $raw['bucket'] ?? -1 ) === $bucket )
			? (int) ( $raw['count'] ?? 0 )
			: 0;

		if ( $count >= $limit ) {
			return true;
		}

		set_transient(
			'crawler_labyrinth_burst_win',
			[
				'bucket' => $bucket,
				'count'  => $count + 1,
			],
			120
		);
		return false;
	}

	/**
	 * @return array{
	 *     handled: bool,
	 *     status: int,
	 *     headers: array<string, string>,
	 *     body: string,
	 *     action: string,
	 *     strikes: int
	 * }
	 */
	private function build_block_response( int $status, int $retry_after_seconds, int $strikes, string $labyrinth_state = 'quarantined' ): array {
		$headers = [
			'Content-Type'           => 'text/plain; charset=utf-8',
			'X-Robots-Tag'           => 'noindex, nofollow, noarchive, nosnippet, noai, noimageai',
			'Cache-Control'          => 'no-store, no-cache, must-revalidate, private',
			'X-Content-Type-Options' => 'nosniff',
			'X-Crawler-Labyrinth'    => $labyrinth_state,
		];
		if ( 429 === $status ) {
			$headers['Retry-After'] = (string) max( 60, $retry_after_seconds );
		}

		$message = 429 === $status
			? 'HTTP 429 Too Many Requests: Automated traversal of disallowed robots.txt routes is rate-limited.'
			: 'HTTP 403 Forbidden: Automated traversal of disallowed robots.txt routes is prohibited.';

		return [
			'handled' => true,
			'status'  => $status,
			'headers' => $headers,
			'body'    => $message . "\n",
			'action'  => 'burst-shed' === $labyrinth_state ? 'burst_shed' : 'quarantined',
			'strikes' => $strikes,
		];
	}

	/**
	 * Emit response status, headers, and escaped body on live HTTP execution.
	 *
	 * @param array{status: int, headers: array<string, string>, body: string} $response Rendered trap response.
	 */
	private function emit_http_response( array $response ): void {
		if ( ! headers_sent() ) {
			if ( function_exists( 'status_header' ) ) {
				status_header( $response['status'] );
			}
			if ( function_exists( 'nocache_headers' ) ) {
				nocache_headers();
			}
			foreach ( $response['headers'] as $name => $value ) {
				header( $name . ': ' . $value );
			}
		}

		$method = isset( $_SERVER['REQUEST_METHOD'] ) && is_scalar( $_SERVER['REQUEST_METHOD'] )
			? strtoupper( sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) ) )
			: 'GET';
		if ( 'HEAD' === $method ) {
			return;
		}

		$content_type = $response['headers']['Content-Type'] ?? 'text/plain; charset=utf-8';
		if ( str_starts_with( $content_type, 'text/plain' ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain-text response served strictly with Content-Type: text/plain and X-Content-Type-Options: nosniff.
			echo $response['body'];
			return;
		}

		echo "<!doctype html>\n";
		echo wp_kses( $response['body'], self::decoy_allowed_html() );
	}

	private static function mark_uncacheable(): void {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- standard WordPress cache bypass constant for trapped requests.
			define( 'DONOTCACHEPAGE', true );
		}
		if ( ! defined( 'DONOTCACHEDB' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- standard WordPress DB cache bypass constant for trapped requests.
			define( 'DONOTCACHEDB', true );
		}
		if ( ! defined( 'DONOTCDN' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- standard WordPress CDN bypass constant for trapped requests.
			define( 'DONOTCDN', true );
		}
	}

	private static function is_excluded_path( string $path ): bool {
		$lower = strtolower( $path );
		foreach ( self::EXCLUDED_PREFIXES as $excluded ) {
			if ( $lower === $excluded || str_starts_with( $lower, $excluded . '/' ) ) {
				return true;
			}
			if ( ( '/sitemap' === $excluded || '/wp-sitemap' === $excluded )
				&& str_starts_with( $lower, $excluded )
				&& str_ends_with( $lower, '.xml' )
			) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Resolve an active quarantine record for today's digest or recent UTC days within TTL.
	 *
	 * @return array{until: int, strikes: int}|null
	 */
	private function find_active_quarantine(
		string $today_q_key,
		string $client_ip,
		string $secret,
		int $ttl_seconds,
		int $now
	): ?array {
		if ( ! function_exists( 'get_transient' ) ) {
			return null;
		}

		$record = $this->parse_quarantine_transient( get_transient( $today_q_key ) );
		if ( null !== $record && $record['until'] > $now ) {
			return $record;
		}

		$max_active = (int) get_option( 'crawler_labyrinth_active_quarantine_max', 0 );
		if ( $max_active <= $now ) {
			return null;
		}

		$lookback_days = min( 7, max( 1, (int) ceil( $ttl_seconds / 86400 ) ) );
		for ( $d = 1; $d <= $lookback_days; $d++ ) {
			$past_day    = gmdate( 'Y-m-d', $now - ( $d * 86400 ) );
			$past_digest = TokenSigner::ip_digest( $client_ip, $past_day, $secret );
			$past_key    = 'crawler_labyrinth_q_' . substr( $past_digest, 0, 24 );
			$past_rec    = $this->parse_quarantine_transient( get_transient( $past_key ) );
			if ( null !== $past_rec && $past_rec['until'] > $now ) {
				return $past_rec;
			}
		}

		return null;
	}

	/**
	 * @return array{until: int, strikes: int}|null
	 */
	private function parse_quarantine_transient( mixed $raw ): ?array {
		if ( is_array( $raw ) && isset( $raw['until'] ) ) {
			return [
				'until'   => (int) $raw['until'],
				'strikes' => max( 1, (int) ( $raw['strikes'] ?? 1 ) ),
			];
		}
		if ( is_numeric( $raw ) ) {
			return [
				'until'   => (int) $raw,
				'strikes' => 1,
			];
		}
		return null;
	}

	/**
	 * Explicit allowlist for synthetic HTML5 decoy pages served through wp_kses.
	 *
	 * @return array<string, array<string, bool>>
	 */
	public static function decoy_allowed_html(): array {
		return [
			'html'    => [ 'lang' => true ],
			'head'    => [],
			'meta'    => [ 'charset' => true, 'name' => true, 'content' => true ],
			'title'   => [],
			'body'    => [],
			'main'    => [],
			'article' => [ 'data-entry' => true ],
			'h1'      => [],
			'p'       => [],
			'nav'     => [ 'aria-label' => true ],
			'ul'      => [],
			'li'      => [],
			'a'       => [ 'href' => true, 'rel' => true ],
		];
	}
}
