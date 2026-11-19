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
		add_action( 'template_redirect', [ $this, 'intercept_request' ], 1 );
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

		$is_admin = function_exists( 'current_user_can' ) && current_user_can( 'manage_options' );
		$uri      = isset( $_SERVER['REQUEST_URI'] ) && is_scalar( $_SERVER['REQUEST_URI'] )
			? sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) )
			: '/';
		$ua       = isset( $_SERVER['HTTP_USER_AGENT'] ) && is_scalar( $_SERVER['HTTP_USER_AGENT'] )
			? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_USER_AGENT'] ) )
			: '';
		$ip       = TokenSigner::client_ip();

		$result = $this->handle_request( $uri, $ua, $ip, $is_admin );
		if ( ! $result['handled'] ) {
			return;
		}

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

		$path = (string) wp_parse_url( $request_uri, PHP_URL_PATH );
		if ( '' === $path ) {
			$path = '/';
		}

		foreach ( self::EXCLUDED_PREFIXES as $excluded ) {
			if ( 0 === stripos( $path, $excluded ) ) {
				return $passthrough;
			}
		}

		$secret    = TokenSigner::ensure_secret();
		$ip_digest = TokenSigner::ip_digest( $client_ip, null, $secret );
		$ua_digest = TokenSigner::ua_digest( $user_agent, $secret );
		$q_key     = 'crawler_labyrinth_q_' . substr( $ip_digest, 0, 24 );
		$s_key     = 'crawler_labyrinth_s_' . substr( $ip_digest, 0, 24 );

		$block_status = (int) $settings['block_status'];
		$ttl_seconds  = (int) $settings['block_ttl_minutes'] * 60;

		// Check existing quarantine state first.
		$quarantined_until = function_exists( 'get_transient' ) ? get_transient( $q_key ) : false;
		if ( false !== $quarantined_until && (int) $quarantined_until > time() ) {
			$retry_after = max( 60, (int) $quarantined_until - time() );
			return $this->build_block_response( $block_status, $retry_after, (int) $settings['strike_threshold'] );
		}

		$prefix = (string) $settings['route_prefix'];
		$regex  = '#^/' . preg_quote( $prefix, '#' ) . '(?:/([a-zA-Z0-9_-]{1,96}))?/?$#';

		$trap_matched = 1 === preg_match( $regex, $path, $matches );
		$ua_matched   = ! $trap_matched && (bool) $settings['intercept_training_ua'] && Settings::is_training_only_ua( $user_agent );

		if ( ! $trap_matched && ! $ua_matched ) {
			return $passthrough;
		}

		$raw_token = $trap_matched ? (string) ( $matches[1] ?? '' ) : 'ua-guard';
		$strikes   = ( function_exists( 'get_transient' ) ? (int) get_transient( $s_key ) : 0 ) + 1;
		if ( function_exists( 'set_transient' ) ) {
			set_transient( $s_key, $strikes, max( 300, $ttl_seconds ) );
		}

		$mode       = (string) $settings['mode'];
		$threshold  = (int) $settings['strike_threshold'];
		$quarantine = ( 'block' === $mode ) || ( 'hybrid' === $mode && $strikes > $threshold );

		$logger = new HitLogger();
		if ( $quarantine ) {
			if ( function_exists( 'set_transient' ) ) {
				set_transient( $q_key, time() + $ttl_seconds, $ttl_seconds );
			}
			$logger->record( $ip_digest, $ua_digest, $user_agent, '' !== $raw_token ? $raw_token : 'root', 'quarantined' );

			if ( function_exists( 'do_action' ) ) {
				do_action( 'crawler_labyrinth_trap_triggered', 'quarantined', $raw_token, $strikes, $ip_digest );
			}

			return $this->build_block_response( $block_status, $ttl_seconds, $strikes );
		}

		$parsed = '' !== $raw_token ? TokenSigner::verify( $raw_token, $secret ) : null;
		$depth  = null !== $parsed ? $parsed['depth'] : 1;
		$seed   = null !== $parsed
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

		$logger->record( $ip_digest, $ua_digest, $user_agent, '' !== $raw_token ? $raw_token : 'root', 'decoy_served' );

		if ( function_exists( 'do_action' ) ) {
			do_action( 'crawler_labyrinth_trap_triggered', 'decoy_served', $raw_token, $strikes, $ip_digest );
		}

		return [
			'handled' => true,
			'status'  => 200,
			'headers' => [
				'Content-Type'           => $decoy['content_type'],
				'X-Robots-Tag'           => 'noindex, nofollow, noarchive, nosnippet, noai, noimageai',
				'Cache-Control'          => 'no-store, no-cache, must-revalidate, private',
				'X-Content-Type-Options' => 'nosniff',
			],
			'body'    => $decoy['body'],
			'action'  => 'decoy_served',
			'strikes' => $strikes,
		];
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
	private function build_block_response( int $status, int $retry_after_seconds, int $strikes ): array {
		$headers = [
			'Content-Type'           => 'text/plain; charset=utf-8',
			'X-Robots-Tag'           => 'noindex, nofollow, noarchive, nosnippet, noai, noimageai',
			'Cache-Control'          => 'no-store, no-cache, must-revalidate, private',
			'X-Content-Type-Options' => 'nosniff',
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
			'action'  => 'quarantined',
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

		$content_type = $response['headers']['Content-Type'] ?? 'text/plain; charset=utf-8';
		if ( str_starts_with( $content_type, 'text/plain' ) ) {
			echo esc_html( $response['body'] );
			return;
		}

		echo "<!doctype html>\n";
		echo wp_kses( $response['body'], self::decoy_allowed_html() );
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
