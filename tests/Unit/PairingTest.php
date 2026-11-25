<?php

declare(strict_types=1);

namespace RedC\Sceneshifter\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedC\Sceneshifter\Admin\PairingTab;
use RedC\Sceneshifter\Crypto\KeyPair;
use RedC\Sceneshifter\Crypto\KeyVault;
use RedC\Sceneshifter\Crypto\MemoryNonceStore;
use RedC\Sceneshifter\Crypto\PairingCode;
use RedC\Sceneshifter\Crypto\SignedRequest;
use RedC\Sceneshifter\Pairing\Handshake;
use RedC\Sceneshifter\Pairing\PairingException;
use RedC\Sceneshifter\Pairing\PairingStore;
use RedC\Sceneshifter\Pairing\PendingCodes;
use RedC\Sceneshifter\Pairing\SecretMaterial;
use RedC\Sceneshifter\Pairing\Sisters;
use RedC\Sceneshifter\Pairing\SiteKeys;
use RedC\Sceneshifter\Store\MemoryKeyValueStore;
use RedC\Sceneshifter\Transport\TransportResult;

final class PairingTest extends TestCase {

	private const NOW = 1800000000;

	private const STAGING_SALTS = 'staging-auth-key|staging-secure-auth-key|staging-logged-in-key';
	private const DEV_SALTS     = 'dev-auth-key|dev-secure-auth-key|dev-logged-in-key|dev-nonce';
	private const LIVE_SALTS    = 'live-auth-key|live-secure-auth-key|live-logged-in-key|live-nonce';

	protected function setUp(): void {
		SisterHarness::$network = array();
	}

	public function test_dev_pairs_with_staging_by_pasting_a_code_made_on_staging(): void {
		$staging = new SisterHarness( 'staging', self::STAGING_SALTS );
		$dev     = new SisterHarness( 'dev', self::DEV_SALTS );

		// Dev isn't public, so the code is made on staging and pasted on dev.
		$this->assertSame( 'there', Sisters::codeSide( Fixtures::registry()->get( 'dev' ), Fixtures::registry()->get( 'staging' ) ) );
		$code = $staging->sisters->createCode( 'dev' )->encode();

		// Dev shows staging's fingerprint before contacting it.
		$checked = $dev->sisters->checkCode( $code );
		$this->assertSame( $staging->sisters->keys()->fingerprint(), KeyPair::fingerprint( $checked->publicKey ) );

		$pairing = $dev->sisters->pair( $code );

		$this->assertSame( 'staging', $pairing->siteId );
		$this->assertSame( $staging->sisters->keys()->fingerprint(), $dev->sisters->pairings()->get( 'staging' )->fingerprint() );
		$this->assertSame( $dev->sisters->keys()->fingerprint(), $staging->sisters->pairings()->get( 'dev' )->fingerprint() );
		$this->assertMatchesRegularExpression( '/^[0-9A-F]{4}(-[0-9A-F]{4}){3}$/', $pairing->fingerprint() );

		// Only the pasting site made a call, and it went to staging.
		$this->assertCount( 1, $dev->sent );
		$this->assertSame( array(), $staging->sent );
		$this->assertSame( 'https://staging.client.co.uk/wp-json/sceneshifter/v1/pair', $dev->sent[0]['url'] );

		// What dev sent: its ID, its public key, its salt fingerprint and the token. Never the salts.
		$payload = json_decode( $dev->sent[0]['body'], true );
		$this->assertSame( array( 'site', 'key', 'salt', 'token' ), array_keys( $payload ) );
		$this->assertStringNotContainsString( 'dev-auth-key', $dev->sent[0]['body'] );
		$this->assertSame( $dev->sisters->keys()->saltFingerprint(), $payload['salt'] );

		// Signed traffic now works both ways where reachable.
		$status = $dev->sisters->status( 'staging' );
		$this->assertTrue( $status->ok(), $status->message );
		$this->assertSame( 'staging', $status->data['site'] );
	}

