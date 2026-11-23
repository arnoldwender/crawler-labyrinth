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
	define( 'CRAWLER_LABYRINTH_VERSION', '1.1.0' );
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

global $mock_options, $mock_blog_options, $mock_site_options, $mock_current_blog_id, $mock_is_multisite, $mock_home_url, $mock_transients, $mock_filters, $mock_actions, $mock_fired_actions, $mock_last_action_args, $mock_user_caps, $mock_logged_in, $mock_is_admin, $mock_redirect_target, $mock_cron, $mock_wpcli_messages;
$mock_options           = [];
$mock_blog_options      = [];
$mock_site_options      = [];
$mock_current_blog_id   = 1;
$mock_is_multisite      = false;
$mock_home_url          = 'https://example.test/';
$mock_transients        = [];
$mock_filters           = [];
$mock_actions           = [];
$mock_fired_actions     = [];
$mock_last_action_args  = [];
$mock_user_caps         = [ 'manage_options' => true ];
$mock_logged_in         = false;
$mock_is_admin          = false;
$mock_redirect_target   = '';
$mock_cron              = [];
$mock_wpcli_messages    = [];

class Crawler_Labyrinth_Test_Redirect extends RuntimeException {}

class Crawler_Labyrinth_Mock_WPDB {
	public string $prefix  = 'wp_';
	public string $options = 'wp_options';
	public int $insert_id  = 0;
	/** @var array<int, object> */
	public array $rows               = [];
	public bool $table_created       = false;
	public bool $table_dropped       = false;
	/** @var array<string, bool> */
	public array $tables_dropped     = [];
	public bool $lock_timeout        = false;
	public bool $insert_fail         = false;
	public int $lock_calls           = 0;
	public int $unlock_calls         = 0;
	public int $show_tables_queries  = 0;
	public int $chain_select_queries = 0;

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
		if ( $this->insert_fail ) {
			return false;
		}
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
		if ( str_contains( $query, 'SELECT GET_LOCK(' ) ) {
			$this->lock_calls++;
			return $this->lock_timeout ? '0' : '1';
		}
		if ( str_contains( $query, 'SELECT RELEASE_LOCK(' ) ) {
			$this->unlock_calls++;
			return '1';
		}
		if ( str_contains( $query, 'SHOW TABLES LIKE' ) ) {
			$this->show_tables_queries++;
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
			if ( str_contains( $query, "action_taken = 'observed'" ) ) {
				return count( array_filter( $this->rows, static fn( $r ): bool => 'observed' === $r->action_taken ) );
			}
			return count( array_filter( $this->rows, static fn( $r ): bool => 'retention_pseudonymized' !== $r->action_taken ) );
		}
		return null;
	}

	/**
	 * @return list<string>
	 */
	public function get_col( string $query ): array {
		global $mock_transients;
		$now = time();
		if ( preg_match( '/CAST\(option_value AS UNSIGNED\)\s*<\s*(\d+)/', $query, $m ) ) {
			$now = (int) $m[1];
		}
		$expired = [];
		foreach ( $mock_transients as $key => $entry ) {
			if ( str_starts_with( $key, '__timeout_' ) ) {
				if ( (int) $entry < $now ) {
					$expired[] = '_transient_timeout_' . substr( $key, 10 );
				}
			}
		}
		return $expired;
	}

	/**
	 * @return list<object>
	 */
	public function get_results( string $query ): array {
		if ( str_contains( $query, 'WHERE id >' ) && str_contains( $query, 'crawler_labyrinth_hits`' ) && ! str_contains( $query, 'created_at <' ) ) {
			$this->chain_select_queries++;
		}
		$rows = array_values( $this->rows );
		if ( str_contains( $query, 'ORDER BY id DESC' ) ) {
			$rows = array_reverse( $rows );
		}
		if ( preg_match( '/\bid\s*>\s*(\d+)/', $query, $m ) ) {
			$min_id = (int) $m[1];
			$rows   = array_values(
				array_filter(
					$rows,
					static fn( $r ): bool => (int) $r->id > $min_id
				)
			);
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
		$normalized = str_replace( '\\', '', $sql );
		if ( preg_match( '/DROP TABLE IF EXISTS\s+`?([^`\s]+)`?/i', $normalized, $m ) ) {
			$this->rows                       = [];
			$this->table_dropped              = true;
			$this->table_created              = false;
			$this->tables_dropped[ $m[1] ]    = true;
			return true;
		}
		if ( preg_match_all( "/'(_transient_(?:timeout_)?[^']+)'/", $normalized, $matches ) && str_contains( $normalized, 'option_name IN' ) ) {
			$deleted = 0;
			foreach ( $matches[1] as $opt_name ) {
				if ( str_starts_with( $opt_name, '_transient_timeout_' ) ) {
					$k = '__timeout_' . substr( $opt_name, 19 );
					if ( isset( $mock_transients[ $k ] ) ) {
						unset( $mock_transients[ $k ] );
						$deleted++;
					}
				} elseif ( str_starts_with( $opt_name, '_transient_' ) ) {
					$k = substr( $opt_name, 11 );
					if ( isset( $mock_transients[ $k ] ) ) {
						unset( $mock_transients[ $k ] );
						$deleted++;
					}
				}
			}
			return $deleted;
		}
		if ( str_contains( $normalized, '_transient_crawler_labyrinth_' ) ) {
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
		global $mock_fired_actions, $mock_last_action_args;
		$mock_fired_actions[ $tag ]     = ( $mock_fired_actions[ $tag ] ?? 0 ) + 1;
		$mock_last_action_args[ $tag ]  = $args;
	}
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $option, mixed $default = false ): mixed {
		global $mock_options, $mock_current_blog_id, $mock_blog_options, $mock_notoptions_poisoned;
		if ( ! empty( $mock_notoptions_poisoned ) && 'crawler_labyrinth_hmac_secret' === $option ) {
			return $default;
		}
		if ( 1 !== ( $mock_current_blog_id ?? 1 ) ) {
			return $mock_blog_options[ $mock_current_blog_id ][ $option ] ?? $default;
		}
		return $mock_options[ $option ] ?? $default;
	}
}
if ( ! function_exists( 'wp_cache_delete' ) ) {
	function wp_cache_delete( string $key, string $group = '' ): bool {
		global $mock_notoptions_poisoned;
		if ( 'notoptions' === $key && 'options' === $group ) {
			$mock_notoptions_poisoned = false;
		}
		return true;
	}
}
if ( ! function_exists( 'get_site_option' ) ) {
	function get_site_option( string $option, mixed $default = false ): mixed {
		global $mock_site_options;
		return $mock_site_options[ $option ] ?? $default;
	}
}
if ( ! function_exists( 'add_option' ) ) {
	function add_option( string $option, mixed $value = '', string $deprecated = '', mixed $autoload = null ): bool {
		unset( $deprecated, $autoload );
		global $mock_options, $mock_current_blog_id, $mock_blog_options, $mock_simulate_race_secret;
		if ( 'crawler_labyrinth_hmac_secret' === $option && ! empty( $mock_simulate_race_secret ) ) {
			$mock_options[ $option ]   = (string) $mock_simulate_race_secret;
			$mock_simulate_race_secret = '';
			return false;
		}
		if ( 1 !== ( $mock_current_blog_id ?? 1 ) ) {
			if ( array_key_exists( $option, $mock_blog_options[ $mock_current_blog_id ] ?? [] ) ) {
				return false;
			}
			$mock_blog_options[ $mock_current_blog_id ][ $option ] = $value;
			return true;
		}
		if ( array_key_exists( $option, $mock_options ) ) {
			return false;
		}
		$mock_options[ $option ] = $value;
		return true;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $option, mixed $value, mixed $autoload = null ): bool {
		unset( $autoload );
		global $mock_options, $mock_current_blog_id, $mock_blog_options;
		if ( 1 !== ( $mock_current_blog_id ?? 1 ) ) {
			$mock_blog_options[ $mock_current_blog_id ][ $option ] = $value;
			return true;
		}
		$mock_options[ $option ] = $value;
		return true;
	}
}
if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( string $option ): bool {
		global $mock_options, $mock_current_blog_id, $mock_blog_options;
		if ( 1 !== ( $mock_current_blog_id ?? 1 ) ) {
			unset( $mock_blog_options[ $mock_current_blog_id ][ $option ] );
			return true;
		}
		unset( $mock_options[ $option ] );
		return true;
	}
}
if ( ! function_exists( 'delete_site_option' ) ) {
	function delete_site_option( string $option ): bool {
		global $mock_site_options;
		unset( $mock_site_options[ $option ] );
		return true;
	}
}
if ( ! function_exists( 'is_multisite' ) ) {
	function is_multisite(): bool {
		global $mock_is_multisite;
		return (bool) ( $mock_is_multisite ?? false );
	}
}
if ( ! function_exists( 'get_sites' ) ) {
	/**
	 * @param array<string, mixed> $args
	 * @return list<int>
	 */
	function get_sites( array $args = [] ): array {
		unset( $args );
		return [ 1, 2 ];
	}
}
if ( ! function_exists( 'switch_to_blog' ) ) {
	function switch_to_blog( int $blog_id ): bool {
		global $mock_current_blog_id, $wpdb;
		$mock_current_blog_id = $blog_id;
		$wpdb->prefix         = 1 === $blog_id ? 'wp_' : "wp_{$blog_id}_";
		$wpdb->options        = $wpdb->prefix . 'options';
		return true;
	}
}
if ( ! function_exists( 'restore_current_blog' ) ) {
	function restore_current_blog(): bool {
		return switch_to_blog( 1 );
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
		global $mock_home_url;
		return rtrim( (string) ( $mock_home_url ?? 'https://example.test/' ), '/' ) . '/' . ltrim( $path, '/' );
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

if ( ! class_exists( 'WP_CLI' ) ) {
	class WP_CLI {
		public static function line( string $msg ): void {
			global $mock_wpcli_messages;
			$mock_wpcli_messages[] = $msg;
		}
		public static function success( string $msg ): void {
			global $mock_wpcli_messages;
			$mock_wpcli_messages[] = 'SUCCESS:' . $msg;
		}
		public static function error( string $msg ): void {
			global $mock_wpcli_messages;
			$mock_wpcli_messages[] = 'ERROR:' . $msg;
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
use CrawlerLabyrinth\CLI\LabyrinthCommand;
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
	'Keyed daily IP digest uses secret key, rotates by UTC day, normalizes IPv6/mapped IPv4, and never reveals raw IP',
	64 === strlen( $d1 )
		&& $d1 !== $d2
		&& $d1 !== TokenSigner::ip_digest( '203.0.113.42', '2026-11-19', str_repeat( 'b', 64 ) )
		&& TokenSigner::ip_digest( '::1', '2026-11-19', $secret ) === TokenSigner::ip_digest( '[::1]:443', '2026-11-19', $secret )
		&& TokenSigner::ip_digest( '2001:DB8::1', '2026-11-19', $secret ) === TokenSigner::ip_digest( '2001:0db8:0000:0000::1', '2026-11-19', $secret )
		&& TokenSigner::ip_digest( '198.51.100.7', '2026-11-19', $secret ) === TokenSigner::ip_digest( '::ffff:198.51.100.7', '2026-11-19', $secret )
		&& ! str_contains( $d1, '203.0.113' )
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
	'BaitRenderer outputs hidden + inert + aria-hidden + tabindex=-1 + rel=nofollow noopener without inline display:none',
	str_contains( $bait, '<div hidden inert aria-hidden="true" data-crawler-labyrinth="1">' )
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
assert_test(
	'DONOTCACHEPAGE is not defined before any trap hit',
	! defined( 'DONOTCACHEPAGE' )
);
$r1     = $router->handle_request( '/crawler-trap/1-seedalpha1-0000/', 'BadScraper/1.0', '198.51.100.10', false );
$r2     = $router->handle_request( '/crawler-trap/2-seedalpha2-0000/', 'BadScraper/1.0', '198.51.100.10', false );
$r3     = $router->handle_request( '/crawler-trap/3-seedalpha3-0000/', 'BadScraper/1.0', '198.51.100.10', false );
$r4     = $router->handle_request( '/ordinary-page/', 'BadScraper/1.0', '198.51.100.10', false );
assert_test(
	'Hybrid mode serves 200 decoys up to strike_threshold (2) then quarantines with HTTP 429, Retry-After, and DONOTCACHEPAGE',
	200 === $r1['status']
		&& 200 === $r2['status']
		&& 429 === $r3['status']
		&& isset( $r3['headers']['Retry-After'] )
		&& true === $r4['handled']
		&& 429 === $r4['status']
		&& defined( 'DONOTCACHEPAGE' )
		&& true === DONOTCACHEPAGE
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

// Group 9: Subdirectory installs, path canonicalization, modes, multi-day quarantine, HEAD & text/plain
echo "\nGroup 9: Subdirectory installs, obfuscated paths, modes, multi-day quarantine & emit_http_response\n";
$mock_home_url   = 'https://example.test/blog/';
$mock_transients = [];
$wpdb->rows      = [];
$wpdb->insert_id = 0;
$mock_options    = [
	'crawler_labyrinth_enabled'               => '1',
	'crawler_labyrinth_route_prefix'          => 'feedback',
	'crawler_labyrinth_mode'                  => 'labyrinth',
	'crawler_labyrinth_response_format'       => 'html',
	'crawler_labyrinth_max_depth'             => 4,
	'crawler_labyrinth_strike_threshold'      => 2,
	'crawler_labyrinth_block_ttl_minutes'     => 10080,
	'crawler_labyrinth_block_status'          => 403,
	'crawler_labyrinth_intercept_training_ua' => '1',
	'crawler_labyrinth_hmac_secret'           => $secret,
	'crawler_labyrinth_schema_version'        => Activator::SCHEMA_VERSION,
];
$router = new TrapRouter();
assert_test(
	'Settings::base_path() normalizes /blog/ subdirectory URL',
	'/blog' === Settings::base_path()
);
$sub_trap    = $router->handle_request( '/blog/feedback/1-seedalpha1-0000/', 'BadBot/1.0', '198.51.100.31', false );
$sub_feed    = $router->handle_request( '/blog/feed/', 'BadBot/1.0', '198.51.100.31', false );
$other_app   = $router->handle_request( '/feedback/1-seedalpha1-0000/', 'BadBot/1.0', '198.51.100.31', false );
$sub_decoy_2 = $router->handle_request( '/blog/index.php/%66eedback//1-seedalpha1-0000/', 'BadBot/1.0', '198.51.100.31', false );
$sub_decoy_3 = $router->handle_request( '//blog///Feedback/', 'BadBot/1.0', '198.51.100.31', false );
$sub_robots  = RobotsInjector::inject( "User-agent: BadBot\n\nUser-agent: OAI-SearchBot # inline\r\nAllow: /\r\nDisallow: /blog/feedback/ # note\r\nSitemap: https://example.test/sitemap.xml\r\n", 'feedback' );
assert_test(
	'Subdirectory /blog/ handles valid "feedback" trap, spares /blog/feed/, ignores non-/blog paths, decodes obfuscated paths, and never quarantines in labyrinth mode',
	true === $sub_trap['handled']
		&& 200 === $sub_trap['status']
		&& str_contains( $sub_trap['body'], 'href="/blog/feedback/' )
		&& false === $sub_feed['handled']
		&& false === $other_app['handled']
		&& true === $sub_decoy_2['handled']
		&& 200 === $sub_decoy_2['status']
		&& true === $sub_decoy_3['handled']
		&& 200 === $sub_decoy_3['status']
		&& 'root' === ( $mock_last_action_args['crawler_labyrinth_trap_triggered'][1] ?? '' )
		&& ! str_contains( (string) ( $mock_last_action_args['crawler_labyrinth_trap_triggered'][3] ?? '' ), '198.51.100' )
);
assert_test(
	'RobotsInjector flushes empty User-agent blocks, respects inline # comments, preserves CRLF, and honors subdir base_path',
	str_contains( $sub_robots, "User-agent: BadBot\r\nDisallow: /blog/feedback/\r\n" )
		&& 2 === substr_count( $sub_robots, 'Disallow: /blog/feedback/' )
		&& str_contains( ( new RobotsInjector() )->filter_robots_txt( "User-agent: *\nAllow: /\n", '1' ), 'Disallow: /blog/feedback/' )
);

// Reset home_url and verify 403 block_status, block mode, maxDepth reject, DecoyGenerator prefix sanitization, HEAD & text/plain
$mock_home_url = 'https://example.test/';
$mock_options['crawler_labyrinth_route_prefix']  = 'crawler-trap';
$mock_options['crawler_labyrinth_mode']          = 'block';
$mock_options['crawler_labyrinth_block_status']  = 403;
$mock_transients                                 = [];
$r_403 = $router->handle_request( '/crawler-trap/1-seedalpha1-0000/', 'BlockBot/1.0', '198.51.100.41', false );
assert_test(
	'Block mode + 403 emits HTTP 403 without Retry-After header',
	true === $r_403['handled'] && 403 === $r_403['status'] && ! isset( $r_403['headers']['Retry-After'] )
);

// Simulate multi-day rollover where yesterday's digest was quarantined for 7 days
$mock_transients   = [];
$yesterday_digest  = TokenSigner::ip_digest( '198.51.100.44', gmdate( 'Y-m-d', time() - 86400 ), $secret );
$yesterday_q_key   = 'crawler_labyrinth_q_' . substr( $yesterday_digest, 0, 24 );
$mock_transients[ $yesterday_q_key ]             = [ 'until' => time() + 7200, 'strikes' => 9 ];
$mock_options['crawler_labyrinth_active_quarantine_max'] = time() + 7200;
$r_rollover = $router->handle_request( '/normal-page/', 'RolloverBot/1.0', '198.51.100.44', false );
assert_test(
	'Multi-day quarantine persists across UTC day rollover',
	true === $r_rollover['handled'] && 403 === $r_rollover['status'] && 9 === $r_rollover['strikes']
);

// DecoyGenerator sanitizes malicious & reserved route_prefix even in plain mode & aligns empty seed padding; depth > max_depth rejected in TrapRouter
$xss_decoy      = $gen->generate( 2, '', 'plain', '/"onclick="alert(1)/', 4, 2, $secret );
$reserved_decoy = $gen->generate( 2, 'ab', 'plain', 'wp-admin', 4, 2, $secret );
$forged_d       = TokenSigner::sign( 16, 'forgedseed1', $secret );
$mock_transients = [];
$mock_options['crawler_labyrinth_mode']          = 'labyrinth';
$mock_options['crawler_labyrinth_max_depth']     = 4;
$mock_options['crawler_labyrinth_active_quarantine_max'] = 0;
$r_overdepth  = $router->handle_request( '/crawler-trap/' . $forged_d . '/', 'BadBot/1.0', '198.51.100.92', false );
$r_feedback_ua = $router->handle_request( '/feedback', 'GPTBot/1.2', '198.51.100.93', false );
$r_feed_ua     = $router->handle_request( '/feed/', 'GPTBot/1.2', '198.51.100.94', false );
assert_test(
	'DecoyGenerator sanitizes routePrefix in plain mode, aligns short/empty seed padding, TrapRouter rejects forged depth > max_depth, and segment-bounded exclusions trap /feedback while sparing /feed/',
	str_contains( $xss_decoy['body'], '- /onclickalert1/' )
		&& str_contains( $reserved_decoy['body'], '- /crawler-trap/' )
		&& ! str_contains( $xss_decoy['body'], 'onclick=' )
		&& ! str_contains( $xss_decoy['body'], '"' )
		&& 'archroot0001' === $xss_decoy['seed']
		&& 'abarch0001' === $reserved_decoy['seed']
		&& str_contains( $r_overdepth['body'], '(Section 1)' )
		&& true === $r_feedback_ua['handled']
		&& false === $r_feed_ua['handled']
);

// Reflection test on emit_http_response for HEAD (empty body) and text/plain (unescaped &)
$emit_ref = new ReflectionMethod( TrapRouter::class, 'emit_http_response' );
$_SERVER['REQUEST_METHOD'] = 'HEAD';
ob_start();
$emit_ref->invoke( $router, [ 'status' => 200, 'headers' => [ 'Content-Type' => 'text/html; charset=utf-8' ], 'body' => '<p>Body</p>' ] );
$head_out = (string) ob_get_clean();
$_SERVER['REQUEST_METHOD'] = 'GET';
ob_start();
$emit_ref->invoke( $router, [ 'status' => 200, 'headers' => [ 'Content-Type' => 'text/plain; charset=utf-8' ], 'body' => "A & B <raw>\n" ] );
$plain_out = (string) ob_get_clean();
assert_test(
	'emit_http_response suppresses body on HEAD and emits unescaped text/plain body',
	'' === $head_out && "A & B <raw>\n" === $plain_out
);

// Group 10: Activator zero-query steady state, HitLogger advisory lock, >500 keyset chain, prev_hash tamper, multi-batch retention & expired transients
echo "\nGroup 10: Activator query caching, HitLogger locking, keyset pagination & multi-batch retention\n";
$wpdb->show_tables_queries = 0;
$mock_options['crawler_labyrinth_schema_version'] = Activator::SCHEMA_VERSION;
Activator::ensure_ready();
assert_test(
	'Activator::ensure_ready executes zero SHOW TABLES LIKE queries once schema is current',
	0 === $wpdb->show_tables_queries
);

$wpdb->rows         = [];
$wpdb->insert_id    = 0;
$wpdb->lock_calls   = 0;
$wpdb->unlock_calls = 0;
$logger             = new HitLogger();
$batch_old          = gmdate( 'Y-m-d H:i:s', time() - ( 40 * DAY_IN_SECONDS ) );
for ( $i = 0; $i < 250; $i++ ) {
	$logger->record( $d1, TokenSigner::ua_digest( 'Bot/' . $i, $secret ), 'Bot/' . $i, '1-seed' . $i, 'decoy_served', '{}', $batch_old );
}
for ( $i = 0; $i < 265; $i++ ) {
	$logger->record( $d2, TokenSigner::ua_digest( 'Fresh/' . $i, $secret ), 'Fresh/' . $i, '1-new' . $i, 'decoy_served', '{}', $new_date );
}
assert_test(
	'HitLogger::record acquires and releases MySQL GET_LOCK around chain appends',
	$wpdb->lock_calls === 515 && $wpdb->unlock_calls === 515
);
$wpdb->lock_timeout = true;
$locked_fail_id     = $logger->record( $d1, $d2, 'LockedBot', '1-lock', 'decoy_served' );
$wpdb->lock_timeout = false;
assert_test(
	'HitLogger::record aborts cleanly (returns 0) on GET_LOCK timeout',
	0 === $locked_fail_id
);

$big_chain = $logger->verify_chain();
assert_test(
	'HitLogger::verify_chain verifies >500 rows cleanly via keyset pagination',
	true === $big_chain['valid'] && 515 === $big_chain['total_verified'],
	var_export( $big_chain, true )
);

// Corrupt prev_hash linkage while keeping row_hash internally consistent with corrupted prev_hash
$orig_prev_2               = $wpdb->rows[2]->prev_hash;
$orig_hash_2               = $wpdb->rows[2]->row_hash;
$wpdb->rows[2]->prev_hash  = str_repeat( 'f', 64 );
$wpdb->rows[2]->row_hash   = HitLogger::compute_full_hash(
	$wpdb->rows[2]->created_at,
	$wpdb->rows[2]->ip_digest,
	$wpdb->rows[2]->ua_digest,
	$wpdb->rows[2]->user_agent_excerpt,
	$wpdb->rows[2]->route_token,
	$wpdb->rows[2]->action_taken,
	$wpdb->rows[2]->meta_json,
	$wpdb->rows[2]->prev_hash,
	$secret
);
$broken_link = $logger->verify_chain();
assert_test(
	'HitLogger::verify_chain rejects broken prev_hash linkage even when row_hash matches the fake prev_hash',
	false === $broken_link['valid'] && 2 === $broken_link['broken_at_id']
);
$wpdb->rows[2]->prev_hash = $orig_prev_2;
$wpdb->rows[2]->row_hash  = $orig_hash_2;

// Test purge_expired aborts if record() fails (insert_fail = true) BEFORE touching user_agent_excerpt
$wpdb->insert_fail = true;
$aborted_purge     = $logger->purge_expired( false, 30 );
$wpdb->insert_fail = false;
$chain_after_abort = $logger->verify_chain();
assert_test(
	'purge_expired aborts without mutating rows when retention audit record insert fails',
	0 === $aborted_purge['pseudonymized']
		&& 'Bot/0' === $wpdb->rows[1]->user_agent_excerpt
		&& true === $chain_after_abort['valid']
);

// Multi-batch purge (>200 rows -> 250 expired rows) + expired transient cleanup
$mock_transients = [
	'crawler_labyrinth_q_expired1'           => [ 'until' => 100 ],
	'__timeout_crawler_labyrinth_q_expired1' => time() - 600,
	'crawler_labyrinth_q_live1'              => [ 'until' => time() + 3600 ],
	'__timeout_crawler_labyrinth_q_live1'    => time() + 3600,
];
$multi_purge = $logger->purge_expired( false, 30 );
$post_purge  = $logger->verify_chain();
assert_test(
	'purge_expired processes >200 rows across batches, deletes expired transients, and preserves HMAC chain',
	250 === $multi_purge['pseudonymized']
		&& HitLogger::REDACTED_MARKER === $wpdb->rows[1]->user_agent_excerpt
		&& HitLogger::REDACTED_MARKER === $wpdb->rows[250]->user_agent_excerpt
		&& 'Fresh/0' === $wpdb->rows[251]->user_agent_excerpt
		&& ! isset( $mock_transients['crawler_labyrinth_q_expired1'] )
		&& isset( $mock_transients['crawler_labyrinth_q_live1'] )
		&& true === $post_purge['valid']
);

// Group 11: AdminDashboard security guards, XSS escaping, non-telemetry chain skip & WP-CLI --no-dry-run
echo "\nGroup 11: AdminDashboard guards, XSS escaping, REST route metadata & WP-CLI purge\n";
$mock_options['crawler_labyrinth_enabled']      = '1';
$mock_options['crawler_labyrinth_route_prefix'] = 'crawler-trap';
$_POST = [
	'crawler_labyrinth_save_nonce'   => 'bad-nonce',
	'crawler_labyrinth_form'         => 'settings',
	'crawler_labyrinth_route_prefix' => 'hacked-prefix',
];
try {
	AdminDashboard::instance()->save_settings();
} catch ( Crawler_Labyrinth_Test_Redirect $e ) {
	unset( $e );
}
assert_test(
	'save_settings rejects invalid nonce without changing options',
	'1' === $mock_options['crawler_labyrinth_enabled']
		&& 'crawler-trap' === $mock_options['crawler_labyrinth_route_prefix']
);
$mock_user_caps['manage_options'] = false;
$_POST['crawler_labyrinth_save_nonce'] = 'valid-nonce-crawler_labyrinth_save_action';
try {
	AdminDashboard::instance()->save_settings();
} catch ( Crawler_Labyrinth_Test_Redirect $e ) {
	unset( $e );
}
$mock_user_caps['manage_options'] = true;
assert_test(
	'save_settings rejects callers lacking manage_options capability',
	'1' === $mock_options['crawler_labyrinth_enabled']
		&& 'crawler-trap' === $mock_options['crawler_labyrinth_route_prefix']
);
$_POST = [];

// Render dashboard telemetry with XSS payload and settings tab without unpaginated chain verify
$wpdb->rows = [
	(object) [
		'id'                 => 1,
		'created_at'         => $new_date,
		'action_taken'       => 'decoy_served',
		'ip_digest'          => str_repeat( 'a', 64 ),
		'route_token'        => '1-test',
		'user_agent_excerpt' => '<script>alert(1)</script>',
	],
];
$_GET = [ 'tab' => 'telemetry', 'updated' => 'purged' ];
ob_start();
AdminDashboard::instance()->render_dashboard();
$telemetry_html = (string) ob_get_clean();
$wpdb->chain_select_queries = 0;
$_GET = [ 'tab' => 'settings', 'updated' => 'settings', 'prefix_fallback' => '1' ];
ob_start();
AdminDashboard::instance()->render_dashboard();
$settings_html = (string) ob_get_clean();
$_GET = [];
assert_test(
	'AdminDashboard escapes user_agent_excerpt XSS, uses unique form nonce IDs, adds table ARIA region, and skips full verify_chain on settings tab',
	str_contains( $telemetry_html, '&lt;script&gt;alert(1)&lt;/script&gt;' )
		&& ! str_contains( $telemetry_html, '<script>alert(1)</script>' )
		&& str_contains( $telemetry_html, 'name="crawler_labyrinth_purge_nonce"' )
		&& str_contains( $telemetry_html, 'name="crawler_labyrinth_lifecycle_nonce"' )
		&& ! str_contains( $telemetry_html, 'id="crawler_labyrinth_save_nonce"' )
		&& str_contains( $telemetry_html, 'role="region" aria-labelledby="cl-telemetry-heading" tabindex="0"' )
		&& str_contains( $telemetry_html, '<table class="widefat striped" aria-labelledby="cl-telemetry-heading">' )
		&& 0 === $wpdb->chain_select_queries
		&& str_contains( $settings_html, 'reverted to' )
);

// REST route permission_callback + strict argument validators & response_format fallback
$GLOBALS['mock_rest_routes'] = [];
$rest->register_routes();
$all_routes_admin_gated = true;
foreach ( [ 'crawler-labyrinth/v1/status', 'crawler-labyrinth/v1/preview', 'crawler-labyrinth/v1/verify-chain' ] as $rname ) {
	$cb = $GLOBALS['mock_rest_routes'][ $rname ]['permission_callback'] ?? null;
	if ( ! is_array( $cb ) || 'check_admin_permission' !== ( $cb[1] ?? '' ) ) {
		$all_routes_admin_gated = false;
	}
}
$preview_args = $GLOBALS['mock_rest_routes']['crawler-labyrinth/v1/preview']['args'];
$mock_options['crawler_labyrinth_response_format'] = 'plain';
$fallback_preview = $rest->handle_preview( new WP_REST_Request( [] ) );
assert_test(
	'REST routes gate manage_options strictly, reject float/scientific depth and newline seed, and respect response_format fallback',
	$all_routes_admin_gated
		&& ! array_key_exists( 'default', $preview_args['format'] )
		&& true === ( $preview_args['depth']['validate_callback'] )( '12' )
		&& false === ( $preview_args['depth']['validate_callback'] )( '1.9' )
		&& false === ( $preview_args['depth']['validate_callback'] )( '1e1' )
		&& false === ( $preview_args['seed']['validate_callback'] )( "arch123\n" )
		&& 'text/plain; charset=utf-8' === $fallback_preview->get_data()['data']['content_type']
);

// WP-CLI purge_expired --no-dry-run (`['dry-run' => false]`) runs real purge
$wpdb->rows      = [];
$wpdb->insert_id = 0;
$logger->record( $d1, $d2, 'CliOldBot/1', '1-cli', 'decoy_served', '{}', $batch_old );
$cli_cmd = new LabyrinthCommand();
$cli_cmd->purge_expired( [], [ 'dry-run' => false ] );
assert_test(
	'LabyrinthCommand::purge_expired honors --no-dry-run (dry-run => false)',
	HitLogger::REDACTED_MARKER === $wpdb->rows[1]->user_agent_excerpt
);

// Single-site & Multisite uninstall opt-in test
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	define( 'WP_UNINSTALL_PLUGIN', true );
}
$mock_is_multisite   = false;
$mock_current_blog_id = 1;
$mock_options        = [
	'crawler_labyrinth_enabled'                  => '1',
	'crawler_labyrinth_route_prefix'             => 'crawler-trap',
	'crawler_labyrinth_delete_data_on_uninstall' => '0',
	'crawler_labyrinth_hmac_secret'              => $secret,
];
$wpdb->table_created  = true;
$wpdb->table_dropped  = false;
$wpdb->tables_dropped = [];
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

// Multisite cleanup across subsite Blog 2
$mock_is_multisite     = true;
$mock_site_options     = [];
$wpdb->tables_dropped  = [];
$mock_options          = [ 'crawler_labyrinth_delete_data_on_uninstall' => '0', 'crawler_labyrinth_enabled' => '1' ];
$mock_blog_options[2]  = [ 'crawler_labyrinth_delete_data_on_uninstall' => '1', 'crawler_labyrinth_enabled' => '1' ];
require ABSPATH . 'uninstall.php';
$mock_is_multisite     = false;
assert_test(
	'uninstall.php iterates Multisite blogs and cleans opted-in subsites while sparing non-opted blogs',
	'1' === ( $mock_options['crawler_labyrinth_enabled'] ?? '' )
		&& [] === ( $mock_blog_options[2] ?? [] )
		&& true === ( $wpdb->tables_dropped['wp_2_crawler_labyrinth_hits'] ?? false )
		&& ! isset( $wpdb->tables_dropped['wp_crawler_labyrinth_hits'] )
);

// Group 12: Round 2 parity, cache-race, UTF-8 truncation, fallback routing & all extra mutant guards
echo "\nGroup 12: Cache race, UTF-8 truncation, unknown tab clamp, and strict invariant pins\n";

// 1. TokenSigner::ensure_secret notoptions cache race + client_ip ignores XFF + verify depth bounds + uppercase seed & mapped IPv6 parity
unset( $mock_options[ TokenSigner::SECRET_OPTION ] );
$mock_notoptions_poisoned   = true;
$mock_simulate_race_secret  = str_repeat( 'e', 64 );
$resolved_race_secret       = TokenSigner::ensure_secret();
$mock_notoptions_poisoned   = false;
$mock_simulate_race_secret  = '';
$mock_options[ TokenSigner::SECRET_OPTION ] = $secret;
$_SERVER['REMOTE_ADDR']          = '192.0.2.10';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.99';
$client_ip_out                   = TokenSigner::client_ip();
unset( $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_X_FORWARDED_FOR'] );
$depth17_mac = substr( hash_hmac( 'sha256', '17|seedalpha1', $secret ), 0, 16 );
$tok_depth17 = '17-seedalpha1-' . $depth17_mac;
assert_test(
	'TokenSigner clears notoptions cache on add_option race, ignores X-Forwarded-For, bounds depth 1..16, and aligns uppercase seed & mapped IPv6 parity',
	str_repeat( 'e', 64 ) === $resolved_race_secret
		&& '192.0.2.10' === $client_ip_out
		&& null === TokenSigner::verify( $tok_depth17, $secret )
		&& str_starts_with( TokenSigner::sign( 2, 'SeedAlpha9', $secret ), '2-seedalpha9-' )
		&& 'seedalpha9' === $gen->generate( 2, 'SeedAlpha9', 'html', 'crawler-trap', 4, 2, $secret )['seed']
		&& '198.51.100.7' === TokenSigner::normalize_ip( '0:0:0:0:0:ffff:198.51.100.7' )
		&& '198.51.100.7' === TokenSigner::normalize_ip( '::ffff:c633:6407' )
		&& 'fe80::1' === TokenSigner::normalize_ip( '[fe80::1%eth0]:443' )
		&& 'crawler-trap' === Settings::sanitize_route_prefix( str_repeat( 'a', 33 ) )
		&& false === ( $preview_args['depth']['validate_callback'] )( "12\n" )
);

// 2. RobotsInjector places Disallow above trailing # comments before blank lines and preserves RFC 9309 path case
$robots_comment_blank = RobotsInjector::inject( "User-agent: Foo\nAllow: /\n# trailing note\n\nUser-agent: *\nAllow: /\n", 'crawler-trap' );
$robots_mixed_case    = RobotsInjector::inject( "User-agent: *\nDisallow: /My-Trap/\n", 'my-trap' );
$robots_case_field    = RobotsInjector::inject( "User-agent: Foo\nAllow: /\nDISALLOW:/crawler-trap/\n", 'crawler-trap' );
assert_test(
	'RobotsInjector inserts Disallow above trailing # comments and treats path matching case-sensitively per RFC 9309',
	str_contains( $robots_comment_blank, "Allow: /\nDisallow: /crawler-trap/\n# trailing note\n" )
		&& str_contains( $robots_mixed_case, "Disallow: /My-Trap/\nDisallow: /my-trap/\n" )
		&& ! str_contains( $robots_case_field, 'Disallow: /crawler-trap/' ),
	$robots_mixed_case
);

// 3. TrapRouter kill switch, active_quarantine_max persistence, and d >= 2..7 lookback rollover
$mock_transients = [];
$mock_options['crawler_labyrinth_enabled']               = '0';
$mock_options['crawler_labyrinth_active_quarantine_max'] = 0;
$disabled_hit = $router->handle_request( '/crawler-trap/1-seedalpha1-0000/', 'GPTBot/1.2', '198.51.100.12', false );
$mock_options['crawler_labyrinth_enabled']               = '1';
$mock_options['crawler_labyrinth_mode']                  = 'block';
$mock_options['crawler_labyrinth_block_ttl_minutes']     = 10080;
$mock_options['crawler_labyrinth_block_status']          = 403;
$block_max_hit = $router->handle_request( '/crawler-trap/1-seedalpha1-0000/', 'BadBot/1.0', '198.51.100.13', false );
$d3_digest     = TokenSigner::ip_digest( '198.51.100.14', gmdate( 'Y-m-d', time() - ( 3 * 86400 ) ), $secret );
$mock_transients[ 'crawler_labyrinth_q_' . substr( $d3_digest, 0, 24 ) ] = [ 'until' => time() + 3600, 'strikes' => 4 ];
$d3_hit        = $router->handle_request( '/ordinary/', 'Rollover3/1.0', '198.51.100.14', false );
assert_test(
	'TrapRouter respects enabled kill switch, persists active_quarantine_max, and checks d=3 lookback within 7-day TTL',
	false === $disabled_hit['handled']
		&& true === $block_max_hit['handled']
		&& (int) ( $mock_options['crawler_labyrinth_active_quarantine_max'] ?? 0 ) > time()
		&& true === $d3_hit['handled']
		&& 403 === $d3_hit['status']
);

// 4. HitLogger REDACTED_MARKER spoof guard, UTF-8 mb_substr truncation, and purge_expired skipping tampered rows
$wpdb->rows      = [];
$wpdb->insert_id = 0;
$spoof_id        = $logger->record( $d1, $d2, HitLogger::REDACTED_MARKER, '1-spoof', 'decoy_served', '{}', $batch_old );
$spoof_before    = $wpdb->rows[ $spoof_id ]->user_agent_excerpt;
$utf8_ua         = str_repeat( 'a', 190 ) . '🦊trailing';
$utf8_id         = $logger->record( $d1, $d2, $utf8_ua, '1-utf8', 'decoy_served', '{}', $new_date );
$tamper_old_id   = $logger->record( $d1, $d2, 'TamperBot/1', '1-tamper', 'decoy_served', '{}', $batch_old );
$wpdb->rows[ $tamper_old_id ]->route_token = 'tampered-before-purge';
$tamper_skip_res = $logger->purge_expired( false, 30 );
$utf8_chain      = $logger->verify_chain();
assert_test(
	'HitLogger filters literal [REDACTED] UA spoofs, truncates UTF-8 cleanly via mb_substr, and skips tampered rows during purge_expired',
	'(filtered)' === $spoof_before
		&& mb_check_encoding( $wpdb->rows[ $utf8_id ]->user_agent_excerpt, 'UTF-8' )
		&& 191 === mb_strlen( $wpdb->rows[ $utf8_id ]->user_agent_excerpt, 'UTF-8' )
		&& HitLogger::REDACTED_MARKER === $wpdb->rows[ $spoof_id ]->user_agent_excerpt
		&& 'TamperBot/1' === $wpdb->rows[ $tamper_old_id ]->user_agent_excerpt
		&& false === $utf8_chain['valid']
		&& $tamper_old_id === $utf8_chain['broken_at_id']
);

// 5. AdminDashboard purge_now form POST + ?tab=unknown_tab fallback renders chain badge & rows
$wpdb->rows      = [];
$wpdb->insert_id = 0;
$logger->record( $d1, $d2, 'PurgePostBot/1', '1-ppost', 'decoy_served', '{}', $batch_old );
$_POST = [
	'crawler_labyrinth_purge_nonce' => 'valid-nonce-crawler_labyrinth_save_action',
	'crawler_labyrinth_form'        => 'purge_now',
];
try {
	AdminDashboard::instance()->save_settings();
} catch ( Crawler_Labyrinth_Test_Redirect $e ) {
	unset( $e );
}
$_POST = [];
$_GET  = [ 'tab' => 'unknown_tab_name' ];
ob_start();
AdminDashboard::instance()->render_dashboard();
$unknown_tab_html = (string) ob_get_clean();
$_GET             = [];
assert_test(
	'AdminDashboard purge_now executes retention and ?tab=unknown_tab renders Telemetry chain badge & rows cleanly',
	HitLogger::REDACTED_MARKER === $wpdb->rows[1]->user_agent_excerpt
		&& str_contains( $unknown_tab_html, 'HMAC CHAIN VALID' )
		&& str_contains( $unknown_tab_html, '1-ppost' )
		&& str_contains( $unknown_tab_html, '<hr class="wp-header-end" aria-hidden="true" />' )
);

// Group 13: v1.1.0 Early init hook, observe mode, burst circuit breaker, entry_vector & X-Crawler-Labyrinth headers
echo "\nGroup 13: v1.1.0 Pre-query init hook, observe dry-run, burst shedding, and entry_vector telemetry\n";

$mock_actions = [];
$router->boot();
assert_test(
	'TrapRouter::boot registers early init hook instead of template_redirect',
	! empty( $mock_actions['init'] ) && empty( $mock_actions['template_redirect'] )
);

assert_test(
	'Settings supports observe mode and clamps burst_limit bounds',
	'observe' === Settings::sanitize_mode( 'observe' )
		&& 0 === Settings::sanitize_burst_limit( 0 )
		&& 0 === Settings::sanitize_burst_limit( -10 )
		&& 5 === Settings::sanitize_burst_limit( 2 )
		&& 10000 === Settings::sanitize_burst_limit( 25000 )
		&& 120 === Settings::sanitize_burst_limit( 'abc' )
);

$wpdb->rows                            = [];
$wpdb->insert_id                       = 0;
$mock_transients                       = [];
$mock_options['crawler_labyrinth_enabled']               = '1';
$mock_options['crawler_labyrinth_route_prefix']          = 'crawler-trap';
$mock_options['crawler_labyrinth_mode']                  = 'observe';
$mock_options['crawler_labyrinth_strike_threshold']      = 2;
$mock_options['crawler_labyrinth_intercept_training_ua'] = '1';
$mock_options['crawler_labyrinth_burst_limit']           = 120;
$signed_bait_token                                       = TokenSigner::sign( 1, 'archobs0001', $secret );
$obs_bait                                                = $router->handle_request( '/crawler-trap/' . $signed_bait_token . '/', 'ObsBot/1.0', '198.51.100.40', false );
$obs_probe                                               = $router->handle_request( '/crawler-trap/unsigned-probe/', 'ObsBot/1.0', '198.51.100.40', false );
$obs_ua                                                  = $router->handle_request( '/ordinary-post/', 'GPTBot/1.2', '198.51.100.40', false );
$obs_over_threshold                                      = $router->handle_request( '/crawler-trap/' . $signed_bait_token . '/', 'ObsBot/1.0', '198.51.100.40', false );
$obs_stats                                               = $logger->get_stats();
$obs_chain                                               = $logger->verify_chain();
$meta1                                                   = json_decode( (string) ( $wpdb->rows[1]->meta_json ?? '{}' ), true );
$meta2                                                   = json_decode( (string) ( $wpdb->rows[2]->meta_json ?? '{}' ), true );
$meta3                                                   = json_decode( (string) ( $wpdb->rows[3]->meta_json ?? '{}' ), true );
assert_test(
	'observe mode logs observed hits past strike_threshold without quarantining, tags entry_vector, and sets X-Crawler-Labyrinth: observed',
	200 === $obs_bait['status']
		&& 200 === $obs_over_threshold['status']
		&& 'observed' === $obs_over_threshold['action']
		&& 'observed' === ( $obs_over_threshold['headers']['X-Crawler-Labyrinth'] ?? '' )
		&& 4 === (int) $obs_stats['observed']
		&& 0 === (int) $obs_stats['quarantined']
		&& true === $obs_chain['valid']
		&& 'signed_bait' === ( $meta1['entry_vector'] ?? '' )
		&& 'robots_probe' === ( $meta2['entry_vector'] ?? '' )
		&& 'ua_guard' === ( $meta3['entry_vector'] ?? '' ),
	var_export( [ $obs_over_threshold['status'], $obs_stats, $meta1, $meta2, $meta3 ], true )
);

$mock_options['crawler_labyrinth_mode'] = 'hybrid';
$decoy_hdr_res = $router->handle_request( '/crawler-trap/' . $signed_bait_token . '/', 'DecoyBot/1.0', '198.51.100.41', false );
$mock_options['crawler_labyrinth_mode'] = 'block';
$block_hdr_res = $router->handle_request( '/crawler-trap/' . $signed_bait_token . '/', 'BlockBot/1.0', '198.51.100.42', false );
assert_test(
	'TrapRouter emits X-Crawler-Labyrinth decoy and quarantined headers on standard responses',
	'decoy' === ( $decoy_hdr_res['headers']['X-Crawler-Labyrinth'] ?? '' )
		&& 'quarantined' === ( $block_hdr_res['headers']['X-Crawler-Labyrinth'] ?? '' )
);

// Burst circuit breaker sheds hit #6 cleanly without DB inserts or per-IP transient inflation
$wpdb->rows                        = [];
$wpdb->insert_id                   = 0;
$mock_transients                   = [];
$mock_options['crawler_labyrinth_mode']        = 'labyrinth';
$mock_options['crawler_labyrinth_burst_limit'] = 5;
for ( $bi = 1; $bi <= 5; $bi++ ) {
	$router->handle_request( '/crawler-trap/' . $signed_bait_token . '/', 'BurstBot/' . $bi, '198.51.100.' . ( 50 + $bi ), false );
}
$rows_before_shed = count( $wpdb->rows );
$shed_res         = $router->handle_request( '/crawler-trap/' . $signed_bait_token . '/', 'BurstBot/6', '198.51.100.56', false );
$rows_after_shed  = count( $wpdb->rows );
$mock_transients  = [];
$mock_options['crawler_labyrinth_burst_limit'] = 120;
assert_test(
	'Global burst circuit breaker sheds excess trap requests with 429 burst-shed and zero MySQL inserts',
	5 === $rows_before_shed
		&& 5 === $rows_after_shed
		&& true === $shed_res['handled']
		&& 429 === $shed_res['status']
		&& 'burst_shed' === $shed_res['action']
		&& '60' === ( $shed_res['headers']['Retry-After'] ?? '' )
		&& 'burst-shed' === ( $shed_res['headers']['X-Crawler-Labyrinth'] ?? '' )
		&& ! isset( $mock_transients[ 'crawler_labyrinth_s_' . substr( TokenSigner::ip_digest( '198.51.100.56', gmdate( 'Y-m-d' ), $secret ), 0, 24 ) ] )
);

echo "\n==============================================================================\n";
echo "  TEST SUITE RESULTS: {$passed_count} PASSED | {$failed_count} FAILED\n";
echo "==============================================================================\n\n";

exit( 0 === $failed_count ? 0 : 1 );
