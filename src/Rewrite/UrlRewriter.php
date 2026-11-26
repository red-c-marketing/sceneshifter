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
	private ?string $filePattern = null;

	/**
	 * @param Site                  $source The site the URLs point at now.
	 * @param Site                  $target The site they should point at.
	 * @param array<string, string> $files  Media saved under another name on the target: source stem => target stem,
	 *                                      as uploads-relative paths without extension (2024/05/photo => 2024/05/photo-1).
	 *                                      Every size of the image follows.
	 */
	public function __construct( Site $source, Site $target, private readonly array $files = array() ) {
		$this->targetBase = rtrim( $target->url, '/' );
		if ( array() !== $files ) {
			$stems = array_map( static fn( string $stem ): string => preg_quote( $stem, '~' ), array_keys( $files ) );
			usort( $stems, static fn( string $a, string $b ): int => strlen( $b ) <=> strlen( $a ) );
			$this->filePattern = '~/(?<stem>' . implode( '|', $stems ) . ')(?<tail>(?:-(?:scaled|rotated))?(?:-\d+x\d+)?\.[A-Za-z0-9]{2,5})(?=[?#]|$)~';
		}

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
				$rest    = $this->moveFile( $m['rest'], $escaped );
				$new     = $this->targetBase . $rest;
				if ( $escaped ) {
					$new = str_replace( '/', '\\/', $this->targetBase ) . $rest;
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
	 * Points a URL's path at the renamed file when the target saved that
	 * image under another name.
	 */
	private function moveFile( string $rest, bool $escaped ): string {
		if ( null === $this->filePattern ) {
			return $rest;
		}
		$plain = $escaped ? str_replace( '\\/', '/', $rest ) : $rest;
		$moved = preg_replace_callback(
			$this->filePattern,
			fn( array $m ): string => '/' . $this->files[ $m['stem'] ] . $m['tail'],
			$plain,
			1
		);
		if ( null === $moved || $moved === $plain ) {
			return $rest;
		}
		return $escaped ? str_replace( '/', '\\/', $moved ) : $moved;
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
