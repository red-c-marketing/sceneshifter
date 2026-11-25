<?php
/**
 * Sends signed requests to paired sisters and checks their signed replies.
 *
 * The site that starts an operation makes every call, because dev sites
 * are not public. No retries or chunking yet.
 *
 * @package RedC\Sceneshifter
 */

declare(strict_types=1);

namespace RedC\Sceneshifter\Transport;

use JsonException;
use RedC\Sceneshifter\Crypto\KeyPair;
use RedC\Sceneshifter\Crypto\SignedRequest;
use RedC\Sceneshifter\Crypto\SignedResponse;
use RedC\Sceneshifter\Crypto\TransportPolicy;
use RedC\Sceneshifter\Pairing\PairingStore;
use RedC\Sceneshifter\Registry\Registry;
use RedC\Sceneshifter\Registry\Site;

final class Transport {

	public const TIMEOUT = 15;

	/** @var callable(string, string, array<string, string>, string): (array{status: int, headers: array<string, string>, body: string}|string) */
	private $http;

	/** @var callable(): float */
	private $clock;

	/**
	 * @param string                                                                                                               $selfId   This site's registry ID.
	 * @param KeyPair|null                                                                                                         $keys     This site's key pair; null when it has none usable.
	 * @param Registry                                                                                                             $registry The site list.
	 * @param PairingStore                                                                                                         $pairings Paired sisters.
	 * @param TransportPolicy                                                                                                      $policy   Which addresses may be called.
	 * @param (callable(string, string, array<string, string>, string): (array{status: int, headers: array<string, string>, body: string}|string))|null $http HTTP function; WpHttp by default.
	 * @param (callable(): float)|null                                                                                             $clock    Current time in seconds; for tests.
	 */
	public function __construct(
		private readonly string $selfId,
		private readonly ?KeyPair $keys,
		private readonly Registry $registry,
		private readonly PairingStore $pairings,
		private readonly TransportPolicy $policy,
		?callable $http = null,
		?callable $clock = null
	) {
		$this->http  = $http ?? new WpHttp();
		$this->clock = $clock ?? static fn(): float => microtime( true );
	}

	/**
	 * The full address of a REST route on a sister.
	 */
	public static function url( Site $site, string $route ): string {
		return rtrim( $site->url, '/' ) . '/wp-json' . $route;
	}

	/**
	 * Sends a signed request to a paired sister and records the outcome
	 * against the pairing.
	 *
	 * @param string                    $siteId  The sister's registry ID.
	 * @param string                    $method  GET or POST.
	 * @param string                    $route   REST route, for example /sceneshifter/v1/status.
	 * @param array<string, mixed>|null $payload JSON body for POST.
	 */
	public function send( string $siteId, string $method, string $route, ?array $payload = null ): TransportResult {
		$site = $this->registry->get( $siteId );
		if ( null === $site ) {
			/* translators: %s: site ID. */
			return new TransportResult( TransportResult::NOT_IN_REGISTRY, sprintf( __( '%s isn\'t in this site\'s list of sites.', 'sceneshifter' ), $siteId ) );
		}
		if ( null === $this->keys ) {
			return self::noKey();
		}
		if ( ! $site->isPublic ) {
			// Not an error to record: the sister calls this site instead.
			return new TransportResult( TransportResult::NOT_PUBLIC, self::notPublicMessage( $site->label ) );
		}
		$pairing = $this->pairings->get( $siteId );
		if ( null === $pairing ) {
			/* translators: %s: site name. */
			return new TransportResult( TransportResult::NOT_PAIRED, sprintf( __( 'This site isn\'t paired with %s.', 'sceneshifter' ), $site->label ) );
		}

		$result = $this->sendWithKey( $site, $pairing->publicKey, $method, $route, $payload );
		$now    = (int) ( $this->clock )();
		if ( $result->ok() ) {
			$this->pairings->recordContact( $siteId, $now );
		} elseif ( TransportResult::NO_KEY !== $result->code ) {
			$this->pairings->recordError( $siteId, $result->message, $now );
		}
		return $result;
	}

	private static function noKey(): TransportResult {
		return new TransportResult( TransportResult::NO_KEY, __( 'This site has no usable key, so it can\'t sign requests. Open Sceneshifter → Settings → Pairing.', 'sceneshifter' ) );
	}

	/**
	 * Why nothing can be sent to a sister marked as not public.
	 */
	public static function notPublicMessage( string $label ): string {
		return sprintf(
			/* translators: %1$s: site name. */
			__( '%1$s isn\'t public, so this site can\'t contact it. Start from %1$s instead: it can contact this site.', 'sceneshifter' ),
			$label
		);
	}

