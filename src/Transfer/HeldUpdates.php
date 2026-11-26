<?php
/**
 * On a site that holds pushed updates for review (Settings → Push): puts a
 * waiting copy live, or throws it away.
 *
 * Applying writes the pushed version onto the published post exactly as an
 * immediate push would have: snapshot first, restored if the write fails,
 * then the sync map and the log entry are brought up to date, so the push
 * can still be undone afterwards. Changes made to the copy itself aren't
 * kept, since the copy only shows what was pushed.
 *
 * @package RedC\Sceneshifter
 */

declare(strict_types=1);

namespace RedC\Sceneshifter\Transfer;

use RedC\Sceneshifter\Log\LogEntry;
use RedC\Sceneshifter\Log\LogStore;
use RedC\Sceneshifter\SyncMap\MapEntry;
use RedC\Sceneshifter\SyncMap\MapStore;
use RedC\Sceneshifter\SyncMap\Uuid;

final class HeldUpdates {

	public const NOT_FOUND = 'not_found';
	public const GONE      = 'gone';
	public const FAILED    = 'failed';

	/** @var callable(): int */
	private $clock;

	public function __construct(
		private readonly MapStore $map,
		private readonly SnapshotStore $snapshots,
		private readonly PostWriter $writer,
		private readonly SiteFacts $facts,
		private readonly LogStore $log,
		private readonly CopiedMedia $media,
		?callable $clock = null
	) {
		$this->clock = $clock ?? 'time';
	}

	/**
	 * @return array{post: int, record: array<string, mixed>}|null
	 */
	public function find( int $heldId ): ?array {
		return $this->writer->held( $heldId );
	}

	public function heldFor( int $postId ): ?int {
		return $this->writer->heldFor( $postId );
	}

	/**
	 * @param int    $heldId The waiting copy.
	 * @param string $user   Who applied it, for the log.
	 * @return array{post: int}|array{error: string, message?: string}
	 */
	public function apply( int $heldId, string $user ): array {
		$held = $this->writer->held( $heldId );
		$rec  = $held['record'] ?? array();
		$pkg  = Package::fromArray( $rec['package'] ?? null );
		if ( null === $held || null === $pkg || null === $pkg->uuid || ! Uuid::isValid( $rec['transfer'] ?? null ) || ! is_string( $rec['sister'] ?? null ) ) {
			return array( 'error' => self::NOT_FOUND );
		}
		$postId   = $held['post'];
		$transfer = $rec['transfer'];
		$sister   = $rec['sister'];
		$options  = is_array( $rec['options'] ?? null ) ? $rec['options'] : array();
		$metaKeys = array_map( 'strval', array_keys( $pkg->meta ) );
		$before   = $this->facts->state( $postId, $metaKeys );
		if ( null === $before ) {
			return array( 'error' => self::GONE );
		}

		$now      = ( $this->clock )();
		$previous = $this->map->get( $sister, MapEntry::POST, $postId );
		$snapshot = new Snapshot( 0, $transfer, $sister, $postId, false, $this->writer->snapshot( $postId ) + array( 'map' => null === $previous ? null : ImportResponder::mapRow( $previous ) ), $now );
		$snapshot = $snapshot->withId( $this->snapshots->save( $snapshot ) );
		try {
			$this->writer->write(
				$postId,
				$pkg,
				array(
					'status'       => ImportResponder::status( $options, $pkg, $before['status'] ),
					'author'       => null,
					'keep_date'    => true,
					'create_terms' => (bool) ( $options['create_terms'] ?? true ),
				)
			);
		} catch ( \Throwable $e ) {
			try {
				$this->writer->restore( $postId, $snapshot->data );
			} catch ( \Throwable $ignored ) {
				unset( $ignored );
			}
			return array(
				'error'   => self::FAILED,
				'message' => $e->getMessage(),
			);
		}

		$after = $this->facts->state( $postId, $metaKeys );
		$hash  = null === $after ? null : ContentHash::overall( $after['parts'] );
		$this->map->save( new MapEntry( $pkg->uuid, MapEntry::POST, $postId, $sister, $pkg->id, $previous->linkedAt ?? $now, $hash, $now ) );
		$this->writer->discard( $heldId, false );

		$entry = $this->log->find( $transfer, LogEntry::IN, $sister, $postId );
		if ( null !== $entry && LogEntry::HELD === $entry->result ) {
			$this->log->setResult( $entry->id, LogEntry::DONE, null );
			$this->log->setDetails(
				$entry->id,
				array(
					'snapshot'   => $snapshot->id,
					'hash'       => $hash,
					'meta_keys'  => $metaKeys,
					'mismatch'   => null === $after ? array() : ContentHash::changed( ContentHash::parts( $pkg, $metaKeys ), $after['parts'] ),
					'applied_by' => $user,
					'applied_at' => $now,
				) + $entry->details
			);
		}
		return array( 'post' => $postId );
	}

	/**
	 * Throws a waiting copy away, with any media only it used.
	 *
	 * @return array{post: int}|array{error: string, message?: string}
	 */
	public function discard( int $heldId, string $user ): array {
		$held = $this->writer->held( $heldId );
		$rec  = $held['record'] ?? array();
		if ( null === $held ) {
			return array( 'error' => self::NOT_FOUND );
		}
		try {
			$this->writer->discard( $heldId, false );
		} catch ( \Throwable $e ) {
			return array(
				'error'   => self::FAILED,
				'message' => $e->getMessage(),
			);
		}
		$transfer = is_string( $rec['transfer'] ?? null ) ? $rec['transfer'] : '';
		$sister   = is_string( $rec['sister'] ?? null ) ? $rec['sister'] : '';
		$entry    = '' === $transfer ? null : $this->log->find( $transfer, LogEntry::IN, $sister, $held['post'] );
		if ( null !== $entry && LogEntry::HELD === $entry->result ) {
			$this->log->setResult( $entry->id, LogEntry::UNDONE, '' === $user ? null : $user );
			$this->media->remove( $sister, $transfer, $entry->sourceId, $heldId );
		}
		return array( 'post' => $held['post'] );
	}
}
