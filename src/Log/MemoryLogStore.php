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
		$id                   = ( array() === $this->entries ? 0 : max( array_keys( $this->entries ) ) ) + 1;
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

	public function recent( int $limit, int $offset = 0, ?LogFilter $filter = null ): array {
		return array_slice( $this->matching( $filter ), $offset, $limit );
	}

	public function total( ?LogFilter $filter = null ): int {
		return count( $this->matching( $filter ) );
	}

	/**
	 * @return list<LogEntry> Newest first.
	 */
	private function matching( ?LogFilter $filter ): array {
		$all = array_values( array_reverse( $this->entries ) );
		return null === $filter ? $all : array_values( array_filter( $all, array( $filter, 'matches' ) ) );
	}

	public function setResult( int $id, string $result, ?string $message ): void {
		if ( isset( $this->entries[ $id ] ) ) {
			$this->entries[ $id ] = $this->entries[ $id ]->withResult( $result, $message );
		}
	}

	public function setDetails( int $id, array $details ): void {
		if ( isset( $this->entries[ $id ] ) ) {
			$this->entries[ $id ] = $this->entries[ $id ]->withDetails( $details );
		}
	}

	public function prune( int $before ): int {
		$count         = count( $this->entries );
		$this->entries = array_filter( $this->entries, static fn( LogEntry $e ): bool => $e->at >= $before );
		return $count - count( $this->entries );
	}
}
