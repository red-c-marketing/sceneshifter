<?php
/**
 * File handling on the target of a media transfer: partial uploads, then
 * the finished file in the uploads folder as an attachment. WpMediaFiles is
 * the WordPress one.
 *
 * @package RedC\Sceneshifter
 */

declare(strict_types=1);

namespace RedC\Sceneshifter\Transfer;

interface MediaFiles {

	public const EXISTS = 'exists';
	public const TYPE   = 'type';
	public const FAILED = 'failed';

	/** The largest file accepted, in bytes. */
	public function maxSize(): int;

	/** Bytes received so far for an upload; 0 if none. */
	public function partSize( string $key ): int;

	/**
	 * @throws \RuntimeException When the bytes can't be stored.
	 */
	public function append( string $key, string $bytes ): void;

	/** SHA-256 of the bytes received, in hex. */
	public function partHash( string $key ): string;

	public function discard( string $key ): void;

	/**
	 * Moves a finished upload into the uploads folder at the same relative
	 * path it has on the source and creates its attachment.
	 *
	 * When a different file is already at that path, returns EXISTS, or
	 * saves it under the next free name (photo-1.jpg) if this site is set to.
	 *
	 * @param string               $file Relative to the uploads folder, e.g. 2024/05/photo.jpg.
	 * @param array<string, mixed> $meta title, alt, caption, description, uuid, and copied: the push that copied it.
	 * @return array{id: int, file: string}|array{error: string, message: string}
	 */
	public function place( string $key, string $file, array $meta ): array;

	public function attachmentExists( int $id ): bool;

	/** The attachment's file, relative to the uploads folder. */
	public function fileOf( int $id ): string;

	/**
	 * Attachments place() created for a push, by the copied value it was given.
	 *
	 * @return list<int>
	 */
	public function copiedBy( string $copied ): array;

	/**
	 * Records that another push uses an attachment an earlier push copied
	 * here, so undoing either one can delete it once nothing uses it. Does
	 * nothing for media Sceneshifter didn't copy.
	 */
	public function alsoCopiedBy( int $id, string $copied ): void;

	/**
	 * Whether anything other than one post (and its revisions) still uses
	 * an attachment: as a featured image, in content, in custom fields, term
	 * meta or ACF options. Errs towards yes.
	 */
	public function inUse( int $id, int $ignorePost ): bool;

	/** Deletes an attachment and its files. */
	public function deleteAttachment( int $id ): void;
}
