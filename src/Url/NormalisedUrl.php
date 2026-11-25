<?php
/**
 * An absolute or root-relative URL broken into comparable parts.
 *
 * @package RedC\Sceneshifter
 */

declare(strict_types=1);

namespace RedC\Sceneshifter\Url;

final class NormalisedUrl {

	/**
	 * @param string      $original The URL exactly as found.
	 * @param string|null $scheme   'http' or 'https'; null for root-relative URLs.
	 * @param string|null $host     Lower-case host; null for root-relative URLs.
	 * @param int|null    $port     Explicit port, if any.
	 * @param string      $path     Decoded path without a trailing slash ('/' for the root).
	 * @param string      $query    Query string without '?'.
	 * @param string      $fragment Fragment without '#'.
	 */
	public function __construct(
		public readonly string $original,
		public readonly ?string $scheme,
		public readonly ?string $host,
		public readonly ?int $port,
		public readonly string $path,
		public readonly string $query = '',
		public readonly string $fragment = ''
	) {}

	public function isRelative(): bool {
		return null === $this->host;
	}

	/**
	 * Host key used for matching: lower case, no trailing dot, no leading
	 * "www.", and the port only when it is not 80 or 443.
	 */
	public function hostKey(): ?string {
		return null === $this->host ? null : self::makeHostKey( $this->host, $this->port );
	}

	public static function makeHostKey( string $host, ?int $port = null ): string {
		$key = strtolower( rtrim( $host, '.' ) );
		$key = (string) preg_replace( '/^www\./', '', $key );
		if ( null !== $port && 80 !== $port && 443 !== $port ) {
			$key .= ':' . $port;
		}
		return $key;
	}

	/**
	 * The path with any WordPress image-size suffix (-300x200) and "-scaled"
	 * removed, so every rendition maps back to its original file.
	 */
	public function pathWithoutImageSize(): string {
		return (string) preg_replace( '/(?:-\d+x\d+)?(?:-scaled)?(\.[a-z0-9]{2,5})$/i', '$1', $this->path );
	}
}
