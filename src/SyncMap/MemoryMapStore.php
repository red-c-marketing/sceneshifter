<?php
/**
 * A sync map held in memory, for tests.
 *
 * @package RedC\Sceneshifter
 */

declare(strict_types=1);

namespace RedC\Sceneshifter\SyncMap;

final class MemoryMapStore implements MapStore {

	/** @var array<string, MapEntry> by "sister|type|local" */
	public array $entries = array();

	public function get( string $sister, string $objectType, int $localId ): ?MapEntry {
		return $this->entries[ "{$sister}|{$objectType}|{$localId}" ] ?? null;
	}

	public function byRemote( string $sister, string $objectType, int $remoteId ): ?MapEntry {
		foreach ( $this->entries as $entry ) {
			if ( $entry->sister === $sister && $entry->objectType === $objectType && $entry->remoteId === $remoteId ) {
				return $entry;
			}
		}
		return null;
	}

	public function save( MapEntry $entry ): void {
		foreach ( $this->entries as $key => $other ) {
			if ( $other->sister === $entry->sister && $other->objectType === $entry->objectType && $other->remoteId === $entry->remoteId && $other->localId !== $entry->localId ) {
				unset( $this->entries[ $key ] );
			}
		}
		$this->entries[ "{$entry->sister}|{$entry->objectType}|{$entry->localId}" ] = $entry;
	}

	public function delete( string $sister, string $objectType, int $localId ): void {
		unset( $this->entries[ "{$sister}|{$objectType}|{$localId}" ] );
	}

	public function linked( string $sister, string $objectType ): array {
		$linked = array();
		foreach ( $this->entries as $entry ) {
			if ( $entry->sister === $sister && $entry->objectType === $objectType ) {
				$linked[ $entry->localId ] = $entry->remoteId;
			}
		}
		return $linked;
	}

	public function count( string $sister ): int {
		return count( array_filter( $this->entries, static fn( MapEntry $entry ): bool => $entry->sister === $sister ) );
	}
}
