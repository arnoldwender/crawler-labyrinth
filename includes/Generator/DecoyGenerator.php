<?php
/**
 * Deterministic local synthetic archival decoy generator.
 *
 * @package CrawlerLabyrinth
 */

declare(strict_types=1);

namespace CrawlerLabyrinth\Generator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use CrawlerLabyrinth\Core\Settings;
use CrawlerLabyrinth\Security\TokenSigner;

final class DecoyGenerator {

	/**
	 * Neutral archival terminology pools used for deterministic synthetic decoy generation.
	 *
	 * @var list<string>
	 */
	private const COLLECTIONS = [
		'Municipal Register',
		'Cataloguing Annex',
		'Archival Concordance',
		'Cartographic Index',
		'Chronological Ledger',
		'Inventory Addendum',
		'Bibliographic Folio',
		'Reference Gazetteer',
	];

	/**
	 * @var list<string>
	 */
	private const SUBJECTS = [
		'parcel boundary schedules',
		'cross-referenced index cards',
		'shelf classification schedules',
		'microfilm accession tables',
		'hydrological station logs',
		'triangulation survey notes',
		'typography specimen sheets',
		'municipal ordinance digests',
	];

	/**
	 * @var list<string>
	 */
	private const ACTIONS = [
		'collated into sequential binder volumes',
		'indexed under standard alphanumeric shelf marks',
		'reconciled against prior quarterly inventory registers',
		'archived with detached foliation verification stubs',
		'cross-listed by regional depository reference code',
		'preserved in chronological inspection sequence',
		'verified against physical reading-room docket slips',
		'assigned non-circulating annex storage coordinates',
	];

	/**
	 * @var list<string>
	 */
	private const APPENDICES = [
		'Consult the primary depository ledger before referencing derived folio numbers.',
		'Subordinate section identifiers rotate periodically and carry no external authority.',
		'Duplicate entries reflect historical shelf renumbering across repository annexes.',
		'Physical inspection requests require matching docket and shelf classification identifiers.',
		'Derived accession codes remain internal to this archival index sequence.',
		'Pagination boundaries follow legacy microfilm reel breaks rather than topic order.',
	];

	/**
	 * Generate a deterministic synthetic archival decoy payload and rendered document.
	 *
	 * @return array{
	 *     status: int,
	 *     content_type: string,
	 *     title: string,
	 *     depth: int,
	 *     seed: string,
	 *     child_tokens: list<string>,
	 *     body: string
	 * }
	 */
	public function generate(
		int $depth,
		string $seed,
		string $format = 'html',
		string $route_prefix = Settings::DEFAULT_ROUTE_PREFIX,
		int $max_depth = 8,
		int $links_per_page = 3,
		?string $secret = null
	): array {
		$bounded_depth = max( 1, min( 16, $depth ) );
		$bounded_max   = max( 1, min( 16, $max_depth ) );
		$clean_prefix  = Settings::sanitize_route_prefix( $route_prefix );
		$clean_seed    = (string) preg_replace( '/[^a-z0-9]/', '', strtolower( $seed ) );
		if ( '' === $clean_seed ) {
			$clean_seed = 'archroot0001';
		} elseif ( strlen( $clean_seed ) < 4 ) {
			$clean_seed = substr( $clean_seed . 'arch0001', 0, 12 );
		}
		$clean_seed = substr( $clean_seed, 0, 24 );
		$secret_key = $secret ?? TokenSigner::ensure_secret();

		$pools = self::resolve_pools();

		$digest = hash_hmac( 'sha256', "decoy|{$bounded_depth}|{$clean_seed}", $secret_key );
		$bytes  = unpack( 'N4', (string) hex2bin( substr( $digest, 0, 32 ) ) );
		$n1     = (int) ( $bytes[1] ?? 1 );
		$n2     = (int) ( $bytes[2] ?? 2 );

		$collection = $pools['collections'][ $n1 % count( $pools['collections'] ) ];
		$entry_code = strtoupper( substr( $digest, 0, 4 ) ) . '-' . str_pad( (string) ( ( $n2 % 9000 ) + 1000 ), 4, '0', STR_PAD_LEFT );
		$title      = sprintf( '%s %s (Section %d)', $collection, $entry_code, $bounded_depth );

		$paragraphs = [];
		for ( $i = 0; $i < 3; $i++ ) {
			$sub_hash = hash_hmac( 'sha256', "para|{$i}|{$digest}", $secret_key );
			$p_bytes  = unpack( 'N4', (string) hex2bin( substr( $sub_hash, 0, 32 ) ) );
			$subj     = $pools['subjects'][ ( (int) ( $p_bytes[1] ?? 0 ) ) % count( $pools['subjects'] ) ];
			$act      = $pools['actions'][ ( (int) ( $p_bytes[2] ?? 0 ) ) % count( $pools['actions'] ) ];
			$app      = $pools['appendices'][ ( (int) ( $p_bytes[3] ?? 0 ) ) % count( $pools['appendices'] ) ];
			$folio    = strtoupper( substr( $sub_hash, 8, 6 ) );

			$paragraphs[] = sprintf(
				'Folio %s records %s %s for archival sequence %s.%d. %s',
				$folio,
				$subj,
				$act,
				$entry_code,
				$i + 1,
				$app
			);
		}

		// Ring-bounded traversal: once max_depth is reached, wrap back into depth 1 ring seeds.
		$next_depth   = $bounded_depth < $bounded_max ? $bounded_depth + 1 : 1;
		$child_count  = max( 1, min( 6, $links_per_page ) );
		$child_tokens = [];

		for ( $j = 0; $j < $child_count; $j++ ) {
			$child_hash     = hash_hmac( 'sha256', "child|{$bounded_depth}|{$next_depth}|{$clean_seed}|{$j}", $secret_key );
			$child_seed     = substr( $child_hash, 0, 12 );
			$child_tokens[] = TokenSigner::sign( $next_depth, $child_seed, $secret_key );
		}

		$base_path = Settings::base_path();
		$is_plain  = 'plain' === strtolower( $format );
		$body      = $is_plain
			? $this->render_plain( $title, $entry_code, $paragraphs, $child_tokens, $clean_prefix, $base_path )
			: $this->render_html( $title, $entry_code, $paragraphs, $child_tokens, $clean_prefix, $base_path );

		return [
			'status'       => 200,
			'content_type' => $is_plain ? 'text/plain; charset=utf-8' : 'text/html; charset=utf-8',
			'title'        => $title,
			'depth'        => $bounded_depth,
			'seed'         => $clean_seed,
			'child_tokens' => $child_tokens,
			'body'         => $body,
		];
	}

