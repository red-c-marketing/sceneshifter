<?php
/**
 * Writes posts on the target of a push. WpPostWriter is the WordPress one.
 *
 * @package RedC\Sceneshifter
 */

declare(strict_types=1);

namespace RedC\Sceneshifter\Transfer;

interface PostWriter {

	/**
	 * Everything needed to put the post back as it is now: its fields, all
	 * its meta as stored, and its terms.
	 *
	 * @return array<string, mixed>
	 */
	public function snapshot( int $id ): array;

	/**
	 * Creates the post (null ID) or overwrites it from a package already
	 * rewritten for this site. Throws when WordPress refuses.
	 *
	 * @param array{status: string, author: int|null, keep_date: bool, create_terms: bool} $options
	 * @return int The post ID.
	 * @throws \RuntimeException When the post can't be written.
	 */
	public function write( ?int $id, Package $package, array $options ): int;

	/**
	 * @param array<string, mixed> $snapshot From snapshot().
	 */
	public function restore( int $id, array $snapshot ): void;

	/**
	 * Removes a post the push created.
	 *
	 * @param bool $trash Move it to the bin rather than delete it.
	 */
	public function discard( int $id, bool $trash ): void;

	/**
	 * @return array{edit: string, view: string}
	 */
	public function urls( int $id ): array;

	/**
	 * The held update waiting for a post, if there is one.
	 */
	public function heldFor( int $postId ): ?int;

	/**
	 * Writes a pushed update as a separate copy waiting for review, leaving
	 * the post itself alone: pending, with no address or Sceneshifter ID of
	 * its own, so nothing matches it in place of the post.
	 *
	 * @param int                  $postId The post the update is for.
	 * @param int|null             $heldId The copy to overwrite, or null for a new one.
	 * @param array<string, mixed> $record What applying it needs: transfer, sister, package, options.
	 * @return int The copy's ID.
	 * @throws \RuntimeException When it can't be written.
	 */
	public function hold( int $postId, ?int $heldId, Package $package, array $record ): int;

	/**
	 * @return array{post: int, record: array<string, mixed>}|null The post a copy is for and what hold() stored.
	 */
	public function held( int $heldId ): ?array;
}
