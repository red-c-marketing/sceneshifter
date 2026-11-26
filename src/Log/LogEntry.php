<?php
/**
 * One post moved by a push, as one site saw it. Both sites keep their own
 * entry, so each has a full history even when the other is unreachable.
 *
 * @package RedC\Sceneshifter
 */

declare(strict_types=1);

namespace RedC\Sceneshifter\Log;

final class LogEntry {

	public const OUT = 'out';
	public const IN  = 'in';

	public const DONE   = 'done';
	public const FAILED = 'failed';
	public const UNDONE = 'undone';

	/** Received, and waiting as a copy for someone to apply it (Settings → Push). */
	public const HELD = 'held';

	/**
	 * @param int                  $id        Store ID; 0 until saved.
	 * @param string               $transfer  Batch UUID, shared by both sites' entries.
	 * @param string               $direction OUT on the site that pushed, IN on the one that received.
	 * @param string               $sister    The other site's registry ID.
	 * @param string               $user      Who started it, by name.
	 * @param int                  $sourceId  The post on the pushing site.
	 * @param int|null             $targetId  The post on the receiving site; null when it failed before arriving.
	 * @param string               $title     Post title at the time.
	 * @param string               $result    DONE, FAILED, UNDONE or HELD.
	 * @param bool                 $created   Whether the push created the post on the receiving site.
	 * @param string|null          $message   Why it failed, or a note.
	 * @param array<string, mixed> $details   Counts, warnings, snapshot ID and hash after writing.
	 * @param int                  $at        Unix time.
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $transfer,
		public readonly string $direction,
		public readonly string $sister,
		public readonly string $user,
		public readonly int $sourceId,
		public readonly ?int $targetId,
		public readonly string $title,
		public readonly string $result,
		public readonly bool $created = false,
		public readonly ?string $message = null,
		public readonly array $details = array(),
		public readonly int $at = 0
	) {}

	public function withId( int $id ): self {
		return new self( $id, $this->transfer, $this->direction, $this->sister, $this->user, $this->sourceId, $this->targetId, $this->title, $this->result, $this->created, $this->message, $this->details, $this->at );
	}

	/**
	 * @param array<string, mixed> $details
	 */
	public function withDetails( array $details ): self {
		return new self( $this->id, $this->transfer, $this->direction, $this->sister, $this->user, $this->sourceId, $this->targetId, $this->title, $this->result, $this->created, $this->message, $details, $this->at );
	}

	public function withResult( string $result, ?string $message ): self {
		return new self( $this->id, $this->transfer, $this->direction, $this->sister, $this->user, $this->sourceId, $this->targetId, $this->title, $result, $this->created, $message, $this->details, $this->at );
	}
}
