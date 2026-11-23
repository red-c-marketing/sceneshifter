<?php

declare(strict_types=1);

namespace RedC\SisterSync\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RedC\SisterSync\Crypto\KeyPair;
use RedC\SisterSync\Crypto\KeyVault;
use RedC\SisterSync\Crypto\MemoryNonceStore;
use RedC\SisterSync\Crypto\PairingCode;
use RedC\SisterSync\Crypto\RequestVerifier;
use RedC\SisterSync\Crypto\SignedRequest;
use RedC\SisterSync\Crypto\TransportPolicy;

final class CryptoTest extends TestCase {

	private const NOW   = 1800000000;
	private const ROUTE = '/sister-sync/v1/import';
	private const BODY  = '{"post":{"title":"Hello"}}';

	private const STAGING_SALTS = 'staging-auth-key-staging-secure-auth-key-staging-logged-in-key';
	private const LIVE_SALTS    = 'live-auth-key-live-secure-auth-key-live-logged-in-key-live-nonce';

	public function test_vault_round_trips_a_key_and_a_copied_database_cannot_open_it(): void {
		$keys   = KeyPair::generate();
		$sealed = ( new KeyVault( self::STAGING_SALTS ) )->seal( $keys );

		$opened = ( new KeyVault( self::STAGING_SALTS ) )->open( $sealed );
		$this->assertNotNull( $opened );
		$this->assertSame( $keys->publicKey, $opened->publicKey );

		$this->assertNull( ( new KeyVault( self::LIVE_SALTS ) )->open( $sealed ), 'Different salts: no key.' );
		$this->assertNull( ( new KeyVault( self::STAGING_SALTS ) )->open( 'v1.garbage' ) );
	}

	public function test_vault_refuses_missing_salts_and_fingerprints_them(): void {
		$this->assertNotSame( ( new KeyVault( self::STAGING_SALTS ) )->saltFingerprint(), ( new KeyVault( self::LIVE_SALTS ) )->saltFingerprint() );
		$this->assertSame( ( new KeyVault( self::LIVE_SALTS ) )->saltFingerprint(), ( new KeyVault( self::LIVE_SALTS ) )->saltFingerprint() );

		$this->expectException( InvalidArgumentException::class );
		new KeyVault( 'put your unique phrase here' );
	}

	public function test_fingerprint_is_short_and_stable(): void {
		$keys = KeyPair::generate();
		$this->assertMatchesRegularExpression( '/^[0-9A-F]{4}(-[0-9A-F]{4}){3}$/', KeyPair::fingerprint( $keys->publicKey ) );
		$this->assertSame( KeyPair::fingerprint( $keys->publicKey ), KeyPair::fingerprint( $keys->publicKey ) );
	}

	public function test_a_signed_request_verifies_once(): void {
		$staging  = KeyPair::generate();
		$headers  = SignedRequest::headers( $staging, 'post', self::ROUTE, 'staging', 'live', self::BODY, self::NOW );
		$verifier = $this->verifier( array( 'staging' => $staging->publicKey ) );

		$this->assertSame(
			array(
				'result' => RequestVerifier::OK,
				'sender' => 'staging',
			),
			$verifier->verify( 'POST', self::ROUTE, self::lowercase( $headers ), self::BODY, self::NOW + 10 )
		);
		$this->assertSame( RequestVerifier::REPLAYED, $verifier->verify( 'POST', self::ROUTE, $headers, self::BODY, self::NOW + 20 )['result'] );
	}

	public function test_tampering_is_refused(): void {
		$staging = KeyPair::generate();
		$keys    = array( 'staging' => $staging->publicKey );
		$headers = SignedRequest::headers( $staging, 'POST', self::ROUTE, 'staging', 'live', self::BODY, self::NOW );

		$cases = array(
			'body changed'   => array( 'POST', self::ROUTE, $headers, '{"post":{"title":"Evil"}}', RequestVerifier::BAD_SIGNATURE ),
			'route changed'  => array( 'POST', '/sister-sync/v1/rollback', $headers, self::BODY, RequestVerifier::BAD_SIGNATURE ),
			'method changed' => array( 'DELETE', self::ROUTE, $headers, self::BODY, RequestVerifier::BAD_SIGNATURE ),
			'too old'        => array( 'POST', self::ROUTE, $headers, self::BODY, RequestVerifier::STALE, self::NOW + 301 ),
			'no signature'   => array( 'POST', self::ROUTE, array_diff_key( $headers, array( SignedRequest::HEADER_SIGNATURE => 1 ) ), self::BODY, RequestVerifier::MISSING_HEADERS ),
		);
		foreach ( $cases as $name => $case ) {
			$result = $this->verifier( $keys )->verify( $case[0], $case[1], $case[2], $case[3], $case[5] ?? self::NOW );
			$this->assertSame( $case[4], $result['result'], $name );
		}
	}

