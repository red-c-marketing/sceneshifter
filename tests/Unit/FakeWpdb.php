<?php

declare(strict_types=1);

namespace RedC\SisterSync\Tests\Unit;

/**
 * Just enough of wpdb for DbNonceStore: INSERT IGNORE into a table with a
 * (sender, nonce) primary key, and DELETE of expired rows.
 */
final class FakeWpdb extends \wpdb {

	/** @var array<string, int> expires_at by "sender|nonce" */
	public array $rows = array();

	/** @var list<string> */
	public array $queries = array();

	/** @var list<mixed> */
	private array $lastArgs = array();

	public function prepare( $query, ...$args ) {
		$this->lastArgs = $args;
		return $query;
	}

	public function query( $query ) {
		$this->queries[] = $query;
		if ( str_starts_with( $query, 'INSERT IGNORE INTO wp_sistersync_nonces (sender, nonce, expires_at)' ) ) {
			[ $sender, $nonce, $expires ] = $this->lastArgs;
			$key = $sender . '|' . $nonce;
			if ( isset( $this->rows[ $key ] ) ) {
				return 0;
			}
			$this->rows[ $key ] = (int) $expires;
			return 1;
		}
		if ( str_starts_with( $query, 'DELETE FROM wp_sistersync_nonces WHERE expires_at < %d' ) ) {
			$before     = count( $this->rows );
			$now        = (int) $this->lastArgs[0];
			$this->rows = array_filter( $this->rows, static fn( int $expires ): bool => $expires >= $now );
			return $before - count( $this->rows );
		}
		return false;
	}
}
