<?php
/**
 * A post as it was on this site before a push changed it, so the push can
 * be undone. For a post the push created there is nothing to keep: undoing
 * it removes the post.
 *
 * @package RedC\Sceneshifter
 */

declare(strict_types=1);

namespace RedC\Sceneshifter\Transfer;

final class Snapshot {

	/**
	 * @param int                  $id       Store ID; 0 until saved.
	 * @param string               $transfer The transfer that took it.
	 * @param string               $sister   The site that pushed.
	 * @param int                  $postId   The post on this site.
	 * @param bool                 $isNew    Whether the push created the post.
	 * @param array<string, mixed> $data     PostWriter::snapshot(); empty for a new post.
	 * @param int                  $taken    Unix time.
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $transfer,
		public readonly string $sister,
		public readonly int $postId,
		public readonly bool $isNew,
		public readonly array $data,
		public readonly int $taken
	) {}

	public function withId( int $id ): self {
		return new self( $id, $this->transfer, $this->sister, $this->postId, $this->isNew, $this->data, $this->taken );
	}
}
