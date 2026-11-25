<?php
/**
 * This site's own Ed25519 key pair, stored encrypted by KeyVault.
 *
 * The key is made the first time it is needed. If the stored key can't be
 * opened here (the database came from an environment with other salts), or
 * was made for a different site ID (copied from an environment with the
 * same salts), the site needs a new key and new pairings. Until then it
 * sends and accepts no signed requests.
 *
 * @package RedC\SisterSync
 */

declare(strict_types=1);

namespace RedC\SisterSync\Pairing;

use InvalidArgumentException;
use RedC\SisterSync\Crypto\KeyPair;
use RedC\SisterSync\Crypto\KeyVault;
use RedC\SisterSync\Store\KeyValueStore;

final class SiteKeys {

	public const OPTION = 'sister_sync_keys';

	public const OK         = 'ok';
	public const MISSING    = 'missing';
	public const NO_SALTS   = 'no_salts';
	public const UNREADABLE = 'unreadable';
	public const FOREIGN    = 'foreign';

	private ?KeyVault $vault = null;

	private ?KeyPair $cached = null;

	/**
	 * @param KeyValueStore $store          Where the sealed key lives.
	 * @param string        $secretMaterial SecretMaterial::fromConstants().
	 * @param string        $siteId         This site's registry ID.
	 */
	public function __construct(
		private readonly KeyValueStore $store,
		string $secretMaterial,
		private readonly string $siteId
	) {
		try {
			$this->vault = new KeyVault( $secretMaterial );
		} catch ( InvalidArgumentException $e ) {
			$this->vault = null;
		}
	}

	public function status(): string {
		if ( null === $this->vault ) {
			return self::NO_SALTS;
		}
		$stored = $this->store->get( self::OPTION );
		if ( ! is_array( $stored ) || ! is_string( $stored['sealed'] ?? null ) ) {
			return self::MISSING;
		}
		if ( ( $stored['site'] ?? null ) !== $this->siteId ) {
			return self::FOREIGN;
		}
		return null === $this->open( $stored['sealed'] ) ? self::UNREADABLE : self::OK;
	}

	/**
	 * Whether this site must make a new key (and pair again) before it can
	 * send or accept signed requests.
	 */
	public function needsNewKey(): bool {
		return in_array( $this->status(), array( self::UNREADABLE, self::FOREIGN ), true );
	}

	/**
	 * The key pair, or null when there is none usable.
	 *
	 * @param bool $create Make one if none has been made yet.
	 */
	public function keyPair( bool $create = false ): ?KeyPair {
		if ( null !== $this->cached ) {
			return $this->cached;
		}
		$status = $this->status();
		if ( self::OK === $status ) {
			$stored = (array) $this->store->get( self::OPTION );
			return $this->cached = $this->open( (string) $stored['sealed'] );
		}
		if ( self::MISSING === $status && $create ) {
			return $this->replace();
		}
		return null;
	}

	/**
	 * Makes and stores a new key pair. The caller must also forget every
	 * pairing, because sisters only know the old public key.
	 */
	public function replace( ?int $now = null ): ?KeyPair {
		if ( null === $this->vault ) {
			return null;
		}
		$pair = KeyPair::generate();
		$this->store->set(
			self::OPTION,
			array(
				'sealed'  => $this->vault->seal( $pair ),
				'site'    => $this->siteId,
				'created' => $now ?? time(),
			)
		);
		return $this->cached = $pair;
	}

	/**
	 * A one-way fingerprint of this site's salts, exchanged while pairing so
	 * two sites with the same salts can be refused. Null without salts.
	 */
	public function saltFingerprint(): ?string {
		return $this->vault?->saltFingerprint();
	}

	public function fingerprint(): ?string {
		$pair = $this->keyPair();
		return null === $pair ? null : KeyPair::fingerprint( $pair->publicKey );
	}

	private function open( string $sealed ): ?KeyPair {
		return $this->vault?->open( $sealed );
	}
}
