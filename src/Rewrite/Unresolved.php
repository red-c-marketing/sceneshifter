<?php
/**
 * A reference the rewriter couldn't map to the target: a missing
 * dependency for the preview to raise.
 *
 * @package RedC\Sceneshifter
 */

declare(strict_types=1);

namespace RedC\Sceneshifter\Rewrite;

final class Unresolved {

	/**
	 * @param string $kind     Rewrite::ATTACHMENT or Rewrite::POST.
	 * @param int    $id       The ID on the source site.
	 * @param string $location Where it was found.
	 */
	public function __construct(
		public readonly string $kind,
		public readonly int $id,
		public readonly string $location
	) {}
}
