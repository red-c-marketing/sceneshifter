<?php

declare(strict_types=1);

namespace RedC\Sceneshifter\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedC\Sceneshifter\SyncMap\Item;
use RedC\Sceneshifter\SyncMap\LinkApplier;
use RedC\Sceneshifter\SyncMap\LinkExisting;
use RedC\Sceneshifter\SyncMap\MapEntry;
use RedC\Sceneshifter\SyncMap\Matcher;
use RedC\Sceneshifter\SyncMap\MatchResult;
use RedC\Sceneshifter\SyncMap\MemoryMapStore;
use RedC\Sceneshifter\SyncMap\Uuid;

final class SyncMapTest extends TestCase {

	private const STAGING_SALTS = 'staging-auth-key|staging-secure-auth-key|staging-logged-in-key';
	private const DEV_SALTS     = 'dev-auth-key|dev-secure-auth-key|dev-logged-in-key|dev-nonce';

	private const UUID_A = '11111111-1111-4111-8111-111111111111';
	private const UUID_B = '22222222-2222-4222-8222-222222222222';

	protected function setUp(): void {
		SisterHarness::$network = array();
	}

	// Matcher.

	public function test_generated_uuids_are_valid_version_4(): void {
		$this->assertTrue( Uuid::isValid( Uuid::generate() ) );
		$this->assertFalse( Uuid::isValid( 'not-a-uuid' ) );
		$this->assertFalse( Uuid::isValid( 'AAAAAAAA-1111-4111-8111-111111111111' ) );
	}

	public function test_matches_pages_by_path_and_keeps_one_to_one(): void {
		$result = Matcher::match(
			'page',
			array( self::item( 1, 'about' ), self::item( 2, 'about/team' ), self::item( 3, 'contact' ), self::item( 4, '' ) ),
			array( self::item( 11, 'about' ), self::item( 12, 'about/team' ), self::item( 13, 'news' ) ),
			array()
		);

		$this->assertSame( array( array( 1, 11, Matcher::PATH ), array( 2, 12, Matcher::PATH ) ), self::pairs( $result ) );
		$this->assertSame( array( 3, 4 ), self::ids( $result->onlyHere ) );
		$this->assertSame( array( 13 ), self::ids( $result->onlyThere ) );
	}

	public function test_same_uuid_wins_over_path(): void {
		$result = Matcher::match(
			'page',
			array( self::item( 1, 'old-address', self::UUID_A ), self::item( 2, 'about' ) ),
			array( self::item( 11, 'about' ), self::item( 12, 'new-address', self::UUID_A ) ),
			array()
		);

		$this->assertSame( array( array( 1, 12, Matcher::UUID ), array( 2, 11, Matcher::PATH ) ), self::pairs( $result ) );
	}

	public function test_already_linked_items_are_counted_not_offered(): void {
		$result = Matcher::match(
			'page',
			array( self::item( 1, 'about' ), self::item( 2, 'contact' ) ),
			array( self::item( 11, 'about' ), self::item( 12, 'contact' ) ),
			array( 1 => 11 )
		);

		$this->assertSame( 1, $result->linked );
		$this->assertSame( array( array( 2, 12, Matcher::PATH ) ), self::pairs( $result ) );
		$this->assertSame( array(), $result->onlyThere );
	}

	public function test_several_candidates_are_set_aside(): void {
		$result = Matcher::match(
			'post',
			array( self::item( 1, 'hello', null, 'post' ), self::item( 2, 'hello', null, 'post' ) ),
			array( self::item( 11, 'hello', null, 'post' ) ),
			array()
		);

		$this->assertSame( array(), $result->proposals );
		$this->assertCount( 2, $result->attention );
		$this->assertSame( Matcher::AMBIGUOUS, $result->attention[0]['reason'] );
		$this->assertSame( array(), $result->onlyThere, 'A candidate in an ambiguous match is not also listed as unmatched.' );
	}

