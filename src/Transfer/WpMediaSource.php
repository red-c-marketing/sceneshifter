<?php
/**
 * Media files on WordPress, for the source of a push.
 *
 * @package RedC\Sceneshifter
 */

declare(strict_types=1);

namespace RedC\Sceneshifter\Transfer;

use RedC\Sceneshifter\SyncMap\Uuid;
use WP_Post;

final class WpMediaSource implements MediaSource {

	public function describe( int $id ): ?array {
		$post = get_post( $id );
		if ( ! $post instanceof WP_Post || 'attachment' !== $post->post_type ) {
			return null;
		}
		$path = function_exists( 'wp_get_original_image_path' ) ? wp_get_original_image_path( $id ) : false;
		$path = is_string( $path ) && is_file( $path ) ? $path : get_attached_file( $id );
		if ( ! is_string( $path ) || ! is_file( $path ) || ! is_readable( $path ) ) {
			return null;
		}
		$attached = (string) get_post_meta( $id, '_wp_attached_file', true );
		$dir      = dirname( $attached );
		return array(
			'file'        => ( '.' === $dir || '' === $dir ? '' : $dir . '/' ) . basename( $path ),
			'path'        => $path,
			'size'        => (int) filesize( $path ),
			'sha256'      => (string) hash_file( 'sha256', $path ),
			'uuid'        => self::uuid( $id ),
			'title'       => $post->post_title,
			'alt'         => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
			'caption'     => $post->post_excerpt,
			'description' => $post->post_content,
		);
	}

	public function read( string $path, int $offset, int $length ): string|false {
		$handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $handle ) {
			return false;
		}
		$bytes = 0 === fseek( $handle, $offset ) ? fread( $handle, max( 1, $length ) ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return $bytes;
	}

	private static function uuid( int $id ): string {
		$uuid = get_post_meta( $id, Uuid::META_KEY, true );
		if ( Uuid::isValid( $uuid ) ) {
			return $uuid;
		}
		$uuid = Uuid::generate();
		update_post_meta( $id, Uuid::META_KEY, $uuid );
		return $uuid;
	}
}
