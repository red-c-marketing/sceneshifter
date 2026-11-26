<?php
/**
 * wp sceneshifter push, log and rollback: pushing from the command line, for
 * testing and scripted releases. Log and rollback cover pulls too.
 *
 * @package RedC\Sceneshifter
 */

declare(strict_types=1);

namespace RedC\Sceneshifter\Cli;

use RedC\Sceneshifter\Admin\HeldNotices;
use RedC\Sceneshifter\Admin\PushPage;
use RedC\Sceneshifter\Log\LogEntry;
use RedC\Sceneshifter\Plugin;
use RedC\Sceneshifter\SyncMap\Uuid;
use RedC\Sceneshifter\Transfer\PreflightResponder;
use RedC\Sceneshifter\Transfer\PreviewItem;
use RedC\Sceneshifter\Transfer\Rollback;
use WP_CLI;
use WP_CLI\Utils;

final class PushCommand {

	public function __construct( private readonly Plugin $plugin ) {}

	/**
	 * Pushes posts to a paired environment.
	 *
	 * Shows a preview of each post first, then pushes them one at a time
	 * after you confirm. Posts with problems are skipped.
	 *
	 * ## OPTIONS
	 *
	 * <id>...
	 * : Post IDs on this site (at most 20).
	 *
	 * --to=<site>
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
	 * : What happens to the status of posts that already exist there.
	 * ---
	 * default: keep
	 * options:
	 *   - keep
	 *   - source
	 * ---
	 *
	 * [--skip-new-terms]
	 * : Don't create categories and tags the other site lacks.
	 *
	 * [--overwrite-unlinked]
	 * : Overwrite posts there that have the same address but were never linked to these. Without it they are left out.
	 *
	 * [--dry-run]
	 * : Show the preview and stop.
	 *
	 * [--yes]
	 * : Push without asking.
	 *
	 * ## EXAMPLES
	 *
	 *     wp sceneshifter push 12 15 --to=staging --dry-run
	 *     wp sceneshifter push 12 --to=live --status=source --yes
	 *
	 * @param list<string>               $args
	 * @param array<string, string|bool> $assoc
	 */
	public function push( array $args, array $assoc ): void {
		$to      = (string) ( $assoc['to'] ?? '' );
		$target  = $this->plugin->registry()->get( $to );
		$preview = $this->plugin->pushPreview();
		$runner  = $this->plugin->pushRunner();
		if ( null === $preview || null === $runner ) {
			WP_CLI::error( 'Sceneshifter is inactive on this site.' );
		}
		if ( null === $target || null === $this->plugin->sisters()->pairings()->get( $to ) ) {
			WP_CLI::error( "{$to} isn't a paired environment. Run wp sceneshifter status to see pairings." );
		}
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $args ) ) ) );
		if ( count( $ids ) > PreflightResponder::MAX_ITEMS ) {
			WP_CLI::error( sprintf( 'Push at most %d posts at a time.', PreflightResponder::MAX_ITEMS ) );
		}

		$built = $preview->build( $to, $ids );
		if ( null !== $built['error'] ) {
			WP_CLI::error( $built['error'] );
		}
		Utils\format_items( 'table', array_map( static fn( PreviewItem $item ): array => self::row( $item ), $built['items'] ), array( 'post', 'title', 'there', 'would_change', 'media_to_copy', 'missing', 'problem' ) );

		$overwrite = ! empty( $assoc['overwrite-unlinked'] );
		$queue     = array();
		foreach ( $built['items'] as $item ) {
			if ( $item->isBlocked() ) {
				continue;
			}
			if ( $item->overwritesUnlinked() && ! $overwrite ) {
				WP_CLI::warning( sprintf( '#%d %s: %s has a post at the same address (ID %d) that was never linked to it. Left out; add --overwrite-unlinked to overwrite it.', $item->package->id, $item->package->title(), $target->label, (int) ( $item->target['id'] ?? 0 ) ) );
				continue;
			}
			$queue[] = $item;
		}
		if ( ! empty( $assoc['dry-run'] ) || array() === $queue ) {
			if ( array() === $queue ) {
				WP_CLI::warning( 'Nothing is left to push.' );
			}
			return;
		}
		WP_CLI::confirm( sprintf( 'Push %d post(s) to %s?', count( $queue ), $target->label ), $assoc );

		$options  = array(
			'new_status'      => (string) ( $assoc['status'] ?? 'draft' ),
			'existing_status' => (string) ( $assoc['existing'] ?? 'keep' ),
			'create_terms'    => empty( $assoc['skip-new-terms'] ),
		);
		$options['overwrite_unlinked'] = $overwrite;
		$user     = wp_get_current_user()->exists() ? wp_get_current_user()->display_name : 'WP-CLI';
		$transfer = Uuid::generate();
		$failed   = 0;
		foreach ( $queue as $item ) {
			$expected = is_int( $item->target['id'] ?? null ) ? $item->target['id'] : null;
			$result   = $runner->run( $to, $item->package->id, $transfer, $options, $expected, $user );
			$text     = wp_strip_all_tags( str_replace( array( '<br>', '</li><li>', '<ul class="sceneshifter-push-warnings"><li>' ), array( ' ', ' / ', ' — ' ), PushPage::resultHtml( $result, $target ) ) );
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
		WP_CLI::success( sprintf( 'Pushed %d post(s) to %s.', count( $queue ), $target->label ) );
	}

	/**
	 * Lists recent pushes from and to this site.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<n>]
	 * : How many.
	 * ---
	 * default: 20
	 * ---
	 *
	 * [--format=<format>]
	 * : table, csv, json or yaml.
	 * ---
	 * default: table
	 * ---
	 *
	 * @param list<string>          $args
	 * @param array<string, string> $assoc
	 */
	public function log( array $args, array $assoc ): void {
		$log = $this->plugin->log();
		if ( null === $log ) {
			WP_CLI::error( 'Sceneshifter is inactive on this site.' );
		}
		Utils\format_items(
			(string) ( $assoc['format'] ?? 'table' ),
			array_map(
				static fn( LogEntry $e ): array => array(
					'id'        => $e->id,
					'when'      => gmdate( 'Y-m-d H:i', $e->at ),
					'direction' => LogEntry::OUT === $e->direction ? 'to ' . $e->sister : 'from ' . $e->sister,
					'who'       => $e->user,
					'post'      => LogEntry::OUT === $e->direction ? $e->sourceId : $e->targetId,
					'title'     => $e->title,
					'result'    => $e->result . ( ! empty( $e->details['pulled'] ) ? ' (pulled)' : '' ) . ( LogEntry::DONE === $e->result ? ( 'held' === ( $e->details['status'] ?? null ) ? ' (held for review there)' : ( $e->created ? ' (created)' : ' (updated)' ) ) : '' ),
					'message'   => (string) $e->message,
				),
				$log->recent( max( 1, (int) ( $assoc['limit'] ?? 20 ) ) )
			),
			array( 'id', 'when', 'direction', 'who', 'post', 'title', 'result', 'message' )
		);
	}

	/**
	 * Undoes one push from the log.
	 *
	 * ## OPTIONS
	 *
	 * <log-id>
	 * : The entry's ID, from wp sceneshifter log.
	 *
	 * [--force]
	 * : Undo even if the post was edited since the push.
	 *
	 * @param list<string>               $args
	 * @param array<string, string|bool> $assoc
	 */
	public function rollback( array $args, array $assoc ): void {
		$entry = $this->plugin->log()?->get( (int) ( $args[0] ?? 0 ) );
		if ( null === $entry ) {
			WP_CLI::error( 'No log entry with that ID.' );
		}
		$force = ! empty( $assoc['force'] );
		$user  = wp_get_current_user()->exists() ? wp_get_current_user()->display_name : 'WP-CLI';
		if ( LogEntry::OUT === $entry->direction ) {
			$result  = $this->plugin->pushRunner()?->undo( $entry, $force, $user );
			$code    = null === $result ? Rollback::FAILED : $result['code'];
			$detail  = $result['message'] ?? null;
			$removed = $result['removed'] ?? 0;
		} else {
			$reply   = null === $entry->targetId ? array( 'error' => Rollback::NOT_FOUND ) : ( $this->plugin->rollback()?->undo( $entry->sister, $entry->transfer, $entry->targetId, $force, $user ) ?? array( 'error' => Rollback::FAILED ) );
			$code    = empty( $reply['undone'] ) ? (string) ( $reply['error'] ?? Rollback::FAILED ) : null;
			$detail  = is_string( $reply['message'] ?? null ) ? $reply['message'] : null;
			$removed = is_array( $reply['media_removed'] ?? null ) ? count( $reply['media_removed'] ) : 0;
			if ( null === $code && ! empty( $entry->details['pulled'] ) ) {
				$this->plugin->pullRunner()?->undone( $entry, $reply, $user );
			}
		}
		match ( $code ) {
			null                    => WP_CLI::success( 0 === $removed ? 'Undone.' : sprintf( 'Undone. Deleted %d copied file(s) that nothing else used.', $removed ) ),
			Rollback::CHANGED_SINCE => WP_CLI::error( 'The post was edited since the push. Run again with --force to undo anyway.' ),
			Rollback::ALREADY       => WP_CLI::error( 'That push was already undone.' ),
			Rollback::NOT_FOUND     => WP_CLI::error( 'There is nothing to undo for that entry.' ),
			default                 => WP_CLI::error( 'Could not undo it' . ( null === $detail ? '.' : ": {$detail}" ) ),
		};
	}

	/**
	 * Puts a pushed update that is waiting for review live.
	 *
	 * ## OPTIONS
	 *
	 * <post-id>
	 * : The published post the update is for (or the waiting copy).
	 *
	 * @param list<string>          $args
	 * @param array<string, string> $assoc
	 */
	public function apply( array $args, array $assoc ): void {
		$this->held( $args, 'apply' );
	}

	/**
	 * Throws away a pushed update that is waiting for review.
	 *
	 * ## OPTIONS
	 *
	 * <post-id>
	 * : The published post the update is for (or the waiting copy).
	 *
	 * @param list<string>          $args
	 * @param array<string, string> $assoc
	 */
	public function discard( array $args, array $assoc ): void {
		$this->held( $args, 'discard' );
	}

	/**
	 * @param list<string> $args
	 */
	private function held( array $args, string $op ): void {
		$held = $this->plugin->heldUpdates();
		if ( null === $held ) {
			WP_CLI::error( 'Sceneshifter is inactive on this site.' );
		}
		$id     = (int) ( $args[0] ?? 0 );
		$heldId = null !== $held->find( $id ) ? $id : $held->heldFor( $id );
		if ( null === $heldId ) {
			WP_CLI::error( "No pushed update is waiting for post {$id}." );
		}
		$user   = wp_get_current_user()->exists() ? wp_get_current_user()->display_name : 'WP-CLI';
		$result = 'apply' === $op ? $held->apply( $heldId, $user ) : $held->discard( $heldId, $user );
		if ( isset( $result['error'] ) ) {
			WP_CLI::error( HeldNotices::outcomeText( 'held_' . $result['error'], (string) ( $result['message'] ?? '' ) ) );
		}
		WP_CLI::success( HeldNotices::outcomeText( 'apply' === $op ? 'applied' : 'discarded', '' ) );
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
			$problem = 'Content type missing there';
		}
		return array(
			'post'          => $item->package->id,
			'title'         => $item->package->title(),
			'there'         => $item->isUpdate() ? ( $item->overwritesUnlinked() ? 'overwrite unlinked #' : 'update #' ) . (int) ( $item->target['id'] ?? 0 ) : 'new',
			'would_change'  => null === $item->changed ? 'everything' : ( array() === $item->changed ? 'nothing' : implode( ', ', array_map( array( PushPage::class, 'partLabel' ), $item->changed ) ) ),
			'media_to_copy' => count( $item->media ),
			'missing'       => count( $item->missing ),
			'problem'       => $problem,
		);
	}
}
