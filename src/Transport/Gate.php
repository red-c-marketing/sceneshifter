<?php
/**
 * Decides whether a signed request from a sister may be answered. Runs
 * before any content code. Every refusal looks the same to the caller: a
 * plain 404.
 *
 * @package RedC\SisterSync
 */

declare(strict_types=1);

namespace RedC\SisterSync\Transport;

use RedC\SisterSync\Crypto\KeyPair;
use RedC\SisterSync\Crypto\NonceStore;
use RedC\SisterSync\Crypto\RequestVerifier;
use RedC\SisterSync\Pairing\PairingStore;
use RedC\SisterSync\Registry\Registry;

final class Gate {

	/** Largest request body accepted, in bytes. Media will travel in chunks below this. */
	public const MAX_BODY = 1048576;

	/**
	 * @param string|null  $selfId   This site's registry ID, or null when Sister Sync is inactive here.
	 * @param KeyPair|null $keys     This site's key pair, or null when it has none usable.
	 * @param Registry     $registry The site list.
	 * @param PairingStore $pairings Paired sisters.
	 * @param NonceStore   $nonces   Seen nonces.
	 */
	public function __construct(
		private readonly ?string $selfId,
		private readonly ?KeyPair $keys,
		private readonly Registry $registry,
		private readonly PairingStore $pairings,
		private readonly NonceStore $nonces
	) {}

	/**
	 * Whether this site takes signed requests at all: it must be identified
	 * in the site list and hold a key it can use.
	 */
	public function isOpen(): bool {
		return null !== $this->selfId && null !== $this->keys;
	}

	/**
	 * @param array<string, string> $headers Request headers.
	 * @return string|null The sender's site ID, or null to refuse.
	 */
	public function verify( string $method, string $route, array $headers, string $body, ?int $now = null ): ?string {
		if ( null === $this->selfId || null === $this->keys || strlen( $body ) > self::MAX_BODY ) {
			return null;
		}
		$selfId   = $this->selfId;
		$registry = $this->registry;
		$pairings = $this->pairings;
		$verifier = new RequestVerifier(
			$selfId,
			static function ( string $sender ) use ( $selfId, $registry, $pairings ): ?string {
				if ( $sender === $selfId || null === $registry->get( $sender ) ) {
					return null;
				}
				return $pairings->publicKey( $sender );
			},
			$this->nonces
		);
		$result = $verifier->verify( $method, $route, $headers, $body, $now );
		return RequestVerifier::OK === $result['result'] ? $result['sender'] : null;
	}
}
