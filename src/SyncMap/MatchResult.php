<?php
/**
 * What Matcher found for one post type.
 *
 * @package RedC\Sceneshifter
 */

declare(strict_types=1);

namespace RedC\Sceneshifter\SyncMap;

final class MatchResult {

	/**
	 * @param string                                                                $type        Post type.
	 * @param int                                                                   $linked      Items here already linked to the sister.
	 * @param list<array{local: Item, remote: Item, reason: string}>                $proposals   Matches to offer, each with Matcher's reason.
	 * @param list<array{local: Item, candidates: list<Item>, reason: string}>      $attention   Items an admin has to sort out.
	 * @param list<Item>                                                            $onlyHere    Items here with no match there.
	 * @param list<Item>                                                            $onlyThere   Items there with no match here.
	 */
	public function __construct(
		public readonly string $type,
		public readonly int $linked,
		public readonly array $proposals,
		public readonly array $attention,
		public readonly array $onlyHere,
		public readonly array $onlyThere
	) {}

	/**
	 * The UUID both items will share once linked.
	 */
	public static function uuidFor( Item $local, Item $remote ): ?string {
		return $local->usableUuid() ?? $remote->usableUuid();
	}
}
