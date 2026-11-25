<?php

declare(strict_types=1);

namespace RedC\Sceneshifter\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedC\Sceneshifter\Rewrite\IdMap;
use RedC\Sceneshifter\Rewrite\Rewrite;
use RedC\Sceneshifter\Rewrite\UrlRewriter;
use RedC\Sceneshifter\Scanner\Finding;
use RedC\Sceneshifter\SyncMap\MapEntry;
use RedC\Sceneshifter\SyncMap\MemoryMapStore;
use RedC\Sceneshifter\Transfer\ContentHash;
use RedC\Sceneshifter\Transfer\Package;
use RedC\Sceneshifter\Transfer\PackageRewriter;
use RedC\Sceneshifter\Transfer\PreflightResponder;
use RedC\Sceneshifter\Transfer\PreviewItem;
use RedC\Sceneshifter\Transfer\PushPreview;
use RedC\Sceneshifter\Transfer\Resolver;

final class TransferTest extends TestCase {

	private const STAGING_SALTS = 'staging-auth-key|staging-secure-auth-key|staging-logged-in-key';
	private const DEV_SALTS     = 'dev-auth-key|dev-secure-auth-key|dev-logged-in-key|dev-nonce';
	private const UUID_A        = '11111111-1111-4111-8111-111111111111';

	protected function setUp(): void {
		SisterHarness::$network = array();
	}

	// PackageRewriter.

	public function test_rewrites_classic_content_acf_meta_and_fields(): void {
		$package = self::package(
			11,
			array(
				'post_content' => '<p><a href="http://client.test:8080/contact/">Contact</a></p><img class="wp-image-5" src="http://client.test:8080/app/uploads/a.jpg">[gallery ids="5, 6"][caption id="attachment_6" align="alignnone"]Hi[/caption]',
				'post_excerpt' => 'See http://client.test:8080/about/',
				'featured'     => 5,
				'post_parent'  => 7,
			),
			array(
				'hero'         => array( '5' ),
				'_hero'        => array( 'field_hero' ),
				'related'      => array( array( '7', '8' ) ),
				'_related'     => array( 'field_rel' ),
				'rows_0_doc'   => array( '6' ),
				'_rows_0_doc'  => array( 'field_doc' ),
				'rows_0_label' => array( '7' ),
				'link'         => array( 'http://client.test:8080/x/' ),
			),
			array(
				'field_hero' => 'image',
				'field_rel'  => 'relationship',
				'field_doc'  => 'file',
			)
		);

		$result = $this->rewriter( new IdMap( array( 5 => 50, 6 => 60 ), array( 7 => 70 ) ) )->rewrite( $package );
		$out    = $result['package'];

		$this->assertSame(
			'<p><a href="https://staging.client.co.uk/contact/">Contact</a></p><img class="wp-image-50" src="https://staging.client.co.uk/app/uploads/a.jpg">[gallery ids="50,60"][caption id="attachment_60" align="alignnone"]Hi[/caption]',
			$out->fields['post_content']
		);
		$this->assertSame( 'See https://staging.client.co.uk/about/', $out->fields['post_excerpt'] );
		$this->assertSame( 50, $out->fields['featured'] );
		$this->assertSame( 70, $out->fields['post_parent'] );
		$this->assertSame( array( '50' ), $out->meta['hero'] );
		$this->assertSame( array( array( '70', '8' ) ), $out->meta['related'] );
		$this->assertSame( array( '60' ), $out->meta['rows_0_doc'] );
		$this->assertSame( array( '7' ), $out->meta['rows_0_label'], 'A value with no ACF type keeps its number.' );
		$this->assertSame( array( 'https://staging.client.co.uk/x/' ), $out->meta['link'] );
		$this->assertCount( 1, $result['unresolved'] );
		$this->assertSame( array( Rewrite::POST, 8, 'Meta related' ), array( $result['unresolved'][0]->kind, $result['unresolved'][0]->id, $result['unresolved'][0]->location ) );
	}

