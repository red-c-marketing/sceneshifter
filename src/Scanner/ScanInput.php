<?php
/**
 * Everything the scanner needs about one post, gathered by the caller so the
 * scanner itself never touches WordPress.
 *
 * @package RedC\Sceneshifter
 */

declare(strict_types=1);

namespace RedC\Sceneshifter\Scanner;

final class ScanInput {

	/**
	 * @param string                               $content    Raw post_content.
	 * @param array<int, array<string, mixed>>|null $blocks    parse_blocks() output, or null for Classic content.
	 * @param string                               $excerpt    Raw post_excerpt.
	 * @param array<string, list<mixed>>           $meta       Meta key => list of unserialised values.
	 * @param (callable(string): ?string)|null     $metaLabel  Returns a friendly label for a meta key (e.g. its ACF field label), or null.
	 */
	public function __construct(
		public readonly string $content,
		public readonly ?array $blocks = null,
		public readonly string $excerpt = '',
		public readonly array $meta = array(),
		public readonly mixed $metaLabel = null
	) {}
}
