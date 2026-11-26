<?php
/**
 * This site's settings for content pushed to it. Like the Check settings,
 * they belong to one site: the receiving site decides how pushes arrive.
 *
 * @package RedC\Sceneshifter
 */

declare(strict_types=1);

namespace RedC\Sceneshifter\Store;

final class PushSettings {

	/** Updates to published posts show to visitors straight away. */
	public const UPDATES_LIVE = 'live';

	/** Updates to published posts wait as a copy until someone applies them. */
	public const UPDATES_REVIEW = 'review';

	/** A different file at the same uploads path stops the push. */
	public const CLASH_STOP = 'stop';

	/** A different file at the same uploads path: save the new one as name-1.jpg. */
	public const CLASH_RENAME = 'rename';

	public const LOG_MONTHS         = 12;
	public const SNAPSHOT_DAYS      = 30;
	public const SNAPSHOTS_PER_POST = 10;

	/**
	 * @param string $publishedUpdates UPDATES_LIVE or UPDATES_REVIEW.
	 * @param int    $defaultAuthor    Author for new posts when no user here has the source author's email; 0 for the first administrator.
	 * @param string $mediaClash       CLASH_STOP or CLASH_RENAME.
	 * @param int    $logMonths        How long to keep log entries; 0 keeps them for ever.
	 * @param int    $snapshotDays     Snapshots older than this are removed...
	 * @param int    $snapshotsPerPost ...unless they are among a post's newest this many.
	 */
	public function __construct(
		public readonly string $publishedUpdates = self::UPDATES_LIVE,
		public readonly int $defaultAuthor = 0,
		public readonly string $mediaClash = self::CLASH_STOP,
		public readonly int $logMonths = self::LOG_MONTHS,
		public readonly int $snapshotDays = self::SNAPSHOT_DAYS,
		public readonly int $snapshotsPerPost = self::SNAPSHOTS_PER_POST
	) {}

	public function holdsUpdates(): bool {
		return self::UPDATES_REVIEW === $this->publishedUpdates;
	}

	public function renamesClashes(): bool {
		return self::CLASH_RENAME === $this->mediaClash;
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public static function fromArray( array $data ): self {
		return new self(
			self::UPDATES_REVIEW === ( $data['publishedUpdates'] ?? null ) ? self::UPDATES_REVIEW : self::UPDATES_LIVE,
			self::number( $data['defaultAuthor'] ?? null, 0, 0, PHP_INT_MAX ),
			self::CLASH_RENAME === ( $data['mediaClash'] ?? null ) ? self::CLASH_RENAME : self::CLASH_STOP,
			self::number( $data['logMonths'] ?? null, self::LOG_MONTHS, 0, 120 ),
			self::number( $data['snapshotDays'] ?? null, self::SNAPSHOT_DAYS, 1, 3650 ),
			self::number( $data['snapshotsPerPost'] ?? null, self::SNAPSHOTS_PER_POST, 1, 1000 )
		);
	}

	/**
	 * A whole number within limits, or the default when it isn't one.
	 */
	private static function number( mixed $value, int $default, int $min, int $max ): int {
		if ( is_int( $value ) || ( is_string( $value ) && 1 === preg_match( '/^\d+$/', $value ) ) ) {
			return min( $max, max( $min, (int) $value ) );
		}
		return $default;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return array(
			'publishedUpdates' => $this->publishedUpdates,
			'defaultAuthor'    => $this->defaultAuthor,
			'mediaClash'       => $this->mediaClash,
			'logMonths'        => $this->logMonths,
			'snapshotDays'     => $this->snapshotDays,
			'snapshotsPerPost' => $this->snapshotsPerPost,
		);
	}
}
