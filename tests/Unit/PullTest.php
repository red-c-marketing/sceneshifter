<?php

declare(strict_types=1);

namespace RedC\Sceneshifter\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedC\Sceneshifter\Log\LogEntry;
use RedC\Sceneshifter\Scanner\Finding;
use RedC\Sceneshifter\SyncMap\MapEntry;
use RedC\Sceneshifter\Transfer\ContentHash;
use RedC\Sceneshifter\Transfer\ExportResponder;
use RedC\Sceneshifter\Transfer\MediaReceiver;
use RedC\Sceneshifter\Transfer\Package;
use RedC\Sceneshifter\Transfer\PreflightResponder;

/**
 * Dev pulls from Staging: Dev makes every call, Staging only answers.
 */
final class PullTest extends TestCase {

	private const STAGING_SALTS = 'staging-auth-key|staging-secure-auth-key|staging-logged-in-key';
	private const DEV_SALTS     = 'dev-auth-key|dev-secure-auth-key|dev-logged-in-key|dev-nonce';
	private const UUID_A        = '11111111-1111-4111-8111-111111111111';
	private const UUID_B        = '22222222-2222-4222-8222-222222222222';
	private const TRANSFER      = '33333333-3333-4333-8333-333333333333';

	protected function setUp(): void {
		SisterHarness::$network = array();
	}

	public function test_pulls_a_new_post_with_its_media(): void {
		[ $staging, $dev ] = $this->paired();
		$staging->packages[11] = self::about();
		$staging->source->add( 5, '2024/05/a.jpg', 'abc', self::UUID_B );
		$dev->lookup->terms['category:news'] = 9;

		$result = $dev->puller()->run( 'staging', 11, self::TRANSFER, array(), null, 'Ann' );

		$this->assertNull( $result->error );
		$this->assertTrue( $result->created );
		$this->assertSame( 11, $result->postId );
		$this->assertSame( 900, $result->targetId );
		$this->assertSame( 'draft', $result->status, 'New posts arrive as drafts by default.' );
		$this->assertSame( 1, $result->mediaCopied );
		$this->assertSame( array( ExportResponder::EXPORT_ROUTE, ExportResponder::MEDIA_ROUTE, ExportResponder::PULLED_ROUTE ), $staging->served, 'Staging only answers; nothing is written there.' );
		$this->assertSame( array(), $staging->writer->writes );

		$written = $dev->writer->writes[0]['package'];
		$this->assertSame( '<p><a href="http://client.test:8080/company/">Hi</a></p><img class="wp-image-700"><img class="wp-image-0">', $written->fields['post_content'], 'Staging\'s address becomes this site\'s, copied media gets its new ID, and deleted media is cleared.' );
		$this->assertSame( 700, $written->fields['featured'] );
		$this->assertSame( 0, $written->fields['post_parent'], 'A parent this site lacks is cleared.' );
		$this->assertSame( 'abc', $dev->mediaFiles->files['2024/05/a.jpg'] );
		$this->assertSame( self::TRANSFER . '/11', $dev->mediaFiles->attachments[700]['meta']['copied'], 'The copy remembers which pull made it, so undoing removes it.' );

		$this->assertSame( 900, $dev->map->byRemote( 'staging', MapEntry::POST, 11 )?->localId );
		$this->assertSame( 700, $dev->map->byRemote( 'staging', MapEntry::POST, 5 )?->localId );
		$this->assertSame( 900, $staging->map->get( 'dev', MapEntry::POST, 11 )?->remoteId, 'Staging\'s sync map matches, as after a push from there.' );
		$this->assertSame( 700, $staging->map->get( 'dev', MapEntry::POST, 5 )?->remoteId );
		$this->assertSame( ContentHash::overall( ContentHash::parts( self::about() ) ), $staging->map->get( 'dev', MapEntry::POST, 11 )?->hash );

		$in = $dev->log->find( self::TRANSFER, LogEntry::IN, 'staging', 900 );
		$this->assertSame( LogEntry::DONE, $in?->result );
		$this->assertTrue( $in?->details['pulled'] ?? false );
		$this->assertSame( 1, $in?->details['media'] ?? null );
		$this->assertSame( 'Ann', $in?->user );
		$this->assertNotNull( $dev->snapshots->get( (int) $result->snapshot ) );

		$out = $staging->log->find( self::TRANSFER, LogEntry::OUT, 'dev', 900 );
		$this->assertSame( LogEntry::DONE, $out?->result );
		$this->assertTrue( $out?->details['pulled'] ?? false );
		$this->assertTrue( (bool) $out?->created );
		$this->assertSame( 11, $out?->sourceId );
	}

