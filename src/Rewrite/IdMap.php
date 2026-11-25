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
	 * @param bool            $clearMissing Turn IDs with no match into 0.
	 */
	public function __construct(
		private readonly array $attachments = array(),
		private readonly array $posts = array(),
		private readonly array $terms = array(),
		public readonly bool $clearMissing = false
	) {}

	/**
	 * A copy that turns IDs it can't map into 0, for writing to the target:
	 * the source's number would point at something unrelated there.
	 */
	public function clearingMissing(): self {
		return new self( $this->attachments, $this->posts, $this->terms, true );
	}

	public function get( string $kind, int $id ): ?int {
		$map = match ( $kind ) {
			Rewrite::ATTACHMENT => $this->attachments,
			Rewrite::TERM       => $this->terms,
			default             => $this->posts,
		};
		return $map[ $id ] ?? null;
	}
}
