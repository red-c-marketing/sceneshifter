<?php
/**
 * What the target can tell about its own code, for drift checks: dev and
 * staging often run code that live doesn't have yet.
 *
 * @package RedC\Sceneshifter
 */

declare(strict_types=1);

namespace RedC\Sceneshifter\Transfer;

interface SiteFacts {

	public function postTypeExists( string $type ): bool;

	public function templateExists( string $type, string $template ): bool;

	/**
	 * @param list<string> $keys ACF field keys.
	 * @return list<string> Those this site doesn't have.
	 */
	public function missingAcfFields( array $keys ): array;

	/**
	 * @param list<string> $taxonomies
	 * @return list<string>
	 */
	public function missingTaxonomies( array $taxonomies ): array;

	/**
	 * @param list<string> $blocks Block names.
	 * @return list<string>
	 */
	public function missingBlocks( array $blocks ): array;

	/**
	 * The post as it stands, with hashes of the parts a push would write.
	 *
	 * @param list<string> $metaKeys Meta keys the push carries.
	 * @return array{status: string, title: string, modified: string, parts: array<string, string>}|null
	 */
	public function state( int $id, array $metaKeys ): ?array;
}
