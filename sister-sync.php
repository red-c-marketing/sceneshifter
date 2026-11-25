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

// A must-use loader can set SISTER_SYNC_URL itself. Otherwise a symlinked
// must-use copy is mapped back to its link, which plugin_dir_url() can't do.
if ( ! defined( 'SISTER_SYNC_URL' ) ) {
	define(
		'SISTER_SYNC_URL',
		\RedC\SisterSync\PluginUrl::forMustUseSymlink( __DIR__, WP_PLUGIN_DIR, WPMU_PLUGIN_DIR, plugins_url( '', WPMU_PLUGIN_DIR . '/index.php' ) )
			?? plugin_dir_url( __FILE__ )
	);
}

// Action Scheduler runs the background site scan. It must be loaded while
// plugins load; when several plugins bundle it, the newest copy wins. Without
// it the scan still runs, but only while the report page is open.
//
// Our own vendor directory has it in development. When a site installs this
// plugin with Composer (Bedrock), the site's Composer puts Action Scheduler
// wherever its installer paths say, usually as an inactive plugin, so ask
// Composer where that is.
( static function (): void {
	$candidates = array( SISTER_SYNC_DIR . 'vendor/woocommerce/action-scheduler' );
	if ( class_exists( \Composer\InstalledVersions::class ) && \Composer\InstalledVersions::isInstalled( 'woocommerce/action-scheduler' ) ) {
		$candidates[] = (string) \Composer\InstalledVersions::getInstallPath( 'woocommerce/action-scheduler' );
	}
	foreach ( $candidates as $dir ) {
		if ( '' !== $dir && is_readable( $dir . '/action-scheduler.php' ) ) {
			require_once $dir . '/action-scheduler.php';
			return;
		}
	}
} )();

register_activation_hook( __FILE__, array( \RedC\SisterSync\Plugin::class, 'activate' ) );

add_action(
	'plugins_loaded',
	static function (): void {
		\RedC\SisterSync\Plugin::instance()->boot();
	}
);
