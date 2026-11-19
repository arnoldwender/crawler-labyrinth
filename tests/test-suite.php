<?php
/**
 * Standalone Automated Test Suite for Crawler Labyrinth.
 *
 * Runs without WordPress or MySQL via deterministic in-memory mocks for options,
 * transients, hooks, $wpdb, and WP REST interfaces.
 *
 * @package CrawlerLabyrinth
 */

declare(strict_types=1);

error_reporting( E_ALL );
ini_set( 'display_errors', '1' );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'CRAWLER_LABYRINTH_VERSION' ) ) {
	define( 'CRAWLER_LABYRINTH_VERSION', '1.0.1' );
}
if ( ! defined( 'CRAWLER_LABYRINTH_FILE' ) ) {
	define( 'CRAWLER_LABYRINTH_FILE', dirname( __DIR__ ) . '/crawler-labyrinth.php' );
}
if ( ! defined( 'CRAWLER_LABYRINTH_PATH' ) ) {
	define( 'CRAWLER_LABYRINTH_PATH', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'CRAWLER_LABYRINTH_URL' ) ) {
	define( 'CRAWLER_LABYRINTH_URL', 'https://example.test/wp-content/plugins/crawler-labyrinth/' );
}

global $mock_options, $mock_transients, $mock_filters, $mock_actions, $mock_fired_actions, $mock_user_caps, $mock_logged_in, $mock_is_admin, $mock_redirect_target, $mock_cron;
$mock_options         = [];
$mock_transients      = [];
$mock_filters         = [];
$mock_actions         = [];
$mock_fired_actions   = [];
$mock_user_caps       = [ 'manage_options' => true ];
$mock_logged_in       = false;
$mock_is_admin        = false;
$mock_redirect_target = '';
$mock_cron            = [];

class Crawler_Labyrinth_Test_Redirect extends RuntimeException {}

class Crawler_Labyrinth_Mock_WPDB {
	public string $prefix  = 'wp_';
	public string $options = 'wp_options';
	public int $insert_id  = 0;
	/** @var array<int, object> */
	public array $rows = [];
	public bool $table_created = false;
	public bool $table_dropped = false;

	public function get_charset_collate(): string {
		return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
	}

	public function esc_like( string $text ): string {
		return addcslashes( $text, '_%\\' );
	}

	public function prepare( string $query, mixed ...$args ): string {
		$idx = 0;
		return (string) preg_replace_callback(
			'/%([isd])/',
			static function ( array $m ) use ( $args, &$idx ): string {
				$val = $args[ $idx++ ] ?? '';
				if ( 'i' === $m[1] ) {
					return '`' . str_replace( '`', '', (string) $val ) . '`';
				}
				if ( 'd' === $m[1] ) {
					return (string) (int) $val;
				}
				return "'" . addslashes( (string) $val ) . "'";
			},
			$query
		);
	}

	public function insert( string $table, array $data, array $format = [] ): int|false {
		unset( $table, $format );
		$this->insert_id++;
		$row                           = (object) ( [ 'id' => $this->insert_id ] + $data );
		$this->rows[ $this->insert_id ] = $row;
		return 1;
	}

	public function update( string $table, array $data, array $where, array $format = [], array $where_format = [] ): int|false {
		unset( $table, $format, $where_format );
		$id = (int) ( $where['id'] ?? 0 );
		if ( ! isset( $this->rows[ $id ] ) ) {
			return false;
		}
		foreach ( $data as $k => $v ) {
			$this->rows[ $id ]->$k = $v;
		}
		return 1;
	}

	public function get_var( string $query ): mixed {
		if ( str_contains( $query, 'SHOW TABLES LIKE' ) ) {
			return $this->table_created && ! $this->table_dropped ? $this->prefix . 'crawler_labyrinth_hits' : null;
		}
		if ( str_contains( $query, 'SELECT row_hash' ) ) {
			if ( [] === $this->rows ) {
				return null;
			}
			$last = end( $this->rows );
			return $last ? $last->row_hash : null;
		}
		if ( str_contains( $query, 'COUNT(DISTINCT ip_digest)' ) ) {
			$set = [];
			foreach ( $this->rows as $r ) {
				if ( 'retention_pseudonymized' !== $r->action_taken ) {
					$set[ $r->ip_digest ] = true;
				}
			}
			return count( $set );
		}
		if ( str_contains( $query, 'COUNT(*)' ) ) {
			if ( str_contains( $query, "action_taken = 'decoy_served'" ) ) {
				return count( array_filter( $this->rows, static fn( $r ): bool => 'decoy_served' === $r->action_taken ) );
			}
			if ( str_contains( $query, "action_taken = 'quarantined'" ) ) {
				return count( array_filter( $this->rows, static fn( $r ): bool => 'quarantined' === $r->action_taken ) );
			}
			return count( array_filter( $this->rows, static fn( $r ): bool => 'retention_pseudonymized' !== $r->action_taken ) );
		}
		return null;
	}

