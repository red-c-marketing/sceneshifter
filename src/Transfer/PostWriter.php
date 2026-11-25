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
}