	public function test_different_uuids_on_matching_paths_need_a_decision(): void {
		$result = Matcher::match( 'page', array( self::item( 1, 'about', self::UUID_A ) ), array( self::item( 11, 'about', self::UUID_B ) ), array() );

		$this->assertSame( array(), $result->proposals );
		$this->assertSame( Matcher::DIFFERENT_IDS, $result->attention[0]['reason'] );
	}

	public function test_a_copied_uuid_is_not_trusted(): void {
		$dupe   = new Item( 1, 'page', 'about-copy', null, self::UUID_A, 'Copy', 'draft', true );
		$result = Matcher::match( 'page', array( $dupe ), array( self::item( 11, 'about', self::UUID_A ) ), array() );

		$this->assertSame( array(), $result->proposals );
		$this->assertSame( array( 1 ), self::ids( $result->onlyHere ) );

		$result = Matcher::match( 'page', array( new Item( 1, 'page', 'about', null, self::UUID_A, 'About', 'publish', true ) ), array( self::item( 11, 'about' ) ), array() );
		$this->assertSame( Matcher::DUPLICATE_ID, $result->attention[0]['reason'] );
	}

	public function test_matches_media_by_file_then_by_name_and_size(): void {
		$result = Matcher::match(
			'attachment',
			array(
				self::media( 1, '2024/05/logo.png', 1000 ),
				self::media( 2, '2024/05/hero.jpg', 2000 ),
				self::media( 3, '2024/05/team.jpg', 3000 ),
				self::media( 4, '2024/05/map.pdf', 4000 ),
			),
			array(
				self::media( 11, '2024/05/logo.png', 1000 ),
				self::media( 12, '2025/01/hero.jpg', 2000 ),
				self::media( 13, '2024/05/team.jpg', 3999 ),
				self::media( 14, '2024/05/map.pdf', null ),
			),
			array()
		);

		$this->assertSame(
			array( array( 1, 11, Matcher::FILE ), array( 4, 14, Matcher::FILE ), array( 2, 12, Matcher::NAME_SIZE ) ),
			self::pairs( $result ),
			'Same file with the same or an unknown size, then same name and size in another folder. A different size never matches.'
		);
		$this->assertSame( array( 3 ), self::ids( $result->onlyHere ) );
	}

	// LinkApplier.

	public function test_applier_refuses_links_that_would_break_existing_ones(): void {
		$index = ( new MemoryContentIndex() )->add( 1, 'page', 'about' )->add( 2, 'page', 'contact', self::UUID_B )->add( 3, 'page', 'team', self::UUID_A );
		$map   = new MemoryMapStore();
		$map->save( new MapEntry( self::UUID_A, MapEntry::POST, 3, 'staging', 33, 1 ) );
		$apply = new LinkApplier( $index, $map );

		$results = $apply->apply(
			'staging',
			array(
				array( 'local' => 1, 'remote' => 11, 'type' => 'page', 'uuid' => self::UUID_A ),
				array( 'local' => 2, 'remote' => 12, 'type' => 'page', 'uuid' => self::UUID_A ),
				array( 'local' => 1, 'remote' => 33, 'type' => 'page', 'uuid' => Uuid::generate() ),
				array( 'local' => 9, 'remote' => 19, 'type' => 'page', 'uuid' => Uuid::generate() ),
				array( 'local' => 3, 'remote' => 33, 'type' => 'post', 'uuid' => self::UUID_A ),
				array( 'local' => 3, 'remote' => 33, 'type' => 'page', 'uuid' => 'nope' ),
			),
			100
		);

		$this->assertSame(
			array( LinkApplier::ID_IN_USE, LinkApplier::DIFFERENT_ID, LinkApplier::ALREADY_LINKED, LinkApplier::MISSING, LinkApplier::TYPE_MISMATCH, LinkApplier::INVALID ),
			$results
		);
		$this->assertNull( $index->get( 1 )->uuid, 'Nothing is written for a refused link.' );
	}

