<?php
/**
 * The permanent ID that recognises the same post on every sister. It is
 * stored in post meta, so a copied database carries it across unchanged.
 *
 * @package RedC\Sceneshifter
 */

declare(strict_types=1);

namespace RedC\Sceneshifter\SyncMap;

final class Uuid {

	public const META_KEY = '_sceneshifter_uuid';

	public static function isValid( mixed $value ): bool {
		return is_string( $value ) && 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $value );
	}

	public static function generate(): string {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return wp_generate_uuid4();
		}
		$bytes    = random_bytes( 16 );
		$bytes[6] = chr( ( ord( $bytes[6] ) & 0x0f ) | 0x40 );
		$bytes[8] = chr( ( ord( $bytes[8] ) & 0x3f ) | 0x80 );
		return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $bytes ), 4 ) );
	}

	/**
	 * Meta keys that duplicate-post plugins must not copy, or the copy
	 * would claim to be the same content as the original.
	 *
	 * @param mixed $keys Keys the plugin already leaves out.
	 * @return mixed
	 */
	public static function excludeFromCopies( $keys ) {
		if ( is_array( $keys ) ) {
			$keys[] = self::META_KEY;
		}
		return $keys;
	}
}
