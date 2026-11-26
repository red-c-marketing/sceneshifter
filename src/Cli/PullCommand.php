<?php
/**
 * wp sceneshifter pull and search: pulling from the command line. A pull
 * brings posts here from an environment this site can reach.
 *
 * @package RedC\Sceneshifter
 */

declare(strict_types=1);

namespace RedC\Sceneshifter\Cli;

use RedC\Sceneshifter\Admin\PullPage;
use RedC\Sceneshifter\Admin\PushPage;
use RedC\Sceneshifter\Plugin;
use RedC\Sceneshifter\Registry\Site;
use RedC\Sceneshifter\SyncMap\Uuid;
use RedC\Sceneshifter\Transfer\PreflightResponder;
use RedC\Sceneshifter\Transfer\PreviewItem;
use WP_CLI;
use WP_CLI\Utils;

final class PullCommand {

	public function __construct( private readonly Plugin $plugin ) {}

	/**
	 * Finds posts on a paired environment that could be pulled here.
	 *
	 * ## OPTIONS
	 *
	 * [<query>]
	 * : Words to look for. Leave out to list the most recently changed.
	 *
	 * --from=<site>
	 * : Registry ID of the paired environment.
	 *
	 * [--type=<post-type>]
	 * : Only this content type.
	 *
	 * [--page=<n>]
	 * : Page of results.
	 * ---
	 * default: 1
	 * ---
	 *
	 * [--format=<format>]
	 * : table, csv, json or yaml.
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp sceneshifter search about --from=live
	 *
	 * @param list<string>          $args
	 * @param array<string, string> $assoc
	 */
	public function search( array $args, array $assoc ): void {
		$from   = (string) ( $assoc['from'] ?? '' );
		$source = $this->source( $from );
		$runner = $this->plugin->pullRunner();
		if ( null === $runner ) {
			WP_CLI::error( 'Sceneshifter is inactive on this site.' );
		}
		if ( null === $source ) {
			WP_CLI::error( self::notReachable( $from ) );
		}
		$found = $runner->search( $source->id, (string) ( $args[0] ?? '' ), (string) ( $assoc['type'] ?? '' ), max( 1, (int) ( $assoc['page'] ?? 1 ) ) );
		if ( null !== $found['error'] ) {
			WP_CLI::error( $found['error'] );
		}
		Utils\format_items(
			(string) ( $assoc['format'] ?? 'table' ),
			array_map(
				static fn( array $row ): array => array(
					'id'     => $row['id'],
					'title'  => $row['title'],
					'type'   => $row['type'],
					'status' => $row['status'],
					'path'   => $row['path'],
					'here'   => null === $row['here'] ? '' : $row['here'],
				),
				$found['items']
			),
			array( 'id', 'title', 'type', 'status', 'path', 'here' )
		);
		WP_CLI::log( sprintf( '%d found on %s.', $found['total'], $source->label ) );
	}

