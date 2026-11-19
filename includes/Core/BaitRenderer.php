<?php
/**
 * Accessible-inert footer bait link renderer.
 *
 * @package CrawlerLabyrinth
 */

declare(strict_types=1);

namespace CrawlerLabyrinth\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use CrawlerLabyrinth\Security\TokenSigner;

final class BaitRenderer {

	public function boot(): void {
		add_action( 'wp_footer', [ $this, 'render_footer_bait' ], 99 );
	}

	/**
	 * Output hidden crawler trap links on public frontend HTML responses only.
	 */
	public function render_footer_bait(): void {
		if ( ! self::should_render_bait() ) {
			return;
		}

		$markup = self::build_markup();
		if ( '' === $markup ) {
			return;
		}

		echo wp_kses( $markup, self::allowed_html() ) . "\n";
	}

	public static function should_render_bait(): bool {
		$settings = Settings::all();
		if ( ! $settings['enabled'] ) {
			return false;
		}
		if ( function_exists( 'is_admin' ) && is_admin() ) {
			return false;
		}
		if ( function_exists( 'is_feed' ) && is_feed() ) {
			return false;
		}
		if ( function_exists( 'is_preview' ) && is_preview() ) {
			return false;
		}
		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
			return false;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return false;
		}
		if ( function_exists( 'is_user_logged_in' ) && is_user_logged_in() ) {
			return false;
		}
		return true;
	}

	/**
	 * Build deterministic hidden bait HTML using native `hidden` + `aria-hidden` + `tabindex="-1"`
	 * so screen readers and keyboard users never reach or focus the trap.
	 */
	public static function build_markup( ?string $seed_base = null ): string {
		$settings = Settings::all();
		$prefix   = (string) $settings['route_prefix'];
		$count    = (int) $settings['link_count'];
		$secret   = TokenSigner::ensure_secret();
		$day_key  = gmdate( 'Y-m-d' );
		$context  = $seed_base ?? ( isset( $_SERVER['REQUEST_URI'] ) && is_scalar( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '/' );

		$links = [];
		for ( $i = 0; $i < $count; $i++ ) {
			$seed_hash = hash_hmac( 'sha256', "bait|{$day_key}|{$context}|{$i}", $secret );
			$token     = TokenSigner::sign( 1, substr( $seed_hash, 0, 12 ), $secret );
			$url       = function_exists( 'home_url' )
				? home_url( '/' . $prefix . '/' . $token . '/' )
				: '/' . $prefix . '/' . $token . '/';
			$label     = sprintf( 'Archival Reference Index %d', $i + 1 );
			$links[]   = '<a href="' . esc_url( $url ) . '" rel="nofollow noopener" tabindex="-1" aria-hidden="true">' . esc_html( $label ) . '</a>';
		}

		$html = '<div hidden aria-hidden="true" data-crawler-labyrinth="1">' . implode( ' ', $links ) . '</div>';

		if ( function_exists( 'apply_filters' ) ) {
			$html = (string) apply_filters( 'crawler_labyrinth_bait_html', $html, $prefix, $count );
		}

		return $html;
	}

	/**
	 * Explicit lowercase wp_kses allowlist for the bait wrapper.
	 *
	 * @return array<string, array<string, bool>>
	 */
	public static function allowed_html(): array {
		return [
			'div' => [
				'hidden'                 => true,
				'aria-hidden'            => true,
				'data-crawler-labyrinth' => true,
			],
			'a'   => [
				'href'        => true,
				'rel'         => true,
				'tabindex'    => true,
				'aria-hidden' => true,
			],
		];
	}
}
