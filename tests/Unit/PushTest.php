<?php

declare(strict_types=1);

namespace RedC\Sceneshifter\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedC\Sceneshifter\Log\LogEntry;
use RedC\Sceneshifter\Scanner\Finding;
use RedC\Sceneshifter\SyncMap\MapEntry;
use RedC\Sceneshifter\Transfer\ContentHash;
use RedC\Sceneshifter\Transfer\HeldUpdates;
use RedC\Sceneshifter\Transfer\ImportResponder;
use RedC\Sceneshifter\Transfer\MediaFiles;
use RedC\Sceneshifter\Transfer\MediaReceiver;
use RedC\Sceneshifter\Transfer\Package;
use RedC\Sceneshifter\Transfer\PreflightResponder;
use RedC\Sceneshifter\Transfer\PushPreview;
use RedC\Sceneshifter\Transfer\PushRunner;
use RedC\Sceneshifter\Transfer\Rollback;

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
		$this->assertSame( self::TRANSFER . '/11', $staging->mediaFiles->attachments[700]['meta']['copied'], 'The copy remembers which push made it.' );
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

	public function test_both_sites_log_the_push_and_a_failure(): void {
		[ $staging, $dev ] = $this->paired();
		$this->runner( $dev, array( 11 => self::about() ), self::mediaA() )->run( 'staging', 11, self::TRANSFER, array(), null, 'Ann' );

		$out = $dev->log->get( 1 );
		$in  = $staging->log->get( 1 );
		$this->assertSame( array( LogEntry::OUT, 'staging', 'Ann', 11, 900, LogEntry::DONE, true ), array( $out?->direction, $out?->sister, $out?->user, $out?->sourceId, $out?->targetId, $out?->result, $out?->created ) );
		$this->assertSame( array( LogEntry::IN, 'dev', 'Ann', 11, 900, LogEntry::DONE, true ), array( $in?->direction, $in?->sister, $in?->user, $in?->sourceId, $in?->targetId, $in?->result, $in?->created ) );
		$this->assertSame( self::TRANSFER, $in?->transfer );

		$staging->writer->fail = 'Disk full';
		$this->runner( $dev, array( 11 => self::about() ), self::mediaA() )->run( 'staging', 11, self::TRANSFER, array(), false, 'Ann' );
		$this->assertSame( LogEntry::FAILED, $dev->log->get( 2 )?->result );
		$this->assertSame( LogEntry::FAILED, $staging->log->get( 2 )?->result );
		$this->assertSame( 'Disk full', $staging->log->get( 2 )?->message );
	}

	public function test_undoing_a_new_post_bins_it_and_forgets_the_link(): void {
		[ $staging, $dev ] = $this->paired();
		$runner = $this->runner( $dev, array( 11 => self::about() ), self::mediaA() );
		$runner->run( 'staging', 11, self::TRANSFER, array(), null, 'Ann' );

		$undo = $runner->undo( $dev->log->get( 1 ), false, 'Bo' );

		$this->assertTrue( $undo['ok'] );
		$this->assertSame( array( 900 ), $staging->writer->discarded );
		$this->assertNull( $staging->map->byRemote( 'dev', MapEntry::POST, 11 ) );
		$this->assertNull( $dev->map->get( 'staging', MapEntry::POST, 11 ) );
		$this->assertSame( array( 700 ), $staging->mediaFiles->deleted, 'Media the push copied, which nothing else uses, is deleted.' );
		$this->assertSame( 1, $undo['removed'] ?? null );
		$this->assertNull( $staging->map->byRemote( 'dev', MapEntry::POST, 5 ) );
		$this->assertNull( $dev->map->get( 'staging', MapEntry::POST, 5 ), 'Both sites forget the deleted copy.' );
		$this->assertSame( array( LogEntry::UNDONE, 'Bo' ), array( $dev->log->get( 1 )?->result, $dev->log->get( 1 )?->message ) );
		$this->assertSame( LogEntry::UNDONE, $staging->log->get( 1 )?->result );
		$this->assertSame( Rollback::ALREADY, $staging->rollback()->undo( 'dev', self::TRANSFER, 900, false, 'Bo' )['error'] ?? null );
		$this->assertSame( Rollback::NOT_FOUND, $staging->rollback()->undo( 'live', self::TRANSFER, 900, false, 'Bo' )['error'] ?? null );
	}

	public function test_undoing_an_update_restores_the_post_and_its_previous_link(): void {
		[ $staging, $dev ] = $this->paired();
		$this->existingAbout( $staging, 'publish' );
		$runner = $this->runner( $dev, array( 11 => self::about() ), self::mediaA() );
		$runner->run( 'staging', 11, self::TRANSFER, array(), 201, 'Ann' );
		$this->assertSame( 'About us', $staging->facts->packages[201]->title() );

		$undo = $runner->undo( $dev->log->get( 1 ), false, 'Bo' );

		$this->assertTrue( $undo['ok'] );
		$this->assertSame( 'Old title', $staging->facts->packages[201]->title() );
		$entry = $staging->map->get( 'dev', MapEntry::POST, 201 );
		$this->assertSame( array( 11, 1, null ), array( $entry?->remoteId, $entry?->linkedAt, $entry?->syncedAt ), 'The link is as it was before the push.' );
		$this->assertNotNull( $dev->map->get( 'staging', MapEntry::POST, 11 ), 'The post still exists there, so this site keeps its link.' );
	}

	public function test_undo_asks_first_when_the_post_was_edited_since(): void {
		[ $staging, $dev ] = $this->paired();
		$this->existingAbout( $staging, 'publish' );
		$runner = $this->runner( $dev, array( 11 => self::about() ), self::mediaA() );
		$runner->run( 'staging', 11, self::TRANSFER, array(), 201, 'Ann' );
		$staging->facts->packages[201] = self::package( 201, array( 'post_title' => 'Edited there later' ) );

		$first = $runner->undo( $dev->log->get( 1 ), false, 'Bo' );
		$this->assertFalse( $first['ok'] );
		$this->assertSame( Rollback::CHANGED_SINCE, $first['code'] );
		$this->assertSame( LogEntry::DONE, $dev->log->get( 1 )?->result );

		$this->assertTrue( $runner->undo( $dev->log->get( 1 ), true, 'Bo' )['ok'] );
		$this->assertSame( 'Old title', $staging->facts->packages[201]->title() );
	}

	public function test_undo_keeps_copied_media_something_else_uses(): void {
		[ $staging, $dev ] = $this->paired();
		$runner = $this->runner( $dev, array( 11 => self::about() ), self::mediaA() );
		$runner->run( 'staging', 11, self::TRANSFER, array(), null );
		$staging->mediaFiles->used = array( 700 );

		$this->assertSame( 0, $runner->undo( $dev->log->get( 1 ), false, 'Bo' )['removed'] ?? null );
		$this->assertSame( array(), $staging->mediaFiles->deleted );
		$this->assertNotNull( $staging->map->byRemote( 'dev', MapEntry::POST, 5 ) );
		$this->assertNotNull( $dev->map->get( 'staging', MapEntry::POST, 5 ) );
	}

	public function test_a_clashing_file_is_renamed_when_the_target_allows_it(): void {
		[ $staging, $dev ] = $this->paired();
		$staging->mediaFiles->files['2024/05/a.jpg'] = 'a different picture';
		$staging->mediaFiles->rename                 = true;
		$about = self::about();
		$about = $about->with( array( 'post_content' => '<img src="http://client.test:8080/app/uploads/2024/05/a-300x200.jpg" class="wp-image-5">' ) + $about->fields, $about->meta );

		$result = $this->runner( $dev, array( 11 => $about ), self::mediaA() )->run( 'staging', 11, self::TRANSFER );

		$this->assertNull( $result->error );
		$this->assertSame( 'abc', $staging->mediaFiles->files['2024/05/a-1.jpg'] );
		$this->assertSame( 'a different picture', $staging->mediaFiles->files['2024/05/a.jpg'], 'The file already there is left alone.' );
		$this->assertSame( array( array( 'from' => '2024/05/a.jpg', 'to' => '2024/05/a-1.jpg' ) ), $result->renamed );
		$this->assertSame( '<img src="https://staging.client.co.uk/app/uploads/2024/05/a-1-300x200.jpg" class="wp-image-700">', $staging->writer->writes[0]['package']->fields['post_content'] );
	}

	public function test_later_pushes_follow_a_file_renamed_earlier(): void {
		[ $staging, $dev ] = $this->paired();
		$staging->lookup->media( 701, '2024/05/a-1.jpg', 3 );
		$staging->map->save( new MapEntry( self::UUID_B, MapEntry::POST, 701, 'dev', 5, 1 ) );
		$about = self::about();
		$about = $about->with( array( 'post_content' => '<img src="http://client.test:8080/app/uploads/2024/05/a.jpg" class="wp-image-5">' ) + $about->fields, $about->meta );

		$result = $this->runner( $dev, array( 11 => $about ), self::mediaA() )->run( 'staging', 11, self::TRANSFER );

		$this->assertSame( 0, $result->mediaCopied );
		$this->assertSame( '<img src="https://staging.client.co.uk/app/uploads/2024/05/a-1.jpg" class="wp-image-701">', $staging->writer->writes[0]['package']->fields['post_content'] );
	}

	public function test_overwriting_a_post_that_was_never_linked_needs_agreement(): void {
		[ $staging, $dev ] = $this->paired();
		$staging->lookup->post( 201, 'page', 'about' );
		$staging->facts->packages[201] = self::package( 201, array( 'post_title' => 'Their about page' ) );
		$runner = $this->runner( $dev, array( 11 => self::about() ), self::mediaA() );

		$refused = $runner->run( 'staging', 11, self::TRANSFER, array(), 201 );
		$this->assertStringContainsString( 'never linked to this one', (string) $refused->error );
		$this->assertSame( array(), $staging->writer->writes );
		$this->assertNotContains( MediaReceiver::CHUNK_ROUTE, $staging->served, 'Nothing is copied either.' );

		$agreed = $runner->run( 'staging', 11, self::TRANSFER, array( 'overwrite_unlinked' => true ), 201 );
		$this->assertNull( $agreed->error );
		$this->assertSame( 201, $agreed->targetId );
		$this->assertSame( 'About us', $staging->facts->packages[201]->title() );
		$this->assertSame( 201, $staging->map->byRemote( 'dev', MapEntry::POST, 11 )?->localId, 'The two are linked from now on.' );
	}

	public function test_a_site_that_holds_updates_keeps_the_published_post_until_applied(): void {
		[ $staging, $dev ] = $this->paired();
		$this->existingAbout( $staging, 'publish' );
		$staging->holdUpdates = true;
		$runner = $this->runner( $dev, array( 11 => self::about() ), self::mediaA() );

		$result = $runner->run( 'staging', 11, self::TRANSFER, array( 'existing_status' => 'keep' ), 201, 'Ann' );

		$this->assertNull( $result->error );
		$this->assertTrue( $result->isHeld() );
		$this->assertSame( 900, $result->heldId );
		$this->assertSame( 201, $result->targetId );
		$this->assertSame( 'Old title', $staging->facts->packages[201]->title(), 'The published post is unchanged.' );
		$this->assertSame( 'About us', $staging->writer->heldCopies[900]['package']->title() );
		$this->assertNull( $staging->map->get( 'dev', MapEntry::POST, 201 )?->syncedAt, 'The sync map waits too.' );
		$this->assertSame( array( LogEntry::HELD, 900 ), array( $staging->log->get( 1 )?->result, $staging->log->get( 1 )?->details['held'] ?? null ) );
		$this->assertSame( 'held', $dev->log->get( 1 )?->details['status'] ?? null );
		$this->assertSame( 900, $staging->held()->heldFor( 201 ) );

		$applied = $staging->held()->apply( 900, 'Cy' );

		$this->assertSame( array( 'post' => 201 ), $applied );
		$this->assertSame( 'About us', $staging->facts->packages[201]->title() );
		$this->assertSame( 'publish', $staging->facts->statuses[201] );
		$this->assertArrayNotHasKey( 900, $staging->writer->heldCopies );
		$this->assertSame( 1700000100, $staging->map->get( 'dev', MapEntry::POST, 201 )?->syncedAt );
		$entry = $staging->log->get( 1 );
		$this->assertSame( array( LogEntry::DONE, 'Cy' ), array( $entry?->result, $entry?->details['applied_by'] ?? null ) );
		$this->assertNotNull( $entry?->details['hash'] ?? null );

		$this->assertTrue( $runner->undo( $dev->log->get( 1 ), false, 'Bo' )['ok'], 'An applied update can still be undone.' );
		$this->assertSame( 'Old title', $staging->facts->packages[201]->title() );
	}

	public function test_undoing_or_discarding_a_held_update_removes_the_copy(): void {
		[ $staging, $dev ] = $this->paired();
		$this->existingAbout( $staging, 'publish' );
		$staging->holdUpdates = true;
		$runner = $this->runner( $dev, array( 11 => self::about() ), self::mediaA() );
		$runner->run( 'staging', 11, self::TRANSFER, array(), 201 );

		$this->assertTrue( $runner->undo( $dev->log->get( 1 ), false, 'Bo' )['ok'] );
		$this->assertSame( array(), $staging->writer->heldCopies );
		$this->assertSame( LogEntry::UNDONE, $staging->log->get( 1 )?->result );
		$this->assertSame( 'Old title', $staging->facts->packages[201]->title() );

		$second = '55555555-5555-4555-8555-555555555555';
		$result = $runner->run( 'staging', 11, $second, array(), 201 );
		$this->assertTrue( $result->isHeld() );
		$this->assertSame( array( 'post' => 201 ), $staging->held()->discard( (int) $result->heldId, 'Cy' ) );
		$this->assertSame( array(), $staging->writer->heldCopies );
		$this->assertSame( array( LogEntry::UNDONE, 'Cy' ), array( $staging->log->get( 2 )?->result, $staging->log->get( 2 )?->message ) );
		$this->assertSame( HeldUpdates::NOT_FOUND, $staging->held()->apply( (int) $result->heldId, 'Cy' )['error'] ?? null );
	}

	public function test_a_newer_push_replaces_the_waiting_copy_and_drafts_are_not_held(): void {
		[ $staging, $dev ] = $this->paired();
		$this->existingAbout( $staging, 'publish' );
		$staging->holdUpdates = true;
		$runner = $this->runner( $dev, array( 11 => self::about() ), self::mediaA() );
		$first  = $runner->run( 'staging', 11, self::TRANSFER, array(), 201 );
		$second = $runner->run( 'staging', 11, '55555555-5555-4555-8555-555555555555', array(), 201 );

		$this->assertSame( $first->heldId, $second->heldId, 'The same copy is reused.' );
		$this->assertCount( 1, $staging->writer->heldCopies );
		$this->assertSame( array( LogEntry::UNDONE, LogEntry::HELD ), array( $staging->log->get( 1 )?->result, $staging->log->get( 2 )?->result ) );

		$staging->facts->statuses[201] = 'draft';
		$draft = $runner->run( 'staging', 11, '66666666-6666-4666-8666-666666666666', array(), 201 );
		$this->assertFalse( $draft->isHeld(), 'Only posts visitors can see are held.' );
		$this->assertSame( 'About us', $staging->facts->packages[201]->title() );
	}

	public function test_old_log_entries_and_snapshots_are_pruned(): void {
		$day       = 86400;
		$now       = 400 * $day;
		$log       = new \RedC\Sceneshifter\Log\MemoryLogStore();
		$snapshots = new \RedC\Sceneshifter\Transfer\MemorySnapshotStore();
		foreach ( array( 10, 200, 399 ) as $age ) {
			$log->add( new LogEntry( 0, self::TRANSFER, LogEntry::IN, 'dev', '', 11, 201, 'A', LogEntry::DONE, false, null, array(), $now - $age * $day ) );
		}
		// Post 201: four old snapshots and one recent; post 202: one old one.
		foreach ( array( 90, 80, 70, 60, 1 ) as $age ) {
			$snapshots->save( new \RedC\Sceneshifter\Transfer\Snapshot( 0, self::TRANSFER, 'dev', 201, false, array(), $now - $age * $day ) );
		}
		$snapshots->save( new \RedC\Sceneshifter\Transfer\Snapshot( 0, self::TRANSFER, 'dev', 202, false, array(), $now - 90 * $day ) );

		$removed = \RedC\Sceneshifter\Log\Retention::prune( $log, $snapshots, new \RedC\Sceneshifter\Store\PushSettings( logMonths: 12, snapshotDays: 30, snapshotsPerPost: 2 ), $now );

		$this->assertSame( array( 'log' => 1, 'snapshots' => 3 ), $removed );
		$this->assertSame( array( 1, 2 ), array_keys( $log->entries ), 'Only the entry older than 12 months goes.' );
		$this->assertSame( array( 4, 5, 6 ), array_keys( $snapshots->snapshots ), 'Each post keeps its newest two, however old.' );

		$forever = \RedC\Sceneshifter\Log\Retention::prune( $log, $snapshots, new \RedC\Sceneshifter\Store\PushSettings( logMonths: 0 ), $now + 9999 * $day );
		$this->assertSame( 0, $forever['log'], '0 months keeps the log for ever.' );
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
			$dev->log,
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
