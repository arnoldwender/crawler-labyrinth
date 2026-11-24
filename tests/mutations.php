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
		'TokenSigner::ip_digest drops secret key making digests unkeyed',
		[
			[ 'includes/Security/TokenSigner.php', 'return hash_hmac( \'sha256\', $ip . \'|\' . $day, $key );', 'return hash( \'sha256\', $ip . \'|\' . $day );' ],
		],
	],
	[
		'TokenSigner::ip_digest stops rotating by UTC day',
		[
			[ 'includes/Security/TokenSigner.php', 'return hash_hmac( \'sha256\', $ip . \'|\' . $day, $key );', 'return hash_hmac( \'sha256\', $ip, $key );' ],
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
			[ 'includes/Core/RobotsInjector.php', 'if ( $in_group && ( $has_non_ua_directive || $saw_blank ) && ! $group_has_disallow ) {', 'if ( false && $in_group && ( $has_non_ua_directive || $saw_blank ) && ! $group_has_disallow ) {' ],
		],
	],
	[
		'RobotsInjector ignores blank-line group flush for empty User-agent blocks',
		[
			[ 'includes/Core/RobotsInjector.php', '} elseif ( $in_group && $is_blank ) {', '} elseif ( false && $in_group && $is_blank ) {' ],
		],
	],
	[
		'BaitRenderer emits accessible-unfriendly inline display:none instead of hidden attribute',
		[
			[ 'includes/Core/BaitRenderer.php', '<div hidden inert aria-hidden="true" data-crawler-labyrinth="1">', '<div style="display:none">' ],
		],
	],
	[
		'BaitRenderer drops inert attribute allowing parent flex layouts to expose hidden bait',
		[
			[ 'includes/Core/BaitRenderer.php', '<div hidden inert aria-hidden="true" data-crawler-labyrinth="1">', '<div hidden aria-hidden="true" data-crawler-labyrinth="1">' ],
		],
	],
	[
		'TrapRouter fails to escalate hybrid strikes into quarantine',
		[
			[ 'includes/Core/TrapRouter.php', '$quarantine = ( \'block\' === $mode ) || ( \'hybrid\' === $mode && $strikes > $threshold );', '$quarantine = ( \'block\' === $mode );' ],
		],
	],
	[
		'TrapRouter allows labyrinth mode to quarantine past strike threshold',
		[
			[ 'includes/Core/TrapRouter.php', '$quarantine = ( \'block\' === $mode ) || ( \'hybrid\' === $mode && $strikes > $threshold );', '$quarantine = ( \'block\' === $mode ) || ( $strikes > $threshold );' ],
		],
	],
	[
		'TrapRouter forces 429 instead of configured 403 block_status',
		[
			[ 'includes/Core/TrapRouter.php', 'return $this->build_block_response( $block_status, $ttl_seconds, $strikes );', 'return $this->build_block_response( 429, $ttl_seconds, $strikes );' ],
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
		'TrapRouter skips subdirectory base_path stripping',
		[
			[ 'includes/Core/TrapRouter.php', '$base_path = Settings::base_path();', '$base_path = \'\';' ],
		],
	],
	[
		'TrapRouter uses unbounded substring match instead of segment boundary on excluded prefixes',
		[
			[ 'includes/Core/TrapRouter.php', 'if ( $lower === $excluded || str_starts_with( $lower, $excluded . \'/\' ) ) {', 'if ( str_starts_with( $lower, $excluded ) ) {' ],
		],
	],
	[
		'TrapRouter omits DONOTCACHEPAGE constant definition on trap hits',
		[
			[ 'includes/Core/TrapRouter.php', "if ( ! defined( 'DONOTCACHEPAGE' ) ) {\n\t\t\t// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- standard WordPress cache bypass constant for trapped requests.\n\t\t\tdefine( 'DONOTCACHEPAGE', true );\n\t\t}", 'if ( false ) {}' ],
		],
	],
	[
		'HitLogger::verify_chain ignores broken prev_hash linkage',
		[
			[ 'includes/Audit/HitLogger.php', 'if ( ! hash_equals( $expected_prev, (string) ( $row->prev_hash ?? \'\' ) ) ) {', 'if ( false ) {' ],
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
		'HitLogger::purge_expired continues redacting rows when audit chain insert fails',
		[
			[ 'includes/Audit/HitLogger.php', 'if ( $audit_id <= 0 ) {', 'if ( false ) {' ],
		],
	],
	[
		'AdminDashboard::save_settings skips wp_verify_nonce verification',
		[
			[ 'includes/Admin/AdminDashboard.php', "if ( '' === \$nonce || ! wp_verify_nonce( \$nonce, 'crawler_labyrinth_save_action' ) ) {", 'if ( false ) {' ],
		],
	],
	[
		'AdminDashboard::save_settings skips current_user_can manage_options check',
		[
			[ 'includes/Admin/AdminDashboard.php', "\t\tif ( '' === \$nonce || ! wp_verify_nonce( \$nonce, 'crawler_labyrinth_save_action' ) ) {\n\t\t\treturn;\n\t\t}\n\t\tif ( ! current_user_can( 'manage_options' ) ) {", "\t\tif ( '' === \$nonce || ! wp_verify_nonce( \$nonce, 'crawler_labyrinth_save_action' ) ) {\n\t\t\treturn;\n\t\t}\n\t\tif ( false ) {" ],
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
		'AdminDashboard::render_dashboard stops escaping user_agent_excerpt in telemetry table',
		[
			[ 'includes/Admin/AdminDashboard.php', "<?php echo esc_html( (string) ( \$row->user_agent_excerpt ?? '' ) ); ?>", "<?php echo (string) ( \$row->user_agent_excerpt ?? '' ); ?>" ],
		],
	],
	[
		'RestController replaces check_admin_permission with __return_true',
		[
			[ 'includes/API/RestController.php', "'callback'            => [ \$this, 'handle_status' ],\n\t\t\t\t'permission_callback' => [ \$this, 'check_admin_permission' ],", "'callback'            => [ \$this, 'handle_status' ],\n\t\t\t\t'permission_callback' => '__return_true'," ],
		],
	],
	[
		'uninstall.php deletes plugin options and audit table without opt-in',
		[
			[ 'uninstall.php', 'if ( ! $crawler_labyrinth_blog_opt_in ) {', 'if ( false && ! $crawler_labyrinth_blog_opt_in ) {' ],
		],
	],
	[
		'HitLogger::record stops filtering spoofed literal [REDACTED] User-Agents',
		[
			[ 'includes/Audit/HitLogger.php', "if ( self::REDACTED_MARKER === \$excerpt && self::RETENTION_ACTION !== \$action_taken ) {\n\t\t\t\$excerpt = '(filtered)';\n\t\t}", 'if ( false ) {}' ],
		],
	],
	[
		'HitLogger::purge_expired skips HMAC verification letting retention erase tamper evidence',
		[
			[ 'includes/Audit/HitLogger.php', "if ( ! hash_equals( \$expected, (string) ( \$row->row_hash ?? '' ) ) ) {\n\t\t\t\t\tcontinue;\n\t\t\t\t}", 'if ( false ) { continue; }' ],
		],
	],
	[
		'TrapRouter drops active_quarantine_max persistence breaking cross-day lookback',
		[
			[ 'includes/Core/TrapRouter.php', "if ( \$quarantine_until > \$prev_max ) {\n\t\t\t\tupdate_option( 'crawler_labyrinth_active_quarantine_max', \$quarantine_until, true );\n\t\t\t}", 'if ( false ) {}' ],
		],
	],
	[
		'TrapRouter hardcodes lookback_days to 1 breaking multi-day quarantine',
		[
			[ 'includes/Core/TrapRouter.php', '$lookback_days = min( 7, max( 1, (int) ceil( $ttl_seconds / 86400 ) ) );', '$lookback_days = 1;' ],
		],
	],
	[
		'TrapRouter ignores enabled kill switch',
		[
			[ 'includes/Core/TrapRouter.php', "if ( ! \$settings['enabled'] || \$is_admin_user ) {", 'if ( $is_admin_user ) {' ],
		],
	],
	[
		'TokenSigner::client_ip trusts spoofable HTTP_X_FORWARDED_FOR header directly',
		[
			[ 'includes/Security/TokenSigner.php', "\$raw = isset( \$_SERVER['REMOTE_ADDR'] ) && is_scalar( \$_SERVER['REMOTE_ADDR'] )\n\t\t\t? sanitize_text_field( wp_unslash( (string) \$_SERVER['REMOTE_ADDR'] ) )\n\t\t\t: '0.0.0.0';", "\$raw = isset( \$_SERVER['HTTP_X_FORWARDED_FOR'] ) ? (string) \$_SERVER['HTTP_X_FORWARDED_FOR'] : ( (string) ( \$_SERVER['REMOTE_ADDR'] ?? '0.0.0.0' ) );" ],
		],
	],
	[
		'TokenSigner::verify drops upper depth <= 16 bound',
		[
			[ 'includes/Security/TokenSigner.php', 'if ( $depth < 1 || $depth > 16 ) {', 'if ( false ) {' ],
		],
	],
	[
		'AdminDashboard::save_settings breaks purge_now form action',
		[
			[ 'includes/Admin/AdminDashboard.php', "} elseif ( 'purge_now' === \$form ) {\n\t\t\t\$redirect_tab = 'telemetry';\n\t\t\t\$updated_flag = 'purged';\n\t\t\t( new HitLogger() )->purge_expired( false );", "} elseif ( 'purge_now' === \$form ) {\n\t\t\t\$redirect_tab = 'telemetry';\n\t\t\t\$updated_flag = 'purged';" ],
		],
	],
	[
		'RestController depth regex allows trailing newline via $ instead of \\z',
		[
			[ 'includes/API/RestController.php', "1 === preg_match( '/^\\d+\\z/', \$val )", "1 === preg_match( '/^\\d+$/', \$val )" ],
		],
	],
	[
		'Settings::sanitize_route_prefix drops >32 max length limit',
		[
			[ 'includes/Core/Settings.php', 'if ( strlen( $clean ) < 3 || strlen( $clean ) > 32 ) {', 'if ( strlen( $clean ) < 3 ) {' ],
		],
	],
	[
		'RobotsInjector lowercases directive path value violating RFC 9309 path case sensitivity',
		[
			[ 'includes/Core/RobotsInjector.php', '$value = trim( substr( $directive, $colon_pos + 1 ) );', '$value = strtolower( trim( substr( $directive, $colon_pos + 1 ) ) );' ],
		],
	],
	[
		'TrapRouter reverts early init hook back to late template_redirect',
		[
			[ 'includes/Core/TrapRouter.php', "add_action( 'init', [ \$this, 'intercept_request' ], 1 );", "add_action( 'template_redirect', [ \$this, 'intercept_request' ], 1 );" ],
		],
	],
	[
		'TrapRouter disables global burst circuit breaker',
		[
			[ 'includes/Core/TrapRouter.php', 'if ( $burst_limit > 0 && $this->is_burst_exceeded( $burst_limit, $now ) ) {', 'if ( false ) {' ],
		],
	],
	[
		'Settings::sanitize_mode rejects observe dry-run mode',
		[
			[ 'includes/Core/Settings.php', "return in_array( \$val, [ 'hybrid', 'labyrinth', 'block', 'observe' ], true ) ? \$val : 'hybrid';", "return in_array( \$val, [ 'hybrid', 'labyrinth', 'block' ], true ) ? \$val : 'hybrid';" ],
		],
	],
	[
		'TrapRouter::boot uses default priority 10 instead of early priority 1 on init',
		[
			[ 'includes/Core/TrapRouter.php', "add_action( 'init', [ \$this, 'intercept_request' ], 1 );", "add_action( 'init', [ \$this, 'intercept_request' ], 10 );" ],
		],
	],
	[
		'TrapRouter drops hot-path early return before secret reads',
		[
			[ 'includes/Core/TrapRouter.php', "if ( ! \$trap_matched && ! \$ua_matched && ! \$has_active_bans ) {\n\t\t\treturn \$passthrough;\n\t\t}", "if ( false && ! \$trap_matched ) {\n\t\t\treturn \$passthrough;\n\t\t}" ],
		],
	],
	[
		'TrapRouter runs burst shedder even in observe dry-run mode',
		[
			[ 'includes/Core/TrapRouter.php', "if ( 'observe' !== \$mode ) {\n\t\t\t\$burst_limit = (int) ( \$settings['burst_limit'] ?? 120 );", "if ( true ) {\n\t\t\t\$burst_limit = (int) ( \$settings['burst_limit'] ?? 120 );" ],
		],
	],
	[
		'TrapRouter reverts trapRegex to single-segment blocking multi-segment and .html probes',
		[
			[ 'includes/Core/TrapRouter.php', "\$regex        = '#^/' . preg_quote( \$prefix, '#' ) . '(?:/(.*))?\$#is';", "\$regex        = '#^/' . preg_quote( \$prefix, '#' ) . '(?:/([a-zA-Z0-9_-]{1,96}))?/?\$#i';" ],
		],
	],
	[
		'TrapRouter omits X-Crawler-Labyrinth state header on decoy and observe responses',
		[
			[ 'includes/Core/TrapRouter.php', "'X-Crawler-Labyrinth'    => \$header_state,", "'X-Crawler-Labyrinth'    => 'disabled'," ],
		],
	],
	[
		'TrapRouter misclassifies unsigned robots probes as signed_bait',
		[
			[ 'includes/Core/TrapRouter.php', "\$entry_vector = 'robots_probe';", "\$entry_vector = 'signed_bait';" ],
		],
	],
	[
		'Settings::sanitize_route_prefix stops rejecting dots and internal slashes',
		[
			[ 'includes/Core/Settings.php', "if ( '' === \$trimmed || str_contains( \$trimmed, '.' ) || str_contains( \$trimmed, '/' ) ) {", "if ( '' === \$trimmed ) {" ],
		],
	],
	[
		'RobotsInjector flushes active group prematurely on Sitemap directive',
		[
			[ 'includes/Core/RobotsInjector.php', "if ( \$is_sitemap ) {\n\t\t\t\tif ( \$in_group ) {\n\t\t\t\t\t\$saw_blank = true;\n\t\t\t\t}\n\t\t\t\t\$result[] = \$line;\n\t\t\t\tcontinue;\n\t\t\t}", "if ( \$is_sitemap ) {\n\t\t\t\tif ( \$in_group && ! \$group_has_disallow ) {\n\t\t\t\t\t\$result = self::insert_before_trailing_blanks( \$result, \$rule );\n\t\t\t\t}\n\t\t\t\t\$in_group = false;\n\t\t\t\t\$result[] = \$line;\n\t\t\t\tcontinue;\n\t\t\t}" ],
		],
	],
	[
		'AdminDashboard drops entry_vector extraction from Telemetry table',
		[
			[ 'includes/Admin/AdminDashboard.php', "\$entry_vector = ( is_array( \$row_meta ) && isset( \$row_meta['entry_vector'] ) && is_string( \$row_meta['entry_vector'] ) )", "\$entry_vector = ( false && is_array( \$row_meta ) && isset( \$row_meta['entry_vector'] ) && is_string( \$row_meta['entry_vector'] ) )" ],
		],
	],
];
