<?php

declare(strict_types=1);

namespace RedC\SisterSync\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RedC\SisterSync\Registry\IncomingRegistry;
use RedC\SisterSync\Registry\Registry;
use RedC\SisterSync\Registry\RegistryBundle;
use RedC\SisterSync\Registry\RegistryDiff;

final class RegistryTransferTest extends TestCase {

	private const STAGING_HOME = 'https://staging.client.co.uk';

	public function test_bundle_round_trips_as_link_text_and_as_a_file(): void {
		$bundle = new RegistryBundle( Fixtures::registry(), 'live' );

		$encoded = $bundle->encode();
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9_-]+$/', $encoded, 'Safe to put in a URL fragment.' );

		foreach ( array( $encoded, $bundle->toJson() ) as $input ) {
			$decoded = RegistryBundle::decode( $input );
			$this->assertSame( 'live', $decoded->from );
			$this->assertSame( Fixtures::registry()->toArray(), $decoded->registry->toArray() );
		}
	}

	public function test_bundle_rejects_other_input(): void {
		foreach ( array( '', '{"sites":[]}', 'not!base64', substr( ( new RegistryBundle( Fixtures::registry(), 'live' ) )->encode(), 0, 40 ) ) as $input ) {
			try {
				RegistryBundle::decode( $input );
				$this->fail( 'Accepted: ' . $input );
			} catch ( InvalidArgumentException $e ) {
				$this->addToAssertionCount( 1 );
			}
		}
	}

	public function test_diff_lists_added_changed_and_removed_sites(): void {
		$current  = Fixtures::registry();
		$data     = $current->toArray();
		$data['sites'][1]['url']   = 'https://stage.client.co.uk';
		$data['sites'][]           = array(
			'id'    => 'preview',
			'label' => 'Preview',
			'role'  => 'other',
			'url'   => 'https://preview.client.co.uk',
		);
		unset( $data['sites'][3] );
		$incoming = Registry::fromArray( $data );

		$byId = array();
		foreach ( RegistryDiff::compare( $current, $incoming ) as $entry ) {
			$byId[ $entry['site']->id ] = $entry;
		}

		$this->assertSame( RegistryDiff::UNCHANGED, $byId['live']['status'] );
		$this->assertSame( RegistryDiff::CHANGED, $byId['staging']['status'] );
		$this->assertSame( array( 'url' ), $byId['staging']['fields'] );
		$this->assertSame( RegistryDiff::ADDED, $byId['preview']['status'] );
		$this->assertSame( RegistryDiff::REMOVED, $byId['uat']['status'] );
	}

	public function test_a_verified_link_from_a_known_sister_can_be_applied(): void {
		$check = $this->check( $this->changed(), 'live', IncomingRegistry::SOURCE_LINK, 'https://www.client.co.uk' );

		$this->assertSame( array(), $check->blockers() );
		$this->assertSame( array(), $check->warnings() );
		$this->assertTrue( $check->originVerified() );
		$this->assertTrue( $check->canApply() );
		$this->assertSame( 'staging', $check->self()?->id );
		$this->assertSame( 4, $check->registryToSave()->version );
	}

	public function test_alias_hosts_count_as_the_sender(): void {
		$check = $this->check( $this->changed(), 'live', IncomingRegistry::SOURCE_LINK, 'https://client.co.uk' );
		$this->assertTrue( $check->originVerified() );
	}

	public function test_a_link_opened_from_another_site_is_refused(): void {
		$check = $this->check( $this->changed(), 'live', IncomingRegistry::SOURCE_LINK, 'https://attacker.example' );

		$this->assertSame( array( IncomingRegistry::WRONG_ORIGIN ), $check->blockers() );
		$this->assertFalse( $check->canApply() );
	}

	public function test_a_link_from_an_unknown_site_is_refused(): void {
		$check = $this->check( $this->changed(), 'stranger', IncomingRegistry::SOURCE_LINK, null );
		$this->assertContains( IncomingRegistry::UNKNOWN_SENDER, $check->blockers() );
	}

	public function test_a_link_without_a_referrer_needs_care_but_is_allowed(): void {
		$check = $this->check( $this->changed(), 'live', IncomingRegistry::SOURCE_LINK, null );

		$this->assertSame( array(), $check->blockers() );
		$this->assertSame( array( IncomingRegistry::UNVERIFIED_ORIGIN ), $check->warnings() );
		$this->assertTrue( $check->canApply() );
	}

	public function test_a_list_without_this_site_is_refused(): void {
		$data = Fixtures::registry()->toArray();
		unset( $data['sites'][1] );
		$check = $this->check( Registry::fromArray( $data ), 'live', IncomingRegistry::SOURCE_FILE, null );

		$this->assertSame( array( IncomingRegistry::NOT_INCLUDED ), $check->blockers() );
	}

	public function test_first_setup_accepts_a_sender_it_does_not_know_yet(): void {
		$check = new IncomingRegistry(
			Registry::empty(),
			new RegistryBundle( Fixtures::registry(), 'live' ),
			self::STAGING_HOME,
			IncomingRegistry::SOURCE_LINK,
			'https://www.client.co.uk'
		);

		$this->assertSame( array(), $check->blockers() );
		$this->assertSame( array( IncomingRegistry::FIRST_SETUP ), $check->warnings() );
		$this->assertSame( 3, $check->registryToSave()->version, 'Takes the sender\'s version.' );
	}

	public function test_an_older_list_warns_and_moves_the_version_on(): void {
		$data            = $this->changed()->toArray();
		$data['version'] = 2;
		$check           = $this->check( Registry::fromArray( $data ), 'live', IncomingRegistry::SOURCE_FILE, null );

		$this->assertSame( array( IncomingRegistry::OLDER ), $check->warnings() );
		$this->assertSame( 4, $check->newVersion() );
	}

	public function test_an_identical_list_has_nothing_to_apply(): void {
		$check = $this->check( Fixtures::registry(), 'live', IncomingRegistry::SOURCE_LINK, 'https://www.client.co.uk' );

		$this->assertTrue( $check->isUpToDate() );
		$this->assertFalse( $check->canApply() );
	}

	private function changed(): Registry {
		$data                      = Fixtures::registry()->toArray();
		$data['sites'][0]['label'] = 'Live site';
		$data['version']           = 4;
		return Registry::fromArray( $data );
	}

	private function check( Registry $incoming, string $from, string $source, ?string $referrer ): IncomingRegistry {
		return new IncomingRegistry( Fixtures::registry(), new RegistryBundle( $incoming, $from ), self::STAGING_HOME, $source, $referrer );
	}
}
