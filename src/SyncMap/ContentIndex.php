<?php
/**
 * The posts and attachments on this site that can be linked to a sister,
 * and their UUIDs.
 *
 * @package RedC\Sceneshifter
 */

declare(strict_types=1);

namespace RedC\Sceneshifter\SyncMap;

interface ContentIndex {

	/**
	 * Post types that can be linked: the enabled content types plus media.
	 *
	 * @return list<string>
	 */
	public function linkableTypes(): array;

	/**
	 * One page of a post type, oldest ID first.
	 *
	 * @return array{items: list<Item>, total: int}
	 */
	public function page( string $type, int $page, int $perPage ): array;

	/**
	 * A linkable post, or null when it doesn't exist, is in the bin or is
	 * not of a linkable type.
	 */
	public function get( int $id ): ?Item;

	/**
	 * IDs of every post that has this UUID.
	 *
	 * @return list<int>
	 */
	public function uuidOwners( string $uuid ): array;

	public function setUuid( int $id, string $uuid ): void;
}
