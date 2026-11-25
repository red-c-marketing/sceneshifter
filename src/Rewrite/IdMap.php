<?php
/**
 * Source-to-target IDs for attachments, posts and terms, as worked out by the
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
	 * @param array<int, int> $terms       Source term ID => target ID.
	 */
	public function __construct(
		private readonly array $attachments = array(),
		private readonly array $posts = array(),
		private readonly array $terms = array()
	) {}

	public function get( string $kind, int $id ): ?int {
		$map = match ( $kind ) {
			Rewrite::ATTACHMENT => $this->attachments,
			Rewrite::TERM       => $this->terms,
			default             => $this->posts,
		};
		return $map[ $id ] ?? null;
	}
}
