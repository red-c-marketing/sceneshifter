<?php
/**
 * Turns a raw URL string into a NormalisedUrl, or null if it is not an
 * http(s) URL we can classify.
 *
 * @package RedC\SisterSync
 */

declare(strict_types=1);

namespace RedC\SisterSync\Url;

final class UrlNormaliser {

	public function normalise( string $raw ): ?NormalisedUrl {
		$url = trim( $raw );
		if ( '' === $url ) {
			return null;
		}

		$url = str_replace( '\\/', '/', $url );
		$url = html_entity_decode( $url, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		if ( str_starts_with( $url, '//' ) ) {
			$url = 'https:' . $url;
		}

		// Root-relative: always points at the site it is on.
		if ( str_starts_with( $url, '/' ) ) {
			$parts = parse_url( $url );
			if ( false === $parts ) {
				return null;
			}
			return new NormalisedUrl(
				$raw,
				null,
				null,
				null,
				self::normalisePath( $parts['path'] ?? '/' ),
				$parts['query'] ?? '',
				$parts['fragment'] ?? ''
			);
		}

		$parts = parse_url( $url );
		if ( false === $parts || empty( $parts['host'] ) ) {
			return null;
		}

		$scheme = strtolower( $parts['scheme'] ?? '' );
		if ( 'http' !== $scheme && 'https' !== $scheme ) {
			return null;
		}

		return new NormalisedUrl(
			$raw,
			$scheme,
			strtolower( rtrim( $parts['host'], '.' ) ),
			isset( $parts['port'] ) ? (int) $parts['port'] : null,
			self::normalisePath( $parts['path'] ?? '/' ),
			$parts['query'] ?? '',
			$parts['fragment'] ?? ''
		);
	}

	private static function normalisePath( string $path ): string {
		$path = rawurldecode( $path );
		if ( '' === $path ) {
			return '/';
		}
		$trimmed = rtrim( $path, '/' );
		return '' === $trimmed ? '/' : $trimmed;
	}
}
