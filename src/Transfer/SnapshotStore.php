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
}
