<?php
/**
 * Checks a signed request from a sister site. Anything that fails is
 * refused before WordPress content code runs; callers answer with a plain
 * 404 so the endpoint gives nothing away.
 *
 * @package RedC\SisterSync
 */

declare(strict_types=1);

namespace RedC\SisterSync\Crypto;

final class RequestVerifier {

	public const OK               = 'ok';
	public const MISSING_HEADERS  = 'missing_headers';
	public const BAD_VERSION      = 'bad_version';
	public const WRONG_TARGET     = 'wrong_target';
	public const STALE            = 'stale';
	public const UNKNOWN_SENDER   = 'unknown_sender';
	public const BAD_SIGNATURE    = 'bad_signature';
	public const REPLAYED         = 'replayed';

	/** How far a request's clock may be from ours, in seconds. */
	public const MAX_SKEW = 300;

	/** How long nonces are remembered, in seconds. */
	public const NONCE_TTL = 600;

	/**
	 * @param string                    $receiverId This site's ID.
	 * @param callable(string): ?string $publicKey  Raw public key of a paired sister, by site ID; null if not paired.
	 * @param NonceStore                $nonces     Seen nonces.
	 */
	public function __construct(
		private readonly string $receiverId,
		private $publicKey,
		private readonly NonceStore $nonces
	) {}

	/**
	 * @param array<string, string> $headers Request headers; names are matched case-insensitively.
	 * @return array{result: string, sender: ?string}
	 */
	public function verify( string $method, string $route, array $headers, string $body, ?int $now = null ): array {
		$now     = $now ?? time();
		$headers = array_change_key_case( $headers, CASE_LOWER );
		$get     = static fn( string $name ): string => trim( (string) ( $headers[ strtolower( $name ) ] ?? '' ) );

		$version   = $get( SignedRequest::HEADER_VERSION );
		$sender    = $get( SignedRequest::HEADER_SENDER );
		$target    = $get( SignedRequest::HEADER_TARGET );
		$timestamp = $get( SignedRequest::HEADER_TIMESTAMP );
		$nonce     = $get( SignedRequest::HEADER_NONCE );
		$signature = $get( SignedRequest::HEADER_SIGNATURE );

		if ( '' === $sender || '' === $target || ! ctype_digit( $timestamp ) || ! preg_match( '/^[A-Za-z0-9_-]{16,64}$/', $nonce ) || '' === $signature ) {
			return self::result( self::MISSING_HEADERS );
		}
		if ( SignedRequest::VERSION !== $version ) {
			return self::result( self::BAD_VERSION );
		}
		if ( ! hash_equals( $this->receiverId, $target ) ) {
			return self::result( self::WRONG_TARGET );
		}
		if ( abs( $now - (int) $timestamp ) > self::MAX_SKEW ) {
			return self::result( self::STALE );
		}

		$key = ( $this->publicKey )( $sender );
		if ( null === $key ) {
			return self::result( self::UNKNOWN_SENDER );
		}

		$raw = base64_decode( strtr( $signature, '-_', '+/' ), true );
		$msg = SignedRequest::canonical( $method, $route, $sender, $target, (int) $timestamp, $nonce, $body );
		if ( false === $raw || ! KeyPair::verify( $raw, $msg, $key ) ) {
			return self::result( self::BAD_SIGNATURE );
		}

		// Only signed requests reach the nonce store, so junk can't fill it.
		if ( ! $this->nonces->remember( $sender, $nonce, (int) $timestamp + self::NONCE_TTL ) ) {
			return self::result( self::REPLAYED );
		}

		return self::result( self::OK, $sender );
	}

	/**
	 * @return array{result: string, sender: ?string}
	 */
	private static function result( string $result, ?string $sender = null ): array {
		return array(
			'result' => $result,
			'sender' => $sender,
		);
	}
}
