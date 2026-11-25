<?php
/**
 * Finds links to sister environments from the command line.
 *
 * @package RedC\Sceneshifter
 */

declare(strict_types=1);

namespace RedC\Sceneshifter\Cli;

use RedC\Sceneshifter\Admin\PairingTab;
use RedC\Sceneshifter\Check\RestController;
use RedC\Sceneshifter\Pairing\Sisters;
use RedC\Sceneshifter\Pairing\SiteKeys;
use RedC\Sceneshifter\Plugin;
use RedC\Sceneshifter\Scanner\Finding;
use RedC\Sceneshifter\SiteScan\ReportFilters;
use RedC\Sceneshifter\SiteScan\ResultStore;
use RedC\Sceneshifter\SiteScan\ScanState;
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
	 *     wp sceneshifter check 12 34
	 *
	 * @param list<string>          $args
	 * @param array<string, string> $assoc
	 */
	public function check( array $args, array $assoc ): void {
		$this->requireActive();
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
	 * in Sceneshifter → Report. Ignores --post_type.
	 *
	 * [--format=<format>]
	 * : table, csv, json or yaml.
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp sceneshifter scan
	 *     wp sceneshifter scan --post_type=page --format=csv > sister-links.csv
	 *     wp sceneshifter scan --save
	 *
	 * @param list<string>          $args
	 * @param array<string, string> $assoc
	 */
	public function scan( array $args, array $assoc ): void {
		$this->requireActive();
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
	 * Lists the other sites, whether each is paired, and for paired sites
	 * asks them for their status over a signed request.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table, csv, json or yaml.
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp sceneshifter status
	 *     wp sceneshifter status --url=https://staging.example.com
	 *
	 * @param list<string>          $args
	 * @param array<string, string> $assoc
	 */
	public function status( array $args, array $assoc ): void {
		$format   = $assoc['format'] ?? 'table';
		$identity = $this->plugin->identity();
		$sisters  = $this->plugin->sisters();
		$self     = $sisters->self();
		if ( null === $self ) {
			WP_CLI::error( sprintf( 'Sceneshifter is inactive on this site (%s). Check the site list in Sceneshifter → Settings.', $identity->status ) );
		}

		$keys = $sisters->keys();
		if ( 'table' === $format ) {
			WP_CLI::log( sprintf( 'This site: %s (%s), site list version %d.', $self->label, $self->id, $this->plugin->registry()->version ) );
			if ( null !== $keys && ( $keys->needsNewKey() || SiteKeys::NO_SALTS === $keys->status() ) ) {
				WP_CLI::warning( Sisters::keyProblem( $keys->status() ) );
			} elseif ( null !== $keys && null !== $keys->fingerprint() ) {
				WP_CLI::log( 'Key fingerprint: ' . $keys->fingerprint() );
			} else {
				WP_CLI::log( 'No key yet. It is made the first time you open Sceneshifter → Settings → Pairing.' );
			}
		}

		$rows = array();
		foreach ( $this->plugin->registry()->sites() as $site ) {
			if ( $site->id === $self->id ) {
				continue;
			}
			$pairing = $sisters->pairings()->get( $site->id );
			$row     = array(
				'site'             => $site->id,
				'label'            => $site->label,
				'public'           => $site->isPublic ? 'yes' : 'no',
				'paired'           => null === $pairing ? 'no' : 'yes',
				'fingerprint'      => null === $pairing ? '' : $pairing->fingerprint(),
				'status'           => '',
				'ms'               => '',
				'plugin_version'   => '',
				'registry_version' => '',
				'message'          => '',
			);
			if ( null !== $pairing ) {
				$result        = $sisters->status( $site->id );
				$row['status'] = $result->ok() ? 'ok' : $result->code;
				$row['ms']     = (string) $result->elapsedMs;
				if ( $result->ok() ) {
					$row['plugin_version']   = (string) ( $result->data['plugin_version'] ?? '' );
					$row['registry_version'] = (string) ( $result->data['registry_version'] ?? '' );
					$row['message']          = implode( ' ', PairingTab::compatibility( $result->data, $this->plugin->registry()->version, $site->label ) );
				} else {
					$row['message'] = $result->message;
				}
			}
			$rows[] = $row;
		}

		if ( array() === $rows && 'table' === $format ) {
			WP_CLI::log( 'There are no other sites in the list.' );
			return;
		}
		Utils\format_items( $format, $rows, array_keys( $rows[0] ?? array( 'site' => '' ) ) );
	}

	private function requireActive(): void {
		if ( ! $this->plugin->identity()->isActive() ) {
			WP_CLI::error( 'Sceneshifter is inactive on this site. Check the site list in Sceneshifter → Settings.' );
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
					'Saved. %d posts scanned; %d items link to other environments (%d errors, %d warnings).',
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
			WP_CLI::success( 'No links to other environments found.' );
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
