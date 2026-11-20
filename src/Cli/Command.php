<?php
/**
 * Finds links to sister environments from the command line.
 *
 * @package RedC\SisterSync
 */

declare(strict_types=1);

namespace RedC\SisterSync\Cli;

use RedC\SisterSync\Check\RestController;
use RedC\SisterSync\Plugin;
use RedC\SisterSync\Scanner\Finding;
use WP_CLI;
use WP_CLI\Utils;

final class Command {

	private const FIELDS = array( 'post_id', 'title', 'severity', 'sister', 'url', 'location', 'count' );

	public function __construct( private readonly Plugin $plugin ) {}

	/**
	 * Checks specific posts for links to sister environments.
	 *
	 * ## OPTIONS
	 *
	 * <id>...
	 * : One or more post IDs.
	 *
	 * [--format=<format>]
	 * : table, csv, json or yaml.
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp sistersync check 12 34
	 *
	 * @param list<string>          $args
	 * @param array<string, string> $assoc
	 */
	public function check( array $args, array $assoc ): void {
		$rows = array();
		foreach ( $args as $id ) {
			$post = get_post( (int) $id );
			if ( ! $post ) {
				WP_CLI::warning( "Post {$id} not found." );
				continue;
			}
			$rows = array_merge( $rows, self::rows( $post, $this->plugin->postScanner()->scan( $post ) ) );
		}
		$this->output( $rows, $assoc['format'] ?? 'table' );
	}

	/**
	 * Scans every post of the enabled content types.
	 *
	 * ## OPTIONS
	 *
	 * [--post_type=<types>]
	 * : Comma-separated post types. Defaults to the enabled content types.
	 *
	 * [--format=<format>]
	 * : table, csv, json or yaml.
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp sistersync scan
	 *     wp sistersync scan --post_type=page --format=csv > sister-links.csv
	 *
	 * @param list<string>          $args
	 * @param array<string, string> $assoc
	 */
	public function scan( array $args, array $assoc ): void {
		$types = isset( $assoc['post_type'] )
			? array_filter( array_map( 'trim', explode( ',', $assoc['post_type'] ) ) )
			: $this->plugin->options()->postTypes();

		$rows    = array();
		$scanned = 0;
		$page    = 1;
		do {
			$ids = get_posts(
				array(
					'post_type'      => $types,
					'post_status'    => 'any',
					'posts_per_page' => 100,
					'paged'          => $page,
					'fields'         => 'ids',
					'orderby'        => 'ID',
					'order'          => 'ASC',
				)
			);
			foreach ( $ids as $id ) {
				$post = get_post( (int) $id );
				if ( $post ) {
					$rows = array_merge( $rows, self::rows( $post, $this->plugin->postScanner()->scan( $post ) ) );
					++$scanned;
				}
			}
			++$page;
		} while ( count( $ids ) === 100 );

		$this->output( $rows, $assoc['format'] ?? 'table' );
		if ( 'table' === ( $assoc['format'] ?? 'table' ) ) {
			WP_CLI::log( sprintf( 'Scanned %d posts.', $scanned ) );
		}
	}

	/**
	 * @param list<array<string, mixed>> $rows
	 */
	private function output( array $rows, string $format ): void {
		if ( array() === $rows && 'table' === $format ) {
			WP_CLI::success( 'No sister links found.' );
			return;
		}
		Utils\format_items( $format, $rows, self::FIELDS );
	}

	/**
	 * @param list<Finding> $findings
	 * @return list<array<string, mixed>>
	 */
	private static function rows( \WP_Post $post, array $findings ): array {
		return array_map(
			static fn( Finding $f ): array => array(
				'post_id'  => $post->ID,
				'title'    => get_the_title( $post ),
				'severity' => $f->severity,
				'sister'   => $f->sister->label,
				'url'      => $f->url,
				'location' => $f->locationText(),
				'count'    => $f->count,
			),
			$findings
		);
	}
}
