<?php
/**
 * The pairing handshake.
 *
 * 1. Site A (which the other site can reach) makes a one-time code for
 *    site B: A's ID, A's public key, a random token, an expiry, a one-way
 *    fingerprint of A's salts and B's ID.
 * 2. An admin pastes the code on B. B checks it and shows both key
 *    fingerprints. When the admin confirms, B calls POST /pair on A, signed
 *    with B's key, sending the token, B's ID, B's public key and B's salt
 *    fingerprint.
 * 3. A checks the token was made for B and is unused, the signature
 *    matches the key B sent, and the salts differ. It uses up the token,
 *    stores B's key and answers, signed with its own key.
 * 4. B checks the answer against the key in the code and stores A's key.
 *
 * @package RedC\Sceneshifter
 */

declare(strict_types=1);

namespace RedC\Sceneshifter\Pairing;

use InvalidArgumentException;
use JsonException;
use RedC\Sceneshifter\Crypto\KeyPair;
use RedC\Sceneshifter\Crypto\NonceStore;
use RedC\Sceneshifter\Crypto\PairingCode;
use RedC\Sceneshifter\Crypto\RequestVerifier;
use RedC\Sceneshifter\Registry\Registry;

final class Handshake {

	public const ROUTE = '/sceneshifter/v1/pair';

	/** Pseudo sender under which used tokens go in the nonce store. Can't clash with a site ID. */
	public const TOKEN_SENDER = '#pair';

	public function __construct(
		private readonly Registry $registry,
		private readonly string $selfId,
		private readonly KeyPair $keys,
		private readonly string $saltFingerprint,
		private readonly PairingStore $pairings,
		private readonly PendingCodes $codes,
		private readonly NonceStore $nonces
	) {}

	/**
	 * Site A: makes a code for a sister to paste. Replaces any earlier code
	 * for that sister.
	 *
	 * @throws PairingException When the sister isn't in the site list, or
	 *                          couldn't use the code because this site isn't public.
	 */
	public function createCode( string $forSiteId, int $now ): PairingCode {
		$sister = $forSiteId === $this->selfId ? null : $this->registry->get( $forSiteId );
		if ( null === $sister ) {
			throw new PairingException( __( 'Choose another site from the list to pair with.', 'sceneshifter' ) );
		}
		$self = $this->registry->get( $this->selfId );
		if ( null !== $self && ! $self->isPublic ) {
			throw new PairingException(
				sprintf(
					/* translators: %s: site name. */
					__( 'This site isn\'t public, so %1$s can\'t contact it to use a code made here. Make the code on %1$s and paste it here instead.', 'sceneshifter' ),
					$sister->label
				)
			);
		}
		$code = PairingCode::create( $this->selfId, $this->keys, $this->saltFingerprint, $now, $forSiteId );
		$this->codes->add( $code, $forSiteId, $now );
		return $code;
	}

	/**
	 * Site B: checks a pasted code before anything is sent.
	 *
	 * @throws PairingException With a message saying what is wrong.
	 */
	public function checkCode( string $input, int $now ): PairingCode {
		try {
			$code = PairingCode::decode( $input, $now );
		} catch ( InvalidArgumentException $e ) {
			throw new PairingException( $e->getMessage() );
		}

		if ( $code->siteId === $this->selfId ) {
			throw new PairingException( __( 'This code was made on this site. Paste it on the other site instead.', 'sceneshifter' ) );
		}
		$from = $this->registry->get( $code->siteId );
		if ( null === $from ) {
			throw new PairingException(
				sprintf(
					/* translators: %s: site ID. */
					__( 'This code comes from a site called "%s", which isn\'t in this site\'s list. Check that both sites have the same list of sites.', 'sceneshifter' ),
					$code->siteId
				)
			);
		}
		if ( ! $from->isPublic ) {
			throw new PairingException(
				sprintf(
					/* translators: %s: site name. */
					__( '%1$s isn\'t public, so this site can\'t contact it to use its code. Make the code on this site instead and paste it on %1$s.', 'sceneshifter' ),
					$from->label
				)
			);
		}
		if ( '' !== $code->forSiteId && $code->forSiteId !== $this->selfId ) {
			$for = $this->registry->get( $code->forSiteId );
			throw new PairingException(
				sprintf(
					/* translators: 1: site name the code was made on, 2: site name it was made for. */
					__( 'This code was made on %1$s for %2$s, not for this site. Make a new code on %1$s and choose this site.', 'sceneshifter' ),
					$from->label,
					null === $for ? $code->forSiteId : $for->label
				)
			);
		}
		if ( hash_equals( $this->keys->publicKey, $code->publicKey ) ) {
			throw new PairingException( self::sameSaltsMessage( $from->label ) );
		}
		if ( hash_equals( $this->saltFingerprint, $code->saltFingerprint ) ) {
			throw new PairingException( self::sameSaltsMessage( $from->label ) );
		}
		return $code;
	}

