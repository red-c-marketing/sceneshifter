<?php
/**
 * Snapshots in a custom table. Like the sync map, each row records which
 * site wrote it, so snapshots in a database copied from another environment
 * are ignored.
 *
 * @package RedC\Sceneshifter
 */

declare(strict_types=1);

namespace RedC\Sceneshifter\Transfer;

final class DbSnapshotStore implements SnapshotStore {

	public function __construct(
		private readonly \wpdb $db,
		private readonly string $table,
		private readonly string $selfId
	) {}

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'sceneshifter_snapshots';
	}

	public static function forSite( string $selfId ): self {
		global $wpdb;
		return new self( $wpdb, self::table(), $selfId );
	}

	public function save( Snapshot $snapshot ): int {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->db->insert(
			$this->table,
			array(
				'site'     => $this->selfId,
				'transfer' => $snapshot->transfer,
				'sister'   => $snapshot->sister,
				'post_id'  => $snapshot->postId,
				'is_new'   => $snapshot->isNew ? 1 : 0,
				'data'     => (string) wp_json_encode( $snapshot->data ),
				'taken'    => $snapshot->taken,
			),
			array( '%s', '%s', '%s', '%d', '%d', '%s', '%d' )
		);
		return (int) $this->db->insert_id;
	}

	public function get( int $id ): ?Snapshot {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
		$row = $this->db->get_row( $this->db->prepare( "SELECT * FROM {$this->table} WHERE site = %s AND id = %d", $this->selfId, $id ), ARRAY_A );
		return is_array( $row ) ? self::snapshot( $row ) : null;
	}

	public function forTransfer( string $transfer, int $postId ): ?Snapshot {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
		$row = $this->db->get_row( $this->db->prepare( "SELECT * FROM {$this->table} WHERE site = %s AND transfer = %s AND post_id = %d ORDER BY id DESC LIMIT 1", $this->selfId, $transfer, $postId ), ARRAY_A );
		return is_array( $row ) ? self::snapshot( $row ) : null;
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private static function snapshot( array $row ): Snapshot {
		$data = json_decode( (string) $row['data'], true );
		return new Snapshot(
			(int) $row['id'],
			(string) $row['transfer'],
			(string) $row['sister'],
			(int) $row['post_id'],
			(bool) $row['is_new'],
			is_array( $data ) ? $data : array(),
			(int) $row['taken']
		);
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				site varchar(32) NOT NULL,
				transfer char(36) NOT NULL,
				sister varchar(32) NOT NULL,
				post_id bigint(20) unsigned NOT NULL,
				is_new tinyint(1) NOT NULL DEFAULT 0,
				data longtext NOT NULL,
				taken bigint(20) unsigned NOT NULL,
				PRIMARY KEY  (id),
				KEY transfer (transfer),
				KEY post (site,post_id)
			) {$charset};"
		);
	}

	public static function uninstall(): void {
		global $wpdb;
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::table() ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- table name only.
	}
}
