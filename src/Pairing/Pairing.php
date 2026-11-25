<?php
/**
 * What this site knows about one paired sister.
 *
 * @package RedC\SisterSync
 */

declare(strict_types=1);

namespace RedC\SisterSync\Pairing;

use RedC\SisterSync\Crypto\KeyPair;

final class Pairing {

	/**
	 * @param string      $siteId      The sister's registry ID.
	 * @param string      $publicKey   Its raw Ed25519 public key.
	 * @param int         $pairedAt    Unix time.
	 * @param int|null    $lastContact Unix time of the last successful exchange, either way.
	 * @param string|null $lastError   The last failure when this site called the sister.
	 * @param int|null    $lastErrorAt Unix time of that failure.
	 */
	public function __construct(
		public readonly string $siteId,
		public readonly string $publicKey,
		public readonly int $pairedAt,
		public readonly ?int $lastContact = null,
		public readonly ?string $lastError = null,
		public readonly ?int $lastErrorAt = null
	) {}

	public function fingerprint(): string {
		return KeyPair::fingerprint( $this->publicKey );
	}

	public function withContact( int $now ): self {
		return new self( $this->siteId, $this->publicKey, $this->pairedAt, $now, null, null );
	}

	public function withError( string $message, int $now ): self {
		return new self( $this->siteId, $this->publicKey, $this->pairedAt, $this->lastContact, $message, $now );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return array(
			'key'           => self::b64( $this->publicKey ),
			'paired_at'     => $this->pairedAt,
			'last_contact'  => $this->lastContact,
			'last_error'    => $this->lastError,
			'last_error_at' => $this->lastErrorAt,
		);
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public static function fromArray( string $siteId, array $data ): ?self {
		$key = base64_decode( strtr( (string) ( $data['key'] ?? '' ), '-_', '+/' ), true );
		if ( false === $key || SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES !== strlen( $key ) ) {
			return null;
		}
		return new self(
			$siteId,
			$key,
			(int) ( $data['paired_at'] ?? 0 ),
			isset( $data['last_contact'] ) ? (int) $data['last_contact'] : null,
			isset( $data['last_error'] ) ? (string) $data['last_error'] : null,
			isset( $data['last_error_at'] ) ? (int) $data['last_error_at'] : null
		);
	}

	private static function b64( string $bytes ): string {
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
	}
}