	public function test_updates_a_linked_post_here(): void {
		[ $staging, $dev ] = $this->paired();
		$staging->packages[11] = self::about();
		$staging->source->add( 5, '2024/05/a.jpg', 'abc', self::UUID_B );
		$dev->lookup->post( 201, 'page', 'about', self::UUID_A );
		$dev->lookup->media( 701, '2024/05/a.jpg', 3 );
		$dev->facts->packages[201] = self::package( 201, array( 'post_title' => 'Old title' ) );
		$dev->facts->statuses[201] = 'publish';
		$dev->map->save( new MapEntry( self::UUID_A, MapEntry::POST, 201, 'staging', 11, 1 ) );
		$dev->holdUpdates = true;

		$result = $dev->puller()->run( 'staging', 11, self::TRANSFER, array(), 201 );

		$this->assertNull( $result->error );
		$this->assertFalse( $result->created );
		$this->assertSame( 201, $result->targetId );
		$this->assertFalse( $result->isHeld(), 'A pull is never held for review: the person pulling is here.' );
		$this->assertSame( 'About us', $dev->facts->packages[201]->title() );
		$this->assertSame( 0, $result->mediaCopied, 'A file this site already has isn\'t copied again.' );
		$this->assertNotContains( ExportResponder::MEDIA_ROUTE, $staging->served );
	}

	public function test_search_and_preview_change_nothing(): void {
		[ $staging, $dev ] = $this->paired();
		$staging->packages[11] = self::about();
		$staging->packages[12] = self::package( 12, array( 'post_title' => 'Contact', 'post_name' => 'contact' ) );
		$staging->map->save( new MapEntry( self::UUID_A, MapEntry::POST, 12, 'dev', 512, 1 ) );
		$staging->source->add( 5, '2024/05/a.jpg', 'abc', self::UUID_B );

		$found = $dev->puller()->search( 'staging', 'con' );
		$this->assertNull( $found['error'] );
		$this->assertSame( 1, $found['total'] );
		$this->assertSame( 12, $found['items'][0]['id'] );
		$this->assertSame( 512, $found['items'][0]['here'], 'Search says which posts this site already has.' );

		$preview = $dev->puller()->preview( 'staging', array( 11, 99 ) );
		$this->assertNull( $preview['error'] );
		$this->assertCount( 1, $preview['items'], 'A post Staging can\'t export is left out.' );
		$item = $preview['items'][0];
		$this->assertFalse( $item->isUpdate() );
		$this->assertSame( array( 5 ), array_column( $item->media, 'id' ) );
		$this->assertSame( array(), $dev->writer->writes );
		$this->assertSame( array(), $dev->mediaFiles->files );
		$this->assertSame( array(), $dev->log->recent( 10 ) );
	}

