<?php
/**
 * WordPress REST API controller (`crawler-labyrinth/v1`).
 *
 * @package CrawlerLabyrinth
 */

declare(strict_types=1);

namespace CrawlerLabyrinth\API;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use CrawlerLabyrinth\Audit\HitLogger;
use CrawlerLabyrinth\Core\Settings;
use CrawlerLabyrinth\Generator\DecoyGenerator;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class RestController {

	private const NAMESPACE = 'crawler-labyrinth/v1';

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/status',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'handle_status' ],
				'permission_callback' => [ $this, 'check_admin_permission' ],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/preview',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'handle_preview' ],
				'permission_callback' => [ $this, 'check_admin_permission' ],
				'args'                => [
					'depth'  => [
						'required'          => false,
						'type'              => 'integer',
						'default'           => 1,
						'validate_callback' => static fn( $val ): bool => is_numeric( $val ) && (int) $val >= 1 && (int) $val <= 16,
						'sanitize_callback' => 'absint',
					],
					'seed'   => [
						'required'          => false,
						'type'              => 'string',
						'default'           => 'archpreview1',
						'validate_callback' => static fn( $val ): bool => is_string( $val ) && 1 === preg_match( '/^[a-z0-9]{4,24}$/i', $val ),
						'sanitize_callback' => 'sanitize_text_field',
					],
					'format' => [
						'required'          => false,
						'type'              => 'string',
						'default'           => 'html',
						'enum'              => [ 'html', 'plain' ],
						'sanitize_callback' => 'sanitize_key',
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/verify-chain',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'handle_verify_chain' ],
				'permission_callback' => [ $this, 'check_admin_permission' ],
			]
		);
	}

	public function check_admin_permission(): bool {
		return function_exists( 'current_user_can' ) && current_user_can( 'manage_options' );
	}

	public function handle_status(): WP_REST_Response {
		$settings = Settings::all();
		$logger   = new HitLogger();

		return new WP_REST_Response(
			[
				'success'  => true,
				'settings' => $settings,
				'stats'    => $logger->get_stats(),
			],
			200
		);
	}

	public function handle_preview( WP_REST_Request $request ): WP_REST_Response {
		$settings = Settings::all();
		$depth    = Settings::clamp_int( $request->get_param( 'depth' ) ?? 1, 1, 16, 1 );
		$seed_raw = $request->get_param( 'seed' );
		$seed     = is_string( $seed_raw ) ? strtolower( sanitize_text_field( $seed_raw ) ) : 'archpreview1';
		$fmt_raw  = $request->get_param( 'format' );
		$format   = is_string( $fmt_raw ) ? Settings::sanitize_format( $fmt_raw ) : (string) $settings['response_format'];

		if ( 1 !== preg_match( '/^[a-z0-9]{4,24}$/', $seed ) ) {
			return new WP_REST_Response(
				[
					'success' => false,
					'error'   => 'Seed must be 4-24 alphanumeric characters.',
				],
				400
			);
		}

		$decoy = ( new DecoyGenerator() )->generate(
			$depth,
			$seed,
			$format,
			(string) $settings['route_prefix'],
			(int) $settings['max_depth'],
			3
		);

		return new WP_REST_Response(
			[
				'success' => true,
				'data'    => $decoy,
			],
			200
		);
	}

	public function handle_verify_chain(): WP_REST_Response {
		$verification = ( new HitLogger() )->verify_chain();

		return new WP_REST_Response(
			[
				'success' => $verification['valid'],
				'data'    => $verification,
			],
			$verification['valid'] ? 200 : 409
		);
	}
}