	/**
	 * @return list<object>
	 */
	public function get_results( string $query ): array {
		$rows = array_values( $this->rows );
		if ( str_contains( $query, 'ORDER BY id DESC' ) ) {
			$rows = array_reverse( $rows );
		}
		if ( preg_match( "/created_at < '([^']+)'/", $query, $m ) ) {
			$cutoff = $m[1];
			$rows   = array_values(
				array_filter(
					$rows,
					static fn( $r ): bool => $r->created_at < $cutoff
						&& '[REDACTED]' !== $r->user_agent_excerpt
						&& 'retention_pseudonymized' !== $r->action_taken
				)
			);
		}
		if ( preg_match( '/LIMIT (\d+)/', $query, $m ) ) {
			$rows = array_slice( $rows, 0, (int) $m[1] );
		}
		return $rows;
	}

	public function query( string $sql ): int|bool {
		global $mock_transients;
		if ( str_contains( $sql, 'DROP TABLE IF EXISTS' ) ) {
			$this->rows          = [];
			$this->table_dropped = true;
			$this->table_created = false;
			return true;
		}
		if ( str_contains( $sql, '_transient_crawler_labyrinth_' ) ) {
			$mock_transients = [];
			return 1;
		}
		return 0;
	}
}

global $wpdb;
$wpdb = new Crawler_Labyrinth_Mock_WPDB();

