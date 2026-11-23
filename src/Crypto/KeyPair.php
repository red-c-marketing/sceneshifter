<?php
/**
 * An Ed25519 signing key pair.
 *
 * @package RedC\SisterSync
 */

declare(strict_types=1);

namespace RedC\SisterSync\Crypto;

use InvalidArgumentException;

final class KeyPair {

	private function __construct(
		private readonly string $secretKey,
		public readonly string $publicKey
	) {}

	public static function generate(): self {
		$pair = sodium_crypto_sign_keypair();
		return new self( sodium_crypto_sign_secretkey( $pair ), sodium_crypto_sign_publickey( $pair ) );
	}

	/**
	 * @param string $secretKey Raw 64-byte Ed25519 secret key.
	 */
	public static function fromSecretKey( string $secretKey ): self {
		if ( SODIUM_CRYPTO_SIGN_SECRETKEYBYTES !== strlen( $secretKey ) ) {
			throw new InvalidArgumentException( 'Not an Ed25519 secret key.' );
		}
		return new self( $secretKey, sodium_crypto_sign_publickey_from_secretkey( $secretKey ) );
	}

	public function sign( string $message ): string {
		return sodium_crypto_sign_detached( $message, $this->secretKey );
	}

	public static function verify( string $signature, string $message, string $publicKey ): bool {
		if ( SODIUM_CRYPTO_SIGN_BYTES !== strlen( $signature ) || SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES !== strlen( $publicKey ) ) {
			return false;
		}
		return sodium_crypto_sign_verify_detached( $signature, $message, $publicKey );
	}

	/**
	 * Only for KeyVault, which encrypts it before it is stored.
	 */
	public function secretKey(): string {
		return $this->secretKey;
	}

	/**
	 * A short code admins compare on both screens when pairing, in four
	 * groups of four characters, for example 3F9A-C21B-77D0-4E5A.
	 */
	public static function fingerprint( string $publicKey ): string {
		$hash = strtoupper( substr( hash( 'sha256', $publicKey ), 0, 16 ) );
		return implode( '-', str_split( $hash, 4 ) );
	}
}
