<?php
/**
 * Which sister addresses requests may be sent to: HTTPS only, except local
 * development hosts when the dev setting allows plain HTTP.
 *
 * @package RedC\SisterSync
 */

declare(strict_types=1);

namespace RedC\SisterSync\Crypto;

final class TransportPolicy {

	private const DEV_SUFFIXES = array( '.test', '.localhost' );

	public function __construct( private readonly bool $allowDevHttp = false ) {}

	public function allows( string $url ): bool {
		$parts = parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return false;
		}
		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		if ( 'https' === $scheme ) {
			return true;
		}
		return 'http' === $scheme && $this->allowDevHttp && self::isDevHost( strtolower( $parts['host'] ) );
	}

	public static function isDevHost( string $host ): bool {
		if ( 'localhost' === $host ) {
			return true;
		}
		foreach ( self::DEV_SUFFIXES as $suffix ) {
			if ( str_ends_with( $host, $suffix ) ) {
				return true;
			}
		}
		return false;
	}
}
