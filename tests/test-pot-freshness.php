<?php
/**
 * Gate: verifies that languages/*.pot covers all translatable strings in PHP source.
 *
 * Uses official `wp i18n make-pot` extraction rather than hand-rolled regex parsing.
 * Includes a self-test asserting missing entries are detected and plugin headers are ignored.
 *
 * Usage:
 *     php tests/test-pot-freshness.php
 *     php tests/test-pot-freshness.php --selftest
 *
 * @package CrawlerLabyrinth
 */

declare(strict_types=1);

const CL_POT_EXCLUDE = 'tests,vendor,node_modules,dist,build,.git';

function cl_plugin_text_domain( string $repo ): ?string {
	foreach ( glob( $repo . '/*.php' ) ?: [] as $php ) {
		$head = (string) file_get_contents( $php, false, null, 0, 4096 );
		if ( ! str_contains( $head, 'Plugin Name:' ) ) {
			continue;
		}
		if ( preg_match( '/^\s*\*?\s*Text Domain:\s*(\S+)/m', $head, $m ) ) {
			return $m[1];
		}
	}
	return null;
}

/**
 * @return array<string, bool>
 */
function cl_pot_entries( string $text ): array {
	$out = [];
	foreach ( preg_split( '/\R\s*\R/', $text ) as $block ) {
		if ( ! preg_match( '/^msgid((?:\s+"(?:[^"\\\\]|\\\\.)*")+)/m', $block, $m ) ) {
			continue;
		}
		preg_match_all( '/"((?:[^"\\\\]|\\\\.)*)"/', $m[1], $parts );
		$msgid = implode( '', $parts[1] );
		if ( '' === $msgid ) {
			continue;
		}
		$out[ $msgid ] = (bool) preg_match( '/^#\.\s+.*\bof the (plugin|theme)\b/m', $block );
	}
	return $out;
}

function cl_make_pot( string $repo, string $domain, string $dest ): ?string {
	$process = proc_open(
		[ 'wp', 'i18n', 'make-pot', $repo, $dest, '--domain=' . $domain, '--exclude=' . CL_POT_EXCLUDE, '--skip-audit', '--allow-root' ],
		[ 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ],
		$pipes
	);
	if ( ! is_resource( $process ) ) {
		return null;
	}
	stream_get_contents( $pipes[1] );
	$stderr = (string) stream_get_contents( $pipes[2] );
	fclose( $pipes[1] );
	fclose( $pipes[2] );
	$code = proc_close( $process );

	if ( 0 !== $code || ! is_file( $dest ) ) {
		fwrite( STDERR, "  wp i18n make-pot failed (exit {$code}): " . substr( trim( $stderr ), 0, 300 ) . "\n" );
		return null;
	}
	return (string) file_get_contents( $dest );
}

/**
 * @return array{missing: list<string>, stale: list<string>, in_code: int, in_pot: int}|null
 */
function cl_measure_pot( string $repo, string $domain ): ?array {
	$tmp        = tempnam( sys_get_temp_dir(), 'clpot' );
	$fresh_text = cl_make_pot( $repo, $domain, $tmp );
	$fresh_all  = null === $fresh_text ? null : cl_pot_entries( $fresh_text );
	@unlink( $tmp );
	if ( null === $fresh_all ) {
		return null;
	}

	$pot_path  = $repo . '/languages/' . $domain . '.pot';
	$current   = is_file( $pot_path ) ? array_keys( cl_pot_entries( (string) file_get_contents( $pot_path ) ) ) : [];
	$from_code = array_keys( array_filter( $fresh_all, static fn( bool $is_header ): bool => ! $is_header ) );

	return [
		'missing' => array_values( array_diff( $from_code, $current ) ),
		'stale'   => array_values( array_diff( $current, array_keys( $fresh_all ) ) ),
		'in_code' => count( $from_code ),
		'in_pot'  => count( $current ),
	];
}

$passed = 0;
$failed = 0;
function cl_check( string $name, bool $ok, string $detail = '' ): void {
	global $passed, $failed;
	if ( $ok ) {
		echo "  [PASS] {$name}\n";
		$passed++;
		return;
	}
	echo "  [FAIL] {$name}: {$detail}\n";
	$failed++;
}

