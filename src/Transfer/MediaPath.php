<?php
/**
 * Upload paths, as WordPress names an image's files.
 *
 * @package RedC\Sceneshifter
 */

declare(strict_types=1);

namespace RedC\Sceneshifter\Transfer;

final class MediaPath {

	/**
	 * A file without its extension or WordPress's -scaled and -rotated
	 * suffixes: 2024/05/photo-scaled.jpg becomes 2024/05/photo, which every
	 * size of that image starts with.
	 */
	public static function stem( string $file ): string {
		$slash = strrpos( $file, '/' );
		$dot   = strrpos( $file, '.' );
		$base  = false !== $dot && ( false === $slash || $dot > $slash ) ? substr( $file, 0, $dot ) : $file;
		return (string) preg_replace( '/-(?:scaled|rotated)$/', '', $base );
	}

	/**
	 * Images the target keeps under another name or folder: source stem =>
	 * target stem, for UrlRewriter.
	 *
	 * @param array<int, string>               $targetFiles Source attachment ID => its file on the target.
	 * @param array<int, array<string, mixed>> $attachments Package::$attachments, with each file here.
	 * @return array<string, string>
	 */
	public static function moves( array $targetFiles, array $attachments ): array {
		$moves = array();
		foreach ( $targetFiles as $source => $file ) {
			$mine = $attachments[ $source ]['file'] ?? '';
			if ( ! is_string( $mine ) || '' === $mine || '' === $file ) {
				continue;
			}
			$from = self::stem( $mine );
			$to   = self::stem( $file );
			if ( $from !== $to ) {
				$moves[ $from ] = $to;
			}
		}
		return $moves;
	}
}
