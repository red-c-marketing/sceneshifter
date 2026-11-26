<?php

declare(strict_types=1);

namespace RedC\Sceneshifter\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedC\Sceneshifter\Check\PublishGuard;
use RedC\Sceneshifter\Scanner\ContentScanner;
use RedC\Sceneshifter\Scanner\Finding;
use RedC\Sceneshifter\Scanner\ScanInput;
use RedC\Sceneshifter\Scanner\SeverityPolicy;
use RedC\Sceneshifter\SiteScan\ScheduledScan;
use RedC\Sceneshifter\Store\CheckSettings;
use RedC\Sceneshifter\Store\PushSettings;
use RedC\Sceneshifter\Url\UrlClassifier;

final class CheckSettingsTest extends TestCase {

	private const CONTENT = '<a href="https://staging.client.co.uk/a">a</a> <a href="http://client.test:8080/b">b</a>';

	public function test_push_settings_default_to_live_updates_and_stopping_on_clashes(): void {
		$defaults = PushSettings::fromArray( array() );
		$this->assertFalse( $defaults->holdsUpdates() );
		$this->assertFalse( $defaults->renamesClashes() );
		$this->assertSame( 0, $defaults->defaultAuthor );

		$set = PushSettings::fromArray( ( new PushSettings( PushSettings::UPDATES_REVIEW, 7, PushSettings::CLASH_RENAME ) )->toArray() );
		$this->assertTrue( $set->holdsUpdates() );
		$this->assertTrue( $set->renamesClashes() );
		$this->assertSame( 7, $set->defaultAuthor );

		$junk = PushSettings::fromArray( array( 'publishedUpdates' => 'later', 'defaultAuthor' => '-3', 'mediaClash' => 'overwrite' ) );
		$this->assertSame( array( PushSettings::UPDATES_LIVE, 0, PushSettings::CLASH_STOP ), array( $junk->publishedUpdates, $junk->defaultAuthor, $junk->mediaClash ) );
	}

	public function test_defaults_match_the_original_rule(): void {
		$settings = new CheckSettings();
		$this->assertSame( Finding::ERROR, $settings->severityFor( 'live', 'staging' ) );
		$this->assertSame( Finding::WARNING, $settings->severityFor( 'live', 'live' ) );
		$this->assertSame( Finding::WARNING, $settings->severityFor( 'staging', 'dev' ) );
	}

	public function test_settings_override_and_ignore_roles(): void {
		$settings = CheckSettings::fromArray(
			array(
				'severity' => array(
					'dev'     => CheckSettings::IGNORE,
					'staging' => Finding::WARNING,
					'bogus'   => Finding::ERROR,
					'uat'     => 'fatal',
				),
			)
		);
		$this->assertNull( $settings->severityFor( 'live', 'dev' ) );
		$this->assertSame( Finding::WARNING, $settings->severityFor( 'live', 'staging' ) );
		$this->assertSame( Finding::ERROR, $settings->severityFor( 'live', 'uat' ), 'Invalid values fall back to the default.' );
		$this->assertSame( array( 'dev', 'staging' ), array_keys( $settings->severity ) );
	}

	public function test_ignored_roles_drop_out_of_scans(): void {
		$registry = Fixtures::registry();
		$live     = $registry->get( 'live' ) ?? throw new \RuntimeException();
		$scanner  = new ContentScanner(
			new UrlClassifier( $registry, $live ),
			new SeverityPolicy( CheckSettings::fromArray( array( 'severity' => array( 'dev' => CheckSettings::IGNORE ) ) ) )
		);

		$findings = $scanner->scan( new ScanInput( self::CONTENT ) );
		$this->assertCount( 1, $findings );
		$this->assertSame( 'staging', $findings[0]->sister->id );
	}

	public function test_cleans_recipients_schedule_and_option_patterns(): void {
		$settings = CheckSettings::fromArray(
			array(
				'schedule'     => 'hourly',
				'recipients'   => "web@example.com, not-an-email\nweb@example.com;ops@example.com",
				'notify'       => 'sometimes',
				'extraOptions' => "mytheme_*\nfooter settings\nbad'name\nshop.options",
			)
		);
		$this->assertSame( CheckSettings::SCHEDULE_OFF, $settings->schedule );
		$this->assertSame( array( 'web@example.com', 'ops@example.com' ), $settings->recipients );
		$this->assertSame( CheckSettings::NOTIFY_NEW, $settings->notify );
		$this->assertSame( array( 'mytheme_*', 'footer', 'settings', 'shop.options' ), $settings->extraOptions );
		$this->assertSame( array( 'mytheme\\_%', 'footer', 'settings', 'shop.options' ), $settings->extraOptionLikePatterns() );
	}

	public function test_round_trips_through_an_array(): void {
		$settings = new CheckSettings( array( 'dev' => CheckSettings::IGNORE ), true, CheckSettings::SCHEDULE_WEEKLY, array( 'a@example.com' ), CheckSettings::NOTIFY_ALWAYS, array( 'x_*' ) );
		$this->assertEquals( $settings, CheckSettings::fromArray( $settings->toArray() ) );
	}

	public function test_scheduled_scan_email_lists_new_findings(): void {
		$message = ScheduledScan::message(
			'Live',
			'https://www.client.co.uk',
			3,
			array(
				'error'   => 4,
				'warning' => 1,
				'objects' => 2,
			),
			array(
				array(
					'object_title' => 'Home',
					'severity'     => Finding::ERROR,
					'sister_id'    => 'staging',
					'url'          => 'https://staging.client.co.uk/a',
					'location'     => 'Block 2 (core/paragraph)',
				),
			),
			'https://www.client.co.uk/wp-admin/admin.php?page=sceneshifter-report',
			static fn( string $id ): string => ucfirst( $id )
		);

		$this->assertStringContainsString( 'scheduled scan of Live (https://www.client.co.uk)', $message );
		$this->assertStringContainsString( '2 items link to other environments: 4 errors and 1 warnings', $message );
		$this->assertStringContainsString( '3 of these are new since the last scan:', $message );
		$this->assertStringContainsString( "- Home [error, Staging]\n  https://staging.client.co.uk/a\n  Block 2 (core/paragraph)", $message );
		$this->assertStringContainsString( '…and 2 more.', $message );
		$this->assertStringContainsString( 'page=sceneshifter-report', $message );
	}

	public function test_publish_blocked_message_counts_every_link(): void {
		$registry = Fixtures::registry();
		$live     = $registry->get( 'live' ) ?? throw new \RuntimeException();
		$findings = ( new ContentScanner( new UrlClassifier( $registry, $live ) ) )->scan( new ScanInput( self::CONTENT . ' <img src="https://staging.client.co.uk/a">' ) );

		$message = PublishGuard::message( $findings );
		$this->assertStringContainsString( '3 times', $message );
		$this->assertStringContainsString( 'Staging: https://staging.client.co.uk/a', $message );
	}
}