	public function test_a_code_works_once(): void {
		$staging = new SisterHarness( 'staging', self::STAGING_SALTS );
		$dev     = new SisterHarness( 'dev', self::DEV_SALTS );
		$code    = $staging->sisters->createCode( 'dev' )->encode();

		$dev->sisters->pair( $code );

		// The exact request again is a replay.
		$first = $dev->sent[0];
		$this->assertSame( 404, $staging->serve( 'POST', $first['url'], $first['headers'], $first['body'] )['status'] );

		// A freshly signed request with the same token is refused too.
		$dev->sisters->pairings()->clear();
		try {
			$dev->sisters->pair( $code );
			$this->fail( 'A used code was accepted again.' );
		} catch ( PairingException $e ) {
			$this->assertStringContainsString( 'didn\'t accept the code', $e->getMessage() );
		}
		$this->assertNull( $dev->sisters->pairings()->get( 'staging' ) );
	}

	public function test_a_used_token_stays_used_even_if_the_code_list_is_restored(): void {
		$keys      = KeyPair::generate();
		$nonces    = new MemoryNonceStore();
		$store     = new MemoryKeyValueStore();
		$handshake = $this->handshake( 'staging', $keys, self::STAGING_SALTS, $store, $nonces );
		$code      = $handshake->createCode( 'dev', self::NOW );
		$saved     = $store->get( PendingCodes::OPTION );

		$devKeys = KeyPair::generate();
		$this->assertNotNull( $this->acceptFrom( $handshake, $devKeys, 'dev', $code, self::DEV_SALTS, self::NOW + 5 ) );

		// Even if two requests raced past the code list, the nonce store's
		// atomic insert only lets one through.
		$store->set( PendingCodes::OPTION, $saved );
		$this->assertNull( $this->acceptFrom( $handshake, $devKeys, 'dev', $code, self::DEV_SALTS, self::NOW + 6 ) );
	}

	public function test_expired_codes_are_refused_on_both_sides(): void {
		$keys      = KeyPair::generate();
		$handshake = $this->handshake( 'staging', $keys, self::STAGING_SALTS );
		$code      = $handshake->createCode( 'dev', self::NOW );
		$later     = self::NOW + PairingCode::LIFETIME + 1;

		$dev = $this->handshake( 'dev', KeyPair::generate(), self::DEV_SALTS );
		try {
			$dev->checkCode( $code->encode(), $later );
			$this->fail( 'Accepted an expired code.' );
		} catch ( PairingException $e ) {
			$this->assertStringContainsString( 'expired', $e->getMessage() );
		}

		$this->assertNull( $this->acceptFrom( $handshake, KeyPair::generate(), 'dev', $code, self::DEV_SALTS, $later ) );
	}

	public function test_sites_with_the_same_salts_cannot_pair(): void {
		$staging = $this->handshake( 'staging', KeyPair::generate(), self::STAGING_SALTS );
		$code    = $staging->createCode( 'dev', self::NOW );

		// Dev with staging's salts refuses the code itself...
		$dev = $this->handshake( 'dev', KeyPair::generate(), self::STAGING_SALTS );
		try {
			$dev->checkCode( $code->encode(), self::NOW );
			$this->fail( 'Paired two sites with the same salts.' );
		} catch ( PairingException $e ) {
			$this->assertStringContainsString( 'same wp-config salts', $e->getMessage() );
		}

		// ...and staging refuses a request whose salt fingerprint matches its own.
		$this->assertNull( $this->acceptFrom( $staging, KeyPair::generate(), 'dev', $code, self::STAGING_SALTS, self::NOW ) );
	}

	public function test_salt_fingerprints_are_one_way(): void {
		$fingerprint = ( new KeyVault( self::STAGING_SALTS ) )->saltFingerprint();
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{16}$/', $fingerprint );
		$this->assertStringNotContainsString( 'staging', $fingerprint );
		$this->assertNotSame( substr( hash( 'sha256', self::STAGING_SALTS ), 0, 16 ), $fingerprint );
	}

