<?php
/**
 * What the resolver asks of the target site's database.
 *
 * @package RedC\Sceneshifter
 */

declare(strict_types=1);

namespace RedC\Sceneshifter\Transfer;

interface TargetLookup {

	/**
	 * The one post (or attachment) with this UUID, or null when none or several have it.
	 *
	 * @return array{id: int, type: string}|null
	 */
	public function byUuid( string $uuid ): ?array;

	/**
	 * Posts of a type at a path (page URI, or slug for flat types), not in the bin.
	 *
	 * @return list<int>
	 */
	public function byPath( string $type, string $path ): array;

	/**
	 * Posts of a type with a slug, not in the bin.
	 *
	 * @return list<int>
	 */
	public function bySlug( string $type, string $slug ): array;

	/**
	 * Attachments whose file under uploads is exactly this path.
	 *
	 * @return list<array{id: int, size: int|null}>
	 */
	public function mediaByFile( string $file ): array;

	/**
	 * Attachments whose file name (in any folder) is this.
	 *
	 * @return list<array{id: int, size: int|null}>
	 */
	public function mediaByName( string $name ): array;

	/**
	 * An attachment's file, relative to the uploads folder; '' if unknown.
	 */
	public function fileOf( int $id ): string;

	public function termBySlug( string $taxonomy, string $slug ): ?int;

	/**
	 * Whether a post (or, with $attachment, an attachment) is still here and
	 * not in the bin, so a sync map link to it can be trusted.
	 */
	public function exists( int $id, bool $attachment ): bool;

	/**
	 * @return array{id: int, name: string}|null
	 */
	public function userByEmail( string $email ): ?array;
}
