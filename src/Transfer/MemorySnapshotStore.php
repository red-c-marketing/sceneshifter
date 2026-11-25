<?php
/**
 * Snapshots in memory, for tests.
 *
 * @package RedC\Sceneshifter
 */

declare(strict_types=1);

namespace RedC\Sceneshifter\Transfer;

final class MemorySnapshotStore implements SnapshotStore {

	/** @var array<int, Snapshot> */
	public array $snapshots = array();

	public function save( Snapshot $snapshot ): int {
		$id                     = count( $this->snapshots ) + 1;
		$this->snapshots[ $id ] = $snapshot->withId( $id );
		return $id;
	}

	public function get( int $id ): ?Snapshot {
		return $this->snapshots[ $id ] ?? null;
	}

	public function forTransfer( string $transfer, int $postId ): ?Snapshot {
		foreach ( $this->snapshots as $snapshot ) {
			if ( $snapshot->transfer === $transfer && $snapshot->postId === $postId ) {
				return $snapshot;
			}
		}
		return null;
	}
}