	public function test_refuses_a_post_that_links_to_a_third_environment(): void {
		[ $staging, $dev ] = $this->paired();
		$staging->packages[11] = self::package( 11 );
		$registry              = Fixtures::registry();
		$live                  = $registry->get( 'live' );
		$here                  = $registry->get( 'dev' );
		$this->assertNotNull( $live );
		$this->assertNotNull( $here );
		$staging->sourceFindings[11] = array( new Finding( $here, 'http://client.test:8080/x', Finding::WARNING, 'content', 'Content' ) );

		$this->assertNull( $dev->puller()->run( 'staging', 11, self::TRANSFER )->error, 'A link to the site pulling is fine.' );

		$staging->sourceFindings[11][] = new Finding( $live, 'https://www.client.co.uk/x', Finding::WARNING, 'content', 'Content' );
		$result                        = $dev->puller()->run( 'staging', 11, '55555555-5555-4555-8555-555555555555' );

		$this->assertStringContainsString( 'Fix that on Staging before pulling', (string) $result->error );
		$this->assertCount( 1, $dev->writer->writes );
		$failed = $dev->log->recent( 1 )[0] ?? null;
		$this->assertSame( LogEntry::FAILED, $failed?->result, 'A pull that stops early is still logged here.' );
		$this->assertTrue( $failed?->details['pulled'] ?? false );
	}

	public function test_stops_on_a_changed_match_or_an_unlinked_post_without_agreement(): void {
		[ $staging, $dev ] = $this->paired();
		$staging->packages[11] = self::package( 11 );
		$dev->lookup->post( 300, 'page', 'about' );
		$dev->facts->packages[300] = self::package( 300, array( 'post_title' => 'Ours' ) );

		$moved = $dev->puller()->run( 'staging', 11, self::TRANSFER, array(), null );
		$this->assertStringContainsString( 'changed since the preview', (string) $moved->error );

		$refused = $dev->puller()->run( 'staging', 11, self::TRANSFER, array(), 300 );
		$this->assertStringContainsString( 'never linked', (string) $refused->error );
		$this->assertSame( array(), $dev->writer->writes );

		$agreed = $dev->puller()->run( 'staging', 11, self::TRANSFER, array( 'overwrite_unlinked' => true ), 300 );
		$this->assertNull( $agreed->error );
		$this->assertSame( 300, $agreed->targetId );
	}

	public function test_copies_large_files_in_chunks(): void {
		[ $staging, $dev ] = $this->paired();
		$bytes                 = str_repeat( 'x', MediaReceiver::CHUNK * 2 + 10 );
		$staging->packages[11] = self::about();
		$staging->source->add( 5, '2024/05/a.jpg', $bytes, self::UUID_B );

		$result = $dev->puller()->run( 'staging', 11, self::TRANSFER );

		$this->assertNull( $result->error );
		$this->assertSame( $bytes, $dev->mediaFiles->files['2024/05/a.jpg'] );
		$this->assertCount( 3, array_keys( $staging->served, ExportResponder::MEDIA_ROUTE, true ) );
	}

	public function test_a_missing_file_stops_the_pull_before_anything_is_written(): void {
		[ $staging, $dev ] = $this->paired();
		$staging->packages[11] = self::about();

		$result = $dev->puller()->run( 'staging', 11, self::TRANSFER );

		$this->assertStringContainsString( 'the file is missing on Staging', (string) $result->error );
		$this->assertSame( array(), $dev->writer->writes );
		$this->assertNotContains( ExportResponder::PULLED_ROUTE, $staging->served );
	}

	public function test_undoing_a_pull_here_tells_the_source(): void {
		[ $staging, $dev ] = $this->paired();
		$staging->packages[11] = self::about();
		$staging->source->add( 5, '2024/05/a.jpg', 'abc', self::UUID_B );
		$dev->puller()->run( 'staging', 11, self::TRANSFER, array(), null, 'Ann' );
		$entry = $dev->log->find( self::TRANSFER, LogEntry::IN, 'staging', 900 );
		$this->assertNotNull( $entry );

		$reply = $dev->rollback()->undo( 'staging', self::TRANSFER, 900, false, 'Bo' );
		$this->assertTrue( $reply['undone'] ?? false );
		$this->assertSame( array( 700 ), $reply['media_removed'] ?? null );

		$this->assertTrue( $dev->puller()->undone( $entry, $reply, 'Bo' ) );
		$this->assertSame( LogEntry::UNDONE, $staging->log->find( self::TRANSFER, LogEntry::OUT, 'dev', 900 )?->result );
		$this->assertNull( $staging->map->get( 'dev', MapEntry::POST, 11 ), 'The post it created is gone, so Staging forgets the link.' );
		$this->assertNull( $staging->map->get( 'dev', MapEntry::POST, 5 ), 'And the copied file.' );
	}

