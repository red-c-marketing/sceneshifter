<?php
/**
 * Log entries in memory, for tests.
 *
 * @package RedC\Sceneshifter
 */

declare(strict_types=1);

namespace RedC\Sceneshifter\Log;

final class MemoryLogStore implements LogStore {

	/** @var array<int, LogEntry> */
	public array $entries = array();

	public function add( LogEntry $entry ): int {
		$id                   = count( $this->entries ) + 1;
		$this->entries[ $id ] = $entry->withId( $id );
		return $id;
	}

	public function get( int $id ): ?LogEntry {
		return $this->entries[ $id ] ?? null;
	}

	public function find( string $transfer, string $direction, string $sister, int $targetId ): ?LogEntry {
		foreach ( array_reverse( $this->entries ) as $entry ) {
			if ( $entry->transfer === $transfer && $entry->direction === $direction && $entry->sister === $sister && $entry->targetId === $targetId ) {
				return $entry;
			}
		}
		return null;
	}

	public function recent( int $limit, int $offset = 0 ): array {
		return array_slice( array_values( array_reverse( $this->entries ) ), $offset, $limit );
	}

	public function total(): int {
		return count( $this->entries );
	}

	public function setResult( int $id, string $result, ?string $message ): void {
		if ( isset( $this->entries[ $id ] ) ) {
			$this->entries[ $id ] = $this->entries[ $id ]->withResult( $result, $message );
		}
	}
}
