<?php
/**
 * Mutations for tests/run-mutations.sh: each entry undoes one fix or invariant with literal
 * replacements [ file, search, replace ]; tests/test-suite.php must turn red for every one.
 *
 * Single-quoted strings only so "$" is never interpolated.
 *
 * @package CrawlerLabyrinth
 */

return [
	[
		'TokenSigner::verify stops using constant-time hash_equals()',
		[
			[ 'includes/Security/TokenSigner.php', 'if ( ! hash_equals( $expected, $actual ) ) {', 'if ( $expected !== $actual ) {' ],
		],
	],
	[
		'Settings allows reserved wp-admin path as trap prefix',
		[
			[ 'includes/Core/Settings.php', 'if ( $clean === $reserved || str_starts_with( $clean, $reserved . \'-\' ) ) {', 'if ( false && $clean === $reserved ) {' ],
		],
	],
	[
		'Settings stops sparing search/citation crawlers before checking training tokens',
		[
			[ 'includes/Core/Settings.php', 'foreach ( $allowed_tokens as $allowed ) {', 'foreach ( [] as $allowed ) {' ],
		],
	],
	[
		'DecoyGenerator stops wrapping max_depth back to depth 1',
		[
			[ 'includes/Generator/DecoyGenerator.php', '$next_depth   = $bounded_depth < $bounded_max ? $bounded_depth + 1 : 1;', '$next_depth   = $bounded_depth + 1;' ],
		],
	],
	[
		'RobotsInjector skips injecting Disallow into existing User-agent groups',
		[
			[ 'includes/Core/RobotsInjector.php', 'if ( $in_group && $has_non_ua_directive && ! $group_has_disallow ) {', 'if ( false && $in_group && $has_non_ua_directive && ! $group_has_disallow ) {' ],
		],
	],
	[
		'BaitRenderer emits accessible-unfriendly inline display:none instead of hidden attribute',
		[
			[ 'includes/Core/BaitRenderer.php', '<div hidden aria-hidden="true" data-crawler-labyrinth="1">', '<div style="display:none">' ],
		],
	],
	[
		'TrapRouter fails to escalate hybrid strikes into quarantine',
		[
			[ 'includes/Core/TrapRouter.php', '$quarantine = ( \'block\' === $mode ) || ( \'hybrid\' === $mode && $strikes > $threshold );', '$quarantine = ( \'block\' === $mode );' ],
		],
	],
	[
		'TrapRouter stops exempting administrators',
		[
			[ 'includes/Core/TrapRouter.php', 'if ( ! $settings[\'enabled\'] || $is_admin_user ) {', 'if ( ! $settings[\'enabled\'] ) {' ],
		],
	],
	[
		'TrapRouter reverts wp_parse_url() back to raw parse_url()',
		[
			[ 'includes/Core/TrapRouter.php', 'wp_parse_url( $request_uri', 'parse_url( $request_uri' ],
		],
	],
	[
		'HitLogger::verify_chain accepts unauthorized manual redaction',
		[
			[ 'includes/Audit/HitLogger.php', 'if ( null === $expected_residual ) {', 'if ( false ) { continue;' ],
			[ 'includes/Audit/HitLogger.php', 'if ( ! hash_equals( $expected_residual, $actual_residual ) ) {', 'if ( null !== $expected_residual && ! hash_equals( $expected_residual, $actual_residual ) ) {' ],
		],
	],
	[
		'AdminDashboard::save_settings overwrites lifecycle opt-in when saving settings tab',
		[
			[ 'includes/Admin/AdminDashboard.php', 'if ( \'settings\' === $form ) {', 'if ( \'settings\' === $form ) { update_option( \'crawler_labyrinth_delete_data_on_uninstall\', \'0\' );' ],
		],
	],
	[
		'AdminDashboard::save_settings writes options when crawler_labyrinth_form marker is missing',
		[
			[ 'includes/Admin/AdminDashboard.php', '} else {' . "\n\t\t\t" . 'return;' . "\n\t\t" . '}', '} else { update_option( \'crawler_labyrinth_enabled\', \'0\' ); }' ],
		],
	],
	[
		'uninstall.php deletes plugin options and audit table without opt-in',
		[
			[ 'uninstall.php', 'if ( ! $crawler_labyrinth_opt_in ) {', 'if ( false && ! $crawler_labyrinth_opt_in ) {' ],
		],
	],
];
