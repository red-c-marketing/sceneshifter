<?php
/**
 * On the site that received a push: undoes it from the snapshot taken just
 * before. A post the push created goes to the bin; an existing post gets
 * its fields, custom fields and terms back, and the sync map its previous
 * link. A push still held for review just loses its copy. Media the push
 * copied is deleted unless something else uses it by now.
 *
 * Refuses, unless forced, when the post has been edited since the push, so
 * those edits aren't lost without a warning.
 *
 * Started here from the log, or by the pushing site through POST /rollback.
 *
 * @package RedC\Sceneshifter
 */

declare(strict_types=1);

namespace RedC\Sceneshifter\Transfer;

use RedC\Sceneshifter\Log\LogEntry;
use RedC\Sceneshifter\Log\LogStore;
use RedC\Sceneshifter\SyncMap\MapEntry;
use RedC\Sceneshifter\SyncMap\MapStore;

final class Rollback {

	public const ROUTE = '/sceneshifter/v1/rollback';

	public const NOT_FOUND     = 'not_found';
	public const ALREADY       = 'already_undone';
	public const CHANGED_SINCE = 'changed_since';
	public const FAILED        = 'failed';

	public function __construct(
		private readonly MapStore $map,
		private readonly SnapshotStore $snapshots,
		private readonly PostWriter $writer,
		private readonly SiteFacts $facts,
		private readonly LogStore $log,
		private readonly CopiedMedia $media
	) {}

	/**
	 * @param string $sister   The site that pushed.
	 * @param string $transfer The batch.
	 * @param int    $postId   The post here.
	 * @param bool   $force    Undo even if the post was edited since.
	 * @param string $user     Who asked, for the log.
	 * @return array<string, mixed> {undone: true, trashed: bool, media_removed: list<int>} or {error, message?}.
	 */
	public function undo( string $sister, string $transfer, int $postId, bool $force, string $user ): array {
		$entry = $this->log->find( $transfer, LogEntry::IN, $sister, $postId );
		if ( null !== $entry && LogEntry::UNDONE === $entry->result ) {
			return array( 'error' => self::ALREADY );
		}
		if ( null !== $entry && LogEntry::HELD === $entry->result ) {
			return $this->dropHeld( $entry, $sister, $transfer, $postId, $user );
		}
		$snapshot = $this->snapshots->forTransfer( $transfer, $postId );
		if ( null === $entry || LogEntry::DONE !== $entry->result || null === $snapshot || $snapshot->sister !== $sister ) {
			return array( 'error' => self::NOT_FOUND );
		}

		if ( ! $force ) {
			$keys  = array_values( array_filter( (array) ( $entry->details['meta_keys'] ?? array() ), 'is_string' ) );
			$state = $this->facts->state( $postId, $keys );
			$then  = $entry->details['hash'] ?? null;
			if ( null !== $state && is_string( $then ) && ContentHash::overall( $state['parts'] ) !== $then ) {
				return array( 'error' => self::CHANGED_SINCE );
			}
		}

		try {
			if ( $snapshot->isNew ) {
				$this->writer->discard( $postId, true );
				$this->map->delete( $sister, MapEntry::POST, $postId );
			} else {
				$this->writer->restore( $postId, $snapshot->data );
				$previous = is_array( $snapshot->data['map'] ?? null ) ? ImportResponder::mapEntry( $snapshot->data['map'] ) : null;
				if ( null === $previous ) {
					$this->map->delete( $sister, MapEntry::POST, $postId );
				} else {
					$this->map->save( $previous );
				}
			}
		} catch ( \Throwable $e ) {
			return array(
				'error'   => self::FAILED,
				'message' => $e->getMessage(),
			);
		}

		$this->log->setResult( $entry->id, LogEntry::UNDONE, '' === $user ? null : $user );
		return array(
			'undone'        => true,
			'trashed'       => $snapshot->isNew,
			'media_removed' => $this->media->remove( $sister, $transfer, $entry->sourceId, $postId ),
		);
	}

	/**
	 * Undoes a push that is still waiting for review: deletes its copy, if
	 * a later push hasn't replaced it, and the media only it used.
	 *
	 * @return array<string, mixed>
	 */
	private function dropHeld( LogEntry $entry, string $sister, string $transfer, int $postId, string $user ): array {
		$heldId = is_int( $entry->details['held'] ?? null ) ? $entry->details['held'] : 0;
		$held   = $heldId > 0 ? $this->writer->held( $heldId ) : null;
		if ( null !== $held && $held['post'] === $postId && ( $held['record']['transfer'] ?? null ) === $transfer ) {
			try {
				$this->writer->discard( $heldId, false );
			} catch ( \Throwable $e ) {
				return array(
					'error'   => self::FAILED,
					'message' => $e->getMessage(),
				);
			}
		}
		$this->log->setResult( $entry->id, LogEntry::UNDONE, '' === $user ? null : $user );
		return array(
			'undone'        => true,
			'trashed'       => false,
			'media_removed' => $this->media->remove( $sister, $transfer, $entry->sourceId, $heldId ),
		);
	}
}
