<?php
/**
 * The one-time code an admin copies from one site to the other to pair
 * them: the site's ID, its public key, a random token and an expiry.
 *
 * @package RedC\Sceneshifter
 */

declare(strict_types=1);

namespace RedC\Sceneshifter\Crypto;

use InvalidArgumentException;
use JsonException;

final class PairingCode {

	public const PREFIX   = 'SSPAIR1-';
	public const LIFETIME = 900;

	/**
	 * @param string $siteId          The site that made the code.
	 * @param string $publicKey       Its raw Ed25519 public key.
	 * @param string $token           Random token the other site sends back to prove it holds the code.
	 * @param int    $expiresAt       Unix time.
	 * @param string $saltFingerprint KeyVault::saltFingerprint(), so sites sharing salts can be refused.
	 * @param string $forSiteId       The site the code is meant for, so pasting it on the wrong site fails early. Optional.
	 */
	public function __construct(
		public readonly string $siteId,
		public readonly string $publicKey,
		public readonly string $token,
		public readonly int $expiresAt,
		public readonly string $saltFingerprint,
		public readonly string $forSiteId = ''
	) {}

	public static function create( string $siteId, KeyPair $keys, string $saltFingerprint, ?int $now = null, string $forSiteId = '' ): self {
		return new self( $siteId, $keys->publicKey, self::b64( random_bytes( 32 ) ), ( $now ?? time() ) + self::LIFETIME, $saltFingerprint, $forSiteId );
	}

	public function encode(): string {
		$data = array(
			'site'  => $this->siteId,
			'key'   => self::b64( $this->publicKey ),
			'token' => $this->token,
			'exp'   => $this->expiresAt,
			'salt'  => $this->saltFingerprint,
		);
		if ( '' !== $this->forSiteId ) {
			$data['for'] = $this->forSiteId;
		}
		$json = json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR );
		return self::PREFIX . self::b64( $json );
	}

	/**
	 * @throws InvalidArgumentException When the code is malformed or has expired.
	 */
	public static function decode( string $code, ?int $now = null ): self {
		$code = preg_replace( '/\s+/', '', $code ) ?? '';
		if ( ! str_starts_with( $code, self::PREFIX ) ) {
			throw new InvalidArgumentException( 'This isn\'t a Sceneshifter pairing code.' );
		}
		$json = base64_decode( strtr( substr( $code, strlen( self::PREFIX ) ), '-_', '+/' ), true );
		try {
			$data = false === $json ? null : json_decode( $json, true, 4, JSON_THROW_ON_ERROR );
		} catch ( JsonException $e ) {
			$data = null;
		}
		if ( ! is_array( $data ) ) {
			throw new InvalidArgumentException( 'This pairing code is incomplete. Copy all of it and try again.' );
		}

		$key = base64_decode( strtr( (string) ( $data['key'] ?? '' ), '-_', '+/' ), true );
		if ( ! is_string( $data['site'] ?? null ) || ! preg_match( '/^[a-z0-9][a-z0-9-]{0,31}$/', $data['site'] )
			|| false === $key || SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES !== strlen( $key )
			|| ! is_string( $data['token'] ?? null ) || strlen( $data['token'] ) < 40
			|| ! is_int( $data['exp'] ?? null ) || ! is_string( $data['salt'] ?? null )
			|| ( isset( $data['for'] ) && ( ! is_string( $data['for'] ) || ! preg_match( '/^[a-z0-9][a-z0-9-]{0,31}$/', $data['for'] ) ) ) ) {
			throw new InvalidArgumentException( 'This pairing code is incomplete. Copy all of it and try again.' );
		}
		if ( $data['exp'] < ( $now ?? time() ) ) {
			throw new InvalidArgumentException( 'This pairing code has expired. Make a new one on the other site.' );
		}

		return new self( $data['site'], $key, $data['token'], $data['exp'], $data['salt'], (string) ( $data['for'] ?? '' ) );
	}

	private static function b64( string $bytes ): string {
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
	}
}
