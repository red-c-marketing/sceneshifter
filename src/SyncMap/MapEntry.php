<?php
/**
 * One row of the sync map: a local object, the same object's ID on one
 * sister, and what was known at the last transfer.
 *
 * @package RedC\Sceneshifter
 */

declare(strict_types=1);

namespace RedC\Sceneshifter\SyncMap;

final class MapEntry {

	public const POST = 'post';
	public const TERM = 'term';

	/**
	 * @param string      $uuid       The shared UUID.
	 * @param string      $objectType POST or TERM.
	 * @param int         $localId    ID on this site.
	 * @param string      $sister     The sister's registry ID.
	 * @param int         $remoteId   ID on the sister.
	 * @param int         $linkedAt   When the two were linked.
	 * @param string|null $hash       Content hash after the last transfer; null when never transferred.
	 * @param int|null    $syncedAt   Time of the last transfer.
	 */
	public function __construct(
		public readonly string $uuid,
		public readonly string $objectType,
		public readonly int $localId,
		public readonly string $sister,
		public readonly int $remoteId,
		public readonly int $linkedAt,
		public readonly ?string $hash = null,
		public readonly ?int $syncedAt = null
	) {}
}
