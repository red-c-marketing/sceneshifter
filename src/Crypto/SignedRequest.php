<?php
/**
 * Signs requests between sister sites.
 *
 * The signature covers the method, REST route, sender, target, time, a
 * random nonce and a SHA-256 hash of the body, so nothing in a request can
 * be changed, and a request meant for one site can't be replayed against
 * another.
 *
 * @package RedC\SisterSync
 */

declare(strict_types=1);

namespace RedC\SisterSync\Crypto;

final class SignedRequest {

	public const VERSION = '1';

	public const HEADER_VERSION   = 'X-Sister-Sync-Version';
	public const HEADER_SENDER    = 'X-Sister-Sync-Sender';
	public const HEADER_TARGET    = 'X-Sister-Sync-Target';
	public const HEADER_TIMESTAMP = 'X-Sister-Sync-Timestamp';
	public const HEADER_NONCE     = 'X-Sister-Sync-Nonce';
	public const HEADER_SIGNATURE = 'X-Sister-Sync-Signature';

	/**
	 * The exact text that is signed.
	 */
	public static function canonical( string $method, string $route, string $senderId, string $targetId, int $timestamp, string $nonce, string $body ): string {
		return implode(
			"\n",
			array(
				'sister-sync-v' . self::VERSION,
				strtoupper( $method ),
				$route,
				$senderId,
				$targetId,
				(string) $timestamp,
				$nonce,
				hash( 'sha256', $body ),
			)
		);
	}

	/**
	 * Headers to send with a request.
	 *
	 * @param string   $route    REST route, for example /sister-sync/v1/import.
	 * @param int|null $now      Current time; for tests.
	 * @param string   $nonce    Random value; generated when empty.
	 * @return array<string, string>
	 */
	public static function headers( KeyPair $keys, string $method, string $route, string $senderId, string $targetId, string $body, ?int $now = null, string $nonce = '' ): array {
		$timestamp = $now ?? time();
		$nonce     = '' !== $nonce ? $nonce : rtrim( strtr( base64_encode( random_bytes( 18 ) ), '+/', '-_' ), '=' );
		$signature = $keys->sign( self::canonical( $method, $route, $senderId, $targetId, $timestamp, $nonce, $body ) );

		return array(
			self::HEADER_VERSION   => self::VERSION,
			self::HEADER_SENDER    => $senderId,
			self::HEADER_TARGET    => $targetId,
			self::HEADER_TIMESTAMP => (string) $timestamp,
			self::HEADER_NONCE     => $nonce,
			self::HEADER_SIGNATURE => rtrim( strtr( base64_encode( $signature ), '+/', '-_' ), '=' ),
		);
	}
}
