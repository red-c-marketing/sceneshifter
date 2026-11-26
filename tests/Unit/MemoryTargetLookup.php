<?php

declare(strict_types=1);

namespace RedC\Sceneshifter\Tests\Unit;

use RedC\Sceneshifter\Transfer\TargetLookup;

/**
 * A target site's posts, media, terms and users, in memory.
 */
final class MemoryTargetLookup implements TargetLookup {

	/** @var array<int, array{type: string, path: string, slug: string, uuid: string|null, file?: string, size?: int|null}> */
	public array $posts = array();

	/** @var array<string, int> "taxonomy:slug" => term ID */
	public array $terms = array();

	/** @var array<string, array{id: int, name: string}> */
	public array $users = array();

	public function post( int $id, string $type, string $path, ?string $uuid = null, string $slug = '' ): self {
		$this->posts[ $id ] = array(
			'type' => $type,
			'path' => $path,
			'slug' => '' === $slug ? basename( $path ) : $slug,
			'uuid' => $uuid,
		);
		return $this;
	}

	public function media( int $id, string $file, ?int $size, ?string $uuid = null ): self {
		$this->posts[ $id ] = array(
			'type' => 'attachment',
			'path' => $file,
			'slug' => basename( $file ),
			'uuid' => $uuid,
			'file' => $file,
			'size' => $size,
		);
		return $this;
	}

	public function byUuid( string $uuid ): ?array {
		$found = array_filter( $this->posts, static fn( array $p ): bool => $p['uuid'] === $uuid );
		if ( 1 !== count( $found ) ) {
			return null;
		}
		$id = (int) array_key_first( $found );
		return array(
			'id'   => $id,
			'type' => $found[ $id ]['type'],
		);
	}

	public function byPath( string $type, string $path ): array {
		return array_keys( array_filter( $this->posts, static fn( array $p ): bool => $p['type'] === $type && $p['path'] === $path ) );
	}

	public function bySlug( string $type, string $slug ): array {
		return array_keys( array_filter( $this->posts, static fn( array $p ): bool => $p['type'] === $type && $p['slug'] === $slug ) );
	}

	public function mediaByFile( string $file ): array {
		return $this->mediaWhere( static fn( array $p ): bool => ( $p['file'] ?? null ) === $file );
	}

	public function mediaByName( string $name ): array {
		return $this->mediaWhere( static fn( array $p ): bool => isset( $p['file'] ) && basename( $p['file'] ) === $name );
	}

	public function fileOf( int $id ): string {
		return (string) ( $this->posts[ $id ]['file'] ?? '' );
	}

	public function termBySlug( string $taxonomy, string $slug ): ?int {
		return $this->terms[ "{$taxonomy}:{$slug}" ] ?? null;
	}

	public function userByEmail( string $email ): ?array {
		return $this->users[ $email ] ?? null;
	}

	/**
	 * @return list<array{id: int, size: int|null}>
	 */
	private function mediaWhere( callable $test ): array {
		$out = array();
		foreach ( $this->posts as $id => $post ) {
			if ( 'attachment' === $post['type'] && $test( $post ) ) {
				$out[] = array(
					'id'   => $id,
					'size' => $post['size'] ?? null,
				);
			}
		}
		return $out;
	}
}
