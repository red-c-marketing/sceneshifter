<?php
/**
 * Unit tests cover the pure PHP core (registry, URLs, scanner) and need no
 * WordPress. Integration tests with the WordPress test suite come later.
 */

declare(strict_types=1);

require dirname( __DIR__ ) . '/vendor/autoload.php';

// Translation functions used by message builders that the unit tests
// cover. They return the English text unchanged.
if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = 'default' ): string { // phpcs:ignore
		return $text;
	}
}
if ( ! function_exists( '_n' ) ) {
	function _n( string $single, string $plural, int $number, string $domain = 'default' ): string { // phpcs:ignore
		return 1 === $number ? $single : $plural;
	}
}

// The database class DbNonceStore type-hints. Tests extend it with an
// in-memory fake; the real one comes with WordPress.
if ( ! class_exists( 'wpdb' ) ) {
	class wpdb { // phpcs:ignore
		/** @var string */
		public $prefix = 'wp_';

		/**
		 * @param string $query
		 * @param mixed  ...$args
		 * @return string
		 */
		public function prepare( $query, ...$args ) {
			return $query;
		}

		/**
		 * @param string $query
		 * @return int|bool
		 */
		public function query( $query ) {
			return false;
		}
	}
}