	public function test_codes_for_the_wrong_site_are_refused(): void {
		$staging = $this->handshake( 'staging', KeyPair::generate(), self::STAGING_SALTS );
		$code    = $staging->createCode( 'live', self::NOW );

		$messages = array();
		foreach ( array( 'dev' => self::DEV_SALTS, 'staging' => self::LIVE_SALTS ) as $site => $salts ) {
			try {
				$this->handshake( $site, KeyPair::generate(), $salts )->checkCode( $code->encode(), self::NOW );
				$this->fail( 'Accepted on ' . $site );
			} catch ( PairingException $e ) {
				$messages[ $site ] = $e->getMessage();
			}
		}
		$this->assertStringContainsString( 'made on Staging for Live, not for this site', $messages['dev'] );
		$this->assertStringContainsString( 'made on this site', $messages['staging'] );

		// Staging refuses dev using a code made for live, even with a valid signature.
		$this->assertNull( $this->acceptFrom( $staging, KeyPair::generate(), 'dev', $code, self::DEV_SALTS, self::NOW ) );

		// A code from a site that isn't in the list.
		$stranger = new PairingCode( 'elsewhere', KeyPair::generate()->publicKey, str_repeat( 'a', 43 ), self::NOW + 60, 'abc' );
		$this->expectExceptionMessage( 'isn\'t in this site\'s list' );
		$this->handshake( 'dev', KeyPair::generate(), self::DEV_SALTS )->checkCode( $stranger->encode(), self::NOW );
	}

	public function test_a_new_code_replaces_the_last_one_for_that_site(): void {
		$staging = $this->handshake( 'staging', KeyPair::generate(), self::STAGING_SALTS );
		$old     = $staging->createCode( 'dev', self::NOW );
		$new     = $staging->createCode( 'dev', self::NOW + 10 );
		$live    = $staging->createCode( 'live', self::NOW + 20 );

		$this->assertNull( $this->acceptFrom( $staging, KeyPair::generate(), 'dev', $old, self::DEV_SALTS, self::NOW + 30 ) );
		$this->assertNotNull( $this->acceptFrom( $staging, KeyPair::generate(), 'dev', $new, self::DEV_SALTS, self::NOW + 30 ) );
		$this->assertNotNull( $this->acceptFrom( $staging, KeyPair::generate(), 'live', $live, self::LIVE_SALTS, self::NOW + 30 ) );
	}

	public function test_a_pair_request_must_be_signed_by_the_key_it_carries(): void {
		$staging = $this->handshake( 'staging', KeyPair::generate(), self::STAGING_SALTS );
		$code    = $staging->createCode( 'dev', self::NOW );

		$sent     = KeyPair::generate();
		$signedBy = KeyPair::generate();
		$body     = (string) json_encode(
			array(
				'site'  => 'dev',
				'key'   => self::b64( $sent->publicKey ),
				'salt'  => ( new KeyVault( self::DEV_SALTS ) )->saltFingerprint(),
				'token' => $code->token,
			)
		);
		$headers = SignedRequest::headers( $signedBy, 'POST', Handshake::ROUTE, 'dev', 'staging', $body, self::NOW );
		$this->assertNull( $staging->accept( 'POST', Handshake::ROUTE, $headers, $body, self::NOW ) );

		// Sender header and body must agree.
		$headers = SignedRequest::headers( $sent, 'POST', Handshake::ROUTE, 'live', 'staging', $body, self::NOW );
		$this->assertNull( $staging->accept( 'POST', Handshake::ROUTE, $headers, $body, self::NOW ) );
	}

	public function test_completing_checks_the_answer_matches(): void {
		$stagingKeys = KeyPair::generate();
		$staging     = $this->handshake( 'staging', $stagingKeys, self::STAGING_SALTS );
		$code        = $staging->createCode( 'dev', self::NOW );
		$devKeys     = KeyPair::generate();
		$store       = new MemoryKeyValueStore();
		$dev         = $this->handshake( 'dev', $devKeys, self::DEV_SALTS, $store );

		foreach ( array(
			array( 'site' => 'live', 'peer_fingerprint' => KeyPair::fingerprint( $devKeys->publicKey ) ),
			array( 'site' => 'staging', 'peer_fingerprint' => KeyPair::fingerprint( KeyPair::generate()->publicKey ) ),
		) as $answer ) {
			try {
				$dev->complete( $code, $answer, self::NOW );
				$this->fail( 'Accepted ' . json_encode( $answer ) );
			} catch ( PairingException $e ) {
				$this->assertNull( ( new PairingStore( $store ) )->get( 'staging' ) );
			}
		}

		$pairing = $dev->complete( $code, array( 'site' => 'staging', 'peer_fingerprint' => KeyPair::fingerprint( $devKeys->publicKey ) ), self::NOW );
		$this->assertSame( KeyPair::fingerprint( $stagingKeys->publicKey ), $pairing->fingerprint() );
		$this->assertSame( self::NOW, $pairing->pairedAt );
	}

