<?php

declare(strict_types=1);

namespace RedC\Sceneshifter\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedC\Sceneshifter\Scanner\Finding;
use RedC\Sceneshifter\SyncMap\MapEntry;
use RedC\Sceneshifter\Transfer\ContentHash;
use RedC\Sceneshifter\Transfer\ImportResponder;
use RedC\Sceneshifter\Transfer\MediaFiles;
use RedC\Sceneshifter\Transfer\MediaReceiver;
use RedC\Sceneshifter\Transfer\Package;
use RedC\Sceneshifter\Transfer\PreflightResponder;
use RedC\Sceneshifter\Transfer\PushPreview;
use RedC\Sceneshifter\Transfer\PushRunner;

final class PushTest extends TestCase {

	private const STAGING_SALTS = 'staging-auth-key|staging-secure-auth-key|staging-logged-in-key';
	private const DEV_SALTS     = 'dev-auth-key|dev-secure-auth-key|dev-logged-in-key|dev-nonce';
	private const UUID_A        = '11111111-1111-4111-8111-111111111111';
	private const UUID_B        = '22222222-2222-4222-8222-222222222222';
	private const TRANSFER      = '33333333-3333-4333-8333-333333333333';
	private const UPLOAD        = '44444444-4444-4444-8444-444444444444';

	protected function setUp(): void {
		SisterHarness::$network = array();
	}

	public function test_pushes_a_new_post_with_its_media(): void {
		[ $staging, $dev ] = $this->paired();
		$staging->lookup->terms['category:news'] = 9;
		$about = self::about();
		$media = ( new MemoryMediaSource() )->add( 5, '2024/05/a.jpg', 'abc', self::UUID_B );

		$result = $this->runner( $dev, array( 11 => $about ), $media )->run( 'staging', 11, self::TRANSFER, array(), null );

		$this->assertNull( $result->error );
		$this->assertTrue( $result->created );
		$this->assertSame( 900, $result->targetId );
		$this->assertSame( 'draft', $result->status, 'New posts arrive as drafts by default.' );
		$this->assertSame( 1, $result->mediaCopied );
		$this->assertSame( array( '/sceneshifter/v1/preflight', MediaReceiver::CHUNK_ROUTE, MediaReceiver::COMMIT_ROUTE, ImportResponder::ROUTE ), $staging->served );

		$written = $staging->writer->writes[0]['package'];
		$this->assertSame( '<p>Hi</p><img class="wp-image-700"><img class="wp-image-0">', $written->fields['post_content'], 'Copied media gets its new ID; media deleted on the source is cleared rather than left pointing at something else.' );
		$this->assertSame( 700, $written->fields['featured'] );
		$this->assertSame( 0, $written->fields['post_parent'], 'A parent the target lacks is cleared.' );
		$this->assertSame( 'abc', $staging->mediaFiles->files['2024/05/a.jpg'] );
		$this->assertEqualsCanonicalizing( array( 7, 12 ), array_column( $result->missing, 'id' ) );

		$this->assertSame( 900, $staging->map->byRemote( 'dev', MapEntry::POST, 11 )?->localId );
		$this->assertSame( 700, $staging->map->byRemote( 'dev', MapEntry::POST, 5 )?->localId );
		$this->assertSame( 900, $dev->map->get( 'staging', MapEntry::POST, 11 )?->remoteId );
		$this->assertSame( 700, $dev->map->get( 'staging', MapEntry::POST, 5 )?->remoteId );
		$this->assertSame( ContentHash::overall( ContentHash::parts( $about ) ), $dev->map->get( 'staging', MapEntry::POST, 11 )?->hash );

		$snapshot = $staging->snapshots->get( (int) $result->snapshot );
		$this->assertNotNull( $snapshot );
		$this->assertTrue( $snapshot->isNew );
		$this->assertSame( 900, $snapshot->postId );
		$this->assertSame( self::TRANSFER, $snapshot->transfer );
	}

