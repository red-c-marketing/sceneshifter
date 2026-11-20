<?php
/**
 * A piece of text to scan, with where it came from.
 *
 * @package RedC\SisterSync
 */

declare(strict_types=1);

namespace RedC\SisterSync\Scanner;

final class TextChunk {

	public const AREA_CONTENT = 'content';
	public const AREA_EXCERPT = 'excerpt';
	public const AREA_META    = 'meta';

	/**
	 * @param string        $area      One of the AREA_* constants.
	 * @param string        $label     Human description of the location, e.g. "core/image" or "ACF: Hero link".
	 * @param string        $text      The text to scan.
	 * @param list<int>|null $blockPath Zero-based path through the block tree, for content in blocks.
	 * @param string|null   $blockName Block name, for content in blocks.
	 */
	public function __construct(
		public readonly string $area,
		public readonly string $label,
		public readonly string $text,
		public readonly ?array $blockPath = null,
		public readonly ?string $blockName = null
	) {}
}