	public function test_unpairing_tells_a_public_sister(): void {
		$staging = new SisterHarness( 'staging', self::STAGING_SALTS );
		$live    = new SisterHarness( 'live', self::LIVE_SALTS );
		$live->sisters->pair( $staging->sisters->createCode( 'live' )->encode() );
		$this->assertNotNull( $staging->sisters->pairings()->get( 'live' ) );

		$told = $staging->sisters->unpair( 'live' );

		$this->assertTrue( $told?->ok() );
		$this->assertNull( $staging->sisters->pairings()->get( 'live' ) );
		$this->assertNull( $live->sisters->pairings()->get( 'staging' ) );
	}

	public function test_unpairing_a_non_public_sister_only_forgets_it_here(): void {
		$staging = new SisterHarness( 'staging', self::STAGING_SALTS );
		$dev     = new SisterHarness( 'dev', self::DEV_SALTS );
		$dev->sisters->pair( $staging->sisters->createCode( 'dev' )->encode() );

		$this->assertNull( $staging->sisters->unpair( 'dev' ), 'Dev is not public, so it is not contacted.' );
		$this->assertNull( $staging->sisters->pairings()->get( 'dev' ) );
		$this->assertSame( array(), $staging->sent );
		$this->assertNotNull( $dev->sisters->pairings()->get( 'staging' ) );

		// Dev's requests are now refused by staging.
		$this->assertSame( TransportResult::REFUSED, $dev->sisters->status( 'staging' )->code );
	}

	public function test_keys_are_made_on_first_need_and_a_copied_database_needs_new_ones(): void {
		$store = new MemoryKeyValueStore();
		$keys  = new SiteKeys( $store, self::STAGING_SALTS, 'staging' );
		$this->assertSame( SiteKeys::MISSING, $keys->status() );
		$this->assertNull( $keys->keyPair(), 'Not made unless asked.' );

		$pair = $keys->keyPair( true );
		$this->assertNotNull( $pair );
		$this->assertSame( SiteKeys::OK, $keys->status() );
		$this->assertSame( $pair->publicKey, ( new SiteKeys( $store, self::STAGING_SALTS, 'staging' ) )->keyPair()?->publicKey );
		$this->assertStringNotContainsString( $pair->secretKey(), (string) json_encode( $store->get( SiteKeys::OPTION ) ) );

		// Staging's database copied to a site with other salts.
		$copy = new SiteKeys( $store, self::LIVE_SALTS, 'staging' );
		$this->assertSame( SiteKeys::UNREADABLE, $copy->status() );
		$this->assertTrue( $copy->needsNewKey() );
		$this->assertNull( $copy->keyPair( true ), 'Never silently replaced.' );

		// Copied to a site with the same salts, which resolves as another site.
		$sameSalts = new SiteKeys( $store, self::STAGING_SALTS, 'dev' );
		$this->assertSame( SiteKeys::FOREIGN, $sameSalts->status() );
		$this->assertNull( $sameSalts->keyPair( true ) );

		// No salts at all.
		$this->assertSame( SiteKeys::NO_SALTS, ( new SiteKeys( $store, '', 'staging' ) )->status() );
	}

	public function test_a_site_with_a_copied_key_refuses_signed_traffic_until_it_has_a_new_key(): void {
		$staging = new SisterHarness( 'staging', self::STAGING_SALTS );
		$dev     = new SisterHarness( 'dev', self::DEV_SALTS );
		$dev->sisters->pair( $staging->sisters->createCode( 'dev' )->encode() );

		// Staging's database is restored onto a server with other salts.
		$moved = new SisterHarness( 'staging', self::LIVE_SALTS );
		$moved->store->set( SiteKeys::OPTION, $staging->store->get( SiteKeys::OPTION ) );
		$moved->store->set( PairingStore::OPTION, $staging->store->get( PairingStore::OPTION ) );
		$moved->boot();

		$this->assertFalse( $moved->sisters->gate()->isOpen() );
		$this->assertSame( TransportResult::REFUSED, $dev->sisters->status( 'staging' )->code );
		$this->assertSame( TransportResult::NO_KEY, $moved->sisters->status( 'dev' )->code );
		try {
			$moved->sisters->createCode( 'dev' );
			$this->fail( 'Made a code with an unreadable key.' );
		} catch ( PairingException $e ) {
			$this->assertStringContainsString( 'made on another environment', $e->getMessage() );
		}

		$this->assertTrue( $moved->sisters->replaceKeys() );
		$this->assertSame( array(), $moved->sisters->pairings()->all(), 'Old pairings are forgotten.' );
		$this->assertTrue( $moved->sisters->gate()->isOpen() );
	}