	public function test_updates_a_linked_post_and_keeps_its_status(): void {
		[ $staging, $dev ] = $this->paired();
		$this->existingAbout( $staging, 'private' );

		$result = $this->runner( $dev, array( 11 => self::about() ), self::mediaA() )->run( 'staging', 11, self::TRANSFER, array( 'new_status' => 'publish' ), 201 );

		$this->assertNull( $result->error );
		$this->assertFalse( $result->created );
		$this->assertSame( 201, $result->targetId );
		$this->assertSame( 'private', $result->status );
		$this->assertTrue( $staging->writer->writes[0]['options']['keep_date'] );
		$this->assertSame( 'About us', $staging->facts->packages[201]->title() );

		$snapshot = $staging->snapshots->get( (int) $result->snapshot );
		$this->assertFalse( (bool) $snapshot?->isNew );
		$this->assertSame( 'Old title', $snapshot?->data['package']['fields']['post_title'] ?? null );

		$entry = $staging->map->get( 'dev', MapEntry::POST, 201 );
		$this->assertSame( 1, $entry?->linkedAt, 'The original link time is kept.' );
		$this->assertSame( 1700000000, $entry?->syncedAt );
		$this->assertNotNull( $entry?->hash );
		$this->assertSame( array(), $result->mismatch );
	}

	public function test_a_failed_write_puts_the_post_back(): void {
		[ $staging, $dev ] = $this->paired();
		$this->existingAbout( $staging, 'publish' );
		$staging->writer->fail = 'Disk full';

		$result = $this->runner( $dev, array( 11 => self::about() ), self::mediaA() )->run( 'staging', 11, self::TRANSFER );

		$this->assertStringContainsString( 'Disk full', (string) $result->error );
		$this->assertStringContainsString( 'put back as it was', (string) $result->error );
		$this->assertSame( array( 201 ), $staging->writer->restored );
		$this->assertSame( 'Old title', $staging->facts->packages[201]->title() );
		$this->assertNull( $staging->map->get( 'dev', MapEntry::POST, 201 )?->syncedAt, 'The sync map is untouched.' );
		$this->assertNull( $dev->map->get( 'staging', MapEntry::POST, 11 ) );
	}

	public function test_stops_when_the_match_changed_since_the_preview(): void {
		[ $staging, $dev ] = $this->paired();
		$this->existingAbout( $staging, 'publish' );

		$result = $this->runner( $dev, array( 11 => self::about() ), self::mediaA() )->run( 'staging', 11, self::TRANSFER, array(), null );

		$this->assertStringContainsString( 'changed since the preview', (string) $result->error );
		$this->assertNotContains( ImportResponder::ROUTE, $staging->served );
		$this->assertNotContains( MediaReceiver::CHUNK_ROUTE, $staging->served );
	}

	public function test_refuses_a_post_that_links_to_a_third_environment(): void {
		[ $staging, $dev ] = $this->paired();
		$live = new Finding( Fixtures::registry()->get( 'live' ), 'https://www.client.co.uk/x', Finding::WARNING, 'content', 'Content' );

		$result = $this->runner( $dev, array( 11 => self::about() ), self::mediaA(), array( 11 => array( $live ) ) )->run( 'staging', 11, self::TRANSFER );

		$this->assertStringContainsString( 'Fix that before pushing', (string) $result->error );
		$this->assertSame( array(), $staging->writer->writes );
	}

	public function test_copies_large_files_in_chunks(): void {
		[ $staging, $dev ] = $this->paired();
		$bytes = str_repeat( 'x', MediaReceiver::CHUNK * 2 + 10 );
		$media = ( new MemoryMediaSource() )->add( 5, '2024/05/a.jpg', $bytes, self::UUID_B );

		$result = $this->runner( $dev, array( 11 => self::about() ), $media )->run( 'staging', 11, self::TRANSFER );

		$this->assertNull( $result->error );
		$this->assertCount( 3, array_keys( $staging->served, MediaReceiver::CHUNK_ROUTE, true ) );
		$this->assertSame( $bytes, $staging->mediaFiles->files['2024/05/a.jpg'] );
		$this->assertSame( array(), $staging->mediaFiles->parts );
	}

