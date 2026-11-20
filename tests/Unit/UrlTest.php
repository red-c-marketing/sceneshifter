<?php

declare(strict_types=1);

namespace RedC\SisterSync\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RedC\SisterSync\Url\Classification;
use RedC\SisterSync\Url\UrlClassifier;
use RedC\SisterSync\Url\UrlNormaliser;

final class UrlTest extends TestCase {

	public function test_normalises_parts(): void {
		$url = ( new UrlNormaliser() )->normalise( 'HTTPS://WWW.Client.co.uk./About%20Us/?a=1#top' );
		$this->assertNotNull( $url );
		$this->assertSame( 'https', $url->scheme );
		$this->assertSame( 'www.client.co.uk', $url->host );
		$this->assertSame( 'client.co.uk', $url->hostKey() );
		$this->assertSame( '/About Us', $url->path );
		$this->assertSame( 'a=1', $url->query );
		$this->assertSame( 'top', $url->fragment );
	}

	public function test_handles_protocol_relative_escaped_and_encoded_urls(): void {
		$normaliser = new UrlNormaliser();
		$this->assertSame( 'staging.client.co.uk', $normaliser->normalise( '//staging.client.co.uk/x' )?->host );
		$this->assertSame( '/wp-content/a.jpg', $normaliser->normalise( 'https:\/\/staging.client.co.uk\/wp-content\/a.jpg' )?->path );
		$this->assertSame( 'a=1&b=2', $normaliser->normalise( 'https://staging.client.co.uk/?a=1&amp;b=2' )?->query );
	}

	public function test_rejects_non_http_urls(): void {
		$normaliser = new UrlNormaliser();
		foreach ( array( 'mailto:a@b.com', 'tel:0123', 'javascript:void(0)', 'ftp://a.example/x', 'images/a.jpg', '' ) as $raw ) {
			$this->assertNull( $normaliser->normalise( $raw ), $raw );
		}
	}

	public function test_root_relative_urls_are_relative(): void {
		$this->assertTrue( ( new UrlNormaliser() )->normalise( '/about/' )?->isRelative() );
	}

	public function test_strips_image_size_suffixes(): void {
		$normaliser = new UrlNormaliser();
		$this->assertSame( '/wp-content/uploads/2026/11/hero.jpg', $normaliser->normalise( 'https://a.example/wp-content/uploads/2026/11/hero-300x200.jpg' )?->pathWithoutImageSize() );
		$this->assertSame( '/wp-content/uploads/2026/11/hero.jpg', $normaliser->normalise( 'https://a.example/wp-content/uploads/2026/11/hero-scaled.jpg' )?->pathWithoutImageSize() );
		$this->assertSame( '/wp-content/uploads/2026/11/hero.jpg', $normaliser->normalise( 'https://a.example/wp-content/uploads/2026/11/hero-1024x683-scaled.jpg' )?->pathWithoutImageSize() );
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: ?string}>
	 */
	public static function classifications(): array {
		return array(
			'staging page'               => array( 'https://staging.client.co.uk/about/', Classification::SISTER, 'staging' ),
			'staging over http'          => array( 'http://staging.client.co.uk/about/', Classification::SISTER, 'staging' ),
			'dev with port'              => array( 'http://client.test:8080/wp-content/uploads/a.jpg', Classification::SISTER, 'dev' ),
			'dev host on other port'     => array( 'http://client.test:3000/', Classification::EXTERNAL, null ),
			'uat subdirectory'           => array( 'https://agency.example.com/clients/client/page', Classification::SISTER, 'uat' ),
			'agency site outside uat'    => array( 'https://agency.example.com/clients/other/page', Classification::EXTERNAL, null ),
			'live primary'               => array( 'https://www.client.co.uk/about', Classification::SELF, 'live' ),
			'live bare domain'           => array( 'https://client.co.uk/about', Classification::SELF, 'live' ),
			'live CDN alias'             => array( 'https://cdn.client.co.uk/a.jpg', Classification::SELF, 'live' ),
			'root relative'              => array( '/about', Classification::SELF, 'live' ),
			'external'                   => array( 'https://www.youtube.com/watch?v=1', Classification::EXTERNAL, null ),
			'lookalike host'             => array( 'https://staging.client.co.uk.evil.example/x', Classification::EXTERNAL, null ),
		);
	}

	#[DataProvider( 'classifications' )]
	public function test_classifies_from_live( string $raw, string $kind, ?string $siteId ): void {
		$registry   = Fixtures::registry();
		$classifier = new UrlClassifier( $registry, $registry->get( 'live' ) ?? throw new \RuntimeException() );
		$result     = $classifier->classify( $raw );
		$this->assertNotNull( $result );
		$this->assertSame( $kind, $result->kind );
		$this->assertSame( $siteId, $result->site?->id );
	}

	public function test_live_is_a_sister_when_on_staging(): void {
		$registry   = Fixtures::registry();
		$classifier = new UrlClassifier( $registry, $registry->get( 'staging' ) ?? throw new \RuntimeException() );
		$this->assertSame( Classification::SISTER, $classifier->classify( 'https://www.client.co.uk/' )?->kind );
		$this->assertSame( Classification::SELF, $classifier->classify( 'https://staging.client.co.uk/' )?->kind );
	}
}