	/**
	 * Sends a signed request and checks the reply against a public key the
	 * caller already trusts. Used directly only while pairing, before the
	 * sister's key is stored. Records nothing.
	 *
	 * @param array<string, mixed>|null $payload JSON body for POST.
	 */
	public function sendWithKey( Site $site, string $peerKey, string $method, string $route, ?array $payload = null ): TransportResult {
		$method = strtoupper( $method );
		if ( null === $this->keys ) {
			return self::noKey();
		}

		$url = self::url( $site, $route );
		if ( ! $this->policy->allows( $url ) ) {
			$host    = (string) parse_url( $url, PHP_URL_HOST );
			$message = sprintf(
				/* translators: 1: site name, 2: address. */
				__( '%1$s\'s address (%2$s) doesn\'t use HTTPS, so nothing was sent to it.', 'sceneshifter' ),
				$site->label,
				$site->url
			);
			if ( TransportPolicy::isDevHost( strtolower( $host ) ) ) {
				$message .= ' ' . __( 'Plain HTTP to .test and .localhost sites can be allowed under Local development in the Pairing settings.', 'sceneshifter' );
			}
			return new TransportResult( TransportResult::INSECURE_URL, $message );
		}

		try {
			$body = 'GET' === $method || null === $payload ? '' : json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR );
		} catch ( JsonException $e ) {
			return new TransportResult( TransportResult::BAD_RESPONSE, $e->getMessage() );
		}

		$start   = ( $this->clock )();
		$headers = SignedRequest::headers( $this->keys, $method, $route, $this->selfId, $site->id, $body, (int) $start );
		$nonce   = $headers[ SignedRequest::HEADER_NONCE ];
		$send    = $headers + array( 'Accept' => 'application/json' );
		if ( '' !== $body ) {
			$send['Content-Type'] = 'application/json';
		}

		$reply   = ( $this->http )( $method, $url, $send, $body );
		$elapsed = (int) round( ( ( $this->clock )() - $start ) * 1000 );

		if ( is_string( $reply ) ) {
			return new TransportResult(
				TransportResult::UNREACHABLE,
				/* translators: 1: site name, 2: error from the HTTP library. */
				sprintf( __( 'Couldn\'t reach %1$s: %2$s', 'sceneshifter' ), $site->label, $reply ),
				array(),
				0,
				$elapsed
			);
		}

		$status   = $reply['status'];
		$rheaders = array_change_key_case( $reply['headers'], CASE_LOWER );
		if ( $status >= 300 && $status < 400 ) {
			return new TransportResult(
				TransportResult::REDIRECTED,
				sprintf(
					/* translators: 1: site name, 2: address it redirected to. */
					__( '%1$s redirected the request to %2$s. Check its address in the site list.', 'sceneshifter' ),
					$site->label,
					$rheaders['location'] ?? __( 'another address', 'sceneshifter' )
				),
				array(),
				$status,
				$elapsed
			);
		}
		if ( 404 === $status ) {
			return new TransportResult(
				TransportResult::REFUSED,
				sprintf(
					/* translators: %s: site name. */
					__( '%s didn\'t accept the request. It may not be paired with this site, Sceneshifter may be inactive or out of date there, or the two servers\' clocks may differ by more than 5 minutes.', 'sceneshifter' ),
					$site->label
				),
				array(),
				$status,
				$elapsed
			);
		}
		if ( 401 === $status ) {
			return new TransportResult(
				TransportResult::HTTP_ERROR,
				sprintf(
					/* translators: %s: site name. */
					__( '%s asked for a username and password (HTTP access control). Sceneshifter can\'t send those yet, so turn off HTTP access control for that environment.', 'sceneshifter' ),
					$site->label
				),
				array(),
				$status,
				$elapsed
			);
		}
		if ( 200 !== $status ) {
			return new TransportResult(
				TransportResult::HTTP_ERROR,
				/* translators: 1: site name, 2: HTTP status code. */
				sprintf( __( '%1$s answered with HTTP status %2$d.', 'sceneshifter' ), $site->label, $status ),
				array(),
				$status,
				$elapsed
			);
		}

		$signature = $rheaders[ strtolower( SignedResponse::HEADER_SIGNATURE ) ] ?? '';
		if ( ! SignedResponse::verify( $signature, $peerKey, $this->selfId, $site->id, $nonce, $status, $reply['body'] ) ) {
			return new TransportResult(
				TransportResult::BAD_SIGNATURE,
				sprintf(
					/* translators: %s: site name. */
					__( 'The reply from %s wasn\'t signed with its key. The address may point at a different site, or something between the two sites changed the reply.', 'sceneshifter' ),
					$site->label
				),
				array(),
				$status,
				$elapsed
			);
		}

		try {
			$data = json_decode( $reply['body'], true, 32, JSON_THROW_ON_ERROR );
		} catch ( JsonException $e ) {
			$data = null;
		}
		if ( ! is_array( $data ) ) {
			return new TransportResult(
				TransportResult::BAD_RESPONSE,
				/* translators: %s: site name. */
				sprintf( __( 'The reply from %s couldn\'t be read.', 'sceneshifter' ), $site->label ),
				array(),
				$status,
				$elapsed
			);
		}

		return new TransportResult( TransportResult::OK, '', $data, $status, $elapsed );
	}
}
