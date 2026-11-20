<?php

declare(strict_types=1);

namespace RedC\SisterSync\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedC\SisterSync\Scanner\ContentScanner;
use RedC\SisterSync\Scanner\Finding;
use RedC\SisterSync\Scanner\ScanInput;
use RedC\SisterSync\Scanner\TextChunk;
use RedC\SisterSync\Url\UrlClassifier;

final class ContentScannerTest extends TestCase {

	private function scanner( string $currentId = 'live' ): ContentScanner {
		$registry = Fixtures::registry();
		return new ContentScanner( new UrlClassifier( $registry, $registry->get( $currentId ) ?? throw new \RuntimeException() ) );
	}

	public function test_classic_content_reports_only_sister_links(): void {
		$content  = '<p><a href="https://staging.client.co.uk/about/">About</a> <a href="https://www.client.co.uk/contact">Contact</a> <a href="https://youtube.com/x">Video</a></p>'
			. '<img src="http://client.test:8080/wp-content/uploads/a.jpg">';
		$findings = $this->scanner()->scan( new ScanInput( $content ) );

		$this->assertCount( 2, $findings );
		$this->assertSame( 'staging', $findings[0]->sister->id );
		$this->assertSame( 'dev', $findings[1]->sister->id );
		$this->assertSame( TextChunk::AREA_CONTENT, $findings[0]->area );
		$this->assertNull( $findings[0]->blockPath );
	}

	public function test_non_live_sisters_are_errors_on_live_and_warnings_elsewhere(): void {
		$content = '<a href="https://staging.client.co.uk/">s</a><a href="https://www.client.co.uk/">l</a>';

		$onLive = $this->scanner( 'live' )->scan( new ScanInput( $content ) );
		$this->assertCount( 1, $onLive );
		$this->assertSame( Finding::ERROR, $onLive[0]->severity );

		$onStaging = $this->scanner( 'staging' )->scan( new ScanInput( $content ) );
		$this->assertCount( 1, $onStaging );
		$this->assertSame( 'live', $onStaging[0]->sister->id );
		$this->assertSame( Finding::WARNING, $onStaging[0]->severity );
	}

	public function test_block_findings_carry_block_paths_matching_the_editor(): void {
		// Shaped like parse_blocks() output, including the whitespace-only
		// freeform blocks it leaves between top-level blocks.
		$blocks = array(
			array( 'blockName' => 'core/paragraph', 'attrs' => array(), 'innerBlocks' => array(), 'innerHTML' => '<p>Hello</p>' ),
			array( 'blockName' => null, 'attrs' => array(), 'innerBlocks' => array(), 'innerHTML' => "\n\n" ),
			array(
				'blockName'   => 'core/group',
				'attrs'       => array(),
				'innerHTML'   => '<div class="wp-block-group"></div>',
				'innerBlocks' => array(
					array( 'blockName' => 'core/paragraph', 'attrs' => array(), 'innerBlocks' => array(), 'innerHTML' => '<p>Plain</p>' ),
					array(
						'blockName'   => 'core/image',
						'attrs'       => array( 'id' => 42, 'url' => 'https://staging.client.co.uk/wp-content/uploads/hero.jpg' ),
						'innerBlocks' => array(),
						'innerHTML'   => '<figure><img src="https://staging.client.co.uk/wp-content/uploads/hero.jpg" class="wp-image-42"/></figure>',
					),
				),
			),
		);

		$findings = $this->scanner()->scan( new ScanInput( '', $blocks ) );

		// Same URL in the attributes and the HTML of one block: one finding, counted twice.
		$this->assertCount( 1, $findings );
		$this->assertSame( array( 1, 1 ), $findings[0]->blockPath );
		$this->assertSame( 'core/image', $findings[0]->blockName );
		$this->assertSame( 2, $findings[0]->count );
		$this->assertSame( 'Block 2 › 2 (core/image)', $findings[0]->locationText() );
	}

	public function test_scans_excerpt_and_nested_meta_with_labels(): void {
		$input = new ScanInput(
			'',
			null,
			'Read more at https://staging.client.co.uk/news',
			array(
				'hero_link'      => array( array( 'title' => 'Go', 'url' => 'https://staging.client.co.uk/go', 'target' => '' ) ),
				'_hero_link'     => array( 'field_abc123' ),
				'_edit_lock'     => array( 'https://staging.client.co.uk/should-be-ignored' ),
				'_oembed_abc'    => array( 'https://staging.client.co.uk/cache' ),
				'plain_text'     => array( 'no links here' ),
			),
			static fn( string $key ): ?string => 'hero_link' === $key ? 'ACF: Hero link' : null
		);

		$findings = $this->scanner()->scan( $input );

		$this->assertCount( 2, $findings );
		$this->assertSame( TextChunk::AREA_EXCERPT, $findings[0]->area );
		$this->assertSame( TextChunk::AREA_META, $findings[1]->area );
		$this->assertSame( 'ACF: Hero link', $findings[1]->label );
		$this->assertSame( 'https://staging.client.co.uk/go', $findings[1]->url );
	}

	public function test_finding_serialises_for_rest(): void {
		$findings = $this->scanner()->scan( new ScanInput( '<a href="https://staging.client.co.uk/">x</a>' ) );
		$data     = $findings[0]->toArray();
		$this->assertSame( 'staging', $data['sister']['id'] );
		$this->assertSame( '#dba617', $data['sister']['colour'] );
		$this->assertSame( 'error', $data['severity'] );
		$this->assertSame( 'Content', $data['location'] );
	}
}