	public function test_a_request_for_staging_cannot_be_replayed_against_live(): void {
		$dev     = KeyPair::generate();
		$headers = SignedRequest::headers( $dev, 'POST', self::ROUTE, 'dev', 'staging', self::BODY, self::NOW );

		$this->assertSame( RequestVerifier::WRONG_TARGET, $this->verifier( array( 'dev' => $dev->publicKey ) )->verify( 'POST', self::ROUTE, $headers, self::BODY, self::NOW )['result'] );
	}

	public function test_unpaired_or_impersonating_senders_are_refused(): void {
		$staging  = KeyPair::generate();
		$attacker = KeyPair::generate();
		$headers  = SignedRequest::headers( $attacker, 'POST', self::ROUTE, 'staging', 'live', self::BODY, self::NOW );

		$this->assertSame( RequestVerifier::BAD_SIGNATURE, $this->verifier( array( 'staging' => $staging->publicKey ) )->verify( 'POST', self::ROUTE, $headers, self::BODY, self::NOW )['result'] );
		$this->assertSame( RequestVerifier::UNKNOWN_SENDER, $this->verifier( array() )->verify( 'POST', self::ROUTE, $headers, self::BODY, self::NOW )['result'] );
	}

	public function test_pairing_code_round_trips_and_expires(): void {
		$keys = KeyPair::generate();
		$code = PairingCode::create( 'staging', $keys, 'abc123', self::NOW );

		$decoded = PairingCode::decode( wordwrap( $code->encode(), 40, "\n", true ), self::NOW + 60 );
		$this->assertSame( 'staging', $decoded->siteId );
		$this->assertSame( $keys->publicKey, $decoded->publicKey );
		$this->assertSame( $code->token, $decoded->token );
		$this->assertSame( 'abc123', $decoded->saltFingerprint );

		$this->expectExceptionMessage( 'expired' );
		PairingCode::decode( $code->encode(), self::NOW + PairingCode::LIFETIME + 1 );
	}

	public function test_pairing_code_rejects_junk(): void {
		foreach ( array( 'hello', PairingCode::PREFIX . 'e30', PairingCode::PREFIX . '!!!' ) as $junk ) {
			try {
				PairingCode::decode( $junk, self::NOW );
				$this->fail( 'Accepted ' . $junk );
			} catch ( InvalidArgumentException $e ) {
				$this->addToAssertionCount( 1 );
			}
		}
	}

	public function test_transport_is_https_only_except_dev_hosts_when_allowed(): void {
		$strict = new TransportPolicy();
		$dev    = new TransportPolicy( true );

		$this->assertTrue( $strict->allows( 'https://staging.client.co.uk' ) );
		$this->assertFalse( $strict->allows( 'http://staging.client.co.uk' ) );
		$this->assertFalse( $strict->allows( 'http://client.test:8080' ) );
		$this->assertTrue( $dev->allows( 'http://client.test:8080' ) );
		$this->assertTrue( $dev->allows( 'http://shop.localhost' ) );
		$this->assertFalse( $dev->allows( 'http://staging.client.co.uk' ) );
		$this->assertFalse( $dev->allows( 'ftp://client.test' ) );
	}

	/**
	 * @param array<string, string> $keys
	 */
	private function verifier( array $keys ): RequestVerifier {
		return new RequestVerifier( 'live', static fn( string $id ): ?string => $keys[ $id ] ?? null, new MemoryNonceStore() );
	}

	/**
	 * @param array<string, string> $headers
	 * @return array<string, string>
	 */
	private static function lowercase( array $headers ): array {
		return array_change_key_case( $headers, CASE_LOWER );
	}
}
