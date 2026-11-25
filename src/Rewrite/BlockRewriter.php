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
 * @package RedC\Sceneshifter
 */

declare(strict_types=1);

namespace RedC\Sceneshifter\Rewrite;

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

	/**
	 * @param UrlRewriter           $urls     Moves source URLs onto the target.
	 * @param IdMap                 $ids      Source => target IDs.
	 * @param array<string, string> $acfTypes ACF field key => type, for ACF block data.
	 */
	public function __construct(
		private readonly UrlRewriter $urls,
		private readonly IdMap $ids,
		private readonly array $acfTypes = array()
	) {}

	/**
	 * Starts a new record of rewrites and unresolved references, for a
	 * caller that rewrites several parts of one post through the methods
	 * below and then calls finish().
	 */
	public function begin(): void {
		$this->rewrites   = array();
		$this->unresolved = array();
	}

	/**
	 * @return array{rewrites: list<Rewrite>, unresolved: list<Unresolved>}
	 */
	public function finish(): array {
		return array(
			'rewrites'   => $this->rewrites,
			'unresolved' => $this->unresolved,
		);
	}

	/**
	 * Parsed blocks, recorded into the current record.
	 *
	 * @param array<int, array<string, mixed>> $blocks
	 * @return list<array<string, mixed>>
	 */
	public function rewriteBlocks( array $blocks ): array {
		return $this->rewriteList( $blocks, array() );
	}

	/**
	 * HTML outside blocks: Classic content.
	 */
	public function rewriteHtmlText( string $html, string $location ): string {
		return $this->rewriteHtml( $html, $location, true );
	}

	/**
	 * Every source URL inside a value (scalar or nested arrays).
	 */
	public function rewriteUrls( mixed $value, string $location ): mixed {
		return $this->urls->rewriteValue( $value, $location, $this->rewrites );
	}

	/**
	 * An ID or list of IDs of one kind, mapped to the target.
	 */
	public function mapValue( string $kind, mixed $value, string $location ): mixed {
		return $this->mapIds( $kind, $value, $location );
	}

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
		if ( str_starts_with( $name, 'acf/' ) && is_array( $attrs['data'] ?? null ) ) {
			$attrs['data'] = $this->rewriteAcfData( $attrs['data'], $location );
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
	 * ACF block data: flat field names, each with a "_name" entry holding
	 * its field key.
	 *
	 * @param array<mixed> $data Decoded JSON, so keys aren't guaranteed to be strings.
	 * @return array<mixed>
	 */
	private function rewriteAcfData( array $data, string $location ): array {
		foreach ( $data as $name => $value ) {
			if ( ! is_string( $name ) || str_starts_with( $name, '_' ) ) {
				continue;
			}
			$kind = AcfValues::idKind( AcfValues::type( $this->acfTypes, self::acfFieldKey( $data, $name ) ) );
			if ( null !== $kind ) {
				$data[ $name ] = $this->mapIds( $kind, $value, $location );
			}
		}
		return $data;
	}

	/**
	 * @param array<mixed> $data
	 */
	private static function acfFieldKey( array $data, string $name ): ?string {
		$key = $data[ '_' . $name ] ?? null;
		return is_string( $key ) && str_starts_with( $key, 'field_' ) ? $key : null;
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
			if ( ! $this->ids->clearMissing ) {
				return $value;
			}
			$mapped = 0;
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
				$mapped  = $this->mapOne( Rewrite::ATTACHMENT, (int) ( $isClass ? $m[2] : $m[4] ), $location, $scratch );
				return $isClass ? $m[1] . $mapped : $m[3] . $mapped . $m[5];
			},
			$html
		);

		// Classic shortcodes: [gallery ids="1,2"] and [caption id="attachment_3"].
		$html = (string) preg_replace_callback(
			'~(\[gallery\b[^\]]*?\bids=["\'])([\d,\s]+)(["\'])|(\[caption\b[^\]]*?\bid=["\']attachment_)(\d+)(["\'])~i',
			function ( array $m ) use ( $location, &$scratch ): string {
				if ( '' !== $m[1] ) {
					$ids = array();
					foreach ( explode( ',', $m[2] ) as $raw ) {
						$id = (int) trim( $raw );
						$mapped = $id > 0 ? $this->mapOne( Rewrite::ATTACHMENT, $id, $location, $scratch ) : 0;
						if ( $mapped > 0 ) {
							// A gallery skips images it can't find rather than show a gap.
							$ids[] = (string) $mapped;
						}
					}
					return $m[1] . implode( ',', $ids ) . $m[3];
				}
				return $m[4] . $this->mapOne( Rewrite::ATTACHMENT, (int) $m[5], $location, $scratch ) . $m[6];
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
	 * One ID for rewriteHtml(), noting the change or the miss in $scratch.
	 *
	 * @param list<Rewrite|Unresolved> $scratch
	 */
	private function mapOne( string $kind, int $id, string $location, array &$scratch ): int {
		$mapped = $this->ids->get( $kind, $id );
		if ( null === $mapped ) {
			$scratch[] = new Unresolved( $kind, $id, $location );
			return $this->ids->clearMissing ? 0 : $id;
		}
		if ( $mapped !== $id ) {
			$scratch[] = new Rewrite( $kind, $location, (string) $id, (string) $mapped );
		}
		return $mapped;
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