	public function test_rewrites_blocks_including_acf_block_data(): void {
		$blocks  = array(
			array(
				'blockName'    => 'core/image',
				'attrs'        => array( 'id' => 5 ),
				'innerBlocks'  => array(),
				'innerHTML'    => '<img class="wp-image-5"/>',
				'innerContent' => array( '<img class="wp-image-5"/>' ),
			),
			array(
				'blockName'    => 'acf/hero',
				'attrs'        => array(
					'data' => array(
						'image'  => 6,
						'_image' => 'field_hero',
						'page'   => '7',
						'_page'  => 'field_a_field_page',
					),
				),
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			),
		);
		$package = self::package( 11, array( 'post_content' => 'blocks' ), array(), array( 'field_hero' => 'image', 'field_page' => 'post_object' ) );
		$parse   = static fn( string $content ): ?array => $blocks;

		$result = ( new PackageRewriter( self::urls(), new IdMap( array( 5 => 50, 6 => 60 ), array( 7 => 70 ) ), $parse, 'json_encode' ) )->rewrite( $package );
		$out    = json_decode( (string) $result['package']->fields['post_content'], true );

		$this->assertSame( 50, $out[0]['attrs']['id'] );
		$this->assertSame( '<img class="wp-image-50"/>', $out[0]['innerHTML'] );
		$this->assertSame( 60, $out[1]['attrs']['data']['image'] );
		$this->assertSame( '70', $out[1]['attrs']['data']['page'], 'A cloned field is typed by the field it clones.' );
	}

	public function test_lists_every_reference(): void {
		$package = self::package(
			11,
			array(
				'post_content' => '<img class="wp-image-5">[gallery ids="6"]',
				'post_parent'  => 7,
			),
			array(
				'tags'  => array( array( '3' ) ),
				'_tags' => array( 'field_tags' ),
			),
			array( 'field_tags' => 'taxonomy' )
		);

		$refs = $this->rewriter( new IdMap() )->references( $package );

		$this->assertSame( array( 5, 6 ), $refs[ Rewrite::ATTACHMENT ] );
		$this->assertSame( array( 7 ), $refs[ Rewrite::POST ] );
		$this->assertSame( array( 3 ), $refs[ Rewrite::TERM ] );
	}

	// ContentHash.

	public function test_hashes_ignore_key_order_and_meta_the_push_does_not_carry(): void {
		$a = self::package( 1, array(), array( 'b' => array( array( 'y' => 1, 'x' => 2 ) ), 'a' => array( '1' ) ) );
		$b = self::package( 2, array(), array( 'a' => array( '1' ), 'b' => array( array( 'x' => 2, 'y' => 1 ) ), 'extra' => array( 'only here' ) ) );

		$this->assertNotSame( ContentHash::parts( $a ), ContentHash::parts( $b ) );
		$this->assertSame( ContentHash::parts( $a, array( 'a', 'b' ) ), ContentHash::parts( $b, array( 'a', 'b' ) ) );

		$c = self::package( 3, array( 'post_title' => 'Other' ) );
		$this->assertSame( array( 'title', 'meta' ), ContentHash::changed( ContentHash::parts( $a ), ContentHash::parts( $c ) ) );
	}

	// Resolver.

