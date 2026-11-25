<?php
/**
 * Reads the wp-config salts that KeyVault derives its key from.
 *
 * Only the constants count. WordPress falls back to salts stored in the
 * database when a constant is missing or still says "put your unique phrase
 * here", but those travel with a copied database, which is exactly what the
 * vault must not trust.
 *
 * @package RedC\SisterSync
 */

declare(strict_types=1);

namespace RedC\SisterSync\Pairing;

final class SecretMaterial {

	public const CONSTANTS = array(
		'AUTH_KEY',
		'SECURE_AUTH_KEY',
		'LOGGED_IN_KEY',
		'NONCE_KEY',
		'AUTH_SALT',
		'SECURE_AUTH_SALT',
		'LOGGED_IN_SALT',
		'NONCE_SALT',
	);

	private const PLACEHOLDER = 'put your unique phrase here';

	/**
	 * The usable salts, joined. Empty when none are set.
	 *
	 * @param array<string, mixed>|null $values Constant values by name; read from the defined constants when null. For tests.
	 */
	public static function fromConstants( ?array $values = null ): string {
		$parts = array();
		foreach ( self::CONSTANTS as $name ) {
			if ( null !== $values ) {
				$value = $values[ $name ] ?? null;
			} else {
				$value = defined( $name ) ? constant( $name ) : null;
			}
			if ( ! is_string( $value ) || '' === trim( $value ) || self::PLACEHOLDER === $value ) {
				continue;
			}
			$parts[] = $name . '=' . $value;
		}
		return implode( "\n", $parts );
	}
}
