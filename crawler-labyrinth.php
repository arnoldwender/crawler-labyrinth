<?php
/**
 * Plugin Name:       Crawler Labyrinth
 * Plugin URI:        https://github.com/arnoldwender/crawler-labyrinth
 * Description:       Local deterministic crawler tarpit and RFC 9309 robots.txt honeypot with hybrid HTTP 429/403 quarantine, HMAC-chained audit logging, and privacy-preserving daily IP digests.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Tested up to:      7.1
 * Requires PHP:      8.2
 * Author:            Arnold Wender
 * Author URI:        https://arnoldwender.com
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       crawler-labyrinth
 * Domain Path:       /languages
 *
 * @package CrawlerLabyrinth
 */

declare(strict_types=1);

namespace CrawlerLabyrinth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CRAWLER_LABYRINTH_VERSION', '1.0.0' );
define( 'CRAWLER_LABYRINTH_FILE', __FILE__ );
define( 'CRAWLER_LABYRINTH_PATH', plugin_dir_path( __FILE__ ) );
define( 'CRAWLER_LABYRINTH_URL', plugin_dir_url( __FILE__ ) );

spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix   = 'CrawlerLabyrinth\\';
		$base_dir = CRAWLER_LABYRINTH_PATH . 'includes/';

		$len = strlen( $prefix );
		if ( 0 !== strncmp( $prefix, $class_name, $len ) ) {
			return;
		}

		$relative_class = substr( $class_name, $len );
		$file           = $base_dir . str_replace( '\\', '/', $relative_class ) . '.php';

		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}
);

register_activation_hook(
	__FILE__,
	static function (): void {
		if ( version_compare( PHP_VERSION, '8.2', '<' ) ) {
			deactivate_plugins( plugin_basename( __FILE__ ) );
			wp_die( esc_html__( 'Crawler Labyrinth requires PHP 8.2 or higher.', 'crawler-labyrinth' ) );
		}

		Core\Activator::activate();
	}
);

register_deactivation_hook(
	__FILE__,
	static function (): void {
		Core\Activator::deactivate();
	}
);

add_action(
	'plugins_loaded',
	static function (): void {
		Core\Plugin::instance()->boot();
	}
);
