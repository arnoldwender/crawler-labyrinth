<?php
/**
 * WordPress Admin dashboard, settings handler, and telemetry widget.
 *
 * @package CrawlerLabyrinth
 */

declare(strict_types=1);

namespace CrawlerLabyrinth\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use CrawlerLabyrinth\Audit\HitLogger;
use CrawlerLabyrinth\Core\Activator;
use CrawlerLabyrinth\Core\RobotsInjector;
use CrawlerLabyrinth\Core\Settings;
use CrawlerLabyrinth\Generator\DecoyGenerator;

final class AdminDashboard {

	private static ?AdminDashboard $instance = null;

	public static function instance(): AdminDashboard {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function boot(): void {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_init', [ $this, 'save_settings' ] );
		add_action( 'wp_dashboard_setup', [ $this, 'register_dashboard_widget' ] );
	}

	public function register_settings(): void {
		if ( ! function_exists( 'register_setting' ) ) {
			return;
		}

		$bool_cb = [ Settings::class, 'sanitize_bool_flag' ];

		register_setting(
			'crawler_labyrinth_settings',
			'crawler_labyrinth_enabled',
			[
				'type'              => 'string',
				'sanitize_callback' => $bool_cb,
				'default'           => '1',
			]
		);
		register_setting(
			'crawler_labyrinth_settings',
			'crawler_labyrinth_route_prefix',
			[
				'type'              => 'string',
				'sanitize_callback' => [ Settings::class, 'sanitize_route_prefix' ],
				'default'           => Settings::DEFAULT_ROUTE_PREFIX,
			]
		);
		register_setting(
			'crawler_labyrinth_settings',
			'crawler_labyrinth_mode',
			[
				'type'              => 'string',
				'sanitize_callback' => [ Settings::class, 'sanitize_mode' ],
				'default'           => 'hybrid',
			]
		);
		register_setting(
			'crawler_labyrinth_settings',
			'crawler_labyrinth_response_format',
			[
				'type'              => 'string',
				'sanitize_callback' => [ Settings::class, 'sanitize_format' ],
				'default'           => 'html',
			]
		);
		register_setting(
			'crawler_labyrinth_settings',
			'crawler_labyrinth_link_count',
			[
				'type'              => 'integer',
				'sanitize_callback' => static fn( $v ): int => Settings::clamp_int( $v, 1, 5, 1 ),
				'default'           => 1,
			]
		);
		register_setting(
			'crawler_labyrinth_settings',
			'crawler_labyrinth_max_depth',
			[
				'type'              => 'integer',
				'sanitize_callback' => static fn( $v ): int => Settings::clamp_int( $v, 1, 16, 8 ),
				'default'           => 8,
			]
		);
		register_setting(
			'crawler_labyrinth_settings',
			'crawler_labyrinth_strike_threshold',
			[
				'type'              => 'integer',
				'sanitize_callback' => static fn( $v ): int => Settings::clamp_int( $v, 1, 100, 5 ),
				'default'           => 5,
			]
		);
		register_setting(
			'crawler_labyrinth_settings',
			'crawler_labyrinth_block_ttl_minutes',
			[
				'type'              => 'integer',
				'sanitize_callback' => static fn( $v ): int => Settings::clamp_int( $v, 5, 10080, 360 ),
				'default'           => 360,
			]
		);
		register_setting(
			'crawler_labyrinth_settings',
			'crawler_labyrinth_block_status',
			[
				'type'              => 'integer',
				'sanitize_callback' => [ Settings::class, 'sanitize_block_status' ],
				'default'           => 429,
			]
		);
		register_setting(
			'crawler_labyrinth_settings',
			'crawler_labyrinth_update_robots',
			[
				'type'              => 'string',
				'sanitize_callback' => $bool_cb,
				'default'           => '1',
			]
		);
		register_setting(
			'crawler_labyrinth_settings',
			'crawler_labyrinth_intercept_training_ua',
			[
				'type'              => 'string',
				'sanitize_callback' => $bool_cb,
				'default'           => '0',
			]
		);
		register_setting(
			'crawler_labyrinth_settings',
			'crawler_labyrinth_retention_days',
			[
				'type'              => 'integer',
				'sanitize_callback' => static fn( $v ): int => Settings::clamp_int( $v, 0, 365, 30 ),
				'default'           => 30,
			]
		);
		register_setting(
			'crawler_labyrinth_settings',
			'crawler_labyrinth_delete_data_on_uninstall',
			[
				'type'              => 'string',
				'sanitize_callback' => $bool_cb,
				'default'           => '0',
			]
		);
	}

	public static function menu_icon(): string {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><rect x="2" y="2" width="16" height="16" rx="2" fill="none" stroke="black" stroke-width="1.8"/><path d="M6 6h8v4H8v4h6" fill="none" stroke="black" stroke-width="1.8" stroke-linecap="square"/></svg>';
		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
	}

	public function register_menu(): void {
		add_menu_page(
			__( 'Crawler Labyrinth', 'crawler-labyrinth' ),
			__( 'Crawler Labyrinth', 'crawler-labyrinth' ),
			'manage_options',
			'crawler-labyrinth',
			[ $this, 'render_dashboard' ],
			self::menu_icon(),
			84
		);
	}

	public function enqueue_assets( string $hook ): void {
		if ( ! str_contains( $hook, 'crawler-labyrinth' ) && 'index.php' !== $hook ) {
			return;
		}

		$css_path = dirname( __DIR__, 2 ) . '/assets/css/crawler-labyrinth-admin.css';
		wp_enqueue_style(
			'crawler-labyrinth-admin',
			CRAWLER_LABYRINTH_URL . 'assets/css/crawler-labyrinth-admin.css',
			[],
			(string) ( @filemtime( $css_path ) ?: CRAWLER_LABYRINTH_VERSION )
		);
	}

	public function register_dashboard_widget(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_add_dashboard_widget(
			'crawler_labyrinth_widget',
			__( 'Crawler Labyrinth — Honeypot & Telemetry', 'crawler-labyrinth' ),
			[ $this, 'render_dashboard_widget' ]
		);
	}

	public function render_dashboard_widget(): void {
		$settings = Settings::all();
		$stats    = ( new HitLogger() )->get_stats();
		$active   = (bool) $settings['enabled'];
		?>
		<div class="cl-widget">
			<div class="cl-widget-banner <?php echo $active ? 'is-active' : ''; ?>">
				<span class="dashicons dashicons-shield-alt" aria-hidden="true"></span>
				<div>
					<div class="cl-widget-title">
						<?php echo $active ? esc_html__( 'Crawler Labyrinth Active', 'crawler-labyrinth' ) : esc_html__( 'Crawler Labyrinth Paused', 'crawler-labyrinth' ); ?>
					</div>
					<div class="cl-widget-sub">
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: mode name, 2: route prefix */
								__( 'Mode: %1$s | Trap: /%2$s/', 'crawler-labyrinth' ),
								strtoupper( (string) $settings['mode'] ),
								(string) $settings['route_prefix']
							)
						);
						?>
					</div>
				</div>
			</div>

