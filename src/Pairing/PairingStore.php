<?php
/**
 * The sisters this site is paired with, keyed by registry site ID.
 *
 * @package RedC\Sceneshifter
 */

declare(strict_types=1);

namespace RedC\Sceneshifter\Pairing;

use RedC\Sceneshifter\Store\KeyValueStore;

final class PairingStore {

	public const OPTION = 'sceneshifter_pairings';

	public function __construct( private readonly KeyValueStore $store ) {}

	/**
	 * @return array<string, Pairing>
	 */
	public function all(): array {
		$data = $this->store->get( self::OPTION, array() );
		if ( ! is_array( $data ) ) {
			return array();
		}
		$pairings = array();
		foreach ( $data as $siteId => $row ) {
			$pairing = is_array( $row ) ? Pairing::fromArray( (string) $siteId, $row ) : null;
			if ( null !== $pairing ) {
				$pairings[ (string) $siteId ] = $pairing;
			}
		}
		return $pairings;
	}

	public function get( string $siteId ): ?Pairing {
		return $this->all()[ $siteId ] ?? null;
	}

	public function publicKey( string $siteId ): ?string {
		return $this->get( $siteId )?->publicKey;
	}

	public function save( Pairing $pairing ): void {
		$data = $this->raw();
		$data[ $pairing->siteId ] = $pairing->toArray();
		$this->store->set( self::OPTION, $data );
	}

	public function remove( string $siteId ): void {
		$data = $this->raw();
		unset( $data[ $siteId ] );
		$this->store->set( self::OPTION, $data );
	}

	public function clear(): void {
		$this->store->delete( self::OPTION );
	}

	public function recordContact( string $siteId, ?int $now = null ): void {
		$pairing = $this->get( $siteId );
		if ( null !== $pairing ) {
			$this->save( $pairing->withContact( $now ?? time() ) );
		}
	}

	public function recordError( string $siteId, string $message, ?int $now = null ): void {
		$pairing = $this->get( $siteId );
		if ( null !== $pairing ) {
			$this->save( $pairing->withError( $message, $now ?? time() ) );
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	private function raw(): array {
		$data = $this->store->get( self::OPTION, array() );
		return is_array( $data ) ? $data : array();
	}
}
