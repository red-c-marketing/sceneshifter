<?php
/**
 * On the target: receives media the source is copying over, in chunks small
 * enough for one signed request each (POST /media/chunk), then checks the
 * whole file and turns it into an attachment (POST /media/commit).
 *
 * A chunk the target already has is accepted again, so the source can retry
 * after a lost reply. A file already copied in an earlier push is reused.
 *
 * @package RedC\Sceneshifter
 */

declare(strict_types=1);

namespace RedC\Sceneshifter\Transfer;

use RedC\Sceneshifter\SyncMap\MapEntry;
use RedC\Sceneshifter\SyncMap\MapStore;
use RedC\Sceneshifter\SyncMap\Uuid;

final class MediaReceiver {

	public const CHUNK_ROUTE  = '/sceneshifter/v1/media/chunk';
	public const COMMIT_ROUTE = '/sceneshifter/v1/media/commit';

	/** Raw bytes per chunk: base64 and JSON keep it well under Gate::MAX_BODY. */
	public const CHUNK = 524288;

	public const INVALID    = 'invalid';
	public const OFFSET     = 'offset';
	public const TOO_LARGE  = 'too_large';
	public const INCOMPLETE = 'incomplete';

	/** @var callable(): int */
	private $clock;

	public function __construct(
		private readonly MapStore $map,
		private readonly MediaFiles $files,
		?callable $clock = null
	) {
		$this->clock = $clock ?? 'time';
	}

	/**
	 * @param array<string, mixed> $payload {upload, offset, data (base64)}.
	 * @return array<string, mixed> {size} or {error, size?}.
	 */
	public function chunk( string $sender, array $payload ): array {
		$key    = self::key( $sender, $payload['upload'] ?? null );
		$offset = $payload['offset'] ?? null;
		$bytes  = is_string( $payload['data'] ?? null ) ? base64_decode( $payload['data'], true ) : false;
		if ( null === $key || ! is_int( $offset ) || $offset < 0 || false === $bytes || strlen( $bytes ) > self::CHUNK ) {
			return array( 'error' => self::INVALID );
		}
		$size = $this->files->partSize( $key );
		if ( $offset !== $size ) {
			// A retry of a chunk already stored is fine; anything else is out of step.
			return $offset + strlen( $bytes ) === $size
				? array( 'size' => $size )
				: array(
					'error' => self::OFFSET,
					'size'  => $size,
				);
		}
		if ( $size + strlen( $bytes ) > $this->files->maxSize() ) {
			$this->files->discard( $key );
			return array( 'error' => self::TOO_LARGE );
		}
		try {
			$this->files->append( $key, $bytes );
		} catch ( \RuntimeException $e ) {
			return array(
				'error'   => MediaFiles::FAILED,
				'message' => $e->getMessage(),
			);
		}
		return array( 'size' => $size + strlen( $bytes ) );
	}

	/**
	 * @param array<string, mixed> $payload {upload, source, file, size, sha256, uuid, title, alt, caption, description}.
	 * @return array<string, mixed> {id, file, reused} or {error, message?}.
	 */
	public function commit( string $sender, array $payload ): array {
		$key    = self::key( $sender, $payload['upload'] ?? null );
		$source = $payload['source'] ?? null;
		$file   = $payload['file'] ?? null;
		$size   = $payload['size'] ?? null;
		$hash   = $payload['sha256'] ?? null;
		if ( null === $key || ! is_int( $source ) || $source <= 0 || ! is_string( $file ) || ! self::safePath( $file ) || ! is_int( $size ) || ! is_string( $hash ) ) {
			return array( 'error' => self::INVALID );
		}

		$linked = $this->map->byRemote( $sender, MapEntry::POST, $source );
		if ( null !== $linked && $this->files->attachmentExists( $linked->localId ) ) {
			$this->files->discard( $key );
			return array(
				'id'     => $linked->localId,
				'file'   => $this->files->fileOf( $linked->localId ),
				'reused' => true,
			);
		}

		if ( $this->files->partSize( $key ) !== $size || ! hash_equals( $this->files->partHash( $key ), strtolower( $hash ) ) ) {
			$this->files->discard( $key );
			return array( 'error' => self::INCOMPLETE );
		}

		$uuid   = Uuid::isValid( $payload['uuid'] ?? null ) ? $payload['uuid'] : Uuid::generate();
		$placed = $this->files->place(
			$key,
			$file,
			array(
				'uuid'        => $uuid,
				'title'       => self::str( $payload['title'] ?? null ),
				'alt'         => self::str( $payload['alt'] ?? null ),
				'caption'     => self::str( $payload['caption'] ?? null ),
				'description' => self::str( $payload['description'] ?? null ),
			)
		);
		if ( isset( $placed['error'] ) ) {
			$this->files->discard( $key );
			return $placed;
		}

		$now = ( $this->clock )();
		$this->map->save( new MapEntry( $uuid, MapEntry::POST, $placed['id'], $sender, $source, $now, null, $now ) );
		return $placed + array( 'reused' => false );
	}

	/**
	 * Uploads are filed per sender, so two sisters can't touch each other's.
	 */
	private static function key( string $sender, mixed $upload ): ?string {
		if ( ! Uuid::isValid( $upload ) || 1 !== preg_match( '/^[a-z0-9_-]{1,32}$/', $sender ) ) {
			return null;
		}
		return $sender . '-' . strtolower( $upload );
	}

	/**
	 * A relative path inside the uploads folder: no "..", no absolute path.
	 */
	public static function safePath( string $file ): bool {
		if ( '' === $file || str_starts_with( $file, '/' ) || str_contains( $file, '\\' ) || str_contains( $file, "\0" ) ) {
			return false;
		}
		foreach ( explode( '/', $file ) as $part ) {
			if ( '' === $part || '.' === $part || '..' === $part ) {
				return false;
			}
		}
		return true;
	}

	private static function str( mixed $value ): string {
		return is_string( $value ) ? $value : '';
	}
}
