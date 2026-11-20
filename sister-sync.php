<?php
/**
 * Plugin Name:       Sister Sync
 * Description:       Finds links to sister environments (dev, staging, live) so staging URLs never reach live. Push and pull between sisters follows in a later version.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Red C
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       sister-sync
 *
 * @package RedC\SisterSync
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

define( 'SISTER_SYNC_VERSION', '0.1.0' );
define( 'SISTER_SYNC_FILE', __FILE__ );
define( 'SISTER_SYNC_DIR', plugin_dir_path( __FILE__ ) );
define( 'SISTER_SYNC_URL', plugin_dir_url( __FILE__ ) );

// Composer autoloader when present (development). Phase 0 has no runtime
// dependencies, so a plain PSR-4 fallback keeps production installs working
// without a vendor directory.
if ( is_readable( SISTER_SYNC_DIR . 'vendor/autoload.php' ) ) {
	require_once SISTER_SYNC_DIR . 'vendor/autoload.php';
} else {
	spl_autoload_register(
		static function ( string $class ): void {
			$prefix = 'RedC\\SisterSync\\';
			if ( ! str_starts_with( $class, $prefix ) ) {
				return;
			}
			$file = SISTER_SYNC_DIR . 'src/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
			if ( is_readable( $file ) ) {
				require_once $file;
			}
		}
	);
}

// Action Scheduler runs the background site scan. It must be loaded while
// plugins load; when several plugins bundle it, the newest copy wins. Without
// it the scan still runs, but only while the report page is open.
if ( is_readable( SISTER_SYNC_DIR . 'vendor/woocommerce/action-scheduler/action-scheduler.php' ) ) {
	require_once SISTER_SYNC_DIR . 'vendor/woocommerce/action-scheduler/action-scheduler.php';
}

register_activation_hook( __FILE__, array( \RedC\SisterSync\Plugin::class, 'activate' ) );

add_action(
	'plugins_loaded',
	static function (): void {
		\RedC\SisterSync\Plugin::instance()->boot();
	}
);