	public function test_applier_links_and_is_safe_to_repeat(): void {
		$index = ( new MemoryContentIndex() )->add( 1, 'page', 'about' );
		$map   = new MemoryMapStore();
		$apply = new LinkApplier( $index, $map );
		$link  = array( 'local' => 1, 'remote' => 11, 'type' => 'page', 'uuid' => self::UUID_A );

		$this->assertSame( array( LinkApplier::OK ), $apply->apply( 'staging', array( $link ), 100 ) );
		$this->assertSame( array( LinkApplier::OK ), $apply->apply( 'staging', array( $link ), 200 ) );
		$this->assertSame( self::UUID_A, $index->get( 1 )->uuid );
		$this->assertSame( array( 1 => 11 ), $map->linked( 'staging', MapEntry::POST ) );

		$other = array( 'local' => 1, 'remote' => 12, 'type' => 'page', 'uuid' => self::UUID_A );
		$this->assertSame( array( LinkApplier::ALREADY_LINKED ), $apply->apply( 'staging', array( $other ), 300 ) );
		$this->assertSame( array( LinkApplier::OK ), $apply->apply( 'live', array( $other ), 300 ), 'The same item can be linked to each sister.' );
	}

	// Link existing content, end to end.

	public function test_dev_links_existing_content_with_staging(): void {
		[ $staging, $dev ] = $this->pairedDevAndStaging();

		$staging->content->add( 101, 'page', 'about' )->add( 102, 'page', 'about/team', self::UUID_A )->add( 103, 'page', 'news' );
		$dev->content->add( 1, 'page', 'about' )->add( 2, 'page', 'about/team' )->add( 3, 'page', 'contact' );
		for ( $i = 1; $i <= 260; $i++ ) {
			$staging->content->add( 1000 + $i, 'attachment', "2024/05/file-{$i}.jpg", null, $i );
			$dev->content->add( 5000 + $i, 'attachment', "2024/05/file-{$i}.jpg", null, $i );
		}

		$linker = $dev->linker();
		$linker->start( 'staging', 100 );
		$steps = 0;
		do {
			$step = $linker->step( 'staging', 100 );
			$this->assertNull( $step['error'] );
			++$steps;
		} while ( ! $step['done'] && $steps < 10 );

		$this->assertSame( 4, $steps, 'One page each of pages and posts, two pages of media.' );
		$this->assertCount( 260, $dev->linker()->cache()->items( 'staging', 'attachment' ) );

		$review = (array) $linker->review( 'staging' );
		$byType = array();
		foreach ( $review as $result ) {
			$byType[ $result->type ] = $result;
		}
		$this->assertSame( array( array( 1, 101, Matcher::PATH ), array( 2, 102, Matcher::PATH ) ), self::pairs( $byType['page'] ) );
		$this->assertCount( 260, $byType['attachment']->proposals );

		$pairs  = LinkExisting::proposedPairs( $review );
		$linked = 0;
		foreach ( array_chunk( $pairs, LinkExisting::LINK_BATCH ) as $batch ) {
			$result = $linker->link( 'staging', $batch, 200 );
			$this->assertNull( $result['error'] );
			$this->assertSame( array(), $result['failed'] );
			$linked += $result['linked'];
		}
		$this->assertSame( 262, $linked );

		// Both sites agree, and the UUID staging already had was kept.
		$this->assertSame( self::UUID_A, $dev->content->get( 2 )->uuid );
		$this->assertSame( $staging->content->get( 101 )->uuid, $dev->content->get( 1 )->uuid );
		$this->assertTrue( Uuid::isValid( $dev->content->get( 1 )->uuid ) );
		$this->assertSame( 101, $dev->map->get( 'staging', MapEntry::POST, 1 )->remoteId );
		$this->assertSame( 1, $staging->map->get( 'dev', MapEntry::POST, 101 )->remoteId );
		$this->assertSame( 262, $staging->map->count( 'dev' ) );

		// A second run offers nothing new.
		$linker->start( 'staging', 300 );
		do {
			$step = $linker->step( 'staging', 300 );
		} while ( ! $step['done'] );
		$again = (array) $linker->review( 'staging' );
		$this->assertSame( array(), LinkExisting::proposedPairs( $again ) );
		$this->assertSame( 2, $again[0]->linked );
	}

