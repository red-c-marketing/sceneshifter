<?php
/**
 * Media files on the source of a push. WpMediaSource is the WordPress one.
 *
 * @package RedC\Sceneshifter
 */

declare(strict_types=1);

namespace RedC\Sceneshifter\Transfer;

interface MediaSource {

	/**
	 * The file to copy for an attachment: the original upload, not a
	 * resized copy, since the target makes its own sizes.
	 *
	 * @return array{file: string, path: string, size: int, sha256: string, uuid: string, title: string, alt: string, caption: string, description: string}|null
	 *         file is relative to the uploads folder; null when the file is gone.
	 */
	public function describe( int $id ): ?array;

	/**
	 * @return string|false False when the file can't be read.
	 */
	public function read( string $path, int $offset, int $length ): string|false;
}
