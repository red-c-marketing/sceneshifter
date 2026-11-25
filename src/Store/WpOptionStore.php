<?php
/**
 * KeyValueStore backed by this site's options. On multisite each subsite has
 * its own options, so each subsite keeps its own keys and pairings.
 *
 * @package RedC\Sceneshifter
 */

declare(strict_types=1);

namespace RedC\Sceneshifter\Store;

final class WpOptionStore implements KeyValueStore {

	public function get( string $key, mixed $default = null ): mixed {
		return get_option( $key, $default );
	}

	public function set( string $key, mixed $value ): void {
		update_option( $key, $value, false );
	}

	public function delete( string $key ): void {
		delete_option( $key );
	}
}
