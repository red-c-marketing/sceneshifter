<?php
/**
 * Rewrites parsed blocks (the arrays parse_blocks() returns) for the target
 * site: attachment and post IDs in known block attributes, wp-image-N
 * classes and gallery data-id values in block HTML, and every URL that
 * points at the source site, in attributes and HTML alike.
 *
 * It works on the parsed structure and hands it back for serialize_blocks(),
 * so nothing is ever found-and-replaced across the serialised post.
 *
 * @package RedC\SisterSync
 */

declare(strict_types=1);

namespace RedC\SisterSync\Rewrite;

final class BlockRewriter {

	/**
	 * Attributes holding attachment IDs, by block name. A list attribute
	 * (like the old gallery's ids) holds several.
	 */
	public const ATTACHMENT_ATTRIBUTES = array(
		'core/image'      => array( 'id' ),
		'core/cover'      => array( 'id' ),
		'core/media-text' => array( 'mediaId' ),
		'core/file'       => array( 'id' ),
		'core/video'      => array( 'id' ),
		'core/audio'      => array( 'id' ),
		'core/gallery'    => array( 'ids' ),
	);

	/** Attributes holding post IDs, by block name. */
	public const POST_ATTRIBUTES = array(
		'core/block'      => array( 'ref' ),
		'core/navigation' => array( 'ref' ),
	);

	/** @var list<Rewrite> */
	private array $rewrites = array();

	/** @var list<Unresolved> */
	private array $unresolved = array();

	public function __construct(
		private readonly UrlRewriter $urls,
		private readonly IdMap $ids
	) {}

	/**
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
	 * @return array{blocks: list<array<string, mixed>>, rewrites: list<Rewrite>, unresolved: list<Unresolved>}
	 */
	public function rewrite( array $blocks ): array {
		$this->rewrites   = array();
		$this->unresolved = array();
		$result           = $this->rewriteList( $blocks, array() );

		return array(
			'blocks'     => $result,
			'rewrites'   => $this->rewrites,
			'unresolved' => $this->unresolved,
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $blocks
	 * @param list<int>                        $parentPath
	 * @return list<array<string, mixed>>
	 */
	private function rewriteList( array $blocks, array $parentPath ): array {
		$out   = array();
		$index = 0;
		foreach ( $blocks as $block ) {
			// Whitespace between blocks parses as an empty freeform block. The
			// editor doesn't count it, so neither do locations.
			$isGap = null === ( $block['blockName'] ?? null ) && '' === trim( (string) ( $block['innerHTML'] ?? '' ) );
			$path  = $isGap ? $parentPath : array_merge( $parentPath, array( $index++ ) );
			$out[] = $isGap ? $block : $this->rewriteBlock( $block, $path );
		}
		return $out;
	}

	/**
	 * @param array<string, mixed> $block
	 * @param list<int>            $path
	 * @return array<string, mixed>
	 */
	private function rewriteBlock( array $block, array $path ): array {
		$name     = (string) ( $block['blockName'] ?? '' );
		$location = self::location( $path, $name );
		$attrs    = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();

		foreach ( self::ATTACHMENT_ATTRIBUTES[ $name ] ?? array() as $attr ) {
			if ( array_key_exists( $attr, $attrs ) ) {
				$attrs[ $attr ] = $this->mapIds( Rewrite::ATTACHMENT, $attrs[ $attr ], $location );
			}
		}
		foreach ( self::POST_ATTRIBUTES[ $name ] ?? array() as $attr ) {
			if ( array_key_exists( $attr, $attrs ) ) {
				$attrs[ $attr ] = $this->mapIds( Rewrite::POST, $attrs[ $attr ], $location );
			}
		}
		$block['attrs'] = $this->urls->rewriteValue( $attrs, $location, $this->rewrites );

		if ( isset( $block['innerHTML'] ) && is_string( $block['innerHTML'] ) ) {
			$block['innerHTML'] = $this->rewriteHtml( $block['innerHTML'], $location, true );
		}
		if ( isset( $block['innerContent'] ) && is_array( $block['innerContent'] ) ) {
			// innerContent holds the same HTML as innerHTML, split around inner
			// blocks (null placeholders). Rewrite it without recording again.
			foreach ( $block['innerContent'] as $i => $chunk ) {
				if ( is_string( $chunk ) ) {
					$block['innerContent'][ $i ] = $this->rewriteHtml( $chunk, $location, false );
				}
			}
		}
		if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
			$block['innerBlocks'] = $this->rewriteList( $block['innerBlocks'], $path );
		}
		return $block;
	}

	/**
	 * An ID or list of IDs (as integers or numeric strings, as attributes
	 * can hold either), mapped to the target. Unmapped IDs stay and are
	 * reported.
	 */
	private function mapIds( string $kind, mixed $value, string $location ): mixed {
		if ( is_array( $value ) ) {
			return array_map( fn( mixed $v ): mixed => $this->mapIds( $kind, $v, $location ), $value );
		}
		if ( ! is_int( $value ) && ! ( is_string( $value ) && ctype_digit( $value ) ) ) {
			return $value;
		}
		$id = (int) $value;
		if ( $id <= 0 ) {
			return $value;
		}
		$mapped = $this->ids->get( $kind, $id );
		if ( null === $mapped ) {
			$this->unresolved[] = new Unresolved( $kind, $id, $location );
			return $value;
		}
		if ( $mapped !== $id ) {
			$this->rewrites[] = new Rewrite( $kind, $location, (string) $id, (string) $mapped );
		}
		return is_string( $value ) ? (string) $mapped : $mapped;
	}

	private function rewriteHtml( string $html, string $location, bool $record ): string {
		$scratch = array();
		$html    = $this->urls->rewriteText( $html, $location, $scratch );

		// wp-image-N in class attributes and data-id="N" on gallery images.
		$html = (string) preg_replace_callback(
			'~(\bwp-image-)(\d+)\b|(<img\b[^>]*\bdata-id=["\'])(\d+)(["\'])~i',
			function ( array $m ) use ( $location, &$scratch ): string {
				$isClass = '' !== $m[1];
				$id      = (int) ( $isClass ? $m[2] : $m[4] );
				$mapped  = $this->ids->get( Rewrite::ATTACHMENT, $id );
				if ( null === $mapped ) {
					$scratch[] = new Unresolved( Rewrite::ATTACHMENT, $id, $location );
					return $m[0];
				}
				if ( $mapped !== $id ) {
					$scratch[] = new Rewrite( Rewrite::ATTACHMENT, $location, (string) $id, (string) $mapped );
				}
				return $isClass ? $m[1] . $mapped : $m[3] . $mapped . $m[5];
			},
			$html
		);

		if ( $record ) {
			foreach ( $scratch as $item ) {
				if ( $item instanceof Unresolved ) {
					$this->addUnresolved( $item );
				} else {
					$this->rewrites[] = $item;
				}
			}
		}
		return $html;
	}

	/**
	 * The same missing ID found in a block's attributes and its HTML is
	 * one missing dependency.
	 */
	private function addUnresolved( Unresolved $item ): void {
		foreach ( $this->unresolved as $known ) {
			if ( $known->kind === $item->kind && $known->id === $item->id && $known->location === $item->location ) {
				return;
			}
		}
		$this->unresolved[] = $item;
	}

	/**
	 * Matches Finding::locationText(): "Block 2 › 1 (core/image)".
	 *
	 * @param list<int> $path
	 */
	public static function location( array $path, string $name ): string {
		return 'Block ' . implode( ' › ', array_map( static fn( int $i ): int => $i + 1, $path ) ) . ( '' !== $name ? " ({$name})" : '' );
	}
}