	public function test_a_media_conflict_stops_the_push_before_anything_is_written(): void {
		[ $staging, $dev ] = $this->paired();
		$staging->mediaFiles->files['2024/05/a.jpg'] = 'a different picture';

		$result = $this->runner( $dev, array( 11 => self::about() ), self::mediaA() )->run( 'staging', 11, self::TRANSFER );

		$this->assertStringContainsString( 'A different file already exists at uploads/2024/05/a.jpg', (string) $result->error );
		$this->assertStringContainsString( 'Nothing else was changed', (string) $result->error );
		$this->assertNotContains( ImportResponder::ROUTE, $staging->served );
	}

	public function test_receiver_accepts_retries_and_refuses_bad_uploads(): void {
		$files    = new MemoryMediaFiles();
		$map      = new \RedC\Sceneshifter\SyncMap\MemoryMapStore();
		$receiver = new MediaReceiver( $map, $files, static fn(): int => 5 );
		$chunk    = static fn( int $offset, string $data ): array => array(
			'upload' => self::UPLOAD,
			'offset' => $offset,
			'data'   => base64_encode( $data ),
		);

		$this->assertSame( array( 'size' => 3 ), $receiver->chunk( 'dev', $chunk( 0, 'abc' ) ) );
		$this->assertSame( array( 'size' => 3 ), $receiver->chunk( 'dev', $chunk( 0, 'abc' ) ), 'A retried chunk is accepted.' );
		$this->assertSame( MediaReceiver::OFFSET, $receiver->chunk( 'dev', $chunk( 10, 'zz' ) )['error'] ?? null );
		$this->assertSame( MediaReceiver::INVALID, $receiver->chunk( 'Dev Site', $chunk( 0, 'abc' ) )['error'] ?? null );
		$this->assertSame( 0, $files->partSize( 'staging-' . self::UPLOAD ), 'Uploads are filed per sender.' );

		$commit = static fn( string $file, string $hash ): array => array(
			'upload' => self::UPLOAD,
			'source' => 5,
			'file'   => $file,
			'size'   => 3,
			'sha256' => $hash,
			'uuid'   => self::UUID_B,
		);
		$this->assertSame( MediaReceiver::INVALID, $receiver->commit( 'dev', $commit( '../../wp-config.php', hash( 'sha256', 'abc' ) ) )['error'] ?? null );
		$this->assertSame( MediaReceiver::INVALID, $receiver->commit( 'dev', $commit( '/etc/passwd', hash( 'sha256', 'abc' ) ) )['error'] ?? null );
		$this->assertSame( MediaReceiver::INCOMPLETE, $receiver->commit( 'dev', $commit( '2024/05/a.jpg', hash( 'sha256', 'abd' ) ) )['error'] ?? null );

		$receiver->chunk( 'dev', $chunk( 0, 'abc' ) );
		$this->assertSame( MediaFiles::TYPE, $receiver->commit( 'dev', $commit( '2024/05/a.php', hash( 'sha256', 'abc' ) ) )['error'] ?? null );

		$receiver->chunk( 'dev', $chunk( 0, 'abc' ) );
		$placed = $receiver->commit( 'dev', $commit( '2024/05/a.jpg', hash( 'sha256', 'abc' ) ) );
		$this->assertSame( 700, $placed['id'] ?? null );
		$this->assertFalse( $placed['reused'] ?? true );
		$this->assertSame( self::UUID_B, $files->attachments[700]['meta']['uuid'] );

		$again = $receiver->commit( 'dev', $commit( '2024/05/a.jpg', hash( 'sha256', 'abc' ) ) );
		$this->assertSame( array( 'id' => 700, 'file' => '2024/05/a.jpg', 'reused' => true ), $again, 'A file copied before is reused.' );
	}

