<?php
/**
 * Hashes of the parts of a post that a transfer writes, so two sites can
 * compare content without sending it: one per part, to show what a push
 * would change, and one overall, to notice edits made on the target since
 * the last transfer.
 *
 * Status and dates are left out: they are chosen per push.
 *
 * @package RedC\Sceneshifter
 */

declare(strict_types=1);

namespace RedC\Sceneshifter\Transfer;

final class ContentHash {

	public const PARTS = array( 'title', 'content', 'excerpt', 'slug', 'order', 'template', 'parent', 'featured', 'meta', 'terms' );

	/**
	 * @param Package           $package  The post, with IDs as the target has them.
	 * @param list<string>|null $metaKeys Only hash these meta keys. A push writes only the keys it
	 *                                    carries, so meta the target alone has doesn't count as a change.
	 * @return array<string, string> Part => short hash.
	 */
	public static function parts( Package $package, ?array $metaKeys = null ): array {
		$fields = $package->fields;
		$terms  = array_map( static fn( array $t ): string => $t['taxonomy'] . ':' . $t['slug'], $package->terms );
		sort( $terms );
		$meta = null === $metaKeys ? $package->meta : array_intersect_key( $package->meta, array_flip( $metaKeys ) );
		ksort( $meta );

		$values = array(
			'title'    => (string) ( $fields['post_title'] ?? '' ),
			'content'  => str_replace( "\r\n", "\n", (string) ( $fields['post_content'] ?? '' ) ),
			'excerpt'  => (string) ( $fields['post_excerpt'] ?? '' ),
			'slug'     => (string) ( $fields['post_name'] ?? '' ),
			'order'    => (string) (int) ( $fields['menu_order'] ?? 0 ),
			'template' => self::template( $fields['page_template'] ?? '' ),
			'parent'   => (string) (int) ( $fields['post_parent'] ?? 0 ),
			'featured' => (string) (int) ( $fields['featured'] ?? 0 ),
			'meta'     => self::canonical( $meta ),
			'terms'    => implode( ',', $terms ),
		);
		return array_map( static fn( string $v ): string => substr( hash( 'sha256', $v ), 0, 16 ), $values );
	}

	/**
	 * @param array<string, string> $parts
	 */
	public static function overall( array $parts ): string {
		ksort( $parts );
		return hash( 'sha256', implode( '|', $parts ) );
	}

	/**
	 * Parts whose hashes differ.
	 *
	 * @param array<string, string> $a
	 * @param array<string, string> $b
	 * @return list<string>
	 */
	public static function changed( array $a, array $b ): array {
		$changed = array();
		foreach ( self::PARTS as $part ) {
			if ( ( $a[ $part ] ?? '' ) !== ( $b[ $part ] ?? '' ) ) {
				$changed[] = $part;
			}
		}
		return $changed;
	}

	private static function template( mixed $template ): string {
		$template = (string) $template;
		return 'default' === $template ? '' : $template;
	}

	/**
	 * JSON with object keys sorted at every level, so the same data hashes
	 * the same on both sites.
	 */
	private static function canonical( mixed $value ): string {
		return (string) json_encode( self::sorted( $value ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR );
	}

	private static function sorted( mixed $value ): mixed {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		if ( ! array_is_list( $value ) ) {
			ksort( $value );
		}
		return array_map( array( self::class, 'sorted' ), $value );
	}
}
