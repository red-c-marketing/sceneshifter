<?php

declare(strict_types=1);

namespace RedC\Sceneshifter\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedC\Sceneshifter\Registry\Site;
use RedC\Sceneshifter\Rewrite\BlockRewriter;
use RedC\Sceneshifter\Rewrite\IdMap;
use RedC\Sceneshifter\Rewrite\Rewrite;
use RedC\Sceneshifter\Rewrite\Unresolved;
use RedC\Sceneshifter\Rewrite\UrlRewriter;

final class RewriteTest extends TestCase {

	private function site( string $id ): Site {
		return Fixtures::registry()->get( $id ) ?? throw new \RuntimeException( $id );
	}

	private function stagingToLive(): UrlRewriter {
		return new UrlRewriter( $this->site( 'staging' ), $this->site( 'live' ) );
	}

	public function test_moves_source_urls_and_keeps_path_query_and_fragment(): void {
		$rewrites = array();
		$text     = $this->stagingToLive()->rewriteText(
			'<a href="https://staging.client.co.uk/about/?a=1&amp;b=2#team">About</a> <img src="//staging.client.co.uk/wp-content/uploads/hero.jpg">',
			'Content',
			$rewrites
		);

		$this->assertSame(
			'<a href="https://www.client.co.uk/about/?a=1&amp;b=2#team">About</a> <img src="https://www.client.co.uk/wp-content/uploads/hero.jpg">',
			$text
		);
		$this->assertCount( 2, $rewrites );
		$this->assertSame( 'https://staging.client.co.uk/about/?a=1&amp;b=2#team', $rewrites[0]->old );
		$this->assertSame( 'Content', $rewrites[0]->location );
	}

	public function test_leaves_target_external_and_lookalike_hosts_alone(): void {
		$text = 'https://www.client.co.uk/x https://staging.client.co.uk.evil.example/x https://notstaging.client.co.uk/x https://youtube.com/watch http://client.test:8080/dev';
		$this->assertSame( $text, $this->stagingToLive()->rewriteText( $text, 'Content' ) );
	}

	public function test_keeps_json_escaping_and_handles_aliases_ports_and_subfolders(): void {
		$liveToStaging = new UrlRewriter( $this->site( 'live' ), $this->site( 'staging' ) );
		$this->assertSame(
			'{"url":"https:\/\/staging.client.co.uk\/shop\/"} https://staging.client.co.uk/img.png',
			$liveToStaging->rewriteText( '{"url":"https:\/\/client.co.uk\/shop\/"} https://cdn.client.co.uk/img.png', 'Meta' )
		);

		$devToUat = new UrlRewriter( $this->site( 'dev' ), $this->site( 'uat' ) );
		$this->assertSame(
			'https://agency.example.com/clients/client/contact http://client.test:9090/x',
			$devToUat->rewriteText( 'http://client.test:8080/contact http://client.test:9090/x', 'Meta' )
		);

		$uatToLive = new UrlRewriter( $this->site( 'uat' ), $this->site( 'live' ) );
		$this->assertSame(
			'https://www.client.co.uk/news https://agency.example.com/clients/other/news',
			$uatToLive->rewriteText( 'https://agency.example.com/clients/client/news https://agency.example.com/clients/other/news', 'Meta' )
		);
	}

	public function test_rewrites_nested_values_without_touching_keys(): void {
		$value = array(
			'https://staging.client.co.uk/key' => array(
				'link' => array(
					'url'   => 'https://staging.client.co.uk/go',
					'title' => 'Go',
				),
				'n'    => 3,
			),
		);
		$this->assertSame(
			array(
				'https://staging.client.co.uk/key' => array(
					'link' => array(
						'url'   => 'https://www.client.co.uk/go',
						'title' => 'Go',
					),
					'n'    => 3,
				),
			),
			$this->stagingToLive()->rewriteValue( $value, 'ACF: Hero link' )
		);
	}

