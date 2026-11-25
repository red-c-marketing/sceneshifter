<?php
/**
 * Nonce store in a custom table, so a captured request can't be replayed
 * against any PHP process on this site. The table uses this site's table
 * prefix, so each subsite of a multisite has its own.
 *
 * @package RedC\Sceneshifter
 */

declare(strict_types=1);

namespace RedC\Sceneshifter\Store;

use RedC\Sceneshifter\Crypto\NonceStore;

final class DbNonceStore implements NonceStore {

	/** Roughly one call in this many also clears out expired rows. */
	public const PURGE_ONE_IN = 20;

	/** @var callable(): int */
	private $now;

	/** @var callable(): bool */
	private $shouldPurge;

	/**
	 * @param \wpdb                $db          Database connection.
	 * @param string               $table       Full table name, with prefix.
	 * @param (callable(): int)|null  $now         Current Unix time; for tests.
	 * @param (callable(): bool)|null $shouldPurge Whether to purge on this call; for tests.
	 */
	public function __construct(
		private readonly \wpdb $db,
		private readonly string $table,
		?callable $now = null,
		?callable $shouldPurge = null
	) {
		$this->now         = $now ?? 'time';
		$this->shouldPurge = $shouldPurge ?? static fn(): bool => 1 === random_int( 1, self::PURGE_ONE_IN );
	}

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'sceneshifter_nonces';
	}

	public static function forSite(): self {
		global $wpdb;
		return new self( $wpdb, self::table() );
	}

	public function remember( string $senderId, string $nonce, int $expiresAt ): bool {
		if ( ( $this->shouldPurge )() ) {
			$this->purge();
		}

		// The primary key makes this atomic: of two identical requests
		// racing, only one insert succeeds.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
		$inserted = $this->db->query( $this->db->prepare( "INSERT IGNORE INTO {$this->table} (sender, nonce, expires_at) VALUES (%s, %s, %d)", $senderId, $nonce, $expiresAt ) );
		return 1 === $inserted;
	}

	/**
	 * Deletes nonces whose window has passed. Requests that old are refused
	 * as stale anyway, so forgetting them is safe.
	 */
	public function purge(): void {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
		$this->db->query( $this->db->prepare( "DELETE FROM {$this->table} WHERE expires_at < %d LIMIT 1000", ( $this->now )() ) );
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
				sender varchar(32) NOT NULL,
				nonce varchar(64) NOT NULL,
				expires_at bigint(20) unsigned NOT NULL,
				PRIMARY KEY  (sender, nonce),
				KEY expires_at (expires_at)
			) {$charset};"
		);
	}

	public static function uninstall(): void {
		global $wpdb;
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::table() ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- table name only.
	}
}
