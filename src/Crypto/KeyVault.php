<?php
/**
 * Encrypts the site's private key with a key derived from the site's own
 * secrets (the wp-config salts). A database copied to another environment
 * carries the encrypted key, but that environment has different salts and
 * can't decrypt it, so it can't impersonate the original site.
 *
 * @package RedC\SisterSync
 */

declare(strict_types=1);

namespace RedC\SisterSync\Crypto;

use InvalidArgumentException;

final class KeyVault {

	private const VERSION = 'v1';

	private string $key;

	/**
	 * @param string $secretMaterial The site's salts, concatenated. Never stored.
	 */
	public function __construct( string $secretMaterial ) {
		if ( strlen( $secretMaterial ) < 32 ) {
			throw new InvalidArgumentException( 'The site\'s secret keys are missing or too short. Set unique salts in wp-config.php.' );
		}
		$this->key = sodium_crypto_generichash( 'sister-sync-key-vault|' . $secretMaterial, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}

	/**
	 * A fingerprint of the secret material, so pairing can refuse two sites
	 * that share salts (and so could read each other's keys).
	 */
	public function saltFingerprint(): string {
		return substr( bin2hex( sodium_crypto_generichash( 'sister-sync-salt-check|' . $this->key, '', 16 ) ), 0, 16 );
	}

	public function seal( KeyPair $pair ): string {
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		return self::VERSION . '.' . self::b64( $nonce . sodium_crypto_secretbox( $pair->secretKey(), $nonce, $this->key ) );
	}

	/**
	 * @return KeyPair|null The key pair, or null when it can't be decrypted
	 *                      here (wrong salts: most likely a copied database).
	 */
	public function open( string $sealed ): ?KeyPair {
		if ( ! str_starts_with( $sealed, self::VERSION . '.' ) ) {
			return null;
		}
		$raw = base64_decode( strtr( substr( $sealed, strlen( self::VERSION ) + 1 ), '-_', '+/' ), true );
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return null;
		}
		$plain = sodium_crypto_secretbox_open(
			substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
			substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
			$this->key
		);
		if ( false === $plain ) {
			return null;
		}
		try {
			return KeyPair::fromSecretKey( $plain );
		} catch ( InvalidArgumentException $e ) {
			return null;
		}
	}

	private static function b64( string $bytes ): string {
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
	}
}
