<?php
/**
 * Append-only HMAC-SHA256 audit logger with pseudonymizing retention.
 *
 * @package CrawlerLabyrinth
 */

declare(strict_types=1);

namespace CrawlerLabyrinth\Audit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use CrawlerLabyrinth\Core\Settings;
use CrawlerLabyrinth\Security\TokenSigner;

final class HitLogger {

	public const REDACTED_MARKER  = '[REDACTED]';
	public const RETENTION_ACTION = 'retention_pseudonymized';

	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'crawler_labyrinth_hits';
	}

	public static function create_table(): void {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_charset_collate' ) ) {
			return;
		}

		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			created_at DATETIME NOT NULL,
			ip_digest CHAR(64) NOT NULL,
			ua_digest CHAR(64) NOT NULL,
			user_agent_excerpt VARCHAR(191) NOT NULL,
			route_token VARCHAR(128) NOT NULL,
			action_taken VARCHAR(32) NOT NULL,
			meta_json TEXT NOT NULL,
			prev_hash CHAR(64) NOT NULL,
			row_hash CHAR(64) NOT NULL,
			PRIMARY KEY  (id),
			KEY created_at_idx (created_at),
			KEY ip_digest_idx (ip_digest),
			KEY action_taken_idx (action_taken)
		) ENGINE=InnoDB {$charset_collate};";

		if ( ! function_exists( 'dbDelta' ) && defined( 'ABSPATH' ) && file_exists( ABSPATH . 'wp-admin/includes/upgrade.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}
		if ( function_exists( 'dbDelta' ) ) {
			dbDelta( $sql );
		}
	}

	public static function table_exists(): bool {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) ) {
			return false;
		}
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- live table existence check.
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		return $found === $table;
	}

	public static function compute_full_hash(
		string $created_at,
		string $ip_digest,
		string $ua_digest,
		string $user_agent_excerpt,
		string $route_token,
		string $action_taken,
		string $meta_json,
		string $prev_hash,
		string $secret
	): string {
		$payload = implode(
			'|',
			[
				'v1',
				$created_at,
				$ip_digest,
				$ua_digest,
				$user_agent_excerpt,
				$route_token,
				$action_taken,
				$meta_json,
				$prev_hash,
			]
		);
		return hash_hmac( 'sha256', $payload, $secret );
	}

	public static function compute_residual_hash(
		string $created_at,
		string $ip_digest,
		string $ua_digest,
		string $route_token,
		string $action_taken,
		string $meta_json,
		string $prev_hash,
		string $secret
	): string {
		$payload = implode(
			'|',
			[
				'redacted-v1',
				$created_at,
				$ip_digest,
				$ua_digest,
				$route_token,
				$action_taken,
				$meta_json,
				$prev_hash,
			]
		);
		return hash_hmac( 'sha256', $payload, $secret );
	}

	/**
	 * Append an HMAC-chained hit record.
	 */
	public function record(
		string $ip_digest,
		string $ua_digest,
		string $user_agent_excerpt,
		string $route_token,
		string $action_taken,
		string $meta_json = '{}',
		?string $created_at = null
	): int {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'insert' ) ) {
			return 0;
		}

		$table     = self::table_name();
		$secret    = TokenSigner::ensure_secret();
		$timestamp = $created_at ?? gmdate( 'Y-m-d H:i:s' );
		$excerpt   = substr( sanitize_text_field( $user_agent_excerpt ), 0, 191 );
		if ( self::REDACTED_MARKER === $excerpt && self::RETENTION_ACTION !== $action_taken ) {
			$excerpt = '(filtered)';
		}
		$token_str  = substr( sanitize_text_field( $route_token ), 0, 128 );
		$action_str = substr( sanitize_key( $action_taken ), 0, 32 );
		$meta_str   = '' !== $meta_json ? $meta_json : '{}';

		$lock_name     = 'cl_chain_' . substr( md5( $table ), 0, 48 );
		$lock_acquired = false;
		if ( method_exists( $wpdb, 'get_var' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- advisory lock serializes tail hash read and insert under concurrent scraper traffic.
			$locked = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock_name, 5 ) );
			if ( null !== $locked && '' !== (string) $locked && 0 === (int) $locked ) {
				return 0;
			}
			$lock_acquired = 1 === (int) $locked;
		}

		try {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- append-only chain tail read.
			$last_hash = $wpdb->get_var( $wpdb->prepare( 'SELECT row_hash FROM %i ORDER BY id DESC LIMIT 1', $table ) );
			$prev_hash = ( is_string( $last_hash ) && 64 === strlen( $last_hash ) ) ? $last_hash : str_repeat( '0', 64 );

			$row_hash = self::compute_full_hash(
				$timestamp,
				$ip_digest,
				$ua_digest,
				$excerpt,
				$token_str,
				$action_str,
				$meta_str,
				$prev_hash,
				$secret
			);

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- plugin-owned audit log insert.
			$ok = $wpdb->insert(
				$table,
				[
					'created_at'         => $timestamp,
					'ip_digest'          => $ip_digest,
					'ua_digest'          => $ua_digest,
					'user_agent_excerpt' => $excerpt,
					'route_token'        => $token_str,
					'action_taken'       => $action_str,
					'meta_json'          => $meta_str,
					'prev_hash'          => $prev_hash,
					'row_hash'           => $row_hash,
				],
				[ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
			);

			return false === $ok ? 0 : (int) ( $wpdb->insert_id ?? 0 );
		} finally {
			if ( $lock_acquired && method_exists( $wpdb, 'get_var' ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- release advisory lock after chain append.
				$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
			}
		}
	}

	/**
	 * Verify the entire HMAC hash chain (including pseudonymized retention rows).
	 *
	 * @return array{valid: bool, total_verified: int, broken_at_id: ?int, error_reason: string}
	 */
	public function verify_chain(): array {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_results' ) ) {
			return [
				'valid'          => false,
				'total_verified' => 0,
				'broken_at_id'   => null,
				'error_reason'   => 'Database interface unavailable.',
			];
		}

		$table              = self::table_name();
		$secret             = TokenSigner::ensure_secret();
		$batch_size         = 500;
		$last_id            = 0;
		$expected_prev      = str_repeat( '0', 64 );
		$verified_count     = 0;
		$pending_redacted   = [];
		$verified_residuals = [];

		while ( true ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- keyset-paginated full chain verification bounds peak PHP memory.
			$rows = $wpdb->get_results(
				$wpdb->prepare( 'SELECT * FROM %i WHERE id > %d ORDER BY id ASC LIMIT %d', $table, $last_id, $batch_size )
			);
			if ( ! is_array( $rows ) || [] === $rows ) {
				break;
			}

			foreach ( $rows as $row ) {
				$id      = (int) ( $row->id ?? 0 );
				$last_id = max( $last_id, $id );
				if ( ! hash_equals( $expected_prev, (string) ( $row->prev_hash ?? '' ) ) ) {
					return [
						'valid'          => false,
						'total_verified' => $verified_count,
						'broken_at_id'   => $id,
						'error_reason'   => sprintf( 'Broken prev_hash linkage at row #%d.', $id ),
					];
				}

				$action  = (string) ( $row->action_taken ?? '' );
				$excerpt = (string) ( $row->user_agent_excerpt ?? '' );

				if ( self::REDACTED_MARKER === $excerpt && self::RETENTION_ACTION !== $action ) {
					$pending_redacted[ (string) $id ] = self::compute_residual_hash(
						(string) ( $row->created_at ?? '' ),
						(string) ( $row->ip_digest ?? '' ),
						(string) ( $row->ua_digest ?? '' ),
						(string) ( $row->route_token ?? '' ),
						$action,
						(string) ( $row->meta_json ?? '{}' ),
						(string) ( $row->prev_hash ?? '' ),
						$secret
					);
					$expected_prev                    = (string) ( $row->row_hash ?? '' );
					++$verified_count;
					continue;
				}

				$expected_hash = self::compute_full_hash(
					(string) ( $row->created_at ?? '' ),
					(string) ( $row->ip_digest ?? '' ),
					(string) ( $row->ua_digest ?? '' ),
					$excerpt,
					(string) ( $row->route_token ?? '' ),
					$action,
					(string) ( $row->meta_json ?? '{}' ),
					(string) ( $row->prev_hash ?? '' ),
					$secret
				);

				if ( ! hash_equals( $expected_hash, (string) ( $row->row_hash ?? '' ) ) ) {
					return [
						'valid'          => false,
						'total_verified' => $verified_count,
						'broken_at_id'   => $id,
						'error_reason'   => sprintf( 'Row hash mismatch at row #%d.', $id ),
					];
				}

				if ( self::RETENTION_ACTION === $action ) {
					$meta = json_decode( (string) ( $row->meta_json ?? '{}' ), true );
					if ( is_array( $meta ) && isset( $meta['residuals'] ) && is_array( $meta['residuals'] ) ) {
						foreach ( $meta['residuals'] as $target_id => $residual ) {
							$target_key = (string) $target_id;
							if ( ! is_string( $residual ) || 64 !== strlen( $residual ) ) {
								continue;
							}
							if ( isset( $pending_redacted[ $target_key ] ) ) {
								if ( ! hash_equals( $residual, $pending_redacted[ $target_key ] ) ) {
									return [
										'valid'          => false,
										'total_verified' => max( 0, $verified_count - 1 ),
										'broken_at_id'   => (int) $target_key,
										'error_reason'   => sprintf( 'Residual hash mismatch on pseudonymized row #%s.', $target_key ),
									];
								}
								unset( $pending_redacted[ $target_key ] );
							} else {
								$verified_residuals[ $target_key ] = $residual;
							}
						}
					}
				}

				$expected_prev = (string) ( $row->row_hash ?? '' );
				++$verified_count;
			}

			if ( count( $rows ) < $batch_size ) {
				break;
			}
		}

		foreach ( $pending_redacted as $target_id => $actual_residual ) {
			$expected_residual = $verified_residuals[ $target_id ] ?? null;
			if ( null === $expected_residual ) {
				return [
					'valid'          => false,
					'total_verified' => max( 0, $verified_count - 1 ),
					'broken_at_id'   => (int) $target_id,
					'error_reason'   => sprintf( 'Unauthorized redaction on row #%s.', $target_id ),
				];
			}

			if ( ! hash_equals( $expected_residual, $actual_residual ) ) {
				return [
					'valid'          => false,
					'total_verified' => max( 0, $verified_count - 1 ),
					'broken_at_id'   => (int) $target_id,
					'error_reason'   => sprintf( 'Residual hash mismatch on pseudonymized row #%s.', $target_id ),
				];
			}
		}

		return [
			'valid'          => true,
			'total_verified' => $verified_count,
			'broken_at_id'   => null,
			'error_reason'   => '',
		];
	}

	/**
	 * Pseudonymize User-Agent excerpts older than the retention window without breaking the HMAC chain.
	 *
	 * @return array{cutoff: string, candidates: int, pseudonymized: int, dry_run: bool}
	 */
	public function purge_expired( bool $dry_run = false, ?int $days_override = null ): array {
		global $wpdb;
		$days = $days_override ?? Settings::clamp_int( get_option( 'crawler_labyrinth_retention_days', 30 ), 0, 365, 30 );
		if ( $days <= 0 || ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_results' ) ) {
			return [
				'cutoff'        => '',
				'candidates'    => 0,
				'pseudonymized' => 0,
				'dry_run'       => $dry_run,
			];
		}

		$day_seconds = defined( 'DAY_IN_SECONDS' ) ? (int) DAY_IN_SECONDS : 86400;
		$cutoff      = gmdate( 'Y-m-d H:i:s', time() - ( $days * $day_seconds ) );
		$table       = self::table_name();
		$secret      = TokenSigner::ensure_secret();

		$batch_limit      = 200;
		$max_batches      = 50;
		$last_id          = 0;
		$total_candidates = 0;
		$total_updated    = 0;

		for ( $batch = 0; $batch < $max_batches; $batch++ ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- keyset-paginated retention lookup.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE id > %d AND created_at < %s AND user_agent_excerpt != %s AND action_taken != %s ORDER BY id ASC LIMIT %d',
					$table,
					$last_id,
					$cutoff,
					self::REDACTED_MARKER,
					self::RETENTION_ACTION,
					$batch_limit
				)
			);
			if ( ! is_array( $rows ) || [] === $rows ) {
				break;
			}

			$total_candidates += count( $rows );
			foreach ( $rows as $row ) {
				$last_id = max( $last_id, (int) ( $row->id ?? 0 ) );
			}

			if ( $dry_run ) {
				if ( count( $rows ) < $batch_limit ) {
					break;
				}
				continue;
			}

			$residuals = [];
			foreach ( $rows as $row ) {
				$expected = self::compute_full_hash(
					(string) ( $row->created_at ?? '' ),
					(string) ( $row->ip_digest ?? '' ),
					(string) ( $row->ua_digest ?? '' ),
					(string) ( $row->user_agent_excerpt ?? '' ),
					(string) ( $row->route_token ?? '' ),
					(string) ( $row->action_taken ?? '' ),
					(string) ( $row->meta_json ?? '{}' ),
					(string) ( $row->prev_hash ?? '' ),
					$secret
				);
				if ( ! hash_equals( $expected, (string) ( $row->row_hash ?? '' ) ) ) {
					continue;
				}
				$id               = (string) ( (int) ( $row->id ?? 0 ) );
				$residuals[ $id ] = self::compute_residual_hash(
					(string) ( $row->created_at ?? '' ),
					(string) ( $row->ip_digest ?? '' ),
					(string) ( $row->ua_digest ?? '' ),
					(string) ( $row->route_token ?? '' ),
					(string) ( $row->action_taken ?? '' ),
					(string) ( $row->meta_json ?? '{}' ),
					(string) ( $row->prev_hash ?? '' ),
					$secret
				);
			}

			if ( [] !== $residuals ) {
				$meta_json = function_exists( 'wp_json_encode' )
					? (string) wp_json_encode( [ 'residuals' => $residuals ] )
					: (string) json_encode( [ 'residuals' => $residuals ] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- fallback when run outside WordPress in unit tests.

				$audit_id = $this->record(
					str_repeat( '0', 64 ),
					str_repeat( '0', 64 ),
					'[SYSTEM]',
					'retention:' . count( $residuals ),
					self::RETENTION_ACTION,
					$meta_json
				);
				if ( $audit_id <= 0 ) {
					break;
				}

				foreach ( array_keys( $residuals ) as $id_str ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- pseudonymizes expired excerpt while keeping hash chain intact.
					$res = $wpdb->update(
						$table,
						[ 'user_agent_excerpt' => self::REDACTED_MARKER ],
						[ 'id' => (int) $id_str ],
						[ '%s' ],
						[ '%d' ]
					);
					if ( false !== $res ) {
						++$total_updated;
					}
				}
			}

			if ( count( $rows ) < $batch_limit ) {
				break;
			}
		}

		if ( ! $dry_run ) {
			$this->purge_expired_transients();
		}

		return [
			'cutoff'        => $cutoff,
			'candidates'    => $total_candidates,
			'pseudonymized' => $total_updated,
			'dry_run'       => $dry_run,
		];
	}

	/**
	 * Clean up expired strike and quarantine transients accumulated from past UTC days.
	 */
	public function purge_expired_transients(): int {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! isset( $wpdb->options ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'get_col' ) || ! method_exists( $wpdb, 'query' ) ) {
			return 0;
		}
		$now         = time();
		$timeout_pfx = $wpdb->esc_like( '_transient_timeout_crawler_labyrinth_' ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- bounded cleanup of expired plugin strike/quarantine transient timeouts.
		$expired_timeouts = $wpdb->get_col( $wpdb->prepare( 'SELECT option_name FROM %i WHERE option_name LIKE %s AND option_value != %s AND CAST(option_value AS UNSIGNED) < %d LIMIT %d', $wpdb->options, $timeout_pfx, '', $now, 500 ) );
		if ( ! is_array( $expired_timeouts ) || [] === $expired_timeouts ) {
			return 0;
		}

		$deleted = 0;
		foreach ( $expired_timeouts as $timeout_name ) {
			if ( ! is_string( $timeout_name ) || ! str_starts_with( $timeout_name, '_transient_timeout_crawler_labyrinth_' ) ) {
				continue;
			}
			$value_name = '_transient_' . substr( $timeout_name, strlen( '_transient_timeout_' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- deletes expired strike/quarantine transient timeout and value pair.
			$res = $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name IN (%s, %s)', $wpdb->options, $timeout_name, $value_name ) );
			if ( is_int( $res ) && $res > 0 ) {
				$deleted += $res;
			}
		}

		return $deleted;
	}

	/**
	 * Fetch summary metrics for admin telemetry, REST, and WP-CLI reporting.
	 *
	 * @return array{total_hits: int, decoy_served: int, quarantined: int, unique_ip_digests: int}
	 */
	public function get_stats(): array {
		global $wpdb;
		$empty = [
			'total_hits'        => 0,
			'decoy_served'      => 0,
			'quarantined'       => 0,
			'unique_ip_digests' => 0,
		];
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) ) {
			return $empty;
		}
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- live summary counters.
		$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE action_taken != %s', $table, self::RETENTION_ACTION ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- live summary counters.
		$decoys = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE action_taken = %s', $table, 'decoy_served' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- live summary counters.
		$quarantined = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE action_taken = %s', $table, 'quarantined' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- live summary counters.
		$unique = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(DISTINCT ip_digest) FROM %i WHERE action_taken != %s', $table, self::RETENTION_ACTION ) );

		return [
			'total_hits'        => $total,
			'decoy_served'      => $decoys,
			'quarantined'       => $quarantined,
			'unique_ip_digests' => $unique,
		];
	}

	/**
	 * Fetch recent audit rows for the admin log table.
	 *
	 * @return list<object>
	 */
	public function get_recent( int $limit = 20 ): array {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_results' ) ) {
			return [];
		}
		$table = self::table_name();
		$cap   = max( 1, min( 100, $limit ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- admin view read.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT %d', $table, $cap ) );
		return is_array( $rows ) ? array_values( $rows ) : [];
	}
}
