<?php

declare(strict_types=1);

namespace RedC\Sceneshifter\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedC\Sceneshifter\Crypto\KeyPair;
use RedC\Sceneshifter\Crypto\MemoryNonceStore;
use RedC\Sceneshifter\Crypto\SignedRequest;
use RedC\Sceneshifter\Pairing\Pairing;
use RedC\Sceneshifter\Pairing\PairingStore;
use RedC\Sceneshifter\Store\MemoryKeyValueStore;
use RedC\Sceneshifter\Transport\Gate;

/**
 * The permission layer for signed endpoints. Anything it refuses is
 * answered with a plain 404.
 */
final class GateTest extends TestCase {

	private const NOW   = 1800000000;
	private const ROUTE = '/sceneshifter/v1/status';

	private KeyPair $live;
	private KeyPair $staging;
	private PairingStore $pairings;
	private MemoryNonceStore $nonces;

	protected function setUp(): void {
		$this->live     = KeyPair::generate();
		$this->staging  = KeyPair::generate();
		$this->pairings = new PairingStore( new MemoryKeyValueStore() );
		$this->pairings->save( new Pairing( 'staging', $this->staging->publicKey, self::NOW - 86400 ) );
		$this->nonces = new MemoryNonceStore();
	}

	public function test_a_paired_sister_gets_through_once(): void {
		$headers = $this->headers();
		$this->assertSame( 'staging', $this->gate()->verify( 'GET', self::ROUTE, $headers, '', self::NOW ) );
		$this->assertNull( $this->gate()->verify( 'GET', self::ROUTE, $headers, '', self::NOW + 1 ), 'Replay.' );
	}

	public function test_everything_else_is_refused(): void {
		$attacker = KeyPair::generate();
		$cases    = array(
			'bad signature'     => array( $this->gate(), 'GET', self::ROUTE, SignedRequest::headers( $attacker, 'GET', self::ROUTE, 'staging', 'live', '', self::NOW ), '', self::NOW ),
			'wrong target'      => array( $this->gate(), 'GET', self::ROUTE, $this->headers( 'uat' ), '', self::NOW ),
			'stale'             => array( $this->gate(), 'GET', self::ROUTE, $this->headers(), '', self::NOW + 301 ),
			'from the future'   => array( $this->gate(), 'GET', self::ROUTE, $this->headers(), '', self::NOW - 301 ),
			'unpaired sender'   => array( $this->gate(), 'GET', self::ROUTE, SignedRequest::headers( $attacker, 'GET', self::ROUTE, 'uat', 'live', '', self::NOW ), '', self::NOW ),
			'other route'       => array( $this->gate(), 'GET', '/sceneshifter/v1/import', $this->headers(), '', self::NOW ),
			'body added'        => array( $this->gate(), 'GET', self::ROUTE, $this->headers(), '{"x":1}', self::NOW ),
			'unsigned'          => array( $this->gate(), 'GET', self::ROUTE, array(), '', self::NOW ),
			'inert site'        => array( new Gate( null, $this->live, Fixtures::registry(), $this->pairings, $this->nonces ), 'GET', self::ROUTE, $this->headers(), '', self::NOW ),
			'no usable key'     => array( new Gate( 'live', null, Fixtures::registry(), $this->pairings, $this->nonces ), 'GET', self::ROUTE, $this->headers(), '', self::NOW ),
			'pretending to be us' => array( $this->gate(), 'GET', self::ROUTE, SignedRequest::headers( $this->live, 'GET', self::ROUTE, 'live', 'live', '', self::NOW ), '', self::NOW ),
		);
		foreach ( $cases as $name => [ $gate, $method, $route, $headers, $body, $now ] ) {
			$this->assertNull( $gate->verify( $method, $route, $headers, $body, $now ), $name );
		}
	}

	public function test_a_paired_sister_removed_from_the_site_list_is_refused(): void {
		$registry = Fixtures::registry()->toArray();
		$registry['sites'] = array_values( array_filter( $registry['sites'], static fn( array $s ): bool => 'staging' !== $s['id'] ) );
		$gate = new Gate( 'live', $this->live, \RedC\Sceneshifter\Registry\Registry::fromArray( $registry ), $this->pairings, $this->nonces );

		$this->assertNull( $gate->verify( 'GET', self::ROUTE, $this->headers(), '', self::NOW ) );
	}

	public function test_oversized_bodies_are_refused_before_checking_the_signature(): void {
		$body    = str_repeat( 'x', Gate::MAX_BODY + 1 );
		$headers = SignedRequest::headers( $this->staging, 'POST', self::ROUTE, 'staging', 'live', $body, self::NOW );
		$this->assertNull( $this->gate()->verify( 'POST', self::ROUTE, $headers, $body, self::NOW ) );
	}

	public function test_an_inert_site_is_closed(): void {
		$this->assertTrue( $this->gate()->isOpen() );
		$this->assertFalse( ( new Gate( null, $this->live, Fixtures::registry(), $this->pairings, $this->nonces ) )->isOpen() );
		$this->assertFalse( ( new Gate( 'live', null, Fixtures::registry(), $this->pairings, $this->nonces ) )->isOpen() );
	}

	private function gate(): Gate {
		return new Gate( 'live', $this->live, Fixtures::registry(), $this->pairings, $this->nonces );
	}

	/**
	 * @return array<string, string>
	 */
	private function headers( string $target = 'live' ): array {
		return SignedRequest::headers( $this->staging, 'GET', self::ROUTE, 'staging', $target, '', self::NOW );
	}
}
