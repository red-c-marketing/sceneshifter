<?php

declare(strict_types=1);

namespace RedC\SisterSync\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RedC\SisterSync\Registry\Identity;
use RedC\SisterSync\Registry\Registry;
use RedC\SisterSync\Registry\Site;

final class RegistryTest extends TestCase {

	public function test_round_trips_through_array(): void {
		$registry = Fixtures::registry();
		$this->assertSame( $registry->toArray(), Registry::fromArray( $registry->toArray() )->toArray() );
		$this->assertSame( 3, $registry->version );
		$this->assertCount( 4, $registry->sites() );
	}

	/**
	 * @return array<string, array{0: string, 1: ?string, 2: string, 3: ?string}>
	 */
	public static function identities(): array {
		return array(
			'live by primary URL'           => array( 'https://www.client.co.uk', 'www.client.co.uk', Identity::OK, 'live' ),
			'live by alias'                 => array( 'https://client.co.uk', 'client.co.uk', Identity::OK, 'live' ),
			'staging'                       => array( 'https://staging.client.co.uk', 'staging.client.co.uk', Identity::OK, 'staging' ),
			'dev with port'                 => array( 'http://client.test:8080', 'client.test:8080', Identity::OK, 'dev' ),
			'dev, wrong port'               => array( 'http://client.test:9090', 'client.test:9090', Identity::HOME_NOT_IN_REGISTRY, null ),
			'subdirectory site'             => array( 'https://agency.example.com/clients/client', 'agency.example.com', Identity::OK, 'uat' ),
			'CLI has no request host'       => array( 'https://staging.client.co.uk', null, Identity::OK, 'staging' ),
			'unknown home'                  => array( 'https://elsewhere.example', 'elsewhere.example', Identity::HOME_NOT_IN_REGISTRY, null ),
			'clone before search-replace'   => array( 'https://www.client.co.uk', 'staging.client.co.uk', Identity::REQUEST_HOST_MISMATCH, 'live' ),
			'request host is case-insensitive' => array( 'https://staging.client.co.uk', 'Staging.Client.co.uk', Identity::OK, 'staging' ),
		);
	}

	#[DataProvider( 'identities' )]
	public function test_resolves_current_site( string $home, ?string $host, string $status, ?string $siteId ): void {
		$identity = Fixtures::registry()->resolveCurrent( $home, $host );
		$this->assertSame( $status, $identity->status );
		$this->assertSame( $siteId, $identity->site?->id );
		$this->assertSame( Identity::OK === $status, $identity->isActive() );
	}

	public function test_empty_registry_is_unconfigured_and_inactive(): void {
		$identity = Registry::empty()->resolveCurrent( 'https://www.client.co.uk', 'www.client.co.uk' );
		$this->assertSame( Identity::UNCONFIGURED, $identity->status );
		$this->assertFalse( $identity->isActive() );
	}

	public function test_rejects_duplicate_ids(): void {
		$this->expectException( InvalidArgumentException::class );
		new Registry(
			array(
				new Site( 'live', 'Live', 'live', 'https://a.example' ),
				new Site( 'live', 'Live 2', 'live', 'https://b.example' ),
			)
		);
	}

	public function test_rejects_two_sites_claiming_the_same_host(): void {
		$this->expectException( InvalidArgumentException::class );
		new Registry(
			array(
				new Site( 'live', 'Live', 'live', 'https://www.a.example' ),
				new Site( 'staging', 'Staging', 'staging', 'https://a.example' ),
			)
		);
	}

	public function test_allows_sites_on_one_host_with_different_paths(): void {
		$registry = new Registry(
			array(
				new Site( 'one', 'One', 'staging', 'https://agency.example/one' ),
				new Site( 'two', 'Two', 'uat', 'https://agency.example/two' ),
			)
		);
		$this->assertSame( 'two', $registry->resolveCurrent( 'https://agency.example/two', 'agency.example' )->site?->id );
	}

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function invalidSites(): array {
		return array(
			'bad id'     => array( array( 'id' => 'Live Site', 'label' => 'Live', 'role' => 'live', 'url' => 'https://a.example' ) ),
			'no label'   => array( array( 'id' => 'live', 'label' => ' ', 'role' => 'live', 'url' => 'https://a.example' ) ),
			'bad role'   => array( array( 'id' => 'live', 'label' => 'Live', 'role' => 'production', 'url' => 'https://a.example' ) ),
			'bad url'    => array( array( 'id' => 'live', 'label' => 'Live', 'role' => 'live', 'url' => 'ftp://a.example' ) ),
			'bad colour' => array( array( 'id' => 'live', 'label' => 'Live', 'role' => 'live', 'url' => 'https://a.example', 'colour' => 'red' ) ),
		);
	}

	/**
	 * @param array<string, mixed> $data
	 */
	#[DataProvider( 'invalidSites' )]
	public function test_rejects_invalid_sites( array $data ): void {
		$this->expectException( InvalidArgumentException::class );
		Site::fromArray( $data );
	}

	public function test_default_colour_follows_role(): void {
		$this->assertSame( '#d63638', Fixtures::registry()->get( 'live' )?->colour() );
	}
}