	public function test_nothing_is_recorded_here_when_staging_refuses(): void {
		[ $staging, $dev ] = $this->pairedDevAndStaging();
		$staging->content->add( 101, 'page', 'about' );
		$dev->content->add( 1, 'page', 'about' );

		$linker = $dev->linker();
		$linker->start( 'staging', 100 );
		do {
			$step = $linker->step( 'staging', 100 );
		} while ( ! $step['done'] );

		// After the fetch, someone links staging's page to something else.
		$staging->content->setUuid( 101, self::UUID_B );
		$staging->map->save( new MapEntry( self::UUID_B, MapEntry::POST, 101, 'dev', 77, 150 ) );

		$result = $linker->link( 'staging', array( array( 1, 101 ) ), 200 );
		$this->assertSame( 0, $result['linked'] );
		$this->assertCount( 1, $result['failed'] );
		$this->assertStringContainsString( 'Staging', $result['failed'][0]['message'] );
		$this->assertNull( $dev->content->get( 1 )->uuid );
		$this->assertNull( $dev->map->get( 'staging', MapEntry::POST, 1 ) );
	}

	public function test_a_type_the_sister_does_not_enable_is_skipped(): void {
		[ $staging, $dev ] = $this->pairedDevAndStaging();
		$staging->content = new MemoryContentIndex( array( 'page', 'attachment' ) );

		$linker = $dev->linker();
		$linker->start( 'staging', 100 );
		do {
			$step = $linker->step( 'staging', 100 );
		} while ( ! $step['done'] );

		$this->assertSame( array( 'post' => 'type_not_enabled' ), $linker->cache()->state( 'staging' )['skipped'] );
		$this->assertSame( array( 'page', 'attachment' ), array_map( static fn( MatchResult $r ): string => $r->type, (array) $linker->review( 'staging' ) ) );
	}

	public function test_links_only_pairs_from_the_fetched_list(): void {
		[ $staging, $dev ] = $this->pairedDevAndStaging();
		$staging->content->add( 101, 'page', 'about' )->add( 102, 'post', 'hello' );
		$dev->content->add( 1, 'page', 'about' );

		$linker = $dev->linker();
		$linker->start( 'staging', 100 );
		do {
			$step = $linker->step( 'staging', 100 );
		} while ( ! $step['done'] );

		$result = $linker->link( 'staging', array( array( 1, 102 ), array( 1, 999 ), array( 9, 101 ) ), 200 );
		$this->assertSame( 0, $result['linked'] );
		$this->assertCount( 3, $result['failed'] );
		$this->assertSame( array(), array_filter( $dev->sent, static fn( array $r ): bool => str_contains( $r['url'], '/map/link' ) ), 'Nothing invalid is sent.' );
	}

	/**
	 * @return array{0: SisterHarness, 1: SisterHarness}
	 */
	private function pairedDevAndStaging(): array {
		$staging = new SisterHarness( 'staging', self::STAGING_SALTS );
		$dev     = new SisterHarness( 'dev', self::DEV_SALTS );
		$dev->sisters->pair( $staging->sisters->createCode( 'dev' )->encode() );
		return array( $staging, $dev );
	}

	private static function item( int $id, string $key, ?string $uuid = null, string $type = 'page' ): Item {
		return new Item( $id, $type, $key, null, $uuid, "Item {$id}", 'publish' );
	}

	private static function media( int $id, string $file, ?int $size ): Item {
		return new Item( $id, 'attachment', $file, $size, null, "Media {$id}", 'inherit' );
	}

	/**
	 * @return list<array{0: int, 1: int, 2: string}>
	 */
	private static function pairs( MatchResult $result ): array {
		return array_map( static fn( array $p ): array => array( $p['local']->id, $p['remote']->id, $p['reason'] ), $result->proposals );
	}

	/**
	 * @param list<Item> $items
	 * @return list<int>
	 */
	private static function ids( array $items ): array {
		return array_map( static fn( Item $item ): int => $item->id, $items );
	}
}
