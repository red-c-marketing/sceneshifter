<?php
/**
 * Ties together this site's keys, its pairings, the handshake and the
 * transport, so screens, REST endpoints and WP-CLI share one set.
 *
 * @package RedC\Sceneshifter
 */

declare(strict_types=1);

namespace RedC\Sceneshifter\Pairing;

use RedC\Sceneshifter\Crypto\NonceStore;
use RedC\Sceneshifter\Crypto\PairingCode;
use RedC\Sceneshifter\Crypto\TransportPolicy;
use RedC\Sceneshifter\Registry\Registry;
use RedC\Sceneshifter\Registry\Site;
use RedC\Sceneshifter\Store\KeyValueStore;
use RedC\Sceneshifter\Transport\Gate;
use RedC\Sceneshifter\Transport\Transport;
use RedC\Sceneshifter\Transport\TransportResult;

final class Sisters {

	public const STATUS_ROUTE = '/sceneshifter/v1/status';
	public const UNPAIR_ROUTE = '/sceneshifter/v1/unpair';

	private ?SiteKeys $keys = null;
	private PairingStore $pairings;
	private PendingCodes $codes;
	private ?Transport $transport = null;

	/** @var (callable(string, string, array<string, string>, string): (array{status: int, headers: array<string, string>, body: string}|string))|null */
	private $http;

	/**
	 * @param Site|null     $self           This site, or null when Sceneshifter is inactive here.
	 * @param Registry      $registry       The site list.
	 * @param KeyValueStore $store          Where keys, pairings and codes are kept.
	 * @param NonceStore    $nonces         Seen request nonces and used pairing tokens.
	 * @param string        $secretMaterial The wp-config salts (SecretMaterial::fromConstants()).
	 * @param bool          $allowDevHttp   Allow plain HTTP to .test and .localhost sisters.
	 * @param (callable(string, string, array<string, string>, string): (array{status: int, headers: array<string, string>, body: string}|string))|null $http HTTP function for the transport; for tests.
	 */
	public function __construct(
		private readonly ?Site $self,
		private readonly Registry $registry,
		KeyValueStore $store,
		private readonly NonceStore $nonces,
		string $secretMaterial,
		private readonly bool $allowDevHttp = false,
		?callable $http = null
	) {
		$this->pairings = new PairingStore( $store );
		$this->codes    = new PendingCodes( $store );
		$this->http     = $http;
		if ( null !== $self ) {
			$this->keys = new SiteKeys( $store, $secretMaterial, $self->id );
		}
	}

	public function self(): ?Site {
		return $this->self;
	}

	/**
	 * This site's keys, or null when Sceneshifter is inactive here.
	 */
	public function keys(): ?SiteKeys {
		return $this->keys;
	}

	public function pairings(): PairingStore {
		return $this->pairings;
	}

	public function allowsDevHttp(): bool {
		return $this->allowDevHttp;
	}

	/**
	 * Null when the site is inactive or has no usable key.
	 *
	 * @param bool $createKey Make this site's key if it has none yet.
	 */
	public function handshake( bool $createKey = false ): ?Handshake {
		$pair = $this->keys?->keyPair( $createKey );
		$salt = $this->keys?->saltFingerprint();
		if ( null === $this->self || null === $pair || null === $salt ) {
			return null;
		}
		return new Handshake( $this->registry, $this->self->id, $pair, $salt, $this->pairings, $this->codes, $this->nonces );
	}

	public function transport(): ?Transport {
		if ( null === $this->self ) {
			return null;
		}
		return $this->transport ??= new Transport(
			$this->self->id,
			$this->keys?->keyPair(),
			$this->registry,
			$this->pairings,
			new TransportPolicy( $this->allowDevHttp ),
			$this->http
		);
	}

	public function gate(): Gate {
		return new Gate( $this->self?->id, $this->keys?->keyPair(), $this->registry, $this->pairings, $this->nonces );
	}

	/**
	 * Site A: a code for the given sister to paste.
	 *
	 * @throws PairingException When this site can't pair.
	 */
	public function createCode( string $forSiteId, ?int $now = null ): PairingCode {
		return $this->requireHandshake()->createCode( $forSiteId, $now ?? time() );
	}

	/**
	 * Site B: checks a pasted code without contacting anyone.
	 *
	 * @throws PairingException When the code can't be used here.
	 */
	public function checkCode( string $input, ?int $now = null ): PairingCode {
		return $this->requireHandshake()->checkCode( $input, $now ?? time() );
	}

