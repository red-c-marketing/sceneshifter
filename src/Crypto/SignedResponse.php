<?php
/**
 * Signs the answer to a signed request, so the caller knows the reply came
 * from the sister it meant to reach and belongs to this request.
 *
 * The signature covers the request's sender, target and nonce, the HTTP
 * status and a SHA-256 hash of the exact response body.
 *
 * @package RedC\Sceneshifter
 */

declare(strict_types=1);

namespace RedC\Sceneshifter\Crypto;

final class SignedResponse {

	public const HEADER_SIGNATURE = 'X-Sceneshifter-Response-Signature';

	public static function canonical( string $requestSender, string $requestTarget, string $requestNonce, int $status, string $body ): string {
		return implode(
			"\n",
			array(
				'sceneshifter-response-v' . SignedRequest::VERSION,
				$requestSender,
				$requestTarget,
				$requestNonce,
				(string) $status,
				hash( 'sha256', $body ),
			)
		);
	}

	public static function sign( KeyPair $keys, string $requestSender, string $requestTarget, string $requestNonce, int $status, string $body ): string {
		return rtrim( strtr( base64_encode( $keys->sign( self::canonical( $requestSender, $requestTarget, $requestNonce, $status, $body ) ) ), '+/', '-_' ), '=' );
	}

	public static function verify( string $signature, string $publicKey, string $requestSender, string $requestTarget, string $requestNonce, int $status, string $body ): bool {
		$raw = base64_decode( strtr( trim( $signature ), '-_', '+/' ), true );
		return false !== $raw && KeyPair::verify( $raw, self::canonical( $requestSender, $requestTarget, $requestNonce, $status, $body ), $publicKey );
	}
}
