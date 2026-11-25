<?php
/**
 * Media transfer files on WordPress. Partial uploads wait in
 * uploads/sceneshifter-tmp, which is closed to visitors, and are cleared
 * after a day if a transfer never finishes.
 *
 * @package RedC\Sceneshifter
 */

declare(strict_types=1);

namespace RedC\Sceneshifter\Transfer;

use RedC\Sceneshifter\SyncMap\Uuid;

final class WpMediaFiles implements MediaFiles {

	private const TEMP_DIR = 'sceneshifter-tmp';
	private const MAX_SIZE = 536870912;
	private const STALE    = DAY_IN_SECONDS;

	public function maxSize(): int {
		/**
		 * The largest file another environment may copy here, in bytes.
		 *
		 * @param int $bytes Default 512 MB.
		 */
		return (int) apply_filters( 'sceneshifter_max_media_size', self::MAX_SIZE );
	}

	public function partSize( string $key ): int {
		$path = $this->part( $key );
		clearstatcache( true, $path );
		return is_file( $path ) ? (int) filesize( $path ) : 0;
	}

	public function append( string $key, string $bytes ): void {
		$dir = $this->tempDir();
		if ( null === $dir ) {
			throw new \RuntimeException( 'The uploads folder isn\'t writable.' );
		}
		if ( false === file_put_contents( $this->part( $key ), $bytes, FILE_APPEND | LOCK_EX ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			throw new \RuntimeException( 'Could not write to the uploads folder.' );
		}
	}

	public function partHash( string $key ): string {
		$path = $this->part( $key );
		return is_file( $path ) ? (string) hash_file( 'sha256', $path ) : hash( 'sha256', '' );
	}

	public function discard( string $key ): void {
		$path = $this->part( $key );
		if ( is_file( $path ) ) {
			wp_delete_file( $path );
		}
	}

	public function place( string $key, string $file, array $meta ): array {
		$this->prune();
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return self::error( self::FAILED, (string) $uploads['error'] );
		}
		$dir  = dirname( $file );
		$dir  = '.' === $dir ? '' : $dir;
		$name = sanitize_file_name( basename( $file ) );
		$type = wp_check_filetype( $name );
		if ( empty( $type['type'] ) ) {
			/* translators: %s: file name. */
			return self::error( self::TYPE, sprintf( __( 'This site doesn\'t accept files like %s.', 'sceneshifter' ), $name ) );
		}
		$folder = untrailingslashit( $uploads['basedir'] ) . ( '' === $dir ? '' : '/' . $dir );
		if ( ! wp_mkdir_p( $folder ) ) {
			return self::error( self::FAILED, __( 'Could not create the folder in uploads.', 'sceneshifter' ) );
		}
		$relative = ( '' === $dir ? '' : $dir . '/' ) . $name;
		$path     = $folder . '/' . $name;
		$part     = $this->part( $key );

		if ( file_exists( $path ) ) {
			// The same file left behind without an attachment can be reused.
			if ( filesize( $path ) !== filesize( $part ) || hash_file( 'sha256', $path ) !== hash_file( 'sha256', $part ) ) {
				/* translators: %s: file path. */
				return self::error( self::EXISTS, sprintf( __( 'A different file already exists at uploads/%s.', 'sceneshifter' ), $relative ) );
			}
			wp_delete_file( $part );
		} elseif ( ! @rename( $part, $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename
			return self::error( self::FAILED, __( 'Could not move the file into uploads.', 'sceneshifter' ) );
		}
		$stat = stat( $folder );
		if ( is_array( $stat ) ) {
			chmod( $path, $stat['mode'] & 0000666 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- as WordPress does for uploads.
		}

		$id = wp_insert_attachment(
			wp_slash(
				array(
					'post_mime_type' => $type['type'],
					'post_title'     => (string) ( $meta['title'] ?? '' ),
					'post_content'   => (string) ( $meta['description'] ?? '' ),
					'post_excerpt'   => (string) ( $meta['caption'] ?? '' ),
					'post_status'    => 'inherit',
					'guid'           => untrailingslashit( $uploads['baseurl'] ) . '/' . $relative,
				)
			),
			$path,
			0,
			true
		);
		if ( is_wp_error( $id ) ) {
			wp_delete_file( $path );
			return self::error( self::FAILED, $id->get_error_message() );
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $path ) );

		if ( '' !== (string) ( $meta['alt'] ?? '' ) ) {
			update_post_meta( $id, '_wp_attachment_image_alt', wp_slash( (string) $meta['alt'] ) );
		}
		if ( Uuid::isValid( $meta['uuid'] ?? null ) ) {
			update_post_meta( $id, Uuid::META_KEY, $meta['uuid'] );
		}
		return array(
			'id'   => $id,
			'file' => $this->fileOf( $id ),
		);
	}

	public function attachmentExists( int $id ): bool {
		return 'attachment' === get_post_type( $id );
	}

	public function fileOf( int $id ): string {
		return (string) get_post_meta( $id, '_wp_attached_file', true );
	}

	private function part( string $key ): string {
		return (string) $this->tempDir() . '/' . preg_replace( '/[^a-z0-9_-]/', '', $key ) . '.part';
	}

	private function tempDir(): ?string {
		$uploads = wp_upload_dir( null, false );
		if ( ! empty( $uploads['error'] ) ) {
			return null;
		}
		$dir = untrailingslashit( $uploads['basedir'] ) . '/' . self::TEMP_DIR;
		if ( ! is_dir( $dir ) ) {
			if ( ! wp_mkdir_p( $dir ) ) {
				return null;
			}
			// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" );
			file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" );
			// phpcs:enable
		}
		return $dir;
	}

	private function prune(): void {
		$dir = $this->tempDir();
		foreach ( null === $dir ? array() : (array) glob( $dir . '/*.part' ) as $path ) {
			if ( is_string( $path ) && filemtime( $path ) < time() - self::STALE ) {
				wp_delete_file( $path );
			}
		}
	}

	/**
	 * @return array{error: string, message: string}
	 */
	private static function error( string $code, string $message ): array {
		return array(
			'error'   => $code,
			'message' => $message,
		);
	}
}
