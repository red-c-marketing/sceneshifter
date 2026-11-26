<?php
/**
 * Media transfer files in memory, for tests.
 *
 * @package RedC\Sceneshifter
 */

declare(strict_types=1);

namespace RedC\Sceneshifter\Tests\Unit;

use RedC\Sceneshifter\Transfer\MediaFiles;

final class MemoryMediaFiles implements MediaFiles {

	public int $max = 10485760;

	public int $nextId = 700;

	/** Save a clashing file as name-1.jpg instead of refusing it. */
	public bool $rename = false;

	/** @var list<int> Attachments something else uses. */
	public array $used = array();

	/** @var list<int> */
	public array $deleted = array();

	/** @var array<string, string> Upload key => bytes so far. */
	public array $parts = array();

	/** @var array<string, string> Relative path => bytes. */
	public array $files = array();

	/** @var array<int, array{file: string, meta: array<string, mixed>}> */
	public array $attachments = array();

	public function maxSize(): int {
		return $this->max;
	}

	public function partSize( string $key ): int {
		return strlen( $this->parts[ $key ] ?? '' );
	}

	public function append( string $key, string $bytes ): void {
		$this->parts[ $key ] = ( $this->parts[ $key ] ?? '' ) . $bytes;
	}

	public function partHash( string $key ): string {
		return hash( 'sha256', $this->parts[ $key ] ?? '' );
	}

	public function discard( string $key ): void {
		unset( $this->parts[ $key ] );
	}

	public function place( string $key, string $file, array $meta ): array {
		if ( ! preg_match( '/\.(jpe?g|png|gif|webp|pdf)$/i', $file ) ) {
			return array(
				'error'   => self::TYPE,
				'message' => 'This site doesn\'t accept files like that.',
			);
		}
		if ( isset( $this->files[ $file ] ) && $this->files[ $file ] !== ( $this->parts[ $key ] ?? '' ) ) {
			if ( ! $this->rename ) {
				return array(
					'error'   => self::EXISTS,
					'message' => "A different file already exists at uploads/{$file}.",
				);
			}
			$dot  = (int) strrpos( $file, '.' );
			$base = substr( $file, 0, $dot );
			$n    = 1;
			while ( isset( $this->files[ $base . '-' . $n . substr( $file, $dot ) ] ) ) {
				++$n;
			}
			$file = $base . '-' . $n . substr( $file, $dot );
		}
		$this->files[ $file ] = $this->parts[ $key ] ?? '';
		unset( $this->parts[ $key ] );
		$id                       = $this->nextId++;
		$this->attachments[ $id ] = array(
			'file' => $file,
			'meta' => $meta,
		);
		return array(
			'id'   => $id,
			'file' => $file,
		);
	}

	public function attachmentExists( int $id ): bool {
		return isset( $this->attachments[ $id ] );
	}

	public function fileOf( int $id ): string {
		return $this->attachments[ $id ]['file'] ?? '';
	}

	public function copiedBy( string $copied ): array {
		return array_keys( array_filter( $this->attachments, static fn( array $a ): bool => ( $a['meta']['copied'] ?? null ) === $copied ) );
	}

	public function inUse( int $id, int $ignorePost ): bool {
		return in_array( $id, $this->used, true );
	}

	public function deleteAttachment( int $id ): void {
		unset( $this->files[ $this->fileOf( $id ) ], $this->attachments[ $id ] );
		$this->deleted[] = $id;
	}
}
