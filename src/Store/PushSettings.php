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

	/**
	 * @param string $publishedUpdates UPDATES_LIVE or UPDATES_REVIEW.
	 * @param int    $defaultAuthor    Author for new posts when no user here has the source author's email; 0 for the first administrator.
	 * @param string $mediaClash       CLASH_STOP or CLASH_RENAME.
	 */
	public function __construct(
		public readonly string $publishedUpdates = self::UPDATES_LIVE,
		public readonly int $defaultAuthor = 0,
		public readonly string $mediaClash = self::CLASH_STOP
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
		$author = $data['defaultAuthor'] ?? 0;
		return new self(
			self::UPDATES_REVIEW === ( $data['publishedUpdates'] ?? null ) ? self::UPDATES_REVIEW : self::UPDATES_LIVE,
			is_numeric( $author ) ? max( 0, (int) $author ) : 0,
			self::CLASH_RENAME === ( $data['mediaClash'] ?? null ) ? self::CLASH_RENAME : self::CLASH_STOP
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return array(
			'publishedUpdates' => $this->publishedUpdates,
			'defaultAuthor'    => $this->defaultAuthor,
			'mediaClash'       => $this->mediaClash,
		);
	}
}