	/**
	 * Site B: what to send to POST /pair.
	 *
	 * @return array{site: string, key: string, salt: string, token: string}
	 */
	public function requestPayload( PairingCode $code ): array {
		return array(
			'site'  => $this->selfId,
			'key'   => self::b64( $this->keys->publicKey ),
			'salt'  => $this->saltFingerprint,
			'token' => $code->token,
		);
	}

	/**
	 * Site A: handles POST /pair. Returns the new pairing, or null to
	 * refuse with a plain 404.
	 *
	 * @param array<string, string> $headers Request headers.
	 */
	public function accept( string $method, string $route, array $headers, string $body, int $now ): ?Pairing {
		if ( 'POST' !== strtoupper( $method ) || self::ROUTE !== $route ) {
			return null;
		}
		try {
			$data = json_decode( $body, true, 4, JSON_THROW_ON_ERROR );
		} catch ( JsonException $e ) {
			return null;
		}
		if ( ! is_array( $data ) ) {
			return null;
		}
		$site  = $data['site'] ?? null;
		$token = $data['token'] ?? null;
		$salt  = $data['salt'] ?? null;
		$key   = is_string( $data['key'] ?? null ) ? base64_decode( strtr( $data['key'], '-_', '+/' ), true ) : false;
		if ( ! is_string( $site ) || ! is_string( $token ) || ! is_string( $salt ) || false === $key || SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES !== strlen( $key ) ) {
			return null;
		}

		// The token must be one this site made for exactly this sister.
		if ( $site === $this->selfId || null === $this->registry->get( $site ) || $this->codes->siteFor( $token, $now ) !== $site ) {
			return null;
		}
		if ( hash_equals( $this->saltFingerprint, $salt ) || hash_equals( $this->keys->publicKey, $key ) ) {
			return null;
		}

		// The request must be signed by the key it carries, for this site,
		// recently, and not seen before.
		$verifier = new RequestVerifier( $this->selfId, static fn( string $sender ): ?string => $sender === $site ? $key : null, $this->nonces );
		if ( RequestVerifier::OK !== $verifier->verify( $method, $route, $headers, $body, $now )['result'] ) {
			return null;
		}

		// Use the token up. The insert is atomic, so two racing requests
		// can't both pair with one code.
		if ( ! $this->nonces->remember( self::TOKEN_SENDER, PendingCodes::hash( $token ), $now + PairingCode::LIFETIME ) ) {
			return null;
		}
		$this->codes->remove( $token );

		$pairing = new Pairing( $site, $key, $now, $now );
		$this->pairings->save( $pairing );
		return $pairing;
	}

	/**
	 * Site A: the answer to a successful POST /pair.
	 *
	 * @return array{site: string, fingerprint: string, peer_fingerprint: string}
	 */
	public function responseData( Pairing $peer ): array {
		return array(
			'site'             => $this->selfId,
			'fingerprint'      => KeyPair::fingerprint( $this->keys->publicKey ),
			'peer_fingerprint' => $peer->fingerprint(),
		);
	}

	/**
	 * Site B: stores the pairing once A has answered. The transport has
	 * already checked the answer is signed with the key in the code.
	 *
	 * @param array<string, mixed> $data A's answer.
	 * @throws PairingException When the answer doesn't match.
	 */
	public function complete( PairingCode $code, array $data, int $now ): Pairing {
		if ( ( $data['site'] ?? null ) !== $code->siteId ) {
			throw new PairingException( __( 'The other site answered as a different site. Check the addresses in the site list.', 'sceneshifter' ) );
		}
		if ( ( $data['peer_fingerprint'] ?? null ) !== KeyPair::fingerprint( $this->keys->publicKey ) ) {
			throw new PairingException( __( 'The other site received a different key for this site. Something between the two sites may have changed the request. Nothing was stored here.', 'sceneshifter' ) );
		}
		$pairing = new Pairing( $code->siteId, $code->publicKey, $now, $now );
		$this->pairings->save( $pairing );
		return $pairing;
	}

	public static function sameSaltsMessage( string $label ): string {
		return sprintf(
			/* translators: %s: site name. */
			__( 'This site and %s have the same wp-config salts, so a copy of one site\'s database could pretend to be the other. Pairing is refused. Give each environment its own salts (live keeps its own), then try again.', 'sceneshifter' ),
			$label
		);
	}

	private static function b64( string $bytes ): string {
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
	}
}