	public function test_rewrites_block_ids_classes_and_urls_structurally(): void {
		$image = array(
			'blockName'    => 'core/image',
			'attrs'        => array(
				'id'  => 5,
				'url' => 'https://staging.client.co.uk/wp-content/uploads/a.jpg',
			),
			'innerBlocks'  => array(),
			'innerHTML'    => '<figure class="wp-block-image"><img src="https://staging.client.co.uk/wp-content/uploads/a.jpg" class="wp-image-5"/></figure>',
			'innerContent' => array( '<figure class="wp-block-image"><img src="https://staging.client.co.uk/wp-content/uploads/a.jpg" class="wp-image-5"/></figure>' ),
		);
		$blocks = array(
			array(
				'blockName'    => 'core/paragraph',
				'attrs'        => array(),
				'innerBlocks'  => array(),
				'innerHTML'    => '<p><a href="https://staging.client.co.uk/page/">Link</a></p>',
				'innerContent' => array( '<p><a href="https://staging.client.co.uk/page/">Link</a></p>' ),
			),
			array(
				'blockName'    => null,
				'attrs'        => array(),
				'innerBlocks'  => array(),
				'innerHTML'    => "\n\n",
				'innerContent' => array( "\n\n" ),
			),
			array(
				'blockName'    => 'core/group',
				'attrs'        => array(),
				'innerBlocks'  => array(
					$image,
					array(
						'blockName'    => 'core/block',
						'attrs'        => array( 'ref' => 77 ),
						'innerBlocks'  => array(),
						'innerHTML'    => '',
						'innerContent' => array(),
					),
				),
				'innerHTML'    => '<div class="wp-block-group"></div>',
				'innerContent' => array( '<div class="wp-block-group">', null, null, '</div>' ),
			),
		);

		$result = ( new BlockRewriter( $this->stagingToLive(), new IdMap( array( 5 => 42 ) ) ) )->rewrite( $blocks );
		$out    = $result['blocks'];

		$this->assertSame( '<p><a href="https://www.client.co.uk/page/">Link</a></p>', $out[0]['innerHTML'] );
		$this->assertSame( array( '<p><a href="https://www.client.co.uk/page/">Link</a></p>' ), $out[0]['innerContent'] );
		$this->assertSame( $blocks[1], $out[1], 'Whitespace between blocks is untouched.' );

		$newImage = $out[2]['innerBlocks'][0];
		$this->assertSame( 42, $newImage['attrs']['id'] );
		$this->assertSame( 'https://www.client.co.uk/wp-content/uploads/a.jpg', $newImage['attrs']['url'] );
		$this->assertStringContainsString( 'class="wp-image-42"', $newImage['innerHTML'] );
		$this->assertStringContainsString( 'class="wp-image-42"', (string) $newImage['innerContent'][0] );
		$this->assertSame( array( '<div class="wp-block-group">', null, null, '</div>' ), $out[2]['innerContent'] );

		$this->assertSame( 77, $out[2]['innerBlocks'][1]['attrs']['ref'], 'Unmapped IDs stay.' );
		$this->assertEquals( array( new Unresolved( Rewrite::POST, 77, 'Block 2 › 2 (core/block)' ) ), $result['unresolved'] );

		$kinds = array_map( static fn( Rewrite $r ): string => $r->kind . '@' . $r->location, $result['rewrites'] );
		$this->assertSame(
			array(
				'url@Block 1 (core/paragraph)',
				'attachment@Block 2 › 1 (core/image)',
				'url@Block 2 › 1 (core/image)',
				'url@Block 2 › 1 (core/image)',
				'attachment@Block 2 › 1 (core/image)',
			),
			$kinds,
			'Each change is recorded once, with the editor\'s block numbering.'
		);
	}

	public function test_gallery_ids_strings_and_data_ids(): void {
		$gallery = array(
			'blockName'    => 'core/gallery',
			'attrs'        => array( 'ids' => array( 5, '6', 9 ) ),
			'innerBlocks'  => array(),
			'innerHTML'    => '<img data-id="5" src="x.jpg"><img data-id="9" src="y.jpg">',
			'innerContent' => array( '<img data-id="5" src="x.jpg"><img data-id="9" src="y.jpg">' ),
		);
		$result  = ( new BlockRewriter( $this->stagingToLive(), new IdMap( array( 5 => 50, 6 => 60 ) ) ) )->rewrite( array( $gallery ) );

		$this->assertSame( array( 50, '60', 9 ), $result['blocks'][0]['attrs']['ids'] );
		$this->assertSame( '<img data-id="50" src="x.jpg"><img data-id="9" src="y.jpg">', $result['blocks'][0]['innerHTML'] );
		$this->assertCount( 1, $result['unresolved'], 'ID 9 is missing once, though found twice.' );
		$this->assertSame( 9, $result['unresolved'][0]->id );
	}
}
