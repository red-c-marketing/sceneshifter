<?php

declare(strict_types=1);

namespace RedC\SisterSync\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedC\SisterSync\Registry\Registry;
use RedC\SisterSync\Registry\RegistryForm;

final class RegistryFormTest extends TestCase {

	public function test_builds_a_registry_from_rows_and_drops_blank_ones(): void {
		$result = RegistryForm::parse(
			array(
				array(
					'label'   => 'Live',
					'role'    => 'live',
					'url'     => 'www.client.co.uk/',
					'aliases' => "client.co.uk\n cdn.client.co.uk, ",
					'public'  => '1',
					'colour'  => '#d63638',
				),
				array(
					'label' => '',
					'role'  => 'staging',
					'url'   => '',
				),
				array(
					'label'  => 'Andrew\'s dev box',
					'role'   => 'dev',
					'url'    => 'http://client.test:8080',
					'colour' => '#123456',
				),
			),
			Registry::empty()
		);

		$this->assertSame( array(), $result['rowErrors'] );
		$registry = $result['registry'];
		$this->assertNotNull( $registry );
		$this->assertSame( 1, $registry->version );

		$live = $registry->get( 'live' );
		$this->assertNotNull( $live );
		$this->assertSame( 'https://www.client.co.uk', $live->url );
		$this->assertSame( array( 'client.co.uk', 'cdn.client.co.uk' ), $live->aliases );
		$this->assertTrue( $live->isPublic );
		$this->assertSame( '', $live->toArray()['colour'], 'The role\'s default colour is not stored.' );

		$dev = $registry->get( 'andrew-s-dev-box' );
		$this->assertNotNull( $dev );
		$this->assertFalse( $dev->isPublic );
		$this->assertSame( '#123456', $dev->colour() );
	}

	public function test_existing_sites_keep_their_ids_and_new_ones_avoid_them(): void {
		$result = RegistryForm::parse(
			array(
				array(
					'label' => 'Live',
					'role'  => 'live',
					'url'   => 'https://new-live.example.com',
				),
				array(
					'id'    => 'live',
					'label' => 'Old live, renamed',
					'role'  => 'live',
					'url'   => 'https://www.client.co.uk',
				),
			),
			Registry::empty()
		);

		$registry = $result['registry'];
		$this->assertNotNull( $registry );
		$this->assertSame( 'Old live, renamed', $registry->get( 'live' )?->label );
		$this->assertSame( 'https://new-live.example.com', $registry->get( 'live-2' )?->url );
	}

	public function test_marks_the_row_with_a_problem(): void {
		$result = RegistryForm::parse(
			array(
				array(
					'label' => 'Live',
					'role'  => 'live',
					'url'   => 'https://www.client.co.uk',
				),
				array(
					'label' => '',
					'role'  => 'staging',
					'url'   => 'https://staging.client.co.uk',
				),
			),
			Registry::empty()
		);

		$this->assertNull( $result['registry'] );
		$this->assertSame( array( 1 ), array_keys( $result['rowErrors'] ) );
		$this->assertSame( 'Give this site a name.', $result['rowErrors'][1] );
		$this->assertCount( 2, $result['rows'], 'Rows come back for redisplay.' );
	}

	public function test_reports_two_sites_claiming_one_address(): void {
		$result = RegistryForm::parse(
			array(
				array(
					'label' => 'Live',
					'role'  => 'live',
					'url'   => 'https://www.client.co.uk',
				),
				array(
					'label' => 'Staging',
					'role'  => 'staging',
					'url'   => 'https://client.co.uk',
				),
			),
			Registry::empty()
		);

		$this->assertNull( $result['registry'] );
		$this->assertStringContainsString( 'both claim', (string) $result['error'] );
	}

	public function test_version_goes_up_only_when_sites_change(): void {
		$previous = Fixtures::registry();
		$rows     = RegistryForm::rowsFrom( $previous );

		$same = RegistryForm::parse( $rows, $previous )['registry'];
		$this->assertSame( $previous->version, $same?->version );

		$rows[1]['label'] = 'Staging (new)';
		$changed          = RegistryForm::parse( $rows, $previous )['registry'];
		$this->assertSame( $previous->version + 1, $changed?->version );
	}

	public function test_suggests_ids_from_names(): void {
		$this->assertSame( 'staging', RegistryForm::suggestId( 'Staging', 'staging' ) );
		$this->assertSame( 'uat-2024', RegistryForm::suggestId( '  UAT (2024) ', 'uat' ) );
		$this->assertSame( 'dev', RegistryForm::suggestId( '★', 'dev' ) );
	}
}
