<?php
/**
 * On the target: works out which local post, attachment or term each
 * reference in a manifest stands for.
 *
 *   The post itself: sync map → UUID → same type and path.
 *   Referenced posts: sync map → UUID → same type and path → same type and slug.
 *   Media: sync map → UUID → same file and size → same file name and size.
 *   Terms: same taxonomy and slug.
 *
 * A match by path or file is only taken when it is the only candidate and
 * isn't already linked to a different item on the source.
 *
 * @package RedC\Sceneshifter
 */

declare(strict_types=1);

namespace RedC\Sceneshifter\Transfer;

use RedC\Sceneshifter\SyncMap\MapEntry;
use RedC\Sceneshifter\SyncMap\MapStore;
use RedC\Sceneshifter\SyncMap\Uuid;

final class Resolver {

	public const LINKED = 'linked';
	public const UUID   = 'uuid';
	public const PATH   = 'path';
	public const SLUG   = 'slug';
	public const FILE   = 'file';
	public const NAME   = 'name_size';
	public const EMAIL  = 'email';

	public function __construct(
		private readonly string $source,
		private readonly MapStore $map,
		private readonly TargetLookup $lookup
	) {}

	/**
	 * @param array<string, mixed> $manifest Package::manifest() as sent.
	 * @return array{target: array{id: int, how: string}|null, blocked_by: int|null, posts: array<int, array{id: int, how: string}|null>, attachments: array<int, array{id: int, how: string, file: string}|null>, term_refs: array<int, int|null>, terms: list<array{taxonomy: string, slug: string, id: int|null}>, author: array{id: int, name: string}|null}
	 */
	public function resolve( array $manifest ): array {
		$sourceId = is_int( $manifest['id'] ?? null ) ? $manifest['id'] : 0;
		$type     = self::str( $manifest['type'] ?? null );
		$blocked  = null;
		$target   = $this->post( $sourceId, self::uuid( $manifest['uuid'] ?? null ), $type, self::str( $manifest['path'] ?? null ), null, $blocked );

		$posts = array();
		foreach ( Package::keyed( $manifest['posts'] ?? null ) as $id => $ref ) {
			$ignored      = null;
			$posts[ $id ] = empty( $ref['missing'] )
				? $this->post( $id, self::uuid( $ref['uuid'] ?? null ), self::str( $ref['type'] ?? null ), self::str( $ref['path'] ?? null ), self::str( $ref['slug'] ?? null ), $ignored )
				: null;
		}

		$media = array();
		foreach ( Package::keyed( $manifest['attachments'] ?? null ) as $id => $ref ) {
			$found        = empty( $ref['missing'] )
				? $this->attachment( $id, self::uuid( $ref['uuid'] ?? null ), self::str( $ref['file'] ?? null ), is_int( $ref['size'] ?? null ) ? $ref['size'] : null )
				: null;
			$media[ $id ] = null === $found ? null : $found + array( 'file' => $this->lookup->fileOf( $found['id'] ) );
		}

		$termRefs = array();
		foreach ( Package::keyed( $manifest['term_refs'] ?? null ) as $id => $ref ) {
			$termRefs[ $id ] = empty( $ref['missing'] ) ? $this->lookup->termBySlug( self::str( $ref['taxonomy'] ?? null ), self::str( $ref['slug'] ?? null ) ) : null;
		}

		$terms = array();
		foreach ( is_array( $manifest['terms'] ?? null ) ? $manifest['terms'] : array() as $term ) {
			if ( is_array( $term ) && is_string( $term['taxonomy'] ?? null ) && is_string( $term['slug'] ?? null ) ) {
				$terms[] = array(
					'taxonomy' => $term['taxonomy'],
					'slug'     => $term['slug'],
					'id'       => $this->lookup->termBySlug( $term['taxonomy'], $term['slug'] ),
				);
			}
		}

		$email = self::str( $manifest['author'] ?? null );
		return array(
			'target'      => $target,
			'blocked_by'  => $blocked,
			'posts'       => $posts,
			'attachments' => $media,
			'term_refs'   => $termRefs,
			'terms'       => $terms,
			'author'      => '' === $email ? null : $this->lookup->userByEmail( $email ),
		);
	}

