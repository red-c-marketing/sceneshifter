<?php
/**
 * Works out the plugin's URL when it is a symlinked must-use plugin.
 *
 * @package RedC\SisterSync
 */

declare(strict_types=1);

namespace RedC\SisterSync;

final class PluginUrl {

	/**
	 * Returns the plugin's URL when it is reached through a symlink inside
	 * the must-use plugins directory, or null when WordPress can work the
	 * URL out itself.
	 *
	 * PHP reports a symlinked file at the link's target, for example
	 * /Volumes/Development/sister-sync, and WordPress can't turn that into a
	 * URL. It maps symlinked normal plugins back to their link, but not
	 * must-use ones, so this finds the link and builds the URL from it.
	 *
	 * @param string $pluginDir  The plugin's own directory (__DIR__).
	 * @param string $pluginsDir WP_PLUGIN_DIR.
	 * @param string $muDir      WPMU_PLUGIN_DIR.
	 * @param string $muUrl      WPMU_PLUGIN_URL.
	 */
	public static function forMustUseSymlink( string $pluginDir, string $pluginsDir, string $muDir, string $muUrl ): ?string {
		$real = self::real( $pluginDir );
		if ( null === $real ) {
			return null;
		}

		// Inside either plugins directory, no symlink is involved.
		foreach ( array( $pluginsDir, $muDir ) as $dir ) {
			$base = self::real( $dir );
			if ( null !== $base && str_starts_with( $real . '/', $base . '/' ) ) {
				return null;
			}
		}

		$entries = is_dir( $muDir ) ? scandir( $muDir ) : false;
		if ( false === $entries ) {
			return null;
		}
		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$link = rtrim( $muDir, '/\\' ) . '/' . $entry;
			if ( is_link( $link ) && self::real( $link ) === $real ) {
				return rtrim( $muUrl, '/' ) . '/' . rawurlencode( $entry ) . '/';
			}
		}

		return null;
	}

	private static function real( string $path ): ?string {
		$real = realpath( $path );
		return false === $real ? null : rtrim( str_replace( '\\', '/', $real ), '/' );
	}
}
