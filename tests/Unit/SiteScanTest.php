<?php

declare(strict_types=1);

namespace RedC\Sceneshifter\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedC\Sceneshifter\Scanner\ContentScanner;
use RedC\Sceneshifter\Scanner\Finding;
use RedC\Sceneshifter\Scanner\TextChunk;
use RedC\Sceneshifter\SiteScan\ReportPage;
use RedC\Sceneshifter\Url\UrlClassifier;

final class SiteScanTest extends TestCase {

	private function scanner(): ContentScanner {
		$registry = Fixtures::registry();
		return new ContentScanner( new UrlClassifier( $registry, $registry->get( 'live' ) ?? throw new \RuntimeException() ) );
	}

	public function test_scans_a_menu_item_url(): void {
		$findings = $this->scanner()->scanValue( TextChunk::AREA_MENU, 'Menu "Main"', 'https://staging.client.co.uk/offers/' );
		$this->assertCount( 1, $findings );
		$this->assertSame( TextChunk::AREA_MENU, $findings[0]->area );
		$this->assertSame( 'Menu "Main"', $findings[0]->locationText() );
		$this->assertSame( Finding::ERROR, $findings[0]->severity );
	}

	public function test_scans_nested_widget_instances(): void {
		$widget = array(
			'title'   => 'Find us',
			'content' => '<!-- wp:image {"url":"https://staging.client.co.uk/map.png"} --><figure><img src="https://staging.client.co.uk/map.png"></figure><!-- /wp:image -->',
			'extra'   => array( 'link' => 'http://client.test:8080/contact' ),
		);
		$findings = $this->scanner()->scanValue( TextChunk::AREA_WIDGET, 'Widget: block #3', $widget );

		$this->assertCount( 2, $findings );
		$this->assertSame( 'staging', $findings[0]->sister->id );
		$this->assertSame( 2, $findings[0]->count );
		$this->assertSame( 'dev', $findings[1]->sister->id );
	}

	public function test_values_with_only_own_or_external_links_find_nothing(): void {
		$value = array( 'https://www.client.co.uk/', 'https://youtube.com/x', 42, null, true );
		$this->assertSame( array(), $this->scanner()->scanValue( TextChunk::AREA_THEME_MOD, 'Theme setting: footer', $value ) );
	}

	public function test_csv_cells_cannot_become_formulas(): void {
		$this->assertSame( "'=HYPERLINK(\"x\")", ReportPage::csvCell( '=HYPERLINK("x")' ) );
		$this->assertSame( "'+1", ReportPage::csvCell( '+1' ) );
		$this->assertSame( "'@SUM(A1)", ReportPage::csvCell( '@SUM(A1)' ) );
		$this->assertSame( 'https://staging.client.co.uk/', ReportPage::csvCell( 'https://staging.client.co.uk/' ) );
		$this->assertSame( '', ReportPage::csvCell( '' ) );
	}
}
