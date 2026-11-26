<?php
/**
 * Where snapshots are kept.
 *
 * @package RedC\Sceneshifter
 */

declare(strict_types=1);

namespace RedC\Sceneshifter\Transfer;

interface SnapshotStore {

	/**
	 * @return int The new snapshot's ID.
	 */
	public function save( Snapshot $snapshot ): int;

	public function get( int $id ): ?Snapshot;

	public function forTransfer( string $transfer, int $postId ): ?Snapshot;

	/**
	 * Removes snapshots taken before a time, except each post's newest few.
	 *
	 * @param int $before     Unix time.
	 * @param int $keepNewest How many of each post's newest snapshots to keep however old.
	 * @return int How many were removed.
	 */
	public function prune( int $before, int $keepNewest ): int;
}
