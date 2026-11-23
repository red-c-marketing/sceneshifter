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
use RedC\SisterSync\SiteScan\ReportFilters;
use RedC\SisterSync\SiteScan\ResultStore;
use RedC\SisterSync\SiteScan\ScanState;
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
	 * [--save]
	 * : Run the full site scan (posts, synced patterns, templates, menus,
	 * widgets, ACF options, theme settings) and save it as the report shown
	 * in Sister Sync → Report. Ignores --post_type.
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
	 *     wp sistersync scan --save
	 *
	 * @param list<string>          $args
	 * @param array<string, string> $assoc
	 */
	public function scan( array $args, array $assoc ): void {
		if ( isset( $assoc['save'] ) ) {
			$this->saveScan( $assoc['format'] ?? 'table' );
			return;
		}

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
	 * Runs every batch of a site scan in this process, then prints what it
	 * stored. Useful where background jobs don't run.
	 */
	private function saveScan( string $format ): void {
		$scanner = $this->plugin->siteScanner();
		$state   = $scanner->start( get_current_user_id(), ScanState::TRIGGER_CLI );
		$bar     = Utils\make_progress_bar( 'Scanning posts', max( 1, $state->total ) );

		$done = 0;
		while ( null !== $state && $state->isRunning() ) {
			$state = $scanner->advance( $state->id );
			if ( null !== $state ) {
				$bar->tick( max( 0, $state->processed - $done ) );
				$done = $state->processed;
			}
		}
		$bar->finish();

		if ( null === $state || ScanState::COMPLETE !== $state->status ) {
			WP_CLI::error( 'The scan did not complete: ' . ( $state->error ?? 'unknown error' ) );
		}

		$store = new ResultStore();
		$rows  = array();
		foreach ( $store->all( $state->id, new ReportFilters() ) as $row ) {
			$rows[] = array(
				'post_id'  => (int) $row['object_id'],
				'title'    => (string) $row['object_title'],
				'severity' => (string) $row['severity'],
				'sister'   => $this->plugin->registry()->get( (string) $row['sister_id'] )->label ?? (string) $row['sister_id'],
				'url'      => (string) $row['url'],
				'location' => (string) $row['location'],
				'count'    => (int) $row['hits'],
			);
		}
		$this->output( $rows, $format );

		if ( 'table' === $format ) {
			$summary = $store->summary( $state->id );
			WP_CLI::success(
				sprintf(
					'Saved. %d posts scanned; %d items with sister links (%d errors, %d warnings).',
					$state->processed,
					$summary['objects'],
					$summary['error'],
					$summary['warning']
				)
			);
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