	public function test_export_refuses_another_format_and_bad_media_requests(): void {
		$staging = new SisterHarness( 'staging', self::STAGING_SALTS );
		$export  = $staging->exporter();

		$this->assertSame( PreflightResponder::FORMAT_MISMATCH, $export->export( 'dev', array( 'format' => '2', 'ids' => array( 1 ) ) )['error'] ?? null );
		$this->assertSame( ExportResponder::INVALID, $export->mediaRead( 'dev', array( 'id' => 'x' ) )['error'] ?? null );
		$this->assertSame( ExportResponder::NOT_FOUND, $export->mediaRead( 'dev', array( 'id' => 5, 'offset' => 0 ) )['error'] ?? null );
		$this->assertSame( ExportResponder::INVALID, $export->pulled( 'dev', array( 'transfer' => 'nope', 'target' => 1 ) )['error'] ?? null );

		$staging->source->add( 5, '2024/05/a.jpg', 'abc', self::UUID_B );
		$read = $export->mediaRead( 'dev', array( 'id' => 5, 'offset' => 1 ) );
		$this->assertSame( base64_encode( 'bc' ), $read['data'] ?? null );
		$this->assertArrayNotHasKey( 'path', $read, 'Server paths stay private.' );
	}

	/**
	 * @return array{0: SisterHarness, 1: SisterHarness}
	 */
	private function paired(): array {
		$staging = new SisterHarness( 'staging', self::STAGING_SALTS );
		$dev     = new SisterHarness( 'dev', self::DEV_SALTS );
		$dev->sisters->pair( $staging->sisters->createCode( 'dev' )->encode() );
		return array( $staging, $dev );
	}

	private static function about(): Package {
		return self::package(
			11,
			array(
				'post_content' => '<p><a href="https://staging.client.co.uk/company/">Hi</a></p><img class="wp-image-5"><img class="wp-image-7">',
				'featured'     => 5,
				'post_parent'  => 12,
			),
			array(
				array(
					'taxonomy' => 'category',
					'slug'     => 'news',
					'name'     => 'News',
					'parent'   => '',
				),
			),
			array( 12 => array( 'type' => 'page', 'path' => 'company', 'title' => 'Company' ) ),
			array(
				5 => array( 'file' => '2024/05/a.jpg', 'size' => 3, 'title' => 'A' ),
				7 => array( 'missing' => true ),
			)
		);
	}

	/**
	 * @param array<string, mixed>                                                      $fields
	 * @param list<array{taxonomy: string, slug: string, name: string, parent: string}> $terms
	 * @param array<int, array<string, mixed>>                                          $posts
	 * @param array<int, array<string, mixed>>                                          $attachments
	 */
	private static function package( int $id, array $fields = array(), array $terms = array(), array $posts = array(), array $attachments = array() ): Package {
		return new Package(
			$id,
			self::UUID_A,
			(string) ( $fields['post_name'] ?? 'about' ),
			$fields + array(
				'post_type'     => 'page',
				'post_title'    => 'About us',
				'post_content'  => '<p>Hello</p>',
				'post_excerpt'  => '',
				'post_name'     => 'about',
				'menu_order'    => 0,
				'post_status'   => 'publish',
				'page_template' => '',
				'post_parent'   => 0,
				'featured'      => 0,
				'author_email'  => '',
			),
			array(),
			array(),
			$terms,
			array(),
			$posts,
			$attachments,
			array()
		);
	}
}