if ( ! function_exists( 'dbDelta' ) ) {
	function dbDelta( string $sql ): array {
		global $wpdb;
		if ( str_contains( $sql, 'crawler_labyrinth_hits' ) ) {
			$wpdb->table_created = true;
			$wpdb->table_dropped = false;
		}
		return [];
	}
}
if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $tag, callable $cb, int $priority = 10, int $args = 1 ): void {
		unset( $priority, $args );
		global $mock_actions;
		$mock_actions[ $tag ][] = $cb;
	}
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $tag, callable $cb, int $priority = 10, int $args = 1 ): void {
		unset( $priority, $args );
		global $mock_filters;
		$mock_filters[ $tag ][] = $cb;
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $tag, mixed $value, mixed ...$args ): mixed {
		global $mock_filters;
		foreach ( $mock_filters[ $tag ] ?? [] as $cb ) {
			$value = $cb( $value, ...$args );
		}
		return $value;
	}
}
if ( ! function_exists( 'do_action' ) ) {
	function do_action( string $tag, mixed ...$args ): void {
		unset( $args );
		global $mock_fired_actions;
		$mock_fired_actions[ $tag ] = ( $mock_fired_actions[ $tag ] ?? 0 ) + 1;
	}
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $option, mixed $default = false ): mixed {
		global $mock_options;
		return $mock_options[ $option ] ?? $default;
	}
}
if ( ! function_exists( 'get_site_option' ) ) {
	function get_site_option( string $option, mixed $default = false ): mixed {
		return get_option( $option, $default );
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $option, mixed $value, mixed $autoload = null ): bool {
		unset( $autoload );
		global $mock_options;
		$mock_options[ $option ] = $value;
		return true;
	}
}
if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( string $option ): bool {
		global $mock_options;
		unset( $mock_options[ $option ] );
		return true;
	}
}
if ( ! function_exists( 'delete_site_option' ) ) {
	function delete_site_option( string $option ): bool {
		return delete_option( $option );
	}
}
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( string $key ): mixed {
		global $mock_transients;
		return $mock_transients[ $key ] ?? false;
	}
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( string $key, mixed $value, int $exp = 0 ): bool {
		unset( $exp );
		global $mock_transients;
		$mock_transients[ $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'wp_next_scheduled' ) ) {
	function wp_next_scheduled( string $hook ): int|false {
		global $mock_cron;
		return $mock_cron[ $hook ] ?? false;
	}
}
if ( ! function_exists( 'wp_schedule_event' ) ) {
	function wp_schedule_event( int $ts, string $rec, string $hook ): bool {
		unset( $rec );
		global $mock_cron;
		$mock_cron[ $hook ] = $ts;
		return true;
	}
}
if ( ! function_exists( 'wp_unschedule_event' ) ) {
	function wp_unschedule_event( int $ts, string $hook ): bool {
		unset( $ts );
		global $mock_cron;
		unset( $mock_cron[ $hook ] );
		return true;
	}
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( string $url, int $component = -1 ): mixed {
		return parse_url( $url, $component );
	}
}
if ( ! function_exists( 'wp_clear_scheduled_hook' ) ) {
	function wp_clear_scheduled_hook( string $hook ): int {
		global $mock_cron;
		$n = isset( $mock_cron[ $hook ] ) ? 1 : 0;
		unset( $mock_cron[ $hook ] );
		return $n;
	}
}
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( string $cap ): bool {
		global $mock_user_caps;
		return (bool) ( $mock_user_caps[ $cap ] ?? false );
	}
}
if ( ! function_exists( 'is_user_logged_in' ) ) {
	function is_user_logged_in(): bool {
		global $mock_logged_in;
		return (bool) $mock_logged_in;
	}
}
if ( ! function_exists( 'is_admin' ) ) {
	function is_admin(): bool {
		global $mock_is_admin;
		return (bool) $mock_is_admin;
	}
}
if ( ! function_exists( 'is_feed' ) ) {
	function is_feed(): bool {
		return false;
	}
}
if ( ! function_exists( 'is_preview' ) ) {
	function is_preview(): bool {
		return false;
	}
}
if ( ! function_exists( 'wp_doing_ajax' ) ) {
	function wp_doing_ajax(): bool {
		return false;
	}
}
if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( mixed $val ): mixed {
		return is_string( $val ) ? stripslashes( $val ) : $val;
	}
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( string $str ): string {
		return trim( strip_tags( $str ) );
	}
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( string $key ): string {
		return (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) );
	}
}
if ( ! function_exists( 'absint' ) ) {
	function absint( mixed $v ): int {
		return abs( (int) $v );
	}
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( string $t ): string {
		return htmlspecialchars( $t, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( string $t ): string {
		return htmlspecialchars( $t, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( string $u ): string {
		return htmlspecialchars( $u, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'wp_kses' ) ) {
	function wp_kses( string $html, array $allowed ): string {
		unset( $allowed );
		return $html;
	}
}
if ( ! function_exists( '__' ) ) {
	function __( string $t, string $d = 'default' ): string {
		unset( $d );
		return $t;
	}
}
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( string $t, string $d = 'default' ): string {
		unset( $d );
		return esc_html( $t );
	}
}
if ( ! function_exists( 'esc_html_e' ) ) {
	function esc_html_e( string $t, string $d = 'default' ): void {
		echo esc_html__( $t, $d );
	}
}
if ( ! function_exists( 'esc_attr_e' ) ) {
	function esc_attr_e( string $t, string $d = 'default' ): void {
		unset( $d );
		echo esc_attr( $t );
	}
}
if ( ! function_exists( 'checked' ) ) {
	function checked( mixed $checked, mixed $current = true, bool $display = true ): string {
		$res = ( (bool) $checked === (bool) $current ) ? 'checked="checked"' : '';
		if ( $display ) {
			echo $res;
		}
		return $res;
	}
}
if ( ! function_exists( 'selected' ) ) {
	function selected( mixed $selected, mixed $current = true, bool $display = true ): string {
		$res = ( (string) $selected === (string) $current ) ? 'selected="selected"' : '';
		if ( $display ) {
			echo $res;
		}
		return $res;
	}
}
if ( ! function_exists( 'wp_nonce_field' ) ) {
	function wp_nonce_field( string $action, string $name ): void {
		echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="valid-nonce-' . esc_attr( $action ) . '" />';
	}
}
if ( ! function_exists( 'wp_verify_nonce' ) ) {
	function wp_verify_nonce( string $nonce, string $action ): int|false {
		return ( 'valid-nonce-' . $action ) === $nonce ? 1 : false;
	}
}
if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( string $path = '' ): string {
		return 'https://example.test/wp-admin/' . ltrim( $path, '/' );
	}
}
if ( ! function_exists( 'home_url' ) ) {
	function home_url( string $path = '' ): string {
		return 'https://example.test/' . ltrim( $path, '/' );
	}
}
if ( ! function_exists( 'add_query_arg' ) ) {
	function add_query_arg( array $args, string $url ): string {
		return $url . '?' . http_build_query( $args );
	}
}
if ( ! function_exists( 'wp_safe_redirect' ) ) {
	function wp_safe_redirect( string $location ): void {
		global $mock_redirect_target;
		$mock_redirect_target = $location;
		throw new Crawler_Labyrinth_Test_Redirect( $location );
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( mixed $data ): string|false {
		return json_encode( $data );
	}
}
if ( ! function_exists( 'register_rest_route' ) ) {
	function register_rest_route( string $ns, string $route, array $args ): void {
		$GLOBALS['mock_rest_routes'][ $ns . $route ] = $args;
	}
}
if ( ! class_exists( 'WP_REST_Server' ) ) {
	class WP_REST_Server {
		public const READABLE  = 'GET';
		public const CREATABLE = 'POST';
	}
}
if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {
		/** @var array<string, mixed> */
		private array $params;
		public function __construct( array $params = [] ) {
			$this->params = $params;
		}
		public function get_param( string $key ): mixed {
			return $this->params[ $key ] ?? null;
		}
	}
}
if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response {
		public mixed $data;
		public int $status;
		public function __construct( mixed $data = null, int $status = 200 ) {
			$this->data   = $data;
			$this->status = $status;
		}
		public function get_status(): int {
			return $this->status;
		}
		public function get_data(): mixed {
			return $this->data;
		}
	}
}

spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix   = 'CrawlerLabyrinth\\';
		$base_dir = ABSPATH . 'includes/';
		if ( 0 !== strncmp( $prefix, $class_name, strlen( $prefix ) ) ) {
			return;
		}
		$file = $base_dir . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';
		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}
);