	/**
	 * Pulls posts here from a paired environment.
	 *
	 * Shows a preview of each post first, then pulls them one at a time
	 * after you confirm. Posts with problems are skipped. Pulls are never
	 * held for review.
	 *
	 * ## OPTIONS
	 *
	 * <id>...
	 * : Post IDs on the other environment (at most 20). Find them with wp sceneshifter search.
	 *
	 * --from=<site>
	 * : Registry ID of the paired environment.
	 *
	 * [--status=<status>]
	 * : How new posts arrive.
	 * ---
	 * default: draft
	 * options:
	 *   - draft
	 *   - pending
	 *   - publish
	 *   - source
	 * ---
	 *
	 * [--existing=<status>]
	 * : What happens to the status of posts that already exist here.
	 * ---
	 * default: keep
	 * options:
	 *   - keep
	 *   - source
	 * ---
	 *
	 * [--skip-new-terms]
	 * : Don't create categories and tags this site lacks.
	 *
	 * [--overwrite-unlinked]
	 * : Overwrite posts here that have the same address but were never linked to these. Without it they are left out.
	 *
	 * [--dry-run]
	 * : Show the preview and stop.
	 *
	 * [--yes]
	 * : Pull without asking.
	 *
	 * ## EXAMPLES
	 *
	 *     wp sceneshifter pull 120 --from=live --dry-run
	 *     wp sceneshifter pull 120 121 --from=staging --existing=source --yes
	 *
	 * @param list<string>               $args
	 * @param array<string, string|bool> $assoc
	 */
	public function pull( array $args, array $assoc ): void {
		$from   = (string) ( $assoc['from'] ?? '' );
		$source = $this->source( $from );
		$runner = $this->plugin->pullRunner();
		if ( null === $runner ) {
			WP_CLI::error( 'Sceneshifter is inactive on this site.' );
		}
		if ( null === $source ) {
			WP_CLI::error( self::notReachable( $from ) );
		}
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $args ) ) ) );
		if ( count( $ids ) > PreflightResponder::MAX_ITEMS ) {
			WP_CLI::error( sprintf( 'Pull at most %d posts at a time.', PreflightResponder::MAX_ITEMS ) );
		}

		$preview = $runner->preview( $source->id, $ids );
		if ( null !== $preview['error'] ) {
			WP_CLI::error( $preview['error'] );
		}
		Utils\format_items( 'table', array_map( static fn( PreviewItem $item ): array => self::row( $item ), $preview['items'] ), array( 'post', 'title', 'here', 'would_change', 'media_to_copy', 'missing', 'problem' ) );

		$overwrite = ! empty( $assoc['overwrite-unlinked'] );
		$queue     = array();
		foreach ( $preview['items'] as $item ) {
			if ( $item->isBlocked() ) {
				continue;
			}
			if ( $item->overwritesUnlinked() && ! $overwrite ) {
				WP_CLI::warning( sprintf( '#%d %s: this site has a post at the same address (ID %d) that was never linked to it. Left out; add --overwrite-unlinked to overwrite it.', $item->package->id, $item->package->title(), (int) ( $item->target['id'] ?? 0 ) ) );
				continue;
			}
			$queue[] = $item;
		}
		if ( ! empty( $assoc['dry-run'] ) || array() === $queue ) {
			if ( array() === $queue ) {
				WP_CLI::warning( 'Nothing is left to pull.' );
			}
			return;
		}
		WP_CLI::confirm( sprintf( 'Pull %d post(s) from %s?', count( $queue ), $source->label ), $assoc );

		$options  = array(
			'new_status'         => (string) ( $assoc['status'] ?? 'draft' ),
			'existing_status'    => (string) ( $assoc['existing'] ?? 'keep' ),
			'create_terms'       => empty( $assoc['skip-new-terms'] ),
			'overwrite_unlinked' => $overwrite,
		);
		$user     = wp_get_current_user()->exists() ? wp_get_current_user()->display_name : 'WP-CLI';
		$transfer = Uuid::generate();
		$failed   = 0;
		foreach ( $queue as $item ) {
			$expected = is_int( $item->target['id'] ?? null ) ? $item->target['id'] : null;
			$result   = $runner->run( $source->id, $item->package->id, $transfer, $options, $expected, $user );
			$text     = wp_strip_all_tags( str_replace( array( '<br>', '</li><li>', '<ul class="sceneshifter-push-warnings"><li>' ), array( ' ', ' / ', ' — ' ), PullPage::resultHtml( $result, $source ) ) );
			if ( $result->ok() ) {
				WP_CLI::log( "#{$item->package->id} {$result->title}: {$text}" );
			} else {
				++$failed;
				WP_CLI::warning( "#{$item->package->id} {$result->title}: {$text}" );
			}
		}
		if ( $failed > 0 ) {
			WP_CLI::error( sprintf( '%d of %d failed.', $failed, count( $queue ) ) );
		}
		WP_CLI::success( sprintf( 'Pulled %d post(s) from %s.', count( $queue ), $source->label ) );
	}

	private function source( string $id ): ?Site {
		foreach ( PushPage::reachable( $this->plugin ) as $site ) {
			if ( $site->id === $id ) {
				return $site;
			}
		}
		return null;
	}

	private static function notReachable( string $id ): string {
		return "{$id} isn't a paired environment this site can reach. Run wp sceneshifter status to see pairings.";
	}

	/**
	 * @return array<string, string|int>
	 */
	private static function row( PreviewItem $item ): array {
		$problem = $item->error ?? '';
		if ( '' === $problem && array() !== $item->foreign ) {
			$problem = 'Links to ' . $item->foreign[0]->sister->label . ': ' . $item->foreign[0]->url;
		}
		if ( '' === $problem && $item->isBlocked() ) {
			$problem = 'Content type missing here';
		}
		return array(
			'post'          => $item->package->id,
			'title'         => $item->package->title(),
			'here'          => $item->isUpdate() ? ( $item->overwritesUnlinked() ? 'overwrite unlinked #' : 'update #' ) . (int) ( $item->target['id'] ?? 0 ) : 'new',
			'would_change'  => null === $item->changed ? 'everything' : ( array() === $item->changed ? 'nothing' : implode( ', ', array_map( array( PushPage::class, 'partLabel' ), $item->changed ) ) ),
			'media_to_copy' => count( $item->media ),
			'missing'       => count( $item->missing ),
			'problem'       => $problem,
		);
	}
}