function cl_selftest(): int {
	$dir = sys_get_temp_dir() . '/cl-pot-selftest-' . bin2hex( random_bytes( 4 ) );
	mkdir( $dir . '/languages', 0o777, true );
	file_put_contents(
		$dir . '/cl-pot-selftest.php',
		"<?php\n/**\n * Plugin Name: Pot Selftest\n * Text Domain: cl-pot-selftest\n */\n"
		. "__( 'string that has an entry', 'cl-pot-selftest' );\n"
		. "__( 'string with no entry at all', 'cl-pot-selftest' );\n"
		. "__( 'string of another plugin', 'somebody-else' );\n"
	);
	file_put_contents(
		$dir . '/languages/cl-pot-selftest.pot',
		"msgid \"\"\nmsgstr \"\"\n\nmsgid \"string that has an entry\"\nmsgstr \"\"\n"
	);

	$res = cl_measure_pot( $dir, 'cl-pot-selftest' );
	if ( null === $res ) {
		echo "  [FAIL] the self-test could not run make-pot\n";
		return 1;
	}

	cl_check( 'a string with no entry is reported', in_array( 'string with no entry at all', $res['missing'], true ), implode( ' | ', $res['missing'] ) );
	cl_check( 'a string that has an entry is not reported', ! in_array( 'string that has an entry', $res['missing'], true ), implode( ' | ', $res['missing'] ) );
	cl_check( "another plugin's text domain is ignored", ! in_array( 'string of another plugin', $res['missing'], true ), implode( ' | ', $res['missing'] ) );
	cl_check( 'the plugin header is not counted as a missing translation', ! in_array( 'Pot Selftest', $res['missing'], true ), implode( ' | ', $res['missing'] ) );

	array_map( 'unlink', (array) glob( $dir . '/languages/*' ) );
	array_map( 'unlink', (array) glob( $dir . '/*.php' ) );
	rmdir( $dir . '/languages' );
	rmdir( $dir );
	return 0;
}

$repo = dirname( __DIR__ );

echo "==============================================================================\n";
echo "  POT FRESHNESS — languages/*.pot completeness check\n";
echo "==============================================================================\n\n";

$which  = proc_open( [ 'which', 'wp' ], [ 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ], $pipes );
$has_wp = false;
if ( is_resource( $which ) ) {
	$has_wp = '' !== trim( (string) stream_get_contents( $pipes[1] ) );
	fclose( $pipes[1] );
	fclose( $pipes[2] );
	proc_close( $which );
}

if ( ! $has_wp ) {
	fwrite( STDERR, "wp-cli is required: this check compares against `wp i18n make-pot`.\n" );
	exit( 1 );
}

if ( in_array( '--selftest', $argv, true ) ) {
	cl_selftest();
	echo "\n  SELF-TEST: {$passed} passed, {$failed} failed\n";
	exit( $failed > 0 ? 1 : 0 );
}

echo "Self-test:\n";
cl_selftest();
echo "\n";

$domain = cl_plugin_text_domain( $repo );
if ( null === $domain ) {
	fwrite( STDERR, "No main plugin file with Plugin Name and Text Domain header in {$repo}.\n" );
	exit( 1 );
}

$result = cl_measure_pot( $repo, $domain );
if ( null === $result ) {
	fwrite( STDERR, "make-pot did not produce a catalog; see the error above.\n" );
	exit( 1 );
}

cl_check( 'the code has translatable strings to check at all', $result['in_code'] > 0, "0 strings extracted for domain {$domain}" );

echo "Domain {$domain}: " . $result['in_code'] . " translatable string(s) in the code, " . $result['in_pot'] . " entry/entries in the .pot\n\n";

cl_check(
	'every string the code translates has an entry in the .pot',
	[] === $result['missing'],
	count( $result['missing'] ) . ' missing, e.g. ' . implode( ' | ', array_map( static fn( string $s ): string => substr( $s, 0, 60 ), array_slice( $result['missing'], 0, 3 ) ) )
);

if ( [] !== $result['stale'] ) {
	echo "  [NOTE] " . count( $result['stale'] ) . " entry/entries in the .pot no longer exist in the code\n";
}

echo "\n==============================================================================\n";
echo "  POT FRESHNESS: {$passed} passed, {$failed} failed\n";
echo "==============================================================================\n\n";

if ( $failed > 0 ) {
	fwrite( STDERR, "Regenerate catalog: wp i18n make-pot . languages/{$domain}.pot --domain={$domain} --exclude=" . CL_POT_EXCLUDE . "\n" );
}

exit( $failed > 0 ? 1 : 0 );