	public function test_resolves_references_in_order_and_refuses_ambiguous_or_taken_matches(): void {
		$lookup = ( new MemoryTargetLookup() )
			->post( 201, 'page', 'about' )
			->post( 202, 'page', 'about/team', self::UUID_A )
			->post( 203, 'page', 'contact' )
			->post( 204, 'post', 'hello' )
			->post( 205, 'post', 'hello-again', null, 'dupe' )
			->post( 206, 'post', 'hello-there', null, 'dupe' )
			->media( 501, '2024/05/a.jpg', 100 )
			->media( 502, '2025/01/b.jpg', 200 )
			->media( 503, '2024/05/c.jpg', 999 );
		$lookup->terms['category:news'] = 9;
		$map = new MemoryMapStore();
		$map->save( new MapEntry( self::UUID_A, MapEntry::POST, 203, 'dev', 99, 1 ) );
		$map->save( new MapEntry( self::UUID_A, MapEntry::POST, 201, 'dev', 30, 1 ) );

		$resolved = ( new Resolver( 'dev', $map, $lookup ) )->resolve(
			array(
				'id'          => 11,
				'type'        => 'page',
				'path'        => 'contact',
				'posts'       => array(
					array( 'id' => 30, 'type' => 'page', 'path' => 'nowhere' ),
					array( 'id' => 31, 'type' => 'page', 'path' => 'renamed', 'uuid' => self::UUID_A ),
					array( 'id' => 32, 'type' => 'post', 'path' => 'x', 'slug' => 'hello' ),
					array( 'id' => 33, 'type' => 'post', 'path' => 'x', 'slug' => 'dupe' ),
					array( 'id' => 34, 'missing' => true ),
				),
				'attachments' => array(
					array( 'id' => 5, 'file' => '2024/05/a.jpg', 'size' => 100 ),
					array( 'id' => 6, 'file' => '2024/05/b.jpg', 'size' => 200 ),
					array( 'id' => 7, 'file' => '2024/05/c.jpg', 'size' => 100 ),
				),
				'terms'       => array( array( 'taxonomy' => 'category', 'slug' => 'news' ), array( 'taxonomy' => 'category', 'slug' => 'fresh' ) ),
			)
		);

		$this->assertNull( $resolved['target'], 'The page at the same path is linked to another source post.' );
		$this->assertSame( 203, $resolved['blocked_by'] );
		$this->assertSame( array( 'id' => 201, 'how' => Resolver::LINKED ), $resolved['posts'][30] );
		$this->assertSame( array( 'id' => 202, 'how' => Resolver::UUID ), $resolved['posts'][31] );
		$this->assertSame( array( 'id' => 204, 'how' => Resolver::SLUG ), $resolved['posts'][32] );
		$this->assertNull( $resolved['posts'][33], 'Two posts share the slug.' );
		$this->assertNull( $resolved['posts'][34] );
		$this->assertSame( array( 'id' => 501, 'how' => Resolver::FILE ), $resolved['attachments'][5] );
		$this->assertSame( array( 'id' => 502, 'how' => Resolver::NAME ), $resolved['attachments'][6] );
		$this->assertNull( $resolved['attachments'][7], 'Same file, different size.' );
		$this->assertSame( array( 9, null ), array_column( $resolved['terms'], 'id' ) );
	}

	// Preflight and preview, end to end.

	public function test_dev_previews_a_push_to_staging(): void {
		[ $staging, $dev ] = $this->paired();
		$staging->lookup->post( 201, 'page', 'about' )->media( 501, '2024/05/a.jpg', 100 );
		$staging->lookup->terms['category:news'] = 9;
		$staging->lookup->users['ed@example.com'] = array( 'id' => 3, 'name' => 'Ed' );
		$staging->facts->packages[201] = self::package( 201, array( 'post_title' => 'About us (old)', 'featured' => 501 ), array(), array(), array( self::term( 'news' ) ) );

		$about = self::package(
			11,
			array(
				'post_title'    => 'About us',
				'post_content'  => '<p>Hello</p><img class="wp-image-6"><img class="wp-image-7">',
				'featured'      => 5,
				'post_parent'   => 12,
				'page_template' => 'tpl-wide.php',
				'author_email'  => 'ed@example.com',
			),
			array(),
			array(),
			array( self::term( 'news' ), self::term( 'fresh' ) ),
			array( 12 => array( 'type' => 'page', 'path' => 'company', 'title' => 'Company' ) ),
			array(
				5 => array( 'file' => '2024/05/a.jpg', 'size' => 100, 'title' => 'A' ),
				6 => array( 'file' => '2024/05/b.jpg', 'size' => 200, 'title' => 'B' ),
				7 => array( 'missing' => true ),
			)
		);
		$product = self::package( 13, array( 'post_type' => 'product', 'post_name' => 'widget' ), array(), array(), array(), array(), array(), null, 'widget' );

		$result = $this->preview( $dev, array( 11 => $about, 13 => $product ), array( 13 => array( self::finding( 'live' ) ) ) )->build( 'staging', array( 11, 13 ) );

		$this->assertNull( $result['error'] );
		[ $item, $new ] = $result['items'];

		$this->assertTrue( $item->isUpdate() );
		$this->assertSame( 201, $item->target['id'] );
		$this->assertSame( Resolver::PATH, $item->target['how'] );
		$this->assertSame( array( 'title', 'content', 'template', 'parent', 'terms' ), $item->changed );
		$this->assertSame( PreviewItem::NEVER_SYNCED, $item->conflict );
		$this->assertSame( 501, $item->rewritten->fields['featured'] );
		$this->assertSame( array( 6 ), array_column( $item->media, 'id' ) );
		$this->assertSame( 1, $item->mediaFound );
		$this->assertSame( array( 7, 12 ), array_column( $item->missing, 'id' ) );
		$this->assertSame( array( true, false ), array_column( $item->missing, 'gone' ), 'Media deleted on the source is already broken there.' );
		$this->assertSame( array( 'id' => 12, 'kind' => Rewrite::POST, 'title' => 'Company', 'location' => 'Parent', 'gone' => false ), $item->missing[1] );
		$this->assertSame( array( 'fresh' ), array_column( $item->newTerms, 'slug' ) );
		$this->assertSame( array( 'id' => 3, 'name' => 'Ed' ), $item->author );
		$this->assertSame( array( PreflightResponder::TEMPLATE_MISSING ), array_column( $item->checks, 'code' ) );
		$this->assertFalse( $item->isBlocked() );

		$this->assertFalse( $new->isUpdate() );
		$this->assertNull( $new->changed );
		$this->assertSame( array( PreflightResponder::POST_TYPE_MISSING ), array_column( $new->checks, 'code' ) );
		$this->assertCount( 1, $new->foreign );
		$this->assertTrue( $new->isBlocked() );
	}

