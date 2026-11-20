<?php
/**
 * The scan results table.
 *
 * @package RedC\SisterSync
 */

declare(strict_types=1);

namespace RedC\SisterSync\SiteScan;

final class Schema {

	public const DB_VERSION        = '1';
	public const DB_VERSION_OPTION = 'sister_sync_db_version';

	public static function resultsTable(): string {
		global $wpdb;
		return $wpdb->prefix . 'sistersync_scan_results';
	}

	/**
	 * Creates or updates the table when the stored version is behind. Cheap
	 * enough to call on every load: one autoloaded option read.
	 */
	public static function maybeInstall(): void {
		if ( get_option( self::DB_VERSION_OPTION ) === self::DB_VERSION ) {
			return;
		}
		self::install();
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::resultsTable();
		$charset = $wpdb->get_charset_collate();

		// dbDelta needs two spaces after PRIMARY KEY and one field per line.
		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				scan_id varchar(36) NOT NULL,
				object_type varchar(20) NOT NULL,
				object_subtype varchar(40) NOT NULL DEFAULT '',
				object_id bigint(20) unsigned NOT NULL DEFAULT 0,
				object_key varchar(191) NOT NULL DEFAULT '',
				object_title text NOT NULL,
				severity varchar(10) NOT NULL,
				sister_id varchar(32) NOT NULL,
				url text NOT NULL,
				area varchar(20) NOT NULL,
				location text NOT NULL,
				hits int(10) unsigned NOT NULL DEFAULT 1,
				PRIMARY KEY  (id),
				KEY scan_object (scan_id, object_type, object_id),
				KEY scan_sister (scan_id, sister_id),
				KEY scan_severity (scan_id, severity)
			) {$charset};"
		);

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, true );
	}

	public static function uninstall(): void {
		global $wpdb;
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::resultsTable() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name only.
		delete_option( self::DB_VERSION_OPTION );
	}
}
