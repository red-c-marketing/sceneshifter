<?php
/**
 * Brings this site's database up to date: custom tables and capabilities.
 *
 * Runs on activation and, because must-use plugins never activate, on every
 * load when the stored versions are behind. On multisite it runs for each
 * subsite the first time that subsite loads, since options and the table
 * prefix are per subsite. Safe to run more than once.
 *
 * @package RedC\Sceneshifter
 */

declare(strict_types=1);

namespace RedC\Sceneshifter;

use RedC\Sceneshifter\SiteScan\Schema;
use RedC\Sceneshifter\Store\DbNonceStore;

final class Upgrade {

	/** Bump when a table changes or a new one is added. */
	public const VERSION = '2';
	public const OPTION  = 'sceneshifter_schema_version';

	/**
	 * Cheap enough for every load: two autoloaded option reads.
	 */
	public static function maybeRun(): void {
		if ( get_option( self::OPTION ) !== self::VERSION ) {
			self::run();
		}
		if ( get_option( Plugin::CAPS_VERSION_OPTION ) !== Plugin::CAPS_VERSION ) {
			Plugin::grantCapabilities();
		}
	}

	public static function run(): void {
		Schema::install();
		DbNonceStore::install();
		update_option( self::OPTION, self::VERSION, true );
	}
}