	/**
	 * @param int|null $blocked Set to a local post found by path that is already linked to something else.
	 * @return array{id: int, how: string}|null
	 */
	private function post( int $sourceId, ?string $uuid, string $type, string $path, ?string $slug, ?int &$blocked ): ?array {
		$linked = $sourceId > 0 ? $this->map->byRemote( $this->source, MapEntry::POST, $sourceId ) : null;
		if ( null !== $linked ) {
			return array(
				'id'  => $linked->localId,
				'how' => self::LINKED,
			);
		}
		if ( null !== $uuid ) {
			$found = $this->lookup->byUuid( $uuid );
			if ( null !== $found && $found['type'] === $type ) {
				return array(
					'id'  => $found['id'],
					'how' => self::UUID,
				);
			}
		}
		if ( '' === $type ) {
			return null;
		}
		foreach ( array( self::PATH => $path, self::SLUG => $slug ) as $how => $key ) {
			if ( null === $key || '' === $key ) {
				continue;
			}
			$ids = self::PATH === $how ? $this->lookup->byPath( $type, $key ) : $this->lookup->bySlug( $type, $key );
			if ( 1 !== count( $ids ) ) {
				continue;
			}
			if ( $this->linkedElsewhere( $ids[0], $sourceId ) ) {
				$blocked = $ids[0];
				return null;
			}
			return array(
				'id'  => $ids[0],
				'how' => $how,
			);
		}
		return null;
	}

	/**
	 * @return array{id: int, how: string}|null
	 */
	private function attachment( int $sourceId, ?string $uuid, string $file, ?int $size ): ?array {
		$linked = $this->map->byRemote( $this->source, MapEntry::POST, $sourceId );
		if ( null !== $linked ) {
			return array(
				'id'  => $linked->localId,
				'how' => self::LINKED,
			);
		}
		if ( null !== $uuid ) {
			$found = $this->lookup->byUuid( $uuid );
			if ( null !== $found && 'attachment' === $found['type'] ) {
				return array(
					'id'  => $found['id'],
					'how' => self::UUID,
				);
			}
		}
		if ( '' === $file ) {
			return null;
		}
		$fits = array_values( array_filter( $this->lookup->mediaByFile( $file ), static fn( array $m ): bool => null === $size || null === $m['size'] || $m['size'] === $size ) );
		if ( 1 === count( $fits ) && ! $this->linkedElsewhere( $fits[0]['id'], $sourceId ) ) {
			return array(
				'id'  => $fits[0]['id'],
				'how' => self::FILE,
			);
		}
		if ( null === $size ) {
			return null;
		}
		$slash = strrpos( $file, '/' );
		$name  = false === $slash ? $file : substr( $file, $slash + 1 );
		$fits  = array_values( array_filter( $this->lookup->mediaByName( $name ), static fn( array $m ): bool => $m['size'] === $size ) );
		if ( 1 === count( $fits ) && ! $this->linkedElsewhere( $fits[0]['id'], $sourceId ) ) {
			return array(
				'id'  => $fits[0]['id'],
				'how' => self::NAME,
			);
		}
		return null;
	}

	private function linkedElsewhere( int $localId, int $sourceId ): bool {
		$entry = $this->map->get( $this->source, MapEntry::POST, $localId );
		return null !== $entry && $entry->remoteId !== $sourceId;
	}

	private static function uuid( mixed $value ): ?string {
		return Uuid::isValid( $value ) ? $value : null;
	}

	private static function str( mixed $value ): string {
		return is_string( $value ) ? $value : '';
	}
}
