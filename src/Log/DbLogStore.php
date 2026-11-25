<?php
/**
 * Log entries in a custom table. As with the sync map, rows written by
 * another site (in a copied database) are ignored.
 *
 * @package RedC\Sceneshifter
 */

declare(strict_types=1);

namespace RedC\Sceneshifter\Log;

final class DbLogStore implements LogStore {

	public function __construct(
		private readonly \wpdb $db,
		private readonly string $table,
		private readonly string $selfId
	) {}

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'sceneshifter_log';
	}

	public static function forSite( string $selfId ): self {
		global $wpdb;
		return new self( $wpdb, self::table(), $selfId );
	}

	public function add( LogEntry $entry ): int {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->db->insert(
			$this->table,
			array(
				'site'      => $this->selfId,
				'transfer'  => $entry->transfer,
				'direction' => $entry->direction,
				'sister'    => $entry->sister,
				'user_name' => $entry->user,
				'source_id' => $entry->sourceId,
				'target_id' => $entry->targetId,
				'title'     => $entry->title,
				'result'    => $entry->result,
				'created'   => $entry->created ? 1 : 0,
				'message'   => $entry->message,
				'details'   => (string) wp_json_encode( $entry->details ),
				'at'        => $entry->at,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%d' )
		);
		return (int) $this->db->insert_id;
	}

	public function get( int $id ): ?LogEntry {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
		$row = $this->db->get_row( $this->db->prepare( "SELECT * FROM {$this->table} WHERE site = %s AND id = %d", $this->selfId, $id ), ARRAY_A );
		return is_array( $row ) ? self::entry( $row ) : null;
	}

	public function find( string $transfer, string $direction, string $sister, int $targetId ): ?LogEntry {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
		$row = $this->db->get_row( $this->db->prepare( "SELECT * FROM {$this->table} WHERE site = %s AND transfer = %s AND direction = %s AND sister = %s AND target_id = %d ORDER BY id DESC LIMIT 1", $this->selfId, $transfer, $direction, $sister, $targetId ), ARRAY_A );
		return is_array( $row ) ? self::entry( $row ) : null;
	}

	public function recent( int $limit, int $offset = 0 ): array {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
		$rows = $this->db->get_results( $this->db->prepare( "SELECT * FROM {$this->table} WHERE site = %s ORDER BY id DESC LIMIT %d OFFSET %d", $this->selfId, $limit, $offset ), ARRAY_A );
		return array_map( array( self::class, 'entry' ), is_array( $rows ) ? $rows : array() );
	}

	public function total(): int {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
		return (int) $this->db->get_var( $this->db->prepare( "SELECT COUNT(*) FROM {$this->table} WHERE site = %s", $this->selfId ) );
	}

	public function setResult( int $id, string $result, ?string $message ): void {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->db->update(
			$this->table,
			array(
				'result'  => $result,
				'message' => $message,
			),
			array(
				'site' => $this->selfId,
				'id'   => $id,
			),
			array( '%s', '%s' ),
			array( '%s', '%d' )
		);
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private static function entry( array $row ): LogEntry {
		$details = json_decode( (string) $row['details'], true );
		return new LogEntry(
			(int) $row['id'],
			(string) $row['transfer'],
			(string) $row['direction'],
			(string) $row['sister'],
			(string) $row['user_name'],
			(int) $row['source_id'],
			null === $row['target_id'] || 0 === (int) $row['target_id'] ? null : (int) $row['target_id'],
			(string) $row['title'],
			(string) $row['result'],
			(bool) $row['created'],
			null === $row['message'] ? null : (string) $row['message'],
			is_array( $details ) ? $details : array(),
			(int) $row['at']
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
				direction varchar(3) NOT NULL,
				sister varchar(32) NOT NULL,
				user_name varchar(250) NOT NULL DEFAULT '',
				source_id bigint(20) unsigned NOT NULL,
				target_id bigint(20) unsigned DEFAULT NULL,
				title text NOT NULL,
				result varchar(10) NOT NULL,
				created tinyint(1) NOT NULL DEFAULT 0,
				message text,
				details longtext NOT NULL,
				at bigint(20) unsigned NOT NULL,
				PRIMARY KEY  (id),
				KEY site_id (site,id),
				KEY transfer (transfer)
			) {$charset};"
		);
	}
}
