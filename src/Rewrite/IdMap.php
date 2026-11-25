<?php
/**
 * Source-to-target IDs for attachments and posts, as worked out by the
 * resolver (sync map UUIDs, then path, hash or slug).
 *
 * @package RedC\Sceneshifter
 */

declare(strict_types=1);

namespace RedC\Sceneshifter\Rewrite;

final class IdMap {

	/**
	 * @param array<int, int> $attachments Source attachment ID => target ID.
	 * @param array<int, int> $posts       Source post ID => target ID.
	 */
	public function __construct(
		private readonly array $attachments = array(),
		private readonly array $posts = array()
	) {}

	public function get( string $kind, int $id ): ?int {
		$map = Rewrite::ATTACHMENT === $kind ? $this->attachments : $this->posts;
		return $map[ $id ] ?? null;
	}
}
