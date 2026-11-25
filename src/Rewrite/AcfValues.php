<?php
/**
 * Which ACF field types hold IDs, and of what. ACF stores a "_{name}" row
 * beside every value naming its field key, in post meta and in ACF block
 * data alike, so each flat value can be typed without walking repeaters.
 *
 * @package RedC\Sceneshifter
 */

declare(strict_types=1);

namespace RedC\Sceneshifter\Rewrite;

final class AcfValues {

	/** ACF field type => kind of ID it stores. */
	public const ID_TYPES = array(
		'image'        => Rewrite::ATTACHMENT,
		'file'         => Rewrite::ATTACHMENT,
		'gallery'      => Rewrite::ATTACHMENT,
		'post_object'  => Rewrite::POST,
		'relationship' => Rewrite::POST,
		'page_link'    => Rewrite::POST,
		'taxonomy'     => Rewrite::TERM,
	);

	/**
	 * The field key stored beside a value, or null.
	 *
	 * @param array<string, mixed> $container Post meta (key => first value) or ACF block data.
	 */
	public static function fieldKey( array $container, string $name ): ?string {
		$key = $container[ '_' . $name ] ?? null;
		if ( is_array( $key ) ) {
			$key = $key[0] ?? null;
		}
		return is_string( $key ) && str_starts_with( $key, 'field_' ) ? $key : null;
	}

	/**
	 * The type of a field key. Clone fields store "field_a_field_b"; the
	 * last key is the cloned field.
	 *
	 * @param array<string, string> $types Field key => type.
	 */
	public static function type( array $types, ?string $fieldKey ): ?string {
		if ( null === $fieldKey ) {
			return null;
		}
		if ( isset( $types[ $fieldKey ] ) ) {
			return $types[ $fieldKey ];
		}
		$last = strrpos( $fieldKey, '_field_' );
		return false === $last ? null : ( $types[ substr( $fieldKey, $last + 1 ) ] ?? null );
	}

	/**
	 * The kind of ID a field type stores, or null when it stores none.
	 */
	public static function idKind( ?string $type ): ?string {
		return null === $type ? null : ( self::ID_TYPES[ $type ] ?? null );
	}
}