	/**
	 * Site B: contacts the site that made the code and, if it agrees,
	 * stores its key.
	 *
	 * @throws PairingException When pairing fails, with the reason.
	 */
	public function pair( string $input, ?int $now = null ): Pairing {
		$now       = $now ?? time();
		$handshake = $this->requireHandshake();
		$code      = $handshake->checkCode( $input, $now );
		$site      = $this->registry->get( $code->siteId );
		$transport = $this->transport();
		if ( null === $site || null === $transport ) {
			throw new PairingException( __( 'Sceneshifter is inactive on this site, so it can\'t pair.', 'sceneshifter' ) );
		}

		$result = $transport->sendWithKey( $site, $code->publicKey, 'POST', Handshake::ROUTE, $handshake->requestPayload( $code ) );
		if ( TransportResult::REFUSED === $result->code ) {
			throw new PairingException(
				sprintf(
					/* translators: %s: site name. */
					__( '%1$s didn\'t accept the code. It may have been used already, replaced by a newer code or expired, or the two servers\' clocks may differ by more than 5 minutes. Make a new code on %1$s and try again.', 'sceneshifter' ),
					$site->label
				)
			);
		}
		if ( ! $result->ok() ) {
			throw new PairingException( $result->message );
		}
		return $handshake->complete( $code, $result->data, $now );
	}

	/**
	 * Forgets a sister. Tells it first when it can be reached, so it forgets
	 * this site too.
	 *
	 * @return TransportResult|null The result of telling the sister, or null when it wasn't tried.
	 */
	public function unpair( string $siteId ): ?TransportResult {
		$result = null;
		$site   = $this->registry->get( $siteId );
		if ( null !== $site && $site->isPublic && null !== $this->pairings->get( $siteId ) && null !== $this->transport() ) {
			$result = $this->transport()->send( $siteId, 'POST', self::UNPAIR_ROUTE, array( 'site' => $this->self?->id ) );
		}
		$this->pairings->remove( $siteId );
		return $result;
	}

	/**
	 * Makes a new key pair and forgets every pairing and unused code, since
	 * sisters only know the old key.
	 */
	public function replaceKeys(): bool {
		if ( null === $this->keys || null === $this->keys->replace() ) {
			return false;
		}
		$this->pairings->clear();
		$this->codes->clear();
		$this->transport = null;
		return true;
	}

	/**
	 * Calls GET /status on a paired sister.
	 */
	public function status( string $siteId ): TransportResult {
		$transport = $this->transport();
		if ( null === $transport ) {
			return new TransportResult( TransportResult::NO_KEY, __( 'Sceneshifter is inactive on this site.', 'sceneshifter' ) );
		}
		return $transport->send( $siteId, 'GET', self::STATUS_ROUTE );
	}

	/**
	 * Which side of a pair should show the code. The site that pastes the
	 * code makes the call, so the code belongs on the site the other can
	 * reach.
	 *
	 * @return string One of 'either', 'here', 'there' or 'neither'.
	 */
	public static function codeSide( Site $self, Site $sister ): string {
		return match ( true ) {
			$self->isPublic && $sister->isPublic => 'either',
			$self->isPublic                      => 'here',
			$sister->isPublic                    => 'there',
			default                              => 'neither',
		};
	}

	private function requireHandshake(): Handshake {
		if ( null === $this->self ) {
			throw new PairingException( __( 'Sceneshifter is inactive on this site, so it can\'t pair. Check the site list first.', 'sceneshifter' ) );
		}
		$handshake = $this->handshake( true );
		if ( null === $handshake ) {
			throw new PairingException( self::keyProblem( $this->keys?->status() ?? SiteKeys::NO_SALTS ) );
		}
		return $handshake;
	}

	/**
	 * Explains a SiteKeys status that stops pairing.
	 */
	public static function keyProblem( string $status ): string {
		return match ( $status ) {
			SiteKeys::NO_SALTS => __( 'This site\'s wp-config.php has no unique salts, so Sceneshifter can\'t keep a key safe here. Set unique salts first.', 'sceneshifter' ),
			SiteKeys::UNREADABLE, SiteKeys::FOREIGN => __( 'This site\'s key was made on another environment, most likely because the database was copied from there. Sceneshifter won\'t send or accept requests until this site has a new key and is paired again.', 'sceneshifter' ),
			default => __( 'This site has no usable key.', 'sceneshifter' ),
		};
	}
}
