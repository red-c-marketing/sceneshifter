<?php
/**
 * wp sceneshifter map: the sync map from the command line.
 *
 * @package RedC\Sceneshifter
 */

declare(strict_types=1);

namespace RedC\Sceneshifter\Cli;

use RedC\Sceneshifter\Plugin;
use RedC\Sceneshifter\SyncMap\LinkExisting;
use RedC\Sceneshifter\SyncMap\MatchResult;
use RedC\Sceneshifter\SyncMap\SyncMap;
use WP_CLI;
use WP_CLI\Utils;

final class MapCommand {

	public function __construct( private readonly Plugin $plugin ) {}

	/**
	 * Matches existing content with a paired environment and links it.
	 *
	 * Fetches the other site's list, suggests matches by content type and
	 * address (media by file name and size), shows a summary, and links
	 * every suggested match after you confirm. Items that need a decision
	 * are listed and left alone.
	 *
	 * ## OPTIONS
	 *
	 * --with=<site>
	 * : Registry ID of the paired environment.
	 *
	 * [--dry-run]
	 * : Show the matches without linking anything.
	 *
	 * [--yes]
	 * : Link without asking.
	 *
	 * ## EXAMPLES
	 *
	 *     wp sceneshifter map link-existing --with=live --dry-run
	 *
	 * @subcommand link-existing
	 *
	 * @param list<string>          $args
	 * @param array<string, string> $assoc
	 */
	public function linkExisting( array $args, array $assoc ): void {
		$syncMap = $this->syncMap();
		$sister  = (string) ( $assoc['with'] ?? '' );
		$site    = $this->plugin->registry()->get( $sister );
		if ( null === $site || null === $this->plugin->sisters()->pairings()->get( $sister ) ) {
			WP_CLI::error( "{$sister} isn't a paired environment. Run wp sceneshifter status to see pairings." );
		}

		$linker = $syncMap->linkExisting();
		$linker->start( $sister, time() );
		do {
			$step = $linker->step( $sister, time() );
			if ( null !== $step['error'] ) {
				WP_CLI::error( $step['error'] );
			}
			if ( '' !== $step['type'] ) {
				WP_CLI::log( sprintf( 'Fetched %s page %d of %d', $step['type'], $step['page'], $step['pages'] ) );
			}
		} while ( ! $step['done'] );

		$state = $linker->cache()->state( $sister );
		foreach ( $state['skipped'] ?? array() as $type => $why ) {
			WP_CLI::warning( "{$type} skipped: it isn't enabled on {$site->label}." );
		}

		$review = (array) $linker->review( $sister );
		Utils\format_items(
			'table',
			array_map(
				static fn( MatchResult $r ): array => array(
					'type'       => $r->type,
					'linked'     => $r->linked,
					'suggested'  => count( $r->proposals ),
					'decide'     => count( $r->attention ),
					'only_here'  => count( $r->onlyHere ),
					'only_there' => count( $r->onlyThere ),
				),
				$review
			),
			array( 'type', 'linked', 'suggested', 'decide', 'only_here', 'only_there' )
		);
		foreach ( $review as $result ) {
			foreach ( $result->attention as $row ) {
				WP_CLI::log( sprintf( 'Needs a decision: %s %d "%s" (%s, %s)', $result->type, $row['local']->id, $row['local']->title, $row['local']->key, $row['reason'] ) );
			}
		}

		$pairs = LinkExisting::proposedPairs( $review );
		if ( array() === $pairs ) {
			WP_CLI::success( 'Nothing new to link.' );
			return;
		}
		if ( isset( $assoc['dry-run'] ) ) {
			WP_CLI::success( count( $pairs ) . ' suggested matches. Nothing was linked (dry run).' );
			return;
		}
		WP_CLI::confirm( sprintf( 'Link %d suggested matches with %s?', count( $pairs ), $site->label ), $assoc );

		$linked = 0;
		$failed = 0;
		foreach ( array_chunk( $pairs, LinkExisting::LINK_BATCH ) as $batch ) {
			$result = $linker->link( $sister, $batch, time() );
			if ( null !== $result['error'] ) {
				WP_CLI::error( "{$result['error']} {$linked} linked before the error." );
			}
			$linked += $result['linked'];
			foreach ( $result['failed'] as $failure ) {
				++$failed;
				WP_CLI::warning( "Post {$failure['local']}: {$failure['message']}" );
			}
		}
		WP_CLI::success( "{$linked} linked, {$failed} refused." );
	}

	private function syncMap(): SyncMap {
		$syncMap = $this->plugin->syncMap();
		if ( null === $syncMap ) {
			WP_CLI::error( 'Sceneshifter is inactive on this site.' );
		}
		return $syncMap;
	}
}
