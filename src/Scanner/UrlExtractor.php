<?php
/**
 * Pulls absolute and protocol-relative URLs out of any text: HTML attributes,
 * srcset lists, CSS url(), shortcode attributes, JSON block attributes and
 * plain prose.
 *
 * Root-relative URLs are ignored on purpose: they always point at the site
 * they are on, so they can never be a sister reference.
 *
 * @package RedC\SisterSync
 */

declare(strict_types=1);

namespace RedC\SisterSync\Scanner;

final class UrlExtractor {

	/**
	 * "//" or "http(s)://", a host, an optional port, then a path that stops
	 * at whitespace, quotes, brackets or a backslash. The look-behind stops a
	 * match starting inside another scheme such as ftp:// or file:///.
	 */
	private const PATTERN = '~(?<![\w.+:/@-])(?:https?:)?//[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)*(?::\d{1,5})?(?:[/?#][^\s"\'<>()\[\]{}|\\\\^`]*)?~i';

	/**
	 * @return list<string> Every URL found, in order, duplicates included.
	 */
	public function extract( string $text ): array {
		if ( '' === $text || ! str_contains( $text, '//' ) ) {
			return array();
		}

		// Undo the escaping Gutenberg and JSON apply inside block comments.
		$text = (string) preg_replace_callback(
			'/\\\\u([0-9a-fA-F]{4})/',
			static fn( array $m ): string => mb_chr( (int) hexdec( $m[1] ), 'UTF-8' ),
			$text
		);
		$text = str_replace( '\\/', '/', $text );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		if ( ! preg_match_all( self::PATTERN, $text, $matches ) ) {
			return array();
		}

		$urls = array();
		foreach ( $matches[0] as $match ) {
			$url = rtrim( $match, '.,;:!?*\'"' );
			if ( strlen( $url ) > 2 ) {
				$urls[] = $url;
			}
		}
		return $urls;
	}
}
