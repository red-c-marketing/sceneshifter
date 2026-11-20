<?php
/**
 * One environment of a client site (live, staging, a dev box, ...).
 *
 * @package RedC\SisterSync
 */

declare(strict_types=1);

namespace RedC\SisterSync\Registry;

use InvalidArgumentException;
use RedC\SisterSync\Url\NormalisedUrl;
use RedC\SisterSync\Url\UrlNormaliser;

final class Site {

	public const ROLES = array( 'dev', 'staging', 'uat', 'live', 'other' );

	private const DEFAULT_COLOURS = array(
		'live'    => '#d63638',
		'staging' => '#dba617',
		'uat'     => '#2271b1',
		'dev'     => '#00a32a',
		'other'   => '#787c82',
	);

	/**
	 * Host key and path prefix pairs this site answers on.
	 *
	 * @var list<array{host: string, prefix: string}>
	 */
	private array $bases = array();

	/**
	 * @param string       $id         Stable short ID, e.g. "live" or "dev-andrew".
	 * @param string       $label      Human label shown in the UI.
	 * @param string       $role       One of self::ROLES.
	 * @param string       $url        Primary base URL.
	 * @param list<string> $aliases    Other hosts or base URLs that count as this site.
	 * @param bool         $isPublic   Whether sister sites can make requests to it.
	 * @param string       $colour     Admin bar badge colour (hex).
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $label,
		public readonly string $role,
		public readonly string $url,
		public readonly array $aliases = array(),
		public readonly bool $isPublic = true,
		private readonly string $colour = ''
	) {
		if ( ! preg_match( '/^[a-z0-9][a-z0-9-]{0,31}$/', $id ) ) {
			throw new InvalidArgumentException( sprintf( 'Site ID "%s" must be 1-32 lower-case letters, digits or hyphens.', $id ) );
		}
		if ( '' === trim( $label ) ) {
			throw new InvalidArgumentException( sprintf( 'Site "%s" needs a label.', $id ) );
		}
		if ( ! in_array( $role, self::ROLES, true ) ) {
			throw new InvalidArgumentException( sprintf( 'Site "%s" has unknown role "%s".', $id, $role ) );
		}
		if ( '' !== $colour && ! preg_match( '/^#[0-9a-f]{3}(?:[0-9a-f]{3})?$/i', $colour ) ) {
			throw new InvalidArgumentException( sprintf( 'Site "%s" colour must be a hex value like #d63638.', $id ) );
		}

		$normaliser = new UrlNormaliser();
		foreach ( array_merge( array( $url ), $aliases ) as $base ) {
			$candidate  = preg_match( '#^[a-z][a-z0-9+.-]*://#i', $base ) ? $base : 'https://' . $base;
			$normalised = $normaliser->normalise( $candidate );
			if ( null === $normalised || $normalised->isRelative() ) {
				throw new InvalidArgumentException( sprintf( 'Site "%s" has an invalid URL or alias "%s".', $id, $base ) );
			}
			$this->bases[] = array(
				'host'   => (string) $normalised->hostKey(),
				'prefix' => '/' === $normalised->path ? '' : $normalised->path,
			);
		}
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public static function fromArray( array $data ): self {
		$aliases = $data['aliases'] ?? array();
		if ( ! is_array( $aliases ) ) {
			throw new InvalidArgumentException( 'Site aliases must be a list.' );
		}
		return new self(
			(string) ( $data['id'] ?? '' ),
			(string) ( $data['label'] ?? '' ),
			(string) ( $data['role'] ?? '' ),
			(string) ( $data['url'] ?? '' ),
			array_values( array_map( 'strval', array_filter( $aliases, static fn( $a ) => '' !== trim( (string) $a ) ) ) ),
			(bool) ( $data['public'] ?? true ),
			(string) ( $data['colour'] ?? '' )
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return array(
			'id'      => $this->id,
			'label'   => $this->label,
			'role'    => $this->role,
			'url'     => $this->url,
			'aliases' => $this->aliases,
			'public'  => $this->isPublic,
			'colour'  => $this->colour,
		);
	}

	public function colour(): string {
		return '' !== $this->colour ? $this->colour : self::DEFAULT_COLOURS[ $this->role ];
	}

	/**
	 * @return list<array{host: string, prefix: string}>
	 */
	public function bases(): array {
		return $this->bases;
	}

	public function hasHostKey( string $hostKey ): bool {
		foreach ( $this->bases as $base ) {
			if ( $base['host'] === $hostKey ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Length of the longest matching path prefix, or null if the URL is not on
	 * this site. Longer prefixes win when several sites share a host.
	 */
	public function matchLength( NormalisedUrl $url ): ?int {
		$hostKey = $url->hostKey();
		if ( null === $hostKey ) {
			return null;
		}
		$best = null;
		foreach ( $this->bases as $base ) {
			if ( $base['host'] !== $hostKey ) {
				continue;
			}
			$prefix = $base['prefix'];
			if ( '' === $prefix || $url->path === $prefix || str_starts_with( $url->path, $prefix . '/' ) ) {
				$best = max( $best ?? -1, strlen( $prefix ) );
			}
		}
		return $best;
	}
}
