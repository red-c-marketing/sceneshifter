<?php
/**
 * What happened when one post was pushed.
 *
 * @package RedC\Sceneshifter
 */

declare(strict_types=1);

namespace RedC\Sceneshifter\Transfer;

final class PushResult {

	/**
	 * @param int                                                                         $postId      The post here.
	 * @param string                                                                      $title       Its title.
	 * @param string|null                                                                 $error       Why it failed; null when it worked.
	 * @param int|null                                                                    $targetId    The post on the target.
	 * @param bool                                                                        $created     Whether the push created it there.
	 * @param string                                                                      $status      Its status there.
	 * @param array{edit?: string, view?: string}                                         $urls        Links to it there.
	 * @param int                                                                         $mediaCopied Files copied over.
	 * @param int                                                                         $rewrites    Links and IDs rewritten.
	 * @param list<string>                                                                $mismatch    ContentHash parts that differ after writing.
	 * @param list<array{site: string, url: string, where: string}>                       $links       Links to other environments found there afterwards.
	 * @param int                                                                         $linksTotal  How many in all.
	 * @param list<array{from: string, to: string}>                                       $renamed     Media saved under a different file name there.
	 * @param list<array{id: int, kind: string, title: string, location: string, gone: bool}> $missing References left unresolved.
	 * @param int|null                                                                    $snapshot    Snapshot ID there, for undoing it.
	 */
	public function __construct(
		public readonly int $postId,
		public readonly string $title,
		public readonly ?string $error = null,
		public readonly ?int $targetId = null,
		public readonly bool $created = false,
		public readonly string $status = '',
		public readonly array $urls = array(),
		public readonly int $mediaCopied = 0,
		public readonly int $rewrites = 0,
		public readonly array $mismatch = array(),
		public readonly array $links = array(),
		public readonly int $linksTotal = 0,
		public readonly array $renamed = array(),
		public readonly array $missing = array(),
		public readonly ?int $snapshot = null
	) {}

	public function ok(): bool {
		return null === $this->error;
	}

	public static function failed( int $postId, string $title, string $error, int $mediaCopied = 0 ): self {
		return new self( $postId, $title, $error, mediaCopied: $mediaCopied );
	}
}
