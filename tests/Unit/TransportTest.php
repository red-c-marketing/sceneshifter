<?php

declare(strict_types=1);

namespace RedC\SisterSync\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedC\SisterSync\Crypto\KeyPair;
use RedC\SisterSync\Crypto\MemoryNonceStore;
use RedC\SisterSync\Crypto\RequestVerifier;
use RedC\SisterSync\Crypto\SignedRequest;
use RedC\SisterSync\Crypto\SignedResponse;
use RedC\SisterSync\Crypto\TransportPolicy;
use RedC\SisterSync\Pairing\Pairing;
use RedC\SisterSync\Pairing\PairingStore;
use RedC\SisterSync\Store\MemoryKeyValueStore;
use RedC\SisterSync\Transport\Transport;
use RedC\SisterSync\Transport\TransportResult;

final class TransportTest extends TestCase {

	private const NOW   = 1800000000.25;
	private const ROUTE = '/sister-sync/v1/status';

	private KeyPair $ours;
	private KeyPair $theirs;
	private PairingStore $pairings;

	/** @var list<array{method: string, url: string, headers: array<string, string>, body: string}> */
	private array $calls = array();

	protected function setUp(): void {
		$this->ours     = KeyPair::generate();
		$this->theirs   = KeyPair::generate();
		$this->pairings = new PairingStore( new MemoryKeyValueStore() );
		$this->pairings->save( new Pairing( 'live', $this->theirs->publicKey, 1700000000 ) );
		$this->pairings->save( new Pairing( 'dev', $this->theirs->publicKey, 1700000000 ) );
		$this->calls = array();
	}

	public function test_builds_a_signed_get_that_the_sister_can_verify(): void {
		$result = $this->transport( $this->answer( array( 'site' => 'live' ) ) )->send( 'live', 'get', self::ROUTE );

		$this->assertTrue( $result->ok(), $result->message );
		$this->assertSame( array( 'site' => 'live' ), $result->data );
		$this->assertSame( 120, $result->elapsedMs );

		$call = $this->calls[0];
		$this->assertSame( 'GET', $call['method'] );
		$this->assertSame( 'https://www.client.co.uk/wp-json/sister-sync/v1/status', $call['url'] );
		$this->assertSame( '', $call['body'] );
		$this->assertArrayNotHasKey( 'Content-Type', $call['headers'] );
		$this->assertSame( 'staging', $call['headers'][ SignedRequest::HEADER_SENDER ] );
		$this->assertSame( 'live', $call['headers'][ SignedRequest::HEADER_TARGET ] );
		$this->assertSame( '1800000000', $call['headers'][ SignedRequest::HEADER_TIMESTAMP ] );

		$verifier = new RequestVerifier( 'live', fn( string $id ): ?string => 'staging' === $id ? $this->ours->publicKey : null, new MemoryNonceStore() );
		$this->assertSame( RequestVerifier::OK, $verifier->verify( 'GET', self::ROUTE, $call['headers'], '', 1800000000 )['result'] );

		$this->assertSame( 1800000000, $this->pairings->get( 'live' )?->lastContact );
	}

	public function test_posts_json_and_keeps_subfolder_addresses(): void {
		$this->pairings->save( new Pairing( 'uat', $this->theirs->publicKey, 1700000000 ) );

		$this->transport( $this->answer( array( 'ok' => true ) ) )->send( 'uat', 'POST', '/sister-sync/v1/import', array( 'title' => 'A/B' ) );

		$this->assertSame( 'https://agency.example.com/clients/client/wp-json/sister-sync/v1/import', $this->calls[0]['url'] );
		$this->assertSame( '{"title":"A/B"}', $this->calls[0]['body'] );
		$this->assertSame( 'application/json', $this->calls[0]['headers']['Content-Type'] );
	}

	public function test_refuses_plain_http_unless_the_dev_setting_allows_local_hosts(): void {
		$strict = $this->transport( $this->answer( array() ) )->send( 'dev', 'GET', self::ROUTE );
		$this->assertSame( TransportResult::INSECURE_URL, $strict->code );
		$this->assertStringContainsString( 'Local development', $strict->message );
		$this->assertSame( array(), $this->calls, 'Nothing was sent.' );

		$dev = $this->transport( $this->answer( array( 'site' => 'dev' ) ), true )->send( 'dev', 'GET', self::ROUTE );
		$this->assertTrue( $dev->ok(), $dev->message );
		$this->assertSame( 'http://client.test:8080/wp-json/sister-sync/v1/status', $this->calls[0]['url'] );
	}

	public function test_refuses_sites_that_are_not_paired_or_not_listed(): void {
		$this->pairings->remove( 'live' );
		$this->assertSame( TransportResult::NOT_PAIRED, $this->transport( $this->answer( array() ) )->send( 'live', 'GET', self::ROUTE )->code );
		$this->assertSame( TransportResult::NOT_IN_REGISTRY, $this->transport( $this->answer( array() ) )->send( 'nowhere', 'GET', self::ROUTE )->code );
		$this->assertSame( array(), $this->calls );
	}

	public function test_without_a_key_nothing_is_sent(): void {
		$transport = new Transport( 'staging', null, Fixtures::registry(), $this->pairings, new TransportPolicy(), $this->recorder( $this->answer( array() ) ) );
		$this->assertSame( TransportResult::NO_KEY, $transport->send( 'live', 'GET', self::ROUTE )->code );
		$this->assertSame( array(), $this->calls );
		$this->assertNull( $this->pairings->get( 'live' )?->lastError );
	}