			<div class="cl-widget-kpi-grid">
				<div class="cl-widget-kpi">
					<span class="cl-stat-label"><?php esc_html_e( 'Trap Hits', 'crawler-labyrinth' ); ?></span>
					<span class="cl-stat-value is-info"><?php echo esc_html( (string) $stats['total_hits'] ); ?></span>
				</div>
				<div class="cl-widget-kpi">
					<span class="cl-stat-label"><?php esc_html_e( 'Quarantined', 'crawler-labyrinth' ); ?></span>
					<span class="cl-stat-value is-warn"><?php echo esc_html( (string) $stats['quarantined'] ); ?></span>
				</div>
			</div>

			<div>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=crawler-labyrinth' ) ); ?>" class="cl-btn cl-btn-secondary">
					<span><?php esc_html_e( 'Open Crawler Labyrinth', 'crawler-labyrinth' ); ?></span>
					<span class="dashicons dashicons-arrow-right-alt" aria-hidden="true"></span>
				</a>
			</div>
		</div>
		<?php
	}

	/**
	 * Handle nonce-protected POST submissions. Each tab writes only its own fields.
	 */
	public function save_settings(): void {
		$nonce = isset( $_POST['crawler_labyrinth_save_nonce'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['crawler_labyrinth_save_nonce'] ) ) : '';
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'crawler_labyrinth_save_action' ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$form = isset( $_POST['crawler_labyrinth_form'] ) ? sanitize_key( wp_unslash( (string) $_POST['crawler_labyrinth_form'] ) ) : '';

		$redirect_tab = 'telemetry';
		if ( 'settings' === $form ) {
			$redirect_tab = 'settings';
			update_option( 'crawler_labyrinth_enabled', isset( $_POST['crawler_labyrinth_enabled'] ) ? '1' : '0' );
			update_option( 'crawler_labyrinth_route_prefix', Settings::sanitize_route_prefix( isset( $_POST['crawler_labyrinth_route_prefix'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['crawler_labyrinth_route_prefix'] ) ) : Settings::DEFAULT_ROUTE_PREFIX ) );
			update_option( 'crawler_labyrinth_mode', Settings::sanitize_mode( isset( $_POST['crawler_labyrinth_mode'] ) ? sanitize_key( wp_unslash( (string) $_POST['crawler_labyrinth_mode'] ) ) : 'hybrid' ) );
			update_option( 'crawler_labyrinth_response_format', Settings::sanitize_format( isset( $_POST['crawler_labyrinth_response_format'] ) ? sanitize_key( wp_unslash( (string) $_POST['crawler_labyrinth_response_format'] ) ) : 'html' ) );
			update_option( 'crawler_labyrinth_link_count', Settings::clamp_int( isset( $_POST['crawler_labyrinth_link_count'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['crawler_labyrinth_link_count'] ) ) : '1', 1, 5, 1 ) );
			update_option( 'crawler_labyrinth_max_depth', Settings::clamp_int( isset( $_POST['crawler_labyrinth_max_depth'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['crawler_labyrinth_max_depth'] ) ) : '8', 1, 16, 8 ) );
			update_option( 'crawler_labyrinth_strike_threshold', Settings::clamp_int( isset( $_POST['crawler_labyrinth_strike_threshold'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['crawler_labyrinth_strike_threshold'] ) ) : '5', 1, 100, 5 ) );
			update_option( 'crawler_labyrinth_block_ttl_minutes', Settings::clamp_int( isset( $_POST['crawler_labyrinth_block_ttl_minutes'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['crawler_labyrinth_block_ttl_minutes'] ) ) : '360', 5, 10080, 360 ) );
			update_option( 'crawler_labyrinth_block_status', Settings::sanitize_block_status( isset( $_POST['crawler_labyrinth_block_status'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['crawler_labyrinth_block_status'] ) ) : '429' ) );
			update_option( 'crawler_labyrinth_update_robots', isset( $_POST['crawler_labyrinth_update_robots'] ) ? '1' : '0' );
			update_option( 'crawler_labyrinth_intercept_training_ua', isset( $_POST['crawler_labyrinth_intercept_training_ua'] ) ? '1' : '0' );
			update_option( 'crawler_labyrinth_retention_days', Settings::clamp_int( isset( $_POST['crawler_labyrinth_retention_days'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['crawler_labyrinth_retention_days'] ) ) : '30', 0, 365, 30 ) );
			Activator::sync_schedule();
		} elseif ( 'lifecycle' === $form ) {
			$redirect_tab = 'telemetry';
			update_option( 'crawler_labyrinth_delete_data_on_uninstall', isset( $_POST['crawler_labyrinth_delete_data_on_uninstall'] ) ? '1' : '0' );
		} elseif ( 'purge_now' === $form ) {
			$redirect_tab = 'telemetry';
			( new HitLogger() )->purge_expired( false );
		} else {
			return;
		}

		wp_safe_redirect(
			add_query_arg(
				[
					'page'    => 'crawler-labyrinth',
					'tab'     => $redirect_tab,
					'updated' => 'true',
				],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public function render_dashboard(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab routing and feedback flag.
		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : 'telemetry';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only feedback flag.
		$was_updated = isset( $_GET['updated'] ) && 'true' === sanitize_key( wp_unslash( (string) $_GET['updated'] ) );
		$settings    = Settings::all();
		$logger      = new HitLogger();
		$stats       = $logger->get_stats();
		$chain       = $logger->verify_chain();
		$recent_rows = $logger->get_recent( 15 );

		$tabs = [
			'telemetry' => [
				'label' => __( 'Telemetry & Audit', 'crawler-labyrinth' ),
				'icon'  => 'dashicons-chart-area',
			],
			'settings'  => [
				'label' => __( 'Trap Settings', 'crawler-labyrinth' ),
				'icon'  => 'dashicons-admin-settings',
			],
			'preview'   => [
				'label' => __( 'Decoy & robots.txt Preview', 'crawler-labyrinth' ),
				'icon'  => 'dashicons-visibility',
			],
			'security'  => [
				'label' => __( 'Privacy, REST & WP-CLI', 'crawler-labyrinth' ),
				'icon'  => 'dashicons-lock',
			],
		];
		if ( ! isset( $tabs[ $active_tab ] ) ) {
			$active_tab = 'telemetry';
		}
		?>
		<div class="wrap cl-admin">
			<header class="cl-header">
				<div class="cl-header-brand">
					<div class="cl-header-icon">
						<span class="dashicons dashicons-shield-alt" aria-hidden="true"></span>
					</div>
					<div>
						<h1><?php esc_html_e( 'Crawler Labyrinth', 'crawler-labyrinth' ); ?></h1>
						<p class="cl-subtitle"><?php esc_html_e( 'Local deterministic crawler tarpit &bull; RFC 9309 trap &bull; Keyed HMAC audit chain', 'crawler-labyrinth' ); ?></p>
					</div>
				</div>
				<div class="cl-header-badges">
					<span class="cl-badge cl-badge-info"><?php echo esc_html( strtoupper( (string) $settings['mode'] ) ); ?></span>
					<span class="cl-badge <?php echo $chain['valid'] ? 'cl-badge-ok' : 'cl-badge-danger'; ?>">
						<?php echo $chain['valid'] ? esc_html__( 'HMAC CHAIN VALID', 'crawler-labyrinth' ) : esc_html__( 'HMAC CHAIN WARNING', 'crawler-labyrinth' ); ?>
					</span>
				</div>
			</header>

			<?php if ( $was_updated ) : ?>
			<div class="notice notice-success is-dismissible" role="status" aria-live="polite">
				<p><?php esc_html_e( 'Settings and schedule updated.', 'crawler-labyrinth' ); ?></p>
			</div>
			<?php endif; ?>

			<nav class="cl-nav-tabs" aria-label="<?php esc_attr_e( 'Crawler Labyrinth Navigation', 'crawler-labyrinth' ); ?>">
				<?php foreach ( $tabs as $slug => $tab ) : ?>
				<a href="<?php echo esc_url( add_query_arg( [ 'page' => 'crawler-labyrinth', 'tab' => $slug ], admin_url( 'admin.php' ) ) ); ?>" class="cl-tab-link <?php echo $active_tab === $slug ? 'is-active' : ''; ?>"<?php echo $active_tab === $slug ? ' aria-current="page"' : ''; ?>>
					<span class="dashicons <?php echo esc_attr( $tab['icon'] ); ?>" aria-hidden="true"></span>
					<?php echo esc_html( $tab['label'] ); ?>
				</a>
				<?php endforeach; ?>
			</nav>

			<?php if ( 'telemetry' === $active_tab ) : ?>
			<section aria-labelledby="cl-telemetry-heading">
				<div class="cl-grid-4">
					<div class="cl-stat-card">
						<span class="cl-stat-label"><?php esc_html_e( 'Honeypot Status', 'crawler-labyrinth' ); ?></span>
						<span class="cl-stat-value is-info"><?php echo $settings['enabled'] ? esc_html__( 'Active', 'crawler-labyrinth' ) : esc_html__( 'Paused', 'crawler-labyrinth' ); ?></span>
						<span class="cl-stat-meta"><?php echo esc_html( '/' . (string) $settings['route_prefix'] . '/' ); ?></span>
					</div>
					<div class="cl-stat-card">
						<span class="cl-stat-label"><?php esc_html_e( 'Decoy Pages Served', 'crawler-labyrinth' ); ?></span>
						<span class="cl-stat-value is-ok"><?php echo esc_html( (string) $stats['decoy_served'] ); ?></span>
						<span class="cl-stat-meta"><?php esc_html_e( '100% local generation', 'crawler-labyrinth' ); ?></span>
					</div>
					<div class="cl-stat-card">
						<span class="cl-stat-label"><?php esc_html_e( 'Quarantined Requests', 'crawler-labyrinth' ); ?></span>
						<span class="cl-stat-value is-warn"><?php echo esc_html( (string) $stats['quarantined'] ); ?></span>
						<span class="cl-stat-meta"><?php echo esc_html( 'HTTP ' . (string) $settings['block_status'] ); ?></span>
					</div>
					<div class="cl-stat-card">
						<span class="cl-stat-label"><?php esc_html_e( 'Unique IP Digests', 'crawler-labyrinth' ); ?></span>
						<span class="cl-stat-value"><?php echo esc_html( (string) $stats['unique_ip_digests'] ); ?></span>
						<span class="cl-stat-meta"><?php echo esc_html( (string) $chain['total_verified'] . ' ' . __( 'verified rows', 'crawler-labyrinth' ) ); ?></span>
					</div>
				</div>

				<div class="cl-card">
					<h2 id="cl-telemetry-heading"><?php esc_html_e( 'Recent Crawler Trap Activity', 'crawler-labyrinth' ); ?></h2>
					<?php if ( [] === $recent_rows ) : ?>
					<p class="cl-subtitle"><?php esc_html_e( 'No trap hits have been recorded yet.', 'crawler-labyrinth' ); ?></p>
					<?php else : ?>
					<div class="cl-table-wrap">
						<table class="widefat striped">
							<thead>
								<tr>
									<th scope="col"><?php esc_html_e( 'ID', 'crawler-labyrinth' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Timestamp (UTC)', 'crawler-labyrinth' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Action', 'crawler-labyrinth' ); ?></th>
									<th scope="col"><?php esc_html_e( 'IP Digest', 'crawler-labyrinth' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Route Token', 'crawler-labyrinth' ); ?></th>
									<th scope="col"><?php esc_html_e( 'User-Agent Excerpt', 'crawler-labyrinth' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $recent_rows as $row ) : ?>
								<tr>
									<td><?php echo esc_html( (string) ( $row->id ?? '' ) ); ?></td>
									<td><?php echo esc_html( (string) ( $row->created_at ?? '' ) ); ?></td>
									<td><?php echo esc_html( (string) ( $row->action_taken ?? '' ) ); ?></td>
									<td><code><?php echo esc_html( substr( (string) ( $row->ip_digest ?? '' ), 0, 16 ) . '...' ); ?></code></td>
									<td><code><?php echo esc_html( (string) ( $row->route_token ?? '' ) ); ?></code></td>
									<td><?php echo esc_html( (string) ( $row->user_agent_excerpt ?? '' ) ); ?></td>
								</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
					<?php endif; ?>

					<form method="post" action="">
						<?php wp_nonce_field( 'crawler_labyrinth_save_action', 'crawler_labyrinth_save_nonce' ); ?>
						<input type="hidden" name="crawler_labyrinth_form" value="purge_now" />
						<button type="submit" class="cl-btn cl-btn-secondary">
							<?php esc_html_e( 'Run Pseudonymizing Retention Now', 'crawler-labyrinth' ); ?>
						</button>
					</form>
				</div>

				<div class="cl-card">
					<h2><?php esc_html_e( 'Data Lifecycle &amp; Uninstall', 'crawler-labyrinth' ); ?></h2>
					<form method="post" action="">
						<?php wp_nonce_field( 'crawler_labyrinth_save_action', 'crawler_labyrinth_save_nonce' ); ?>
						<input type="hidden" name="crawler_labyrinth_form" value="lifecycle" />
						<fieldset class="cl-fieldset">
							<legend class="screen-reader-text"><?php esc_html_e( 'Data Lifecycle Options', 'crawler-labyrinth' ); ?></legend>
							<div class="cl-form-group">
								<label for="cl-delete-uninstall" class="cl-toggle-label">
									<input type="checkbox" id="cl-delete-uninstall" name="crawler_labyrinth_delete_data_on_uninstall" value="1" <?php checked( (bool) $settings['delete_on_uninstall'] ); ?> />
									<span><strong><?php esc_html_e( 'Delete plugin options and audit log table on uninstall', 'crawler-labyrinth' ); ?></strong> &mdash; <?php esc_html_e( 'By default all settings and the HMAC-chained hit log survive plugin deletion.', 'crawler-labyrinth' ); ?></span>
								</label>
							</div>
						</fieldset>
						<button type="submit" class="cl-btn cl-btn-primary">
							<?php esc_html_e( 'Save Lifecycle Preference', 'crawler-labyrinth' ); ?>
						</button>
					</form>
				</div>
			</section>

			<?php elseif ( 'settings' === $active_tab ) : ?>
			<section aria-labelledby="cl-settings-heading">
				<div class="cl-card">
					<h2 id="cl-settings-heading"><?php esc_html_e( 'Crawler Labyrinth &amp; Quarantine Configuration', 'crawler-labyrinth' ); ?></h2>
					<form method="post" action="">
						<?php wp_nonce_field( 'crawler_labyrinth_save_action', 'crawler_labyrinth_save_nonce' ); ?>
						<input type="hidden" name="crawler_labyrinth_form" value="settings" />

						<fieldset class="cl-fieldset">
							<legend><?php esc_html_e( 'Activation &amp; Routing', 'crawler-labyrinth' ); ?></legend>
							<div class="cl-form-group">
								<label for="cl-enabled" class="cl-toggle-label">
									<input type="checkbox" id="cl-enabled" name="crawler_labyrinth_enabled" value="1" <?php checked( (bool) $settings['enabled'] ); ?> />
									<span><?php esc_html_e( 'Enable Crawler Labyrinth honeypot and trap routing', 'crawler-labyrinth' ); ?></span>
								</label>
							</div>
							<div class="cl-form-group">
								<label for="cl-update-robots" class="cl-toggle-label">
									<input type="checkbox" id="cl-update-robots" name="crawler_labyrinth_update_robots" value="1" <?php checked( (bool) $settings['update_robots'] ); ?> />
									<span><?php esc_html_e( 'Inject Disallow rule across all RFC 9309 User-agent groups in robots.txt', 'crawler-labyrinth' ); ?></span>
								</label>
							</div>
							<div class="cl-form-group">
								<label for="cl-intercept-ua" class="cl-toggle-label">
									<input type="checkbox" id="cl-intercept-ua" name="crawler_labyrinth_intercept_training_ua" value="1" <?php checked( (bool) $settings['intercept_training_ua'] ); ?> />
									<span><?php esc_html_e( 'Opt-in: also trap known AI training scraper User-Agents (spares search and citation bots)', 'crawler-labyrinth' ); ?></span>
								</label>
							</div>
							<div class="cl-form-group">
								<label for="cl-prefix"><?php esc_html_e( 'Trap Route Prefix (lowercase alphanumeric and hyphens, 3-32 chars)', 'crawler-labyrinth' ); ?></label>
								<input type="text" id="cl-prefix" name="crawler_labyrinth_route_prefix" class="cl-input" value="<?php echo esc_attr( (string) $settings['route_prefix'] ); ?>" />
							</div>
						</fieldset>

						<fieldset class="cl-fieldset">
							<legend><?php esc_html_e( 'Defense Mode &amp; Quarantine Limits', 'crawler-labyrinth' ); ?></legend>
							<div class="cl-form-group">
								<label for="cl-mode"><?php esc_html_e( 'Trap Mode', 'crawler-labyrinth' ); ?></label>
								<select id="cl-mode" name="crawler_labyrinth_mode" class="cl-input">
									<option value="hybrid" <?php selected( (string) $settings['mode'], 'hybrid' ); ?>><?php esc_html_e( 'Hybrid (serve decoy up to strike threshold, then quarantine)', 'crawler-labyrinth' ); ?></option>
									<option value="labyrinth" <?php selected( (string) $settings['mode'], 'labyrinth' ); ?>><?php esc_html_e( 'Labyrinth Only (always serve bounded synthetic decoys)', 'crawler-labyrinth' ); ?></option>
									<option value="block" <?php selected( (string) $settings['mode'], 'block' ); ?>><?php esc_html_e( 'Immediate Block (quarantine on first disallowed hit)', 'crawler-labyrinth' ); ?></option>
								</select>
							</div>
							<div class="cl-form-group">
								<label for="cl-format"><?php esc_html_e( 'Decoy Response Format', 'crawler-labyrinth' ); ?></label>
								<select id="cl-format" name="crawler_labyrinth_response_format" class="cl-input">
									<option value="html" <?php selected( (string) $settings['response_format'], 'html' ); ?>><?php esc_html_e( 'Semantic HTML5 (interlinked archival folios)', 'crawler-labyrinth' ); ?></option>
									<option value="plain" <?php selected( (string) $settings['response_format'], 'plain' ); ?>><?php esc_html_e( 'Plain Text (minimal bandwidth)', 'crawler-labyrinth' ); ?></option>
								</select>
							</div>
							<div class="cl-grid-2">
								<div class="cl-form-group">
									<label for="cl-links"><?php esc_html_e( 'Footer Bait Links per Page (1-5)', 'crawler-labyrinth' ); ?></label>
									<input type="number" min="1" max="5" id="cl-links" name="crawler_labyrinth_link_count" class="cl-input" value="<?php echo esc_attr( (string) $settings['link_count'] ); ?>" />
								</div>
								<div class="cl-form-group">
									<label for="cl-depth"><?php esc_html_e( 'Max Labyrinth Ring Depth (1-16)', 'crawler-labyrinth' ); ?></label>
									<input type="number" min="1" max="16" id="cl-depth" name="crawler_labyrinth_max_depth" class="cl-input" value="<?php echo esc_attr( (string) $settings['max_depth'] ); ?>" />
								</div>
								<div class="cl-form-group">
									<label for="cl-strikes"><?php esc_html_e( 'Hybrid Strike Threshold (1-100)', 'crawler-labyrinth' ); ?></label>
									<input type="number" min="1" max="100" id="cl-strikes" name="crawler_labyrinth_strike_threshold" class="cl-input" value="<?php echo esc_attr( (string) $settings['strike_threshold'] ); ?>" />
								</div>
								<div class="cl-form-group">
									<label for="cl-ttl"><?php esc_html_e( 'Quarantine TTL in Minutes (5-10080)', 'crawler-labyrinth' ); ?></label>
									<input type="number" min="5" max="10080" id="cl-ttl" name="crawler_labyrinth_block_ttl_minutes" class="cl-input" value="<?php echo esc_attr( (string) $settings['block_ttl_minutes'] ); ?>" />
								</div>
								<div class="cl-form-group">
									<label for="cl-status"><?php esc_html_e( 'Quarantine HTTP Status Code', 'crawler-labyrinth' ); ?></label>
									<select id="cl-status" name="crawler_labyrinth_block_status" class="cl-input">
										<option value="429" <?php selected( (int) $settings['block_status'], 429 ); ?>><?php esc_html_e( '429 Too Many Requests (with Retry-After)', 'crawler-labyrinth' ); ?></option>
										<option value="403" <?php selected( (int) $settings['block_status'], 403 ); ?>><?php esc_html_e( '403 Forbidden', 'crawler-labyrinth' ); ?></option>
									</select>
								</div>
								<div class="cl-form-group">
									<label for="cl-retention"><?php esc_html_e( 'User-Agent Retention Days (0 = keep, 1-365)', 'crawler-labyrinth' ); ?></label>
									<input type="number" min="0" max="365" id="cl-retention" name="crawler_labyrinth_retention_days" class="cl-input" value="<?php echo esc_attr( (string) $settings['retention_days'] ); ?>" />
								</div>
							</div>
						</fieldset>

						<button type="submit" class="cl-btn cl-btn-primary">
							<?php esc_html_e( 'Save Labyrinth Settings', 'crawler-labyrinth' ); ?>
						</button>
					</form>
				</div>
			</section>

			<?php elseif ( 'preview' === $active_tab ) : ?>
			<?php
			$sample_decoy  = ( new DecoyGenerator() )->generate(
				1,
				'archpreview1',
				(string) $settings['response_format'],
				(string) $settings['route_prefix'],
				(int) $settings['max_depth'],
				3
			);
			$sample_robots = RobotsInjector::inject(
				"User-agent: OAI-SearchBot\nAllow: /\n\nUser-agent: *\nAllow: /\n",
				(string) $settings['route_prefix']
			);
			?>
			<section aria-labelledby="cl-preview-heading">
				<div class="cl-card">
					<h2 id="cl-preview-heading"><?php esc_html_e( 'Sample Synthetic Archival Entry', 'crawler-labyrinth' ); ?></h2>
					<pre class="cl-code-block"><?php echo esc_html( $sample_decoy['body'] ); ?></pre>
				</div>
				<div class="cl-card">
					<h2><?php esc_html_e( 'RFC 9309 robots.txt Group Injection Preview', 'crawler-labyrinth' ); ?></h2>
					<pre class="cl-code-block"><?php echo esc_html( $sample_robots ); ?></pre>
				</div>
			</section>

			<?php elseif ( 'security' === $active_tab ) : ?>
			<section aria-labelledby="cl-security-heading">
				<div class="cl-card">
					<h2 id="cl-security-heading"><?php esc_html_e( 'Privacy, REST API &amp; WP-CLI Reference', 'crawler-labyrinth' ); ?></h2>
					<p class="cl-subtitle">
						<?php esc_html_e( 'All decoy generation is 100% local with zero outbound proxy calls. Visitor IP addresses are hashed with a daily rotating HMAC-SHA256 key and never stored in plaintext. Scheduled retention pseudonymizes User-Agent excerpts while preserving hash-chain verification.', 'crawler-labyrinth' ); ?>
					</p>
					<h3><?php esc_html_e( 'WP-CLI Commands', 'crawler-labyrinth' ); ?></h3>
					<pre class="cl-code-block">wp crawler-labyrinth status
wp crawler-labyrinth preview --depth=2 --seed=archpreview1 --format=plain
wp crawler-labyrinth verify_chain
wp crawler-labyrinth purge_expired --dry-run</pre>
					<h3><?php esc_html_e( 'REST API Endpoints (manage_options required)', 'crawler-labyrinth' ); ?></h3>
					<pre class="cl-code-block">GET  /wp-json/crawler-labyrinth/v1/status
POST /wp-json/crawler-labyrinth/v1/preview
POST /wp-json/crawler-labyrinth/v1/verify-chain</pre>
				</div>
			</section>
			<?php endif; ?>
		</div>
		<?php
	}
}
