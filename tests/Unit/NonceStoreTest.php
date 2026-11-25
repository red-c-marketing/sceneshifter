<?php

declare(strict_types=1);

namespace RedC\SisterSync\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RedC\SisterSync\Crypto\MemoryNonceStore;
use RedC\SisterSync\Crypto\NonceStore;
use RedC\SisterSync\Store\DbNonceStore;

/**
 * Every NonceStore must refuse a nonce it has already seen from a sender
 * within the window.
 */
final class NonceStoreTest extends TestCase {

	private const NOW = 1800000000;

	/**
	 * @return array<string, array{0: callable(): NonceStore}>
	 */
	public static function stores(): array {
		return array(
			'memory'   => array( static fn(): NonceStore => new MemoryNonceStore() ),
			'database' => array( static fn(): NonceStore => new DbNonceStore( new FakeWpdb(), 'wp_sistersync_nonces', static fn(): int => self::NOW, static fn(): bool => false ) ),
		);
	}

	/**
	 * @param callable(): NonceStore $make
	 */
	#[DataProvider( 'stores' )]
	public function test_a_nonce_is_accepted_once_per_sender( callable $make ): void {
		$store = $make();

		$this->assertTrue( $store->remember( 'staging', 'nonce-aaaaaaaaaaaaaaaa', self::NOW + 600 ) );
		$this->assertFalse( $store->remember( 'staging', 'nonce-aaaaaaaaaaaaaaaa', self::NOW + 600 ), 'Replay refused.' );
		$this->assertTrue( $store->remember( 'dev', 'nonce-aaaaaaaaaaaaaaaa', self::NOW + 600 ), 'Another sender may use the same value.' );
		$this->assertTrue( $store->remember( 'staging', 'nonce-bbbbbbbbbbbbbbbb', self::NOW + 600 ) );
	}

	public function test_database_store_uses_one_atomic_insert_and_purges_expired_rows(): void {
		$db    = new FakeWpdb();
		$now   = self::NOW;
		$purge = false;
		$store = new DbNonceStore( $db, 'wp_sistersync_nonces', static function () use ( &$now ): int {
			return $now;
		}, static function () use ( &$purge ): bool {
			return $purge;
		} );

		$this->assertTrue( $store->remember( 'staging', 'old-nonce-aaaaaaaaaaaa', self::NOW + 600 ) );
		$this->assertTrue( $store->remember( 'staging', 'new-nonce-aaaaaaaaaaaa', self::NOW + 1200 ) );
		$this->assertCount( 2, $db->queries, 'One query per nonce when not purging.' );
		$this->assertStringStartsWith( 'INSERT IGNORE', $db->queries[0] );

		// Eleven minutes on, the first nonce's window has passed.
		$now   = self::NOW + 660;
		$purge = true;
		$this->assertTrue( $store->remember( 'staging', 'third-nonce-aaaaaaaaaa', $now + 600 ) );
		$this->assertStringStartsWith( 'DELETE FROM', $db->queries[2] );
		$this->assertSame( array( 'staging|new-nonce-aaaaaaaaaaaa', 'staging|third-nonce-aaaaaaaaaa' ), array_keys( $db->rows ) );
	}
}
