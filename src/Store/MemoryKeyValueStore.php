<?php
/**
 * In-memory KeyValueStore for tests and single-process tools.
 *
 * @package RedC\SisterSync
 */

declare(strict_types=1);

namespace RedC\SisterSync\Store;

final class MemoryKeyValueStore implements KeyValueStore {

	/** @var array<string, mixed> */
	private array $values = array();

	public function get( string $key, mixed $default = null ): mixed {
		return array_key_exists( $key, $this->values ) ? $this->values[ $key ] : $default;
	}

	public function set( string $key, mixed $value ): void {
		$this->values[ $key ] = $value;
	}

	public function delete( string $key ): void {
		unset( $this->values[ $key ] );
	}
}
