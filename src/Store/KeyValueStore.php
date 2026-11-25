<?php
/**
 * Somewhere to keep small pieces of state. The WordPress implementation uses
 * options; tests use an in-memory one.
 *
 * @package RedC\Sceneshifter
 */

declare(strict_types=1);

namespace RedC\Sceneshifter\Store;

interface KeyValueStore {

	public function get( string $key, mixed $default = null ): mixed;

	public function set( string $key, mixed $value ): void;

	public function delete( string $key ): void;
}
