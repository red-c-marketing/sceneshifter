<?php

declare(strict_types=1);

namespace RedC\Sceneshifter\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedC\Sceneshifter\Scanner\UrlExtractor;

final class UrlExtractorTest extends TestCase {

	private UrlExtractor $extractor;

	protected function setUp(): void {
		$this->extractor = new UrlExtractor();
	}

	public function test_finds_urls_in_html_attributes(): void {
		$html = '<a href="https://staging.client.co.uk/about/">About</a><img src=\'//staging.client.co.uk/a.jpg\' alt="">';
		$this->assertSame(
			array( 'https://staging.client.co.uk/about/', '//staging.client.co.uk/a.jpg' ),
			$this->extractor->extract( $html )
		);
	}

	public function test_finds_every_srcset_candidate(): void {
		$html = '<img srcset="https://s.example/a-300x200.jpg 300w, https://s.example/a-1024x683.jpg 1024w">';
		$this->assertSame(
			array( 'https://s.example/a-300x200.jpg', 'https://s.example/a-1024x683.jpg' ),
			$this->extractor->extract( $html )
		);
	}

	public function test_finds_css_background_urls(): void {
		$html = '<div style="background-image:url(\'https://s.example/bg.jpg\')"></div><div style="background:url(https://s.example/b2.png) no-repeat"></div>';
		$this->assertSame( array( 'https://s.example/bg.jpg', 'https://s.example/b2.png' ), $this->extractor->extract( $html ) );
	}

	public function test_finds_urls_in_shortcodes_and_prose(): void {
		$text = '[video src="https://s.example/v.mp4"] See https://s.example/page. Or (https://s.example/other)!';
		$this->assertSame(
			array( 'https://s.example/v.mp4', 'https://s.example/page', 'https://s.example/other' ),
			$this->extractor->extract( $text )
		);
	}

	public function test_decodes_json_and_entity_escaping(): void {
		$text = '<!-- wp:image {"url":"https:\/\/s.example\/a.jpg?x=1&y=2"} --> <a href="https://s.example/?a=1&amp;b=2">x</a>';
		$this->assertSame(
			array( 'https://s.example/a.jpg?x=1&y=2', 'https://s.example/?a=1&b=2' ),
			$this->extractor->extract( $text )
		);
	}

	public function test_ignores_other_schemes_and_relative_paths(): void {
		$text = '<a href="/about">x</a> ftp://s.example/f file:///etc/passwd mailto:a@s.example images/a.jpg';
		$this->assertSame( array(), $this->extractor->extract( $text ) );
	}

	public function test_keeps_ports(): void {
		$this->assertSame( array( 'http://client.test:8080/x' ), $this->extractor->extract( 'go to http://client.test:8080/x now' ) );
	}
}