	public function test_secret_material_ignores_placeholders_and_database_salts(): void {
		$this->assertSame( '', SecretMaterial::fromConstants( array( 'AUTH_KEY' => 'put your unique phrase here', 'NONCE_KEY' => '' ) ) );
		$material = SecretMaterial::fromConstants(
			array(
				'AUTH_KEY'  => 'one',
				'NONCE_KEY' => 'two',
				'OTHER'     => 'ignored',
			)
		);
		$this->assertSame( "AUTH_KEY=one\nNONCE_KEY=two", $material );
	}

	public function test_codes_are_only_made_where_the_other_site_can_reach_them(): void {
		$dev = $this->handshake( 'dev', KeyPair::generate(), self::DEV_SALTS );
		try {
			$dev->createCode( 'staging', self::NOW );
			$this->fail( 'Made a code on a site that isn\'t public.' );
		} catch ( PairingException $e ) {
			$this->assertStringContainsString( 'Make the code on Staging', $e->getMessage() );
		}

		// A code that somehow came from dev is refused on staging before any call.
		$fromDev = PairingCode::create( 'dev', KeyPair::generate(), ( new KeyVault( self::DEV_SALTS ) )->saltFingerprint(), self::NOW, 'staging' );
		$this->expectExceptionMessage( 'Dev isn\'t public' );
		$this->handshake( 'staging', KeyPair::generate(), self::STAGING_SALTS )->checkCode( $fromDev->encode(), self::NOW );
	}

	public function test_code_side_follows_reachability(): void {
		$registry = Fixtures::registry();
		$this->assertSame( 'either', Sisters::codeSide( $registry->get( 'live' ), $registry->get( 'staging' ) ) );
		$this->assertSame( 'here', Sisters::codeSide( $registry->get( 'staging' ), $registry->get( 'dev' ) ) );
		$this->assertSame( 'there', Sisters::codeSide( $registry->get( 'dev' ), $registry->get( 'live' ) ) );
	}

	public function test_status_answers_are_checked_for_compatibility(): void {
		$this->assertSame( array(), PairingTab::compatibility( array( 'format' => '1', 'registry_version' => 3, 'time' => time() ), 3, 'Live' ) );

		$warnings = PairingTab::compatibility( array( 'format' => '2', 'registry_version' => 2, 'time' => time() - 120 ), 3, 'Live' );
		$this->assertCount( 3, $warnings );
		$this->assertStringContainsString( 'different package format', $warnings[0] );
		$this->assertStringContainsString( 'version 3 of the site list', $warnings[1] );
		$this->assertStringContainsString( 'clocks', $warnings[2] );
	}

	private function handshake( string $siteId, KeyPair $keys, string $salts, ?MemoryKeyValueStore $store = null, ?MemoryNonceStore $nonces = null ): Handshake {
		$store = $store ?? new MemoryKeyValueStore();
		return new Handshake(
			Fixtures::registry(),
			$siteId,
			$keys,
			( new KeyVault( $salts ) )->saltFingerprint(),
			new PairingStore( $store ),
			new PendingCodes( $store ),
			$nonces ?? new MemoryNonceStore()
		);
	}

	/**
	 * Builds a correctly signed POST /pair from a sister and hands it to the handshake.
	 */
	private function acceptFrom( Handshake $handshake, KeyPair $keys, string $siteId, PairingCode $code, string $salts, int $now ): ?\RedC\Sceneshifter\Pairing\Pairing {
		$body    = (string) json_encode(
			array(
				'site'  => $siteId,
				'key'   => self::b64( $keys->publicKey ),
				'salt'  => ( new KeyVault( $salts ) )->saltFingerprint(),
				'token' => $code->token,
			)
		);
		$headers = SignedRequest::headers( $keys, 'POST', Handshake::ROUTE, $siteId, $code->siteId, $body, $now );
		return $handshake->accept( 'POST', Handshake::ROUTE, $headers, $body, $now );
	}

	private static function b64( string $bytes ): string {
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
	}
}
