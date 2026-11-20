<?php
/**
 * Gathers a post's content, excerpt and meta from WordPress and runs the
 * content scanner over it.
 *
 * @package RedC\SisterSync
 */

declare(strict_types=1);

namespace RedC\SisterSync\Scanner;

use WP_Post;

final class PostScanner {

	public function __construct( private readonly ContentScanner $scanner ) {}

	/**
	 * @param string|null $content Unsaved content from the editor, to check before saving.
	 * @param string|null $excerpt Unsaved excerpt from the editor.
	 * @return list<Finding>
	 */
	public function scan( WP_Post $post, ?string $content = null, ?string $excerpt = null ): array {
		$content ??= (string) $post->post_content;
		$excerpt ??= (string) $post->post_excerpt;

		$rawMeta = get_post_meta( $post->ID );
		$rawMeta = is_array( $rawMeta ) ? $rawMeta : array();

		$meta = array();
		foreach ( $rawMeta as $key => $values ) {
			$meta[ (string) $key ] = array_map( 'maybe_unserialize', (array) $values );
		}

		return $this->scanner->scan(
			new ScanInput(
				$content,
				has_blocks( $content ) ? parse_blocks( $content ) : null,
				$excerpt,
				$meta,
				static fn( string $key ): ?string => self::acfLabel( $key, $rawMeta )
			)
		);
	}

	/**
	 * ACF stores a "_{key}" meta row holding the field key for every value.
	 * Use it to show the field's label instead of the raw meta key.
	 *
	 * @param array<string, array<int, mixed>> $rawMeta
	 */
	private static function acfLabel( string $key, array $rawMeta ): ?string {
		$fieldKey = $rawMeta[ '_' . $key ][0] ?? null;
		if ( ! is_string( $fieldKey ) || ! str_starts_with( $fieldKey, 'field_' ) || ! function_exists( 'acf_get_field' ) ) {
			return null;
		}
		$field = acf_get_field( $fieldKey );
		if ( ! is_array( $field ) || empty( $field['label'] ) ) {
			return null;
		}

		// Repeater and flexible content rows are stored as parent_0_child.
		$label = 'ACF: ' . $field['label'];
		if ( preg_match( '/_(\d+)_[^_]+$/', $key, $m ) ) {
			$label .= sprintf( ' (row %d)', (int) $m[1] + 1 );
		}
		return $label;
	}
}
