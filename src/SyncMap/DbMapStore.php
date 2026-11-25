<?php
/**
 * The sync map in a custom table.
 *
 * Each row also records which site wrote it. A database copied from another
 * environment brings that environment's rows along, and those describe its
 * links, not this site's, so they are ignored.
 *
 * @package RedC\Sceneshifter
 */

declare(strict_types=1);

namespace RedC\Sceneshifter\SyncMap;

final class DbMapStore implements MapStore {

	/**
	 * @param \wpdb  $db     Database connection.
	 * @param string $table  Full table name, with prefix.
	 * @param string $selfId This site's registry ID.
	 */
	public function __construct(
		private readonly \wpdb $db,
		private readonly string $table,
		private readonly string $selfId
	) {}

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'sceneshifter_map';
	}

	public static function forSite( string $selfId ): self {
		global $wpdb;
		return new self( $wpdb, self::table(), $selfId );
	}

	public function get( string $sister, string $objectType, int $localId ): ?MapEntry {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
		$row = $this->db->get_row( $this->db->prepare( "SELECT * FROM {$this->table} WHERE site = %s AND sister = %s AND object_type = %s AND local_id = %d", $this->selfId, $sister, $objectType, $localId ), ARRAY_A );
		return is_array( $row ) ? self::entry( $row ) : null;
	}

	public function byRemote( string $sister, string $objectType, int $remoteId ): ?MapEntry {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
		$row = $this->db->get_row( $this->db->prepare( "SELECT * FROM {$this->table} WHERE site = %s AND sister = %s AND object_type = %s AND remote_id = %d", $this->selfId, $sister, $objectType, $remoteId ), ARRAY_A );
		return is_array( $row ) ? self::entry( $row ) : null;
	}

	public function save( MapEntry $entry ): void {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
		$this->db->query(
			$this->db->prepare(
				"INSERT INTO {$this->table} (site, sister, object_type, local_id, remote_id, uuid, hash, synced_at, linked_at)
				VALUES (%s, %s, %s, %d, %d, %s, NULLIF(%s, ''), NULLIF(%d, 0), %d)
				ON DUPLICATE KEY UPDATE remote_id = VALUES(remote_id), uuid = VALUES(uuid), hash = VALUES(hash), synced_at = VALUES(synced_at), linked_at = VALUES(linked_at)",
				$this->selfId,
				$entry->sister,
				$entry->objectType,
				$entry->localId,
				$entry->remoteId,
				$entry->uuid,
				(string) $entry->hash,
				(int) $entry->syncedAt,
				$entry->linkedAt
			)
		);
	}

	public function linked( string $sister, string $objectType ): array {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
		$rows   = $this->db->get_results( $this->db->prepare( "SELECT local_id, remote_id FROM {$this->table} WHERE site = %s AND sister = %s AND object_type = %s", $this->selfId, $sister, $objectType ), ARRAY_A );
		$linked = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$linked[ (int) $row['local_id'] ] = (int) $row['remote_id'];
		}
		return $linked;
	}

	public function count( string $sister ): int {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
		return (int) $this->db->get_var( $this->db->prepare( "SELECT COUNT(*) FROM {$this->table} WHERE site = %s AND sister = %s", $this->selfId, $sister ) );
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private static function entry( array $row ): MapEntry {
		return new MapEntry(
			(string) $row['uuid'],
			(string) $row['object_type'],
			(int) $row['local_id'],
			(string) $row['sister'],
			(int) $row['remote_id'],
			(int) $row['linked_at'],
			null === $row['hash'] ? null : (string) $row['hash'],
			null === $row['synced_at'] ? null : (int) $row['synced_at']
		);
	}

	/**
	 * Creates or updates the table. Called from the upgrade routine.
	 */
	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		// dbDelta needs two spaces after PRIMARY KEY and one field per line.
		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				site varchar(32) NOT NULL,
				sister varchar(32) NOT NULL,
				object_type varchar(10) NOT NULL,
				local_id bigint(20) unsigned NOT NULL,
				remote_id bigint(20) unsigned NOT NULL,
				uuid char(36) NOT NULL,
				hash char(64) DEFAULT NULL,
				synced_at bigint(20) unsigned DEFAULT NULL,
				linked_at bigint(20) unsigned NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY local_object (site,sister,object_type,local_id),
				UNIQUE KEY remote_object (site,sister,object_type,remote_id),
				KEY uuid (uuid)
			) {$charset};"
		);
	}

	public static function uninstall(): void {
		global $wpdb;
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::table() ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- table name only.
	}
}
