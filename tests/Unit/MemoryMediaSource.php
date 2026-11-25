<?php
/**
 * Source media in memory, for tests.
 *
 * @package RedC\Sceneshifter
 */

declare(strict_types=1);

namespace RedC\Sceneshifter\Tests\Unit;

use RedC\Sceneshifter\Transfer\MediaSource;

final class MemoryMediaSource implements MediaSource {

	/** @var array<int, array{file: string, bytes: string, uuid: string, title: string}> */
	public array $media = array();

	public function add( int $id, string $file, string $bytes, string $uuid ): self {
		$this->media[ $id ] = array(
			'file'  => $file,
			'bytes' => $bytes,
			'uuid'  => $uuid,
			'title' => basename( $file ),
		);
		return $this;
	}

	public function describe( int $id ): ?array {
		$m = $this->media[ $id ] ?? null;
		return null === $m ? null : array(
			'file'        => $m['file'],
			'path'        => "mem://{$id}",
			'size'        => strlen( $m['bytes'] ),
			'sha256'      => hash( 'sha256', $m['bytes'] ),
			'uuid'        => $m['uuid'],
			'title'       => $m['title'],
			'alt'         => '',
			'caption'     => '',
			'description' => '',
		);
	}

	public function read( string $path, int $offset, int $length ): string|false {
		$id = (int) substr( $path, strlen( 'mem://' ) );
		return isset( $this->media[ $id ] ) ? substr( $this->media[ $id ]['bytes'], $offset, $length ) : false;
	}
}
