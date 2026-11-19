<?php
/**
 * CSS token hygiene and accessibility baseline gate for Crawler Labyrinth admin styles.
 *
 * @package CrawlerLabyrinth
 */

declare(strict_types=1);

$css_file = dirname( __DIR__ ) . '/assets/css/crawler-labyrinth-admin.css';
$passed   = 0;
$failed   = 0;

function check_css( string $label, bool $condition, string $detail = '' ): void {
	global $passed, $failed;
	if ( $condition ) {
		echo "  [PASS] {$label}\n";
		$passed++;
		return;
	}
	echo "  [FAIL] {$label}" . ( '' !== $detail ? " ({$detail})" : '' ) . "\n";
	$failed++;
}

echo "==============================================================================\n";
echo "  CRAWLER LABYRINTH — CSS TOKEN & ACCESSIBILITY GATE\n";
echo "==============================================================================\n\n";

check_css( 'Admin stylesheet exists', is_file( $css_file ), $css_file );

$css = is_file( $css_file ) ? (string) file_get_contents( $css_file ) : '';

check_css(
	'Stylesheet defines --cl-* design tokens',
	str_contains( $css, '--cl-bg:' ) && str_contains( $css, '--cl-primary:' ) && str_contains( $css, '--cl-touch-min: 2.75rem' )
);

// Check that no raw hex color appears outside `--cl-*` token definitions.
$hex_violations = [];
foreach ( explode( "\n", $css ) as $lineno => $line ) {
	$trimmed = trim( $line );
	if ( '' === $trimmed || str_starts_with( $trimmed, '/*' ) || str_starts_with( $trimmed, '*' ) || str_starts_with( $trimmed, '--cl-' ) ) {
		continue;
	}
	if ( 1 === preg_match( '/#[0-9a-fA-F]{3,8}\b/', $trimmed ) ) {
		$hex_violations[] = sprintf( 'line %d: %s', $lineno + 1, $trimmed );
	}
}
check_css( 'No raw hex colors outside --cl-* token declarations', [] === $hex_violations, implode( '; ', $hex_violations ) );

check_css(
	'No outline: none or outline: 0 removes keyboard focus indicators',
	1 !== preg_match( '/outline\s*:\s*(none|0)\b/i', $css )
);

check_css(
	'Explicit :focus-visible rule and 2.75rem (44px) touch target rule are present',
	str_contains( $css, ':focus-visible' ) && str_contains( $css, 'min-height: var(--cl-touch-min)' )
);

echo "\n  CSS TOKEN GATE: {$passed} passed, {$failed} failed\n\n";
exit( 0 === $failed ? 0 : 1 );