	public function test_failures_are_explained_and_recorded(): void {
		$cases = array(
			'unreachable'  => array( static fn(): string => 'cURL error 28: Operation timed out', TransportResult::UNREACHABLE, 'Operation timed out' ),
			'quiet 404'    => array( self::reply( 404, '{"code":"rest_no_route"}' ), TransportResult::REFUSED, 'didn\'t accept the request' ),
			'redirect'     => array( self::reply( 301, '', array( 'Location' => 'https://client.co.uk/' ) ), TransportResult::REDIRECTED, 'https://client.co.uk/' ),
			'server error' => array( self::reply( 500, 'Oops' ), TransportResult::HTTP_ERROR, 'HTTP status 500' ),
			'basic auth'   => array( self::reply( 401, 'Unauthorized' ), TransportResult::HTTP_ERROR, 'HTTP access control' ),
			'unsigned'     => array( self::reply( 200, '{"site":"live"}' ), TransportResult::BAD_SIGNATURE, 'wasn\'t signed with its key' ),
		);
		foreach ( $cases as $name => [ $http, $code, $text ] ) {
			$result = $this->transport( $http )->send( 'live', 'GET', self::ROUTE );
			$this->assertSame( $code, $result->code, $name );
			$this->assertStringContainsString( $text, $result->message, $name );
			$this->assertSame( $result->message, $this->pairings->get( 'live' )?->lastError, $name );
			$this->assertSame( 1800000000, $this->pairings->get( 'live' )?->lastErrorAt, $name );
		}

		// A later success clears the error.
		$this->transport( $this->answer( array( 'site' => 'live' ) ) )->send( 'live', 'GET', self::ROUTE );
		$this->assertNull( $this->pairings->get( 'live' )?->lastError );
	}

	public function test_a_reply_signed_by_another_key_or_for_another_request_is_refused(): void {
		$impostor = KeyPair::generate();
		$http     = function ( string $method, string $url, array $headers, string $body ) use ( $impostor ): array {
			$reply = '{"site":"live"}';
			return array(
				'status'  => 200,
				'headers' => array( 'X-Sister-Sync-Response-Signature' => SignedResponse::sign( $impostor, 'staging', 'live', $headers[ SignedRequest::HEADER_NONCE ], 200, $reply ) ),
				'body'    => $reply,
			);
		};
		$this->assertSame( TransportResult::BAD_SIGNATURE, $this->transport( $http )->send( 'live', 'GET', self::ROUTE )->code );

		$replayed = function (): array {
			$reply = '{"site":"live"}';
			return array(
				'status'  => 200,
				'headers' => array( 'x-sister-sync-response-signature' => SignedResponse::sign( $this->theirs, 'staging', 'live', 'an-old-nonce-from-before', 200, $reply ) ),
				'body'    => $reply,
			);
		};
		$this->assertSame( TransportResult::BAD_SIGNATURE, $this->transport( $replayed )->send( 'live', 'GET', self::ROUTE )->code );
	}

	public function test_a_signed_reply_that_is_not_json_is_refused(): void {
		$http = function ( string $method, string $url, array $headers ): array {
			return array(
				'status'  => 200,
				'headers' => array( 'x-sister-sync-response-signature' => SignedResponse::sign( $this->theirs, 'staging', 'live', $headers[ SignedRequest::HEADER_NONCE ], 200, '<html>' ) ),
				'body'    => '<html>',
			);
		};
		$this->assertSame( TransportResult::BAD_RESPONSE, $this->transport( $http )->send( 'live', 'GET', self::ROUTE )->code );
	}

	/**
	 * @param callable(string, string, array<string, string>, string): (array{status: int, headers: array<string, string>, body: string}|string) $http
	 */
	private function transport( callable $http, bool $allowDevHttp = false ): Transport {
		$ticks = array( self::NOW, self::NOW + 0.12, self::NOW + 0.12 );
		$clock = static function () use ( &$ticks ): float {
			return count( $ticks ) > 1 ? array_shift( $ticks ) : $ticks[0];
		};
		return new Transport( 'staging', $this->ours, Fixtures::registry(), $this->pairings, new TransportPolicy( $allowDevHttp ), $this->recorder( $http ), $clock );
	}

	/**
	 * @param callable(string, string, array<string, string>, string): (array{status: int, headers: array<string, string>, body: string}|string) $http
	 * @return callable(string, string, array<string, string>, string): (array{status: int, headers: array<string, string>, body: string}|string)
	 */
	private function recorder( callable $http ): callable {
		return function ( string $method, string $url, array $headers, string $body ) use ( $http ): array|string {
			$this->calls[] = compact( 'method', 'url', 'headers', 'body' );
			return $http( $method, $url, $headers, $body );
		};
	}

	/**
	 * A correctly signed answer from the sister.
	 *
	 * @param array<string, mixed> $data
	 */
	private function answer( array $data ): callable {
		return function ( string $method, string $url, array $headers ) use ( $data ): array {
			$body = (string) json_encode( $data );
			return array(
				'status'  => 200,
				'headers' => array( 'X-Sister-Sync-Response-Signature' => SignedResponse::sign( $this->theirs, $headers[ SignedRequest::HEADER_SENDER ], $headers[ SignedRequest::HEADER_TARGET ], $headers[ SignedRequest::HEADER_NONCE ], 200, $body ) ),
				'body'    => $body,
			);
		};
	}

	/**
	 * @param array<string, string> $headers
	 */
	private static function reply( int $status, string $body, array $headers = array() ): callable {
		return static fn(): array => array(
			'status'  => $status,
			'headers' => $headers,
			'body'    => $body,
		);
	}
}