	public function test_reports_edits_on_the_target_since_the_last_push(): void {
		[ $staging, $dev ] = $this->paired();
		$staging->lookup->post( 201, 'page', 'about' );
		$staging->facts->packages[201] = self::package( 201, array( 'post_title' => 'Edited there' ) );
		$staging->map->save( new MapEntry( self::UUID_A, MapEntry::POST, 201, 'dev', 11, 1, 'hash-at-last-push', 1 ) );

		$item = $this->preview( $dev, array( 11 => self::package( 11 ) ) )->build( 'staging', array( 11 ) )['items'][0];

		$this->assertSame( Resolver::LINKED, $item->target['how'] );
		$this->assertSame( PreviewItem::EDITED, $item->conflict );
		$this->assertSame( array( 'title' ), $item->changed );
	}

	public function test_refuses_a_different_package_format(): void {
		[ , $dev ] = $this->paired();
		$preview = new PushPreview( Fixtures::registry()->get( 'dev' ), Fixtures::registry(), $dev->sisters->transport(), '2', static fn( int $id ): Package => self::package( $id ), static fn(): array => array(), static fn(): ?array => null, 'json_encode' );

		$result = $preview->build( 'staging', array( 11 ) );

		$this->assertStringContainsString( 'different package format', (string) $result['error'] );
	}

	/**
	 * @param array<int, Package>       $packages
	 * @param array<int, list<Finding>> $findings
	 */
	private function preview( SisterHarness $dev, array $packages, array $findings = array() ): PushPreview {
		return new PushPreview(
			Fixtures::registry()->get( 'dev' ),
			Fixtures::registry(),
			$dev->sisters->transport(),
			'1',
			static fn( int $id ): ?Package => $packages[ $id ] ?? null,
			static fn( int $id ): array => $findings[ $id ] ?? array(),
			static fn( string $content ): ?array => null,
			'json_encode'
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

	private function rewriter( IdMap $ids ): PackageRewriter {
		return new PackageRewriter( self::urls(), $ids, static fn( string $content ): ?array => null, 'json_encode' );
	}

	private static function urls(): UrlRewriter {
		return new UrlRewriter( Fixtures::registry()->get( 'dev' ), Fixtures::registry()->get( 'staging' ) );
	}

	private static function finding( string $sister ): Finding {
		return new Finding( Fixtures::registry()->get( $sister ), 'https://www.client.co.uk/x', Finding::WARNING, 'content', 'Content' );
	}

	/**
	 * @return array{taxonomy: string, slug: string, name: string, parent: string}
	 */
	private static function term( string $slug ): array {
		return array(
			'taxonomy' => 'category',
			'slug'     => $slug,
			'name'     => ucfirst( $slug ),
			'parent'   => '',
		);
	}

	/**
	 * @param array<string, mixed>                                                      $fields
	 * @param array<string, list<mixed>>                                                $meta
	 * @param array<string, string>                                                     $acf
	 * @param list<array{taxonomy: string, slug: string, name: string, parent: string}> $terms
	 * @param array<int, array<string, mixed>>                                          $posts
	 * @param array<int, array<string, mixed>>                                          $attachments
	 */
	private static function package( int $id, array $fields = array(), array $meta = array(), array $acf = array(), array $terms = array(), array $posts = array(), array $attachments = array(), ?string $uuid = null, string $path = 'about' ): Package {
		return new Package(
			$id,
			$uuid,
			$path,
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
			$meta,
			$acf,
			$terms,
			array(),
			$posts,
			$attachments,
			array()
		);
	}
}