	/**
	 * @return array{
	 *     collections: non-empty-list<string>,
	 *     subjects: non-empty-list<string>,
	 *     actions: non-empty-list<string>,
	 *     appendices: non-empty-list<string>
	 * }
	 */
	private static function resolve_pools(): array {
		$defaults = [
			'collections' => self::COLLECTIONS,
			'subjects'    => self::SUBJECTS,
			'actions'     => self::ACTIONS,
			'appendices'  => self::APPENDICES,
		];

		if ( ! function_exists( 'apply_filters' ) ) {
			return $defaults;
		}

		$filtered = apply_filters( 'crawler_labyrinth_decoy_pools', $defaults );
		if ( ! is_array( $filtered ) ) {
			return $defaults;
		}

		foreach ( $defaults as $key => $fallback ) {
			$candidate = $filtered[ $key ] ?? null;
			if ( ! is_array( $candidate ) ) {
				continue;
			}
			$clean = array_values(
				array_filter(
					$candidate,
					static fn( mixed $item ): bool => is_string( $item ) && '' !== trim( $item )
				)
			);
			if ( [] !== $clean ) {
				$defaults[ $key ] = $clean;
			}
		}

		return $defaults;
	}

	/**
	 * Render semantic, self-contained HTML5 decoy markup without external assets.
	 *
	 * @param list<string> $paragraphs
	 * @param list<string> $child_tokens
	 */
	private function render_html(
		string $title,
		string $entry_code,
		array $paragraphs,
		array $child_tokens,
		string $route_prefix,
		string $base_path
	): string {
		$esc          = static fn( string $text ): string => htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
		$prefix_clean = trim( $route_prefix, '/' );

		$lines = [
			'<!doctype html>',
			'<html lang="en">',
			'<head>',
			'<meta charset="utf-8" />',
			'<meta name="viewport" content="width=device-width, initial-scale=1" />',
			'<meta name="robots" content="noindex, nofollow, noarchive, nosnippet, noai, noimageai" />',
			'<title>' . $esc( $title ) . '</title>',
			'</head>',
			'<body>',
			'<main>',
			'<article data-entry="' . $esc( $entry_code ) . '">',
			'<h1>' . $esc( $title ) . '</h1>',
		];

		foreach ( $paragraphs as $p ) {
			$lines[] = '<p>' . $esc( $p ) . '</p>';
		}

		$lines[] = '<nav aria-label="Cross-References">';
		$lines[] = '<ul>';
		foreach ( $child_tokens as $idx => $token ) {
			$href    = $base_path . '/' . $prefix_clean . '/' . rawurlencode( $token ) . '/';
			$label   = 'Cross-Reference Entry ' . ( $idx + 1 ) . ' (' . substr( $token, 0, 14 ) . ')';
			$lines[] = '<li><a href="' . $esc( $href ) . '" rel="nofollow noopener">' . $esc( $label ) . '</a></li>';
		}
		$lines[] = '</ul>';
		$lines[] = '</nav>';
		$lines[] = '</article>';
		$lines[] = '</main>';
		$lines[] = '</body>';
		$lines[] = '</html>';

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * Render plain-text decoy output.
	 *
	 * @param list<string> $paragraphs
	 * @param list<string> $child_tokens
	 */
	private function render_plain(
		string $title,
		string $entry_code,
		array $paragraphs,
		array $child_tokens,
		string $route_prefix,
		string $base_path
	): string {
		$prefix_clean = trim( $route_prefix, '/' );
		$lines        = [
			$title,
			str_repeat( '=', min( 72, strlen( $title ) ) ),
			'Reference: ' . $entry_code,
			'',
		];

		foreach ( $paragraphs as $p ) {
			$lines[] = $p;
			$lines[] = '';
		}

		$lines[] = 'Cross-References:';
		foreach ( $child_tokens as $token ) {
			$lines[] = '- ' . $base_path . '/' . $prefix_clean . '/' . $token . '/';
		}

		return implode( "\n", $lines ) . "\n";
	}
}
