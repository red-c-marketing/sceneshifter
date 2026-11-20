<?php
/**
 * Walks a parse_blocks() tree and yields each block's attributes and own
 * HTML as separate chunks, tagged with the block's path.
 *
 * Whitespace-only freeform blocks (the gaps parse_blocks() leaves between
 * blocks) are skipped and not counted, so a path matches the block list the
 * editor shows.
 *
 * @package RedC\SisterSync
 */

declare(strict_types=1);

namespace RedC\SisterSync\Scanner;

final class BlockWalker {

	/**
	 * @param array<int, array<string, mixed>> $blocks Output of parse_blocks().
	 * @param list<int>                        $parentPath
	 * @return \Generator<TextChunk>
	 */
	public function walk( array $blocks, array $parentPath = array() ): \Generator {
		$index = 0;
		foreach ( $blocks as $block ) {
			$name = isset( $block['blockName'] ) && is_string( $block['blockName'] ) ? $block['blockName'] : null;
			$html = is_string( $block['innerHTML'] ?? null ) ? $block['innerHTML'] : '';

			if ( null === $name && '' === trim( $html ) ) {
				continue;
			}

			$path  = array_merge( $parentPath, array( $index ) );
			$label = $name ?? 'core/freeform';
			++$index;

			$attrs = $block['attrs'] ?? array();
			if ( is_array( $attrs ) && array() !== $attrs ) {
				yield new TextChunk(
					TextChunk::AREA_CONTENT,
					$label,
					(string) json_encode( $attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
					$path,
					$label
				);
			}

			if ( '' !== trim( $html ) ) {
				yield new TextChunk( TextChunk::AREA_CONTENT, $label, $html, $path, $label );
			}

			$inner = $block['innerBlocks'] ?? array();
			if ( is_array( $inner ) && array() !== $inner ) {
				yield from $this->walk( $inner, $path );
			}
		}
	}
}