	public function test_import_refuses_stale_or_moved_targets(): void {
		[ $staging ] = $this->paired();
		$this->existingAbout( $staging, 'publish' );
		$payload = array(
			'format'   => '1',
			'transfer' => self::TRANSFER,
			'package'  => self::about()->toArray(),
		);

		$this->assertSame( PreflightResponder::FORMAT_MISMATCH, $staging->importer()->import( 'dev', array( 'format' => '2' ) + $payload )['error'] ?? null );
		$this->assertSame( ImportResponder::INVALID, $staging->importer()->import( 'dev', array( 'transfer' => 'x' ) + $payload )['error'] ?? null );
		$this->assertSame( ImportResponder::MOVED, $staging->importer()->import( 'dev', $payload + array( 'target' => null ) )['error'] ?? null );
		$this->assertSame( ImportResponder::CHANGED, $staging->importer()->import( 'dev', $payload + array( 'target' => 201, 'expect_hash' => 'stale' ) )['error'] ?? null );
		$this->assertSame( array(), $staging->writer->writes );
		$this->assertSame( array(), $staging->snapshots->snapshots );

		$staging->facts->postTypes = array( 'post' );
		$this->assertSame( PreflightResponder::POST_TYPE_MISSING, $staging->importer()->import( 'dev', $payload )['error'] ?? null );
	}

	public function test_arrival_status(): void {
		$package = self::about();
		$this->assertSame( 'draft', ImportResponder::status( array(), $package, null ) );
		$this->assertSame( 'publish', ImportResponder::status( array( 'new_status' => 'source' ), $package, null ) );
		$this->assertSame( 'pending', ImportResponder::status( array( 'new_status' => 'pending' ), $package, null ) );
		$this->assertSame( 'draft', ImportResponder::status( array( 'new_status' => 'trash' ), $package, null ) );
		$this->assertSame( 'private', ImportResponder::status( array(), $package, 'private' ) );
		$this->assertSame( 'publish', ImportResponder::status( array( 'existing_status' => 'source' ), $package, 'draft' ) );
		$trashed = $package->with( array( 'post_status' => 'trash' ) + $package->fields, $package->meta );
		$this->assertSame( 'draft', ImportResponder::status( array( 'new_status' => 'source' ), $trashed, null ) );
	}

	/**
	 * @param array<int, Package>       $packages
	 * @param array<int, list<Finding>> $findings
	 */
	private function runner( SisterHarness $dev, array $packages, MemoryMediaSource $media, array $findings = array() ): PushRunner {
		$packager = static fn( int $id ): ?Package => $packages[ $id ] ?? null;
		$preview  = new PushPreview(
			Fixtures::registry()->get( 'dev' ),
			Fixtures::registry(),
			$dev->sisters->transport(),
			'1',
			$packager,
			static fn( int $id ): array => $findings[ $id ] ?? array(),
			static fn( string $content ): ?array => null,
			'json_encode'
		);
		return new PushRunner(
			Fixtures::registry(),
			$dev->sisters->transport(),
			'1',
			$preview,
			$packager,
			static fn( int $id ): ?string => $packages[ $id ]->uuid ?? null,
			$dev->map,
			$media,
			static fn(): int => 1700000000
		);
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

	private function existingAbout( SisterHarness $staging, string $status ): void {
		$staging->lookup->post( 201, 'page', 'about', self::UUID_A );
		$staging->lookup->media( 701, '2024/05/a.jpg', 3 );
		$staging->facts->packages[201] = self::package( 201, array( 'post_title' => 'Old title' ) );
		$staging->facts->statuses[201] = $status;
		$staging->map->save( new MapEntry( self::UUID_A, MapEntry::POST, 201, 'dev', 11, 1 ) );
	}

	private static function mediaA(): MemoryMediaSource {
		return ( new MemoryMediaSource() )->add( 5, '2024/05/a.jpg', 'abc', self::UUID_B );
	}

	private static function about(): Package {
		return self::package(
			11,
			array(
				'post_content' => '<p>Hi</p><img class="wp-image-5"><img class="wp-image-7">',
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
			'about',
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
