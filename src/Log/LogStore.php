<?php
/**
 * Where log entries are kept.
 *
 * @package RedC\Sceneshifter
 */

declare(strict_types=1);

namespace RedC\Sceneshifter\Log;

interface LogStore {

	/**
	 * @return int The new entry's ID.
	 */
	public function add( LogEntry $entry ): int;

	public function get( int $id ): ?LogEntry;

	/**
	 * The entry for a post in a transfer, in one direction.
	 */
	public function find( string $transfer, string $direction, string $sister, int $targetId ): ?LogEntry;

	/**
	 * Newest first.
	 *
	 * @return list<LogEntry>
	 */
	public function recent( int $limit, int $offset = 0 ): array;

	public function total(): int;

	public function setResult( int $id, string $result, ?string $message ): void;
}
