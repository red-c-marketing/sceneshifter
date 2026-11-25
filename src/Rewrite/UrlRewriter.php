<?php
/**
 * Moves URLs that point at the source site onto the target site, keeping
 * their path, query and fragment. Links to the target or to external sites
 * are left alone.
 *
 * It works on one value at a time (a block attribute, a block's own HTML,
 * one meta value), never on serialised data, and keeps the escaping it
 * finds: a URL written with \/ (as in JSON) stays that way.
 *
 * @package RedC\Sceneshifter
 */

declare(strict_types=1);

namespace RedC\Sceneshifter\Rewrite;

use RedC\Sceneshifter\Registry\Site;

final class UrlRewriter {

	private string $pattern;
	private string $targetBase;

	public function __construct( Site $source, Site $target ) {
		$this->targetBase = rtrim( $target->url, '/' );

		$alternatives = array();
		foreach ( $source->bases() as $base ) {
			[ $host, $port ] = array_pad( explode( ':', $base['host'], 2 ), 2, '' );
			$alternatives[]  = '(?:www\.)?' . preg_quote( $host, '~' )
				. ( '' !== $port ? ':' . preg_quote( $port, '~' ) : '(?::(?:80|443))?' )
				. self::escapedPath( $base['prefix'] );
		}
		// Longest bases first, so a site in a subfolder wins over its host.
		usort( $alternatives, static fn( string $a, string $b ): int => strlen( $b ) <=> strlen( $a ) );

		$slash         = '(?:\\\\/|/)';
		$this->pattern = '~(?<![\w.@:/-])(?:https?:)?' . $slash . $slash . '(?:' . implode( '|', $alternatives ) . ')'
			. '(?=' . $slash . '|[?#"\'\s<>)\]\\\\]|$)'
			. '(?<rest>(?:\\\\/|[^\s"\'<>()\[\]{}|\\\\^`])*)~i';
	}

	/**
	 * @param string             $text     Any text: a URL, HTML, CSS.
	 * @param string             $location Recorded with each change.
	 * @param list<Rewrite>|null $rewrites Collects the changes.
	 * @param-out list<Rewrite>   $rewrites
	 */
	public function rewriteText( string $text, string $location, ?array &$rewrites = null ): string {
		$rewrites ??= array();
		$result     = preg_replace_callback(
			$this->pattern,
			function ( array $m ) use ( $location, &$rewrites ): string {
				$escaped = str_contains( $m[0], '\\/' );
				$new     = $this->targetBase . $m['rest'];
				if ( $escaped ) {
					$new = str_replace( '/', '\\/', $this->targetBase ) . $m['rest'];
				}
				$rewrites[] = new Rewrite( Rewrite::URL, $location, $m[0], $new );
				return $new;
			},
			$text
		);
		return $result ?? $text;
	}

	/**
	 * Rewrites every string inside a value: a scalar, or arrays nested to
	 * any depth (post meta, ACF values). Keys are left alone.
	 *
	 * @param list<Rewrite>|null $rewrites
	 * @param-out list<Rewrite>   $rewrites
	 */
	public function rewriteValue( mixed $value, string $location, ?array &$rewrites = null ): mixed {
		$rewrites ??= array();
		if ( is_string( $value ) ) {
			return $this->rewriteText( $value, $location, $rewrites );
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				$value[ $key ] = $this->rewriteValue( $item, $location, $rewrites );
			}
		}
		return $value;
	}

	/**
	 * A path prefix that also matches when written with \/ escaping.
	 */
	private static function escapedPath( string $prefix ): string {
		if ( '' === $prefix ) {
			return '';
		}
		return implode( '(?:\\\\/|/)', array_map( static fn( string $p ): string => preg_quote( $p, '~' ), explode( '/', $prefix ) ) );
	}
}
