<?php
/**
 * WordPress runtime verification suite for Crawler Labyrinth.
 *
 * Usage inside an active WordPress environment:
 *   wp eval-file wp-content/plugins/crawler-labyrinth/tests/test-runtime.php --allow-root --user=1
 *
 * Cleans up every temporary user, option override, and inserted audit row on completion.
 *
 * @package CrawlerLabyrinth
 */

defined( 'ABSPATH' ) || exit;

use CrawlerLabyrinth\Audit\HitLogger;
use CrawlerLabyrinth\Core\Activator;
use CrawlerLabyrinth\Core\Plugin;
use CrawlerLabyrinth\Security\TokenSigner;

$GLOBALS['cl_rt'] = [ 'pass' => 0, 'fail' => 0 ];

function cl_rt_check( string $label, bool $ok, string $detail = '' ): void {
	$GLOBALS['cl_rt'][ $ok ? 'pass' : 'fail' ]++;
	WP_CLI::log( ( $ok ? '[PASS] ' : '[FAIL] ' ) . $label . ( $ok || '' === $detail ? '' : ': ' . $detail ) );
}

if ( ! class_exists( Plugin::class ) ) {
	WP_CLI::error( 'crawler-labyrinth is not active or loaded.' );
}

$original_user_id = get_current_user_id();
if ( ! current_user_can( 'manage_options' ) ) {
	$admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
	if ( empty( $admins ) ) {
		WP_CLI::error( 'No administrator user found.' );
	}
	wp_set_current_user( (int) $admins[0] );
}

$admin_id      = get_current_user_id();
$editor_id     = 0;
$inserted_ids  = [];
$orig_options  = [];
$watch_options = [ 'crawler_labyrinth_enabled', 'crawler_labyrinth_update_robots', 'crawler_labyrinth_route_prefix' ];
foreach ( $watch_options as $opt ) {
	$orig_options[ $opt ] = get_option( $opt, '__ABSENT__' );
}

