<?php
/**
 * What a push would do to one post, worked out without changing anything.
 *
 * @package RedC\Sceneshifter
 */

declare(strict_types=1);

namespace RedC\Sceneshifter\Transfer;

use RedC\Sceneshifter\Rewrite\Rewrite;
use RedC\Sceneshifter\Scanner\Finding;

final class PreviewItem {

	public const NEVER_SYNCED = 'never';
	public const EDITED       = 'edited';

	/**
	 * @param Package                                                             $package     As on the source.
	 * @param Package|null                                                        $rewritten   With IDs and URLs for the target; null when the preflight failed for it.
	 * @param array<string, mixed>|null                                           $target      The post it would replace: id, how, status, title, modified, hash, synced_hash, synced_at.
	 * @param int|null                                                            $blockedBy   A post at the same address on the target, already linked to something else.
	 * @param list<string>|null                                                   $changed     ContentHash parts that would change; null for a new post.
	 * @param string|null                                                         $conflict    NEVER_SYNCED or EDITED, for an update.
	 * @param list<Rewrite>                                                       $rewrites    Every change to IDs and URLs.
	 * @param list<array{id: int, kind: string, title: string, location: string, gone: bool}> $missing References the target doesn't have; gone when the source doesn't have it either.
	 * @param list<array{id: int, title: string, file: string, size: int|null}>   $media       Media the target doesn't have, to transfer.
	 * @param int                                                                 $mediaFound  Referenced media the target already has.
	 * @param list<array{taxonomy: string, slug: string, name: string}>          $newTerms    Assigned terms the target doesn't have.
	 * @param array{id: int, name: string}|null                                   $author      The matching user on the target, or null to use the default.
	 * @param list<array<string, mixed>>                                          $checks      Drift checks from the target (PreflightResponder codes).
	 * @param list<Finding>                                                       $foreign     Links to environments other than the target.
	 * @param string|null                                                         $error       Why this post can't be previewed.
	 */
	public function __construct(
		public readonly Package $package,
		public readonly ?Package $rewritten = null,
		public readonly ?array $target = null,
		public readonly ?int $blockedBy = null,
		public readonly ?array $changed = null,
		public readonly ?string $conflict = null,
		public readonly array $rewrites = array(),
		public readonly array $missing = array(),
		public readonly array $media = array(),
		public readonly int $mediaFound = 0,
		public readonly array $newTerms = array(),
		public readonly ?array $author = null,
		public readonly array $checks = array(),
		public readonly array $foreign = array(),
		public readonly ?string $error = null
	) {}

	public function isUpdate(): bool {
		return null !== $this->target;
	}

	/**
	 * Problems that stop the push: the post type is missing on the target,
	 * or the content links to a third environment.
	 */
	public function isBlocked(): bool {
		if ( null !== $this->error || array() !== $this->foreign ) {
			return true;
		}
		foreach ( $this->checks as $check ) {
			if ( PreflightResponder::POST_TYPE_MISSING === ( $check['code'] ?? '' ) ) {
				return true;
			}
		}
		return false;
	}
}
