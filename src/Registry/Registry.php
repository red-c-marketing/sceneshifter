<?php
/**
 * The list of every environment of a client site. Identical on every site.
 *
 * @package RedC\SisterSync
 */

declare(strict_types=1);

namespace RedC\SisterSync\Registry;

use InvalidArgumentException;
use RedC\SisterSync\Url\NormalisedUrl;
use RedC\SisterSync\Url\UrlNormaliser;

final class Registry {

	/**
	 * @var array<string, Site>
	 */
	private array $sites = array();

	/**
	 * @param list<Site> $sites
	 * @param int        $version Incremented on every edit so out-of-date copies can be spotted.
	 */
	public function __construct( array $sites, public readonly int $version = 1 ) {
		$claimed = array();
		foreach ( $sites as $site ) {
			if ( isset( $this->sites[ $site->id ] ) ) {
				throw new InvalidArgumentException( sprintf( 'Site ID "%s" is used more than once.', $site->id ) );
			}
			foreach ( $site->bases() as $base ) {
				$key = $base['host'] . $base['prefix'];
				if ( isset( $claimed[ $key ] ) && $claimed[ $key ] !== $site->id ) {
					throw new InvalidArgumentException(
						sprintf( 'Sites "%s" and "%s" both claim %s.', $claimed[ $key ], $site->id, $key )
					);
				}
				$claimed[ $key ] = $site->id;
			}
			$this->sites[ $site->id ] = $site;
		}
	}

	public static function empty(): self {
		return new self( array(), 0 );
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public static function fromArray( array $data ): self {
		$sites = $data['sites'] ?? array();
		if ( ! is_array( $sites ) ) {
			throw new InvalidArgumentException( 'The registry needs a "sites" list.' );
		}
		return new self(
			array_values( array_map( static fn( $s ) => Site::fromArray( (array) $s ), $sites ) ),
			(int) ( $data['version'] ?? 1 )
		);
	}

	/**
	 * @return array{version: int, sites: list<array<string, mixed>>}
	 */
	public function toArray(): array {
		return array(
			'version' => $this->version,
			'sites'   => array_values( array_map( static fn( Site $s ) => $s->toArray(), $this->sites ) ),
		);
	}

	public function withVersion( int $version ): self {
		return new self( array_values( $this->sites ), $version );
	}

	/**
	 * @return list<Site>
	 */
	public function sites(): array {
		return array_values( $this->sites );
	}

	public function isEmpty(): bool {
		return array() === $this->sites;
	}

	public function get( string $id ): ?Site {
		return $this->sites[ $id ] ?? null;
	}

	public function matchUrl( NormalisedUrl $url ): ?Site {
		$best       = null;
		$bestLength = -1;
		foreach ( $this->sites as $site ) {
			$length = $site->matchLength( $url );
			if ( null !== $length && $length > $bestLength ) {
				$best       = $site;
				$bestLength = $length;
			}
		}
		return $best;
	}

	/**
	 * Works out which entry this site is.
	 *
	 * @param string      $homeUrl     The site's home_url().
	 * @param string|null $requestHost The Host header of the current request, or null for CLI and cron.
	 */
	public function resolveCurrent( string $homeUrl, ?string $requestHost ): Identity {
		if ( $this->isEmpty() ) {
			return new Identity( Identity::UNCONFIGURED );
		}

		$home = ( new UrlNormaliser() )->normalise( $homeUrl );
		$site = null === $home ? null : $this->matchUrl( $home );
		if ( null === $site ) {
			return new Identity( Identity::HOME_NOT_IN_REGISTRY, null, $homeUrl );
		}

		if ( null !== $requestHost && '' !== $requestHost ) {
			$hostKey = self::hostKeyFromHeader( $requestHost );
			if ( ! $site->hasHostKey( $hostKey ) ) {
				return new Identity( Identity::REQUEST_HOST_MISMATCH, $site, $requestHost );
			}
		}

		return new Identity( Identity::OK, $site );
	}

	private static function hostKeyFromHeader( string $header ): string {
		$header = strtolower( trim( $header ) );
		if ( preg_match( '/^(.+):(\d+)$/', $header, $m ) && ! str_contains( $m[1], ']' ) ) {
			return NormalisedUrl::makeHostKey( $m[1], (int) $m[2] );
		}
		return NormalisedUrl::makeHostKey( $header );
	}
}
