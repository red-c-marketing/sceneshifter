<?php
/**
 * Pairing codes this site has shown and not yet seen used. Only a hash of
 * each token is kept. One code per sister at a time: a new code replaces
 * the last one.
 *
 * @package RedC\Sceneshifter
 */

declare(strict_types=1);

namespace RedC\Sceneshifter\Pairing;

use RedC\Sceneshifter\Crypto\PairingCode;
use RedC\Sceneshifter\Store\KeyValueStore;

final class PendingCodes {

	public const OPTION = 'sceneshifter_pairing_codes';

	public function __construct( private readonly KeyValueStore $store ) {}

	public static function hash( string $token ): string {
		return hash( 'sha256', $token );
	}

	public function add( PairingCode $code, string $forSiteId, int $now ): void {
		$codes = array_filter(
			$this->live( $now ),
			static fn( array $row ): bool => $row['for'] !== $forSiteId
		);
		$codes[ self::hash( $code->token ) ] = array(
			'for'     => $forSiteId,
			'expires' => $code->expiresAt,
		);
		$this->store->set( self::OPTION, $codes );
	}

	/**
	 * The sister a token was made for, if it is still waiting to be used.
	 */
	public function siteFor( string $token, int $now ): ?string {
		$codes = $this->live( $now );
		$hash  = self::hash( $token );
		foreach ( $codes as $stored => $row ) {
			if ( hash_equals( (string) $stored, $hash ) ) {
				return $row['for'];
			}
		}
		return null;
	}

	public function remove( string $token ): void {
		$codes = $this->raw();
		unset( $codes[ self::hash( $token ) ] );
		$this->store->set( self::OPTION, $codes );
	}

	public function clear(): void {
		$this->store->delete( self::OPTION );
	}

	/**
	 * Unexpired codes.
	 *
	 * @return array<string, array{for: string, expires: int}>
	 */
	private function live( int $now ): array {
		return array_filter( $this->raw(), static fn( array $row ): bool => $row['expires'] >= $now );
	}

	/**
	 * @return array<string, array{for: string, expires: int}>
	 */
	private function raw(): array {
		$data  = $this->store->get( self::OPTION, array() );
		$codes = array();
		if ( is_array( $data ) ) {
			foreach ( $data as $hash => $row ) {
				if ( is_array( $row ) && is_string( $row['for'] ?? null ) && is_int( $row['expires'] ?? null ) ) {
					$codes[ (string) $hash ] = array(
						'for'     => $row['for'],
						'expires' => $row['expires'],
					);
				}
			}
		}
		return $codes;
	}
}