try {
	// 1. Table & plugins_loaded cron schedule
	cl_rt_check( 'Custom audit table exists', HitLogger::table_exists() );
	$next_cron = wp_next_scheduled( Activator::CRON_HOOK );
	cl_rt_check( 'Daily retention cron crawler_labyrinth_daily_purge is scheduled', false !== $next_cron && $next_cron > 0, (string) $next_cron );

	// 2. robots_txt filter injects into every group
	update_option( 'crawler_labyrinth_enabled', '1' );
	update_option( 'crawler_labyrinth_update_robots', '1' );
	update_option( 'crawler_labyrinth_route_prefix', 'crawler-trap' );
	$robots_sample = "User-agent: OAI-SearchBot\nAllow: /\n\nUser-agent: *\nAllow: /\n";
	$robots_out    = apply_filters( 'robots_txt', $robots_sample, true );
	cl_rt_check(
		'robots_txt filter injects Disallow: /crawler-trap/ across both OAI-SearchBot and * groups',
		2 === substr_count( (string) $robots_out, 'Disallow: /crawler-trap/' ),
		(string) $robots_out
	);

	// 3. REST auth & validation (editor role must receive 403)
	$editor_login = 'cl_rt_editor_' . wp_generate_password( 6, false );
	$created_id   = wp_create_user( $editor_login, wp_generate_password( 16, true ), $editor_login . '@example.invalid' );
	if ( ! is_wp_error( $created_id ) ) {
		$editor_id = (int) $created_id;
		$user_obj  = new WP_User( $editor_id );
		$user_obj->set_role( 'editor' );
		wp_set_current_user( $editor_id );

		$unauth_req = new WP_REST_Request( 'POST', '/crawler-labyrinth/v1/preview' );
		$unauth_req->set_param( 'depth', 1 );
		$unauth_req->set_param( 'seed', 'archpreview1' );
		$unauth_req->set_param( 'format', 'plain' );
		$unauth_res = rest_do_request( $unauth_req );
		cl_rt_check(
			'REST /preview refuses editor role with 403 on valid body',
			403 === $unauth_res->get_status(),
			'status=' . $unauth_res->get_status()
		);
	} else {
		cl_rt_check( 'Create temporary editor user for REST auth probe', false, $created_id->get_error_message() );
	}

	wp_set_current_user( $admin_id );
	$bad_req = new WP_REST_Request( 'POST', '/crawler-labyrinth/v1/preview' );
	$bad_req->set_param( 'seed', '!!bad!!' );
	$bad_res = rest_do_request( $bad_req );
	cl_rt_check( 'REST /preview refuses invalid seed with 400', 400 === $bad_res->get_status(), 'status=' . $bad_res->get_status() );

	$good_req = new WP_REST_Request( 'POST', '/crawler-labyrinth/v1/preview' );
	$good_req->set_param( 'depth', 2 );
	$good_req->set_param( 'seed', 'runtime99' );
	$good_req->set_param( 'format', 'plain' );
	$good_res  = rest_do_request( $good_req );
	$good_data = $good_res->get_data();
	cl_rt_check(
		'REST /preview returns 200 and signed child tokens for administrator',
		200 === $good_res->get_status() && true === ( $good_data['success'] ?? false ) && ! empty( $good_data['data']['child_tokens'] ),
		(string) wp_json_encode( $good_data )
	);

	// 4. HitLogger insert + verify_chain + clean deletion by ID
	$logger = new HitLogger();
	$secret = TokenSigner::ensure_secret();
	$row_id = $logger->record(
		TokenSigner::ip_digest( '198.51.100.99', null, $secret ),
		TokenSigner::ua_digest( 'RuntimeProbe/1.0', $secret ),
		'RuntimeProbe/1.0',
		'1-runtime01',
		'decoy_served'
	);
	if ( $row_id > 0 ) {
		$inserted_ids[] = $row_id;
	}
	$chain = $logger->verify_chain();
	cl_rt_check( 'HitLogger records row in database and verify_chain passes', $row_id > 0 && true === $chain['valid'], (string) wp_json_encode( $chain ) );

	// 5. WP-CLI subcommands
	$cli_status = WP_CLI::runcommand( 'crawler-labyrinth status', [ 'return' => 'all', 'exit_error' => false, 'launch' => false ] );
	$cli_verify = WP_CLI::runcommand( 'crawler-labyrinth verify_chain', [ 'return' => 'all', 'exit_error' => false, 'launch' => false ] );
	$cli_purge  = WP_CLI::runcommand( 'crawler-labyrinth purge_expired --dry-run', [ 'return' => 'all', 'exit_error' => false, 'launch' => false ] );
	cl_rt_check(
		'WP-CLI status, verify_chain, and purge_expired --dry-run exit 0',
		0 === (int) $cli_status->return_code && 0 === (int) $cli_verify->return_code && 0 === (int) $cli_purge->return_code,
		(string) wp_json_encode( [ $cli_status->return_code, $cli_verify->return_code, $cli_purge->return_code ] )
	);
} finally {
	global $wpdb;
	foreach ( $inserted_ids as $del_id ) {
		$wpdb->delete( HitLogger::table_name(), [ 'id' => (int) $del_id ], [ '%d' ] );
	}
	if ( $editor_id > 0 ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $editor_id );
	}
	foreach ( $orig_options as $opt => $val ) {
		if ( '__ABSENT__' === $val ) {
			delete_option( $opt );
		} else {
			update_option( $opt, $val );
		}
	}
	wp_set_current_user( $original_user_id );
}

$p = $GLOBALS['cl_rt']['pass'];
$f = $GLOBALS['cl_rt']['fail'];
WP_CLI::log( sprintf( 'RESULT: %d passed, %d failed', $p, $f ) );
if ( $f > 0 ) {
	WP_CLI::error( 'Runtime suite failed.' );
}
WP_CLI::log( 'VERDICT: PASS' );
