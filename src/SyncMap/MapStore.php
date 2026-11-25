<?php
/**
 * Where this site records which of its objects match which objects on each
 * sister.
 *
 * @package RedC\Sceneshifter
 */

declare(strict_types=1);

namespace RedC\Sceneshifter\SyncMap;

interface MapStore {

	public function get( string $sister, string $objectType, int $localId ): ?MapEntry;

	public function byRemote( string $sister, string $objectType, int $remoteId ): ?MapEntry;

	/**
	 * Adds the entry, or replaces the one for the same local object.
	 */
	public function save( MapEntry $entry ): void;

	/**
	 * Local ID => remote ID for everything linked to a sister.
	 *
	 * @return array<int, int>
	 */
	public function linked( string $sister, string $objectType ): array;

	public function count( string $sister ): int;
}
