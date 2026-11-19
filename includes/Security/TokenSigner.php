<?php
/**
 * HMAC-SHA256 trap token signing, constant-time verification, and daily IP digests.
 *
 * @package CrawlerLabyrinth
 */

declare(strict_types=1);

namespace CrawlerLabyrinth\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TokenSigner {

	public const SECRET_OPTION = 'crawler_labyrinth_hmac_secret';

	/**
	 * Return or initialize the plugin's persistent 256-bit HMAC secret.
	 *
	 * Unlike wp_salt(), this secret survives database migrations between environments
	 * without invalidating historical audit chains.
	 */
	public static function ensure_secret(): string {
		$stored = get_option( self::SECRET_OPTION, '' );
		if ( is_string( $stored ) && 1 === preg_match( '/^[a-f0-9]{64}$/', $stored ) ) {
			return $stored;
		}

		$generated = bin2hex( random_bytes( 32 ) );
		if ( function_exists( 'add_option' ) ) {
			$added = add_option( self::SECRET_OPTION, $generated, '', true );
			if ( ! $added ) {
				$reread = get_option( self::SECRET_OPTION, '' );
				if ( is_string( $reread ) && 1 === preg_match( '/^[a-f0-9]{64}$/', $reread ) ) {
					return $reread;
				}
				update_option( self::SECRET_OPTION, $generated, true );
			}
			return $generated;
		}

		update_option( self::SECRET_OPTION, $generated, true );
		return $generated;
	}

	/**
	 * Sign a trap navigation token: `<depth>-<seed>-<hmac16>`.
	 */
	public static function sign( int $depth, string $seed, ?string $secret = null ): string {
		$clamped_depth = max( 1, min( 16, $depth ) );
		$clean_seed    = strtolower( (string) preg_replace( '/[^a-z0-9]/', '', $seed ) );
		if ( '' === $clean_seed ) {
			$clean_seed = 'archroot0001';
		} elseif ( strlen( $clean_seed ) < 4 ) {
			$clean_seed = substr( $clean_seed . 'arch0001', 0, 12 );
		}
		$clean_seed = substr( $clean_seed, 0, 24 );

		$key = $secret ?? self::ensure_secret();
		$mac = substr( hash_hmac( 'sha256', $clamped_depth . '|' . $clean_seed, $key ), 0, 16 );
		return $clamped_depth . '-' . $clean_seed . '-' . $mac;
	}

	/**
	 * Verify a trap token in constant time and extract its depth and seed.
	 *
	 * @return array{depth: int, seed: string}|null
	 */
	public static function verify( string $token, ?string $secret = null ): ?array {
		if ( 1 !== preg_match( '/^([1-9][0-9]?)-([a-z0-9]{4,24})-([a-f0-9]{16})$/', $token, $matches ) ) {
			return null;
		}

		$depth = (int) $matches[1];
		if ( $depth < 1 || $depth > 16 ) {
			return null;
		}

		$seed     = $matches[2];
		$actual   = $matches[3];
		$key      = $secret ?? self::ensure_secret();
		$expected = substr( hash_hmac( 'sha256', $depth . '|' . $seed, $key ), 0, 16 );

		if ( ! hash_equals( $expected, $actual ) ) {
			return null;
		}

		return [
			'depth' => $depth,
			'seed'  => $seed,
		];
	}

	/**
	 * Canonicalize client IPv4/IPv6 strings (stripping ports, brackets, IPv4-mapped prefix, uppercase hex).
	 */
	public static function normalize_ip( string $raw_ip ): string {
		$trimmed = trim( $raw_ip );
		if ( '' === $trimmed ) {
			return '0.0.0.0';
		}

		if ( 1 === preg_match( '/^\[([^\]]+)\](?::\d+)?$/', $trimmed, $m ) ) {
			$trimmed = $m[1];
		} elseif ( 1 === preg_match( '/^(\d{1,3}(?:\.\d{1,3}){3}):\d+$/', $trimmed, $m ) ) {
			$trimmed = $m[1];
		}

		$lower = strtolower( $trimmed );
		if ( str_starts_with( $lower, '::ffff:' ) ) {
			$mapped = substr( $lower, 7 );
			if ( 1 === preg_match( '/^\d{1,3}(?:\.\d{1,3}){3}$/', $mapped ) ) {
				return $mapped;
			}
		}

		if ( str_contains( $lower, ':' ) && false !== filter_var( $lower, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			$packed = inet_pton( $lower );
			if ( false !== $packed ) {
				$canonical = inet_ntop( $packed );
				if ( is_string( $canonical ) && '' !== $canonical ) {
					return $canonical;
				}
			}
			return $lower;
		}

		return $trimmed;
	}

	/**
	 * Compute a keyed daily digest of a visitor IP address.
	 * Raw IP addresses are never written to options, transients, or database tables.
	 */
	public static function ip_digest( string $raw_ip, ?string $utc_day = null, ?string $secret = null ): string {
		$day = $utc_day ?? gmdate( 'Y-m-d' );
		$ip  = self::normalize_ip( $raw_ip );
		$key = $secret ?? self::ensure_secret();
		return hash_hmac( 'sha256', $ip . '|' . $day, $key );
	}

	/**
	 * Compute a keyed digest of the request User-Agent string.
	 */
	public static function ua_digest( string $user_agent, ?string $secret = null ): string {
		$key = $secret ?? self::ensure_secret();
		return hash_hmac( 'sha256', $user_agent, $key );
	}

	/**
	 * Read and sanitize the client IP from server variables (filterable for trusted proxies).
	 */
	public static function client_ip(): string {
		$raw = isset( $_SERVER['REMOTE_ADDR'] ) && is_scalar( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) )
			: '0.0.0.0';

		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters( 'crawler_labyrinth_client_ip', $raw );
			if ( is_string( $filtered ) && '' !== trim( $filtered ) ) {
				return sanitize_text_field( $filtered );
			}
		}

		return '' !== $raw ? $raw : '0.0.0.0';
	}
}
