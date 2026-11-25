<?php

declare(strict_types=1);

namespace RedC\Sceneshifter\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedC\Sceneshifter\PluginUrl;

final class PluginUrlTest extends TestCase {

	private const MU_URL = 'https://site.test/app/mu-plugins';

	private string $root;
	private string $source;
	private string $plugins;
	private string $mu;

	protected function setUp(): void {
		$this->root    = sys_get_temp_dir() . '/sceneshifter-url-' . bin2hex( random_bytes( 4 ) );
		$this->source  = $this->root . '/Development/Sceneshifter';
		$this->plugins = $this->root . '/site/plugins';
		$this->mu      = $this->root . '/site/mu-plugins';
		foreach ( array( $this->source, $this->plugins, $this->mu ) as $dir ) {
			mkdir( $dir, 0777, true );
		}
	}

	protected function tearDown(): void {
		$this->remove( $this->root );
	}

	public function test_maps_a_symlinked_must_use_plugin_to_its_link(): void {
		symlink( $this->source, $this->mu . '/sceneshifter' );

		$this->assertSame(
			self::MU_URL . '/sceneshifter/',
			PluginUrl::forMustUseSymlink( $this->source, $this->plugins, $this->mu, self::MU_URL . '/' )
		);
	}

	public function test_ignores_other_symlinks_in_must_use_plugins(): void {
		mkdir( $this->root . '/other' );
		symlink( $this->root . '/other', $this->mu . '/other' );

		$this->assertNull( PluginUrl::forMustUseSymlink( $this->source, $this->plugins, $this->mu, self::MU_URL ) );
	}

	public function test_leaves_a_copied_must_use_plugin_to_wordpress(): void {
		mkdir( $this->mu . '/sceneshifter' );

		$this->assertNull( PluginUrl::forMustUseSymlink( $this->mu . '/sceneshifter', $this->plugins, $this->mu, self::MU_URL ) );
	}

	public function test_leaves_a_normal_plugin_to_wordpress(): void {
		mkdir( $this->plugins . '/sceneshifter' );
		symlink( $this->plugins . '/sceneshifter', $this->mu . '/sceneshifter' );

		$this->assertNull( PluginUrl::forMustUseSymlink( $this->plugins . '/sceneshifter', $this->plugins, $this->mu, self::MU_URL ) );
	}

	public function test_encodes_the_link_name(): void {
		symlink( $this->source, $this->mu . '/scene shifter' );

		$this->assertSame(
			self::MU_URL . '/scene%20shifter/',
			PluginUrl::forMustUseSymlink( $this->source, $this->plugins, $this->mu, self::MU_URL )
		);
	}

	private function remove( string $path ): void {
		if ( is_link( $path ) || is_file( $path ) ) {
			unlink( $path );
			return;
		}
		if ( ! is_dir( $path ) ) {
			return;
		}
		foreach ( array_diff( (array) scandir( $path ), array( '.', '..' ) ) as $entry ) {
			$this->remove( $path . '/' . $entry );
		}
		rmdir( $path );
	}
}