use CrawlerLabyrinth\Admin\AdminDashboard;
use CrawlerLabyrinth\API\RestController;
use CrawlerLabyrinth\Audit\HitLogger;
use CrawlerLabyrinth\Core\Activator;
use CrawlerLabyrinth\Core\BaitRenderer;
use CrawlerLabyrinth\Core\RobotsInjector;
use CrawlerLabyrinth\Core\Settings;
use CrawlerLabyrinth\Core\TrapRouter;
use CrawlerLabyrinth\Generator\DecoyGenerator;
use CrawlerLabyrinth\Security\TokenSigner;

$passed_count = 0;
$failed_count = 0;

function assert_test( string $label, bool $condition, string $detail = '' ): void {
	global $passed_count, $failed_count;
	if ( $condition ) {
		echo "  [PASS] {$label}\n";
		$passed_count++;
		return;
	}
	echo "  [FAIL] {$label}" . ( '' !== $detail ? " ({$detail})" : '' ) . "\n";
	$failed_count++;
}

echo "==============================================================================\n";
echo "  CRAWLER LABYRINTH — STANDALONE UNIT & SECURITY SUITE\n";
echo "==============================================================================\n\n";

// Group 1: Settings & UA Separation
echo "Group 1: Settings sanitization & search/citation crawler protection\n";
assert_test(
	'sanitize_route_prefix rejects reserved WordPress paths and falls back to crawler-trap',
	'crawler-trap' === Settings::sanitize_route_prefix( 'wp-admin' )
		&& 'crawler-trap' === Settings::sanitize_route_prefix( 'wp-json' )
		&& 'crawler-trap' === Settings::sanitize_route_prefix( 'ab' )
		&& 'arch-trap-1' === Settings::sanitize_route_prefix( '/Arch-Trap-1/' ),
	Settings::sanitize_route_prefix( 'wp-admin' )
);
assert_test(
	'is_training_only_ua matches GPTBot/CCBot/ClaudeBot/Bytespider while sparing search and preview bots',
	Settings::is_training_only_ua( 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; GPTBot/1.2' )
		&& Settings::is_training_only_ua( 'CCBot/2.0 (https://commoncrawl.org/faq/)' )
		&& Settings::is_training_only_ua( 'ClaudeBot/1.0' )
		&& Settings::is_training_only_ua( 'Bytespider' )
		&& ! Settings::is_training_only_ua( 'OAI-SearchBot/1.0' )
		&& ! Settings::is_training_only_ua( 'OAI-SearchBot/1.0 (compatible; GPTBot-test)' )
		&& ! Settings::is_training_only_ua( 'Claude-SearchBot/1.0' )
		&& ! Settings::is_training_only_ua( 'PerplexityBot/1.0' )
		&& ! Settings::is_training_only_ua( 'Googlebot/2.1' )
		&& ! Settings::is_training_only_ua( 'Applebot/0.1' )
		&& ! Settings::is_training_only_ua( 'Slackbot-LinkExpanding 1.0' )
);

// Group 2: TokenSigner & Keyed IP Hashing
echo "\nGroup 2: TokenSigner HMAC signing, timing-safe pin, and keyed daily IP digests\n";
$secret = str_repeat( 'a', 64 );
$tok    = TokenSigner::sign( 3, 'seedalpha99', $secret );
$parsed = TokenSigner::verify( $tok, $secret );
assert_test(
	'TokenSigner signs and verifies valid trap token',
	null !== $parsed && 3 === $parsed['depth'] && 'seedalpha99' === $parsed['seed'],
	var_export( $parsed, true )
);
$tampered = substr( $tok, 0, -1 ) . ( str_ends_with( $tok, '0' ) ? '1' : '0' );
assert_test(
	'TokenSigner rejects tampered signature',
	null === TokenSigner::verify( $tampered, $secret ) && null === TokenSigner::verify( '1-seed-bad', $secret )
);
$signer_src = (string) file_get_contents( ABSPATH . 'includes/Security/TokenSigner.php' );
assert_test(
	'TokenSigner::verify uses hash_equals() in constant time',
	1 === substr_count( $signer_src, '! hash_equals(' ) && ! str_contains( $signer_src, '$expected !== $actual' )
);
$d1 = TokenSigner::ip_digest( '203.0.113.42', '2026-11-19', $secret );
$d2 = TokenSigner::ip_digest( '203.0.113.42', '2026-11-20', $secret );
assert_test(
	'Keyed daily IP digest rotates by UTC day and never reveals raw IP',
	64 === strlen( $d1 ) && $d1 !== $d2 && ! str_contains( $d1, '203.0.113' )
);

// Group 3: DecoyGenerator
echo "\nGroup 3: Deterministic local DecoyGenerator\n";
$gen  = new DecoyGenerator();
$doc1 = $gen->generate( 2, 'folio1234', 'html', 'crawler-trap', 4, 3, $secret );
$doc2 = $gen->generate( 2, 'folio1234', 'html', 'crawler-trap', 4, 3, $secret );
$wrap = $gen->generate( 4, 'folio1234', 'html', 'crawler-trap', 4, 2, $secret );
assert_test(
	'DecoyGenerator is 100% deterministic for identical depth/seed and carries noindex/noai directives',
	$doc1['body'] === $doc2['body']
		&& str_contains( $doc1['body'], 'noindex, nofollow, noarchive, nosnippet, noai, noimageai' )
		&& ! str_contains( $doc1['body'], '<script' ),
	substr( $doc1['body'], 0, 120 )
);
assert_test(
	'DecoyGenerator wraps max_depth child tokens back to depth 1 ring',
	str_starts_with( $wrap['child_tokens'][0], '1-' ) && null !== TokenSigner::verify( $wrap['child_tokens'][0], $secret )
);

// Group 4: RobotsInjector (RFC 9309)
echo "\nGroup 4: RFC 9309 multi-group robots.txt injection\n";
$sample_robots = "User-agent: OAI-SearchBot\nAllow: /\nUser-agent: GPTBot\nDisallow: /\n\nUser-agent: *\nAllow: /\n";
$injected      = RobotsInjector::inject( $sample_robots, 'crawler-trap' );
assert_test(
	'RobotsInjector injects Disallow: /crawler-trap/ into every User-agent group (no Allow shadowing)',
	3 === substr_count( $injected, 'Disallow: /crawler-trap/' )
		&& str_contains( $injected, "User-agent: OAI-SearchBot\nAllow: /\nDisallow: /crawler-trap/" )
		&& str_contains( $injected, "User-agent: *\nAllow: /\nDisallow: /crawler-trap/" ),
	$injected
);
$injected_twice = RobotsInjector::inject( $injected, 'crawler-trap' );
assert_test(
	'RobotsInjector is idempotent when invoked twice',
	$injected === $injected_twice
);

// Group 5: BaitRenderer
echo "\nGroup 5: Accessible-inert BaitRenderer\n";
$mock_options  = [ 'crawler_labyrinth_enabled' => '1', 'crawler_labyrinth_link_count' => 2, 'crawler_labyrinth_route_prefix' => 'crawler-trap' ];
$mock_logged_in = false;
$mock_is_admin  = false;
$bait           = BaitRenderer::build_markup( '/pricing/' );
assert_test(
	'BaitRenderer outputs hidden + aria-hidden + tabindex=-1 + rel=nofollow noopener without inline display:none',
	str_contains( $bait, '<div hidden aria-hidden="true" data-crawler-labyrinth="1">' )
		&& 2 === substr_count( $bait, 'rel="nofollow noopener" tabindex="-1" aria-hidden="true"' )
		&& ! str_contains( $bait, 'display:none' )
		&& ! str_contains( $bait, 'display: none' ),
	$bait
);
$mock_logged_in = true;
assert_test(
	'BaitRenderer skips logged-in users',
	! BaitRenderer::should_render_bait()
);
$mock_logged_in = false;

// Group 6: TrapRouter Hybrid Escalation & Exemptions
echo "\nGroup 6: TrapRouter hybrid escalation, quarantine, admin & search exemptions\n";
Activator::ensure_ready();
$mock_transients = [];
$mock_options    = [
	'crawler_labyrinth_enabled'               => '1',
	'crawler_labyrinth_route_prefix'          => 'crawler-trap',
	'crawler_labyrinth_mode'                  => 'hybrid',
	'crawler_labyrinth_response_format'       => 'html',
	'crawler_labyrinth_strike_threshold'      => 2,
	'crawler_labyrinth_block_ttl_minutes'     => 60,
	'crawler_labyrinth_block_status'          => 429,
	'crawler_labyrinth_intercept_training_ua' => '1',
	'crawler_labyrinth_hmac_secret'           => $secret,
	'crawler_labyrinth_schema_version'        => Activator::SCHEMA_VERSION,
];
$router = new TrapRouter();
$r1     = $router->handle_request( '/crawler-trap/1-seedalpha1-0000/', 'BadScraper/1.0', '198.51.100.10', false );
$r2     = $router->handle_request( '/crawler-trap/2-seedalpha2-0000/', 'BadScraper/1.0', '198.51.100.10', false );
$r3     = $router->handle_request( '/crawler-trap/3-seedalpha3-0000/', 'BadScraper/1.0', '198.51.100.10', false );
$r4     = $router->handle_request( '/ordinary-page/', 'BadScraper/1.0', '198.51.100.10', false );
assert_test(
	'Hybrid mode serves 200 decoys up to strike_threshold (2) then quarantines with HTTP 429 and Retry-After',
	200 === $r1['status']
		&& 200 === $r2['status']
		&& 429 === $r3['status']
		&& isset( $r3['headers']['Retry-After'] )
		&& true === $r4['handled']
		&& 429 === $r4['status']
);
$r_admin = $router->handle_request( '/crawler-trap/test/', 'BadScraper/1.0', '198.51.100.10', true );
$r_wp    = $router->handle_request( '/wp-admin/', 'BadScraper/1.0', '198.51.100.10', false );
$r_oai   = $router->handle_request( '/ordinary-page/', 'OAI-SearchBot/1.0', '198.51.100.55', false );
$r_gpt   = $router->handle_request( '/ordinary-page/', 'GPTBot/1.2', '198.51.100.77', false );
assert_test(
	'TrapRouter spares admins, wp-admin, and OAI-SearchBot while trapping opt-in GPTBot',
	false === $r_admin['handled']
		&& false === $r_wp['handled']
		&& false === $r_oai['handled']
		&& true === $r_gpt['handled']
		&& 200 === $r_gpt['status']
);

$router_src = (string) file_get_contents( ABSPATH . 'includes/Core/TrapRouter.php' );
assert_test(
	'TrapRouter uses wp_parse_url() instead of bare parse_url()',
	str_contains( $router_src, 'wp_parse_url( $request_uri' )
		&& 0 === preg_match( '/(?<!wp_)parse_url\s*\(/', $router_src )
);

// Group 7: HitLogger HMAC Chain & Pseudonymizing Retention
echo "\nGroup 7: HitLogger HMAC chain integrity and pseudonymizing retention\n";
$wpdb->rows      = [];
$wpdb->insert_id = 0;
$logger          = new HitLogger();
$old_date        = gmdate( 'Y-m-d H:i:s', time() - ( 45 * DAY_IN_SECONDS ) );
$new_date        = gmdate( 'Y-m-d H:i:s' );
$id_old1         = $logger->record( $d1, TokenSigner::ua_digest( 'BadBot/1', $secret ), 'BadBot/1', '1-aaa', 'decoy_served', '{}', $old_date );
$id_old2         = $logger->record( $d1, TokenSigner::ua_digest( 'BadBot/1', $secret ), 'BadBot/1', '2-bbb', 'quarantined', '{}', $old_date );
$id_new          = $logger->record( $d2, TokenSigner::ua_digest( 'BadBot/2', $secret ), 'BadBot/2', '1-ccc', 'decoy_served', '{}', $new_date );

$chain_ok = $logger->verify_chain();
assert_test(
	'HitLogger HMAC chain verifies intact rows',
	true === $chain_ok['valid'] && 3 === $chain_ok['total_verified'],
	var_export( $chain_ok, true )
);

// Simulate SQL tampering and restore
$saved_token                         = $wpdb->rows[ $id_old2 ]->route_token;
$wpdb->rows[ $id_old2 ]->route_token = 'tampered-route';
$chain_bad                           = $logger->verify_chain();
assert_test(
	'HitLogger detects SQL tampering and reports exact broken_at_id',
	false === $chain_bad['valid'] && $id_old2 === $chain_bad['broken_at_id'],
	var_export( $chain_bad, true )
);
$wpdb->rows[ $id_old2 ]->route_token = $saved_token;

// Dry run vs real pseudonymization
$dry               = $logger->purge_expired( true, 30 );
$pur               = $logger->purge_expired( false, 30 );
$chain_after_purge = $logger->verify_chain();
assert_test(
	'Pseudonymizing retention redacts expired user_agent_excerpt without deleting rows and keeps chain valid',
	2 === $dry['candidates']
		&& 2 === $pur['pseudonymized']
		&& HitLogger::REDACTED_MARKER === $wpdb->rows[ $id_old1 ]->user_agent_excerpt
		&& 'BadBot/2' === $wpdb->rows[ $id_new ]->user_agent_excerpt
		&& true === $chain_after_purge['valid'],
	var_export( $chain_after_purge, true )
);

// Tampering a redacted row's metadata must still fail
$wpdb->rows[ $id_old1 ]->action_taken = 'decoy_tamper';
$chain_redacted_bad                   = $logger->verify_chain();
assert_test(
	'Tampering a pseudonymized row still fails chain verification via residual hash',
	false === $chain_redacted_bad['valid'] && $id_old1 === $chain_redacted_bad['broken_at_id']
);
$wpdb->rows[ $id_old1 ]->action_taken = 'decoy_served';

// Manual unauthorized redaction must fail
$wpdb->rows[ $id_new ]->user_agent_excerpt = HitLogger::REDACTED_MARKER;
$chain_unauth_redact                       = $logger->verify_chain();
assert_test(
	'Manual SQL redaction without retention_pseudonymized record fails verification',
	false === $chain_unauth_redact['valid'] && $id_new === $chain_unauth_redact['broken_at_id']
);

// Group 8: Admin per-form isolation, REST, Hooks, and Opt-in Uninstall
echo "\nGroup 8: Admin per-form isolation, REST, filters, and opt-in uninstall\n";
$admin_src = (string) file_get_contents( ABSPATH . 'includes/Admin/AdminDashboard.php' );
assert_test(
	'AdminDashboard contains no raw emoji and sets aria-current="page"',
	1 !== preg_match( '/[\x{1F300}-\x{1FAFF}]/u', $admin_src )
		&& str_contains( $admin_src, 'aria-current="page"' )
);

$mock_options['crawler_labyrinth_enabled']                  = '1';
$mock_options['crawler_labyrinth_delete_data_on_uninstall'] = '1';
$_POST                                                      = [
	'crawler_labyrinth_save_nonce'   => 'valid-nonce-crawler_labyrinth_save_action',
	'crawler_labyrinth_form'         => 'settings',
	'crawler_labyrinth_enabled'      => '1',
	'crawler_labyrinth_route_prefix' => 'custom-trap',
	'crawler_labyrinth_mode'         => 'labyrinth',
];
try {
	AdminDashboard::instance()->save_settings();
} catch ( Crawler_Labyrinth_Test_Redirect $e ) {
	unset( $e );
}
assert_test(
	'Saving settings form updates settings tab only and preserves lifecycle opt-in flag',
	'custom-trap' === ( $mock_options['crawler_labyrinth_route_prefix'] ?? '' )
		&& 'labyrinth' === ( $mock_options['crawler_labyrinth_mode'] ?? '' )
		&& '1' === ( $mock_options['crawler_labyrinth_delete_data_on_uninstall'] ?? '' )
);

$_POST = [
	'crawler_labyrinth_save_nonce' => 'valid-nonce-crawler_labyrinth_save_action',
	'crawler_labyrinth_enabled'    => '0',
];
try {
	AdminDashboard::instance()->save_settings();
} catch ( Crawler_Labyrinth_Test_Redirect $e ) {
	unset( $e );
}
assert_test(
	'Posting without crawler_labyrinth_form marker changes no option',
	'1' === ( $mock_options['crawler_labyrinth_enabled'] ?? '' )
);
$_POST = [];

$rest                             = new RestController();
$mock_user_caps['manage_options'] = false;
assert_test( 'REST denies non-admin callers', false === $rest->check_admin_permission() );
$mock_user_caps['manage_options'] = true;
$bad_preview                      = $rest->handle_preview( new WP_REST_Request( [ 'seed' => '!!' ] ) );
$good_preview                     = $rest->handle_preview( new WP_REST_Request( [ 'depth' => 2, 'seed' => 'goodseed12', 'format' => 'plain' ] ) );
assert_test(
	'REST /preview returns 400 on invalid seed and 200 on valid input',
	400 === $bad_preview->get_status() && 200 === $good_preview->get_status()
);

// Custom filter extensibility check
add_filter(
	'crawler_labyrinth_training_bots',
	static function ( array $bots ): array {
		$bots[] = 'CustomScraperBot';
		return $bots;
	}
);
assert_test(
	'crawler_labyrinth_training_bots filter extends bot detection without overriding allowed search bots',
	Settings::is_training_only_ua( 'CustomScraperBot/1.0' )
		&& ! Settings::is_training_only_ua( 'OAI-SearchBot/1.0 CustomScraperBot' )
);

// Uninstall opt-in test
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	define( 'WP_UNINSTALL_PLUGIN', true );
}
$mock_options        = [
	'crawler_labyrinth_enabled'                  => '1',
	'crawler_labyrinth_route_prefix'             => 'crawler-trap',
	'crawler_labyrinth_delete_data_on_uninstall' => '0',
	'crawler_labyrinth_hmac_secret'              => $secret,
];
$wpdb->table_created = true;
$wpdb->table_dropped = false;
require ABSPATH . 'uninstall.php';
assert_test(
	'uninstall.php preserves options and table by default when delete_data_on_uninstall is 0',
	'crawler-trap' === ( $mock_options['crawler_labyrinth_route_prefix'] ?? null ) && false === $wpdb->table_dropped
);

$mock_options['crawler_labyrinth_delete_data_on_uninstall'] = '1';
require ABSPATH . 'uninstall.php';
assert_test(
	'uninstall.php deletes all options, transients, and drops table when delete_data_on_uninstall is 1',
	[] === $mock_options && [] === $mock_transients && true === $wpdb->table_dropped
);

echo "\n==============================================================================\n";
echo "  TEST SUITE RESULTS: {$passed_count} PASSED | {$failed_count} FAILED\n";
echo "==============================================================================\n\n";

exit( 0 === $failed_count ? 0 : 1 );
