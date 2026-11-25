<?php
/**
 * On the target: answers POST /import by writing one post from a package
 * the source has already rewritten for this site.
 *
 *   1. Works out the local post again and refuses if it isn't the one the
 *      source previewed, or if that post changed since the preflight.
 *   2. Snapshots the post before touching it.
 *   3. Writes it. If anything fails, puts the snapshot back (or removes the
 *      post it created), so a post is never left half-written.
 *   4. Records the link in the sync map, then reads the post back to check
 *      it matches the package and has no links to other environments.
 *
 * @package RedC\Sceneshifter
 */

declare(strict_types=1);

namespace RedC\Sceneshifter\Transfer;

use RedC\Sceneshifter\Log\LogEntry;
use RedC\Sceneshifter\Log\LogStore;
use RedC\Sceneshifter\Scanner\Finding;
use RedC\Sceneshifter\SyncMap\MapEntry;
use RedC\Sceneshifter\SyncMap\MapStore;
use RedC\Sceneshifter\SyncMap\Uuid;

final class ImportResponder {

	public const ROUTE = '/sceneshifter/v1/import';

	public const INVALID = 'invalid';
	public const MOVED   = 'moved';
	public const CHANGED = 'changed';
	public const FAILED  = 'failed';

	/** How a new post arrives. "source" keeps the status it has on the source. */
	public const NEW_STATUSES = array( 'draft', 'pending', 'publish', 'source' );

	/** How an existing post's status changes. */
	public const EXISTING_STATUSES = array( 'keep', 'source' );

	private const LINK_LIMIT = 20;

	/** @var callable(int): list<Finding> */
	private $findings;

	/** @var callable(): int */
	private $clock;

	/**
	 * @param string                       $format    Package format (Plugin::PACKAGE_FORMAT).
	 * @param MapStore                     $map       This site's sync map.
	 * @param TargetLookup                 $lookup    Finds local posts.
	 * @param SiteFacts                    $facts     Post types and post state.
	 * @param PostWriter                   $writer    Writes posts.
	 * @param SnapshotStore                $snapshots Where snapshots go.
	 * @param LogStore                     $log       This site's transfer log.
	 * @param callable(int): list<Finding> $findings  Links to other environments in a post.
	 * @param (callable(): int)|null       $clock     Current Unix time.
	 */
	public function __construct(
		private readonly string $format,
		private readonly MapStore $map,
		private readonly TargetLookup $lookup,
		private readonly SiteFacts $facts,
		private readonly PostWriter $writer,
		private readonly SnapshotStore $snapshots,
		private readonly LogStore $log,
		callable $findings,
		?callable $clock = null
	) {
		$this->findings = $findings;
		$this->clock    = $clock ?? 'time';
	}

	/**
	 * @param string               $sender  The verified sister.
	 * @param array<string, mixed> $payload {format, transfer, package, target, expect_hash, author, options, user}.
	 * @return array<string, mixed> The result, or {error, message?} with nothing changed.
	 */
	public function import( string $sender, array $payload ): array {
		if ( ( $payload['format'] ?? null ) !== $this->format ) {
			return array(
				'error'  => PreflightResponder::FORMAT_MISMATCH,
				'format' => $this->format,
			);
		}
		$package  = Package::fromArray( $payload['package'] ?? null );
		$transfer = $payload['transfer'] ?? null;
		if ( null === $package || null === $package->uuid || ! Uuid::isValid( $transfer ) ) {
			return array( 'error' => self::INVALID );
		}
		if ( ! $this->facts->postTypeExists( $package->type() ) ) {
			return array( 'error' => PreflightResponder::POST_TYPE_MISSING );
		}

		$resolved = ( new Resolver( $sender, $this->map, $this->lookup ) )->resolve( $package->manifest() );
		$localId  = $resolved['target']['id'] ?? null;
		if ( array_key_exists( 'target', $payload ) && ( is_int( $payload['target'] ) ? $payload['target'] : null ) !== $localId ) {
			return array( 'error' => self::MOVED );
		}

		$metaKeys = array_map( 'strval', array_keys( $package->meta ) );
		$now      = ( $this->clock )();
		$before   = null;
		$snapshot = null;
		if ( null !== $localId ) {
			$before = $this->facts->state( $localId, $metaKeys );
			if ( null === $before ) {
				return array( 'error' => self::INVALID );
			}
			$expect = $payload['expect_hash'] ?? null;
			if ( is_string( $expect ) && ContentHash::overall( $before['parts'] ) !== $expect ) {
				return array( 'error' => self::CHANGED );
			}
			$previous = $this->map->get( $sender, MapEntry::POST, $localId );
			$snapshot = new Snapshot( 0, $transfer, $sender, $localId, false, $this->writer->snapshot( $localId ) + array( 'map' => null === $previous ? null : self::mapRow( $previous ) ), $now );
			$snapshot = $snapshot->withId( $this->snapshots->save( $snapshot ) );
		}

		$options = is_array( $payload['options'] ?? null ) ? $payload['options'] : array();
		$user    = is_string( $payload['user'] ?? null ) ? substr( $payload['user'], 0, 250 ) : '';
		try {
			$id = $this->writer->write(
				$localId,
				$package,
				array(
					'status'       => self::status( $options, $package, $before['status'] ?? null ),
					'author'       => is_int( $payload['author'] ?? null ) && $payload['author'] > 0 ? $payload['author'] : null,
					'keep_date'    => null !== $localId,
					'create_terms' => (bool) ( $options['create_terms'] ?? true ),
				)
			);
		} catch ( \Throwable $e ) {
			$restored = false;
			if ( null !== $snapshot ) {
				try {
					$this->writer->restore( $snapshot->postId, $snapshot->data );
					$restored = true;
				} catch ( \Throwable $ignored ) {
					$restored = false;
				}
			}
			$this->log->add( new LogEntry( 0, $transfer, LogEntry::IN, $sender, $user, $package->id, $localId, $package->title(), LogEntry::FAILED, false, $e->getMessage(), array( 'restored' => $restored ), $now ) );
			return array(
				'error'    => self::FAILED,
				'message'  => $e->getMessage(),
				'restored' => $restored,
			);
		}

		if ( null === $snapshot ) {
			$snapshot = new Snapshot( 0, $transfer, $sender, $id, true, array(), $now );
			$snapshot = $snapshot->withId( $this->snapshots->save( $snapshot ) );
		}

		$after    = $this->facts->state( $id, $metaKeys );
		$hash     = null === $after ? null : ContentHash::overall( $after['parts'] );
		$existing = $this->map->get( $sender, MapEntry::POST, $id );
		$this->map->save( new MapEntry( $package->uuid, MapEntry::POST, $id, $sender, $package->id, $existing->linkedAt ?? $now, $hash, $now ) );

		$findings = ( $this->findings )( $id );
		$mismatch = null === $after ? array() : ContentHash::changed( ContentHash::parts( $package, $metaKeys ), $after['parts'] );
		$this->log->add(
			new LogEntry(
				0,
				$transfer,
				LogEntry::IN,
				$sender,
				$user,
				$package->id,
				$id,
				$package->title(),
				LogEntry::DONE,
				null === $localId,
				null,
				array(
					'snapshot'    => $snapshot->id,
					'hash'        => $hash,
					'meta_keys'   => $metaKeys,
					'mismatch'    => $mismatch,
					'links_total' => count( $findings ),
				),
				$now
			)
		);
		return array(
			'id'          => $id,
			'created'     => null === $localId,
			'snapshot'    => $snapshot->id,
			'status'      => $after['status'] ?? '',
			'hash'        => $hash,
			'mismatch'    => $mismatch,
			'links'       => array_map(
				static fn( Finding $f ): array => array(
					'site'  => $f->sister->label,
					'url'   => $f->url,
					'where' => $f->locationText(),
				),
				array_slice( $findings, 0, self::LINK_LIMIT )
			),
			'links_total' => count( $findings ),
			'urls'        => $this->writer->urls( $id ),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function mapRow( MapEntry $entry ): array {
		return array(
			'uuid'      => $entry->uuid,
			'type'      => $entry->objectType,
			'local'     => $entry->localId,
			'sister'    => $entry->sister,
			'remote'    => $entry->remoteId,
			'linked_at' => $entry->linkedAt,
			'hash'      => $entry->hash,
			'synced_at' => $entry->syncedAt,
		);
	}

	/**
	 * @param array<string, mixed> $row From mapRow().
	 */
	public static function mapEntry( array $row ): ?MapEntry {
		if ( ! is_string( $row['uuid'] ?? null ) || ! is_int( $row['local'] ?? null ) || ! is_int( $row['remote'] ?? null ) || ! is_string( $row['sister'] ?? null ) ) {
			return null;
		}
		return new MapEntry(
			$row['uuid'],
			is_string( $row['type'] ?? null ) ? $row['type'] : MapEntry::POST,
			$row['local'],
			$row['sister'],
			$row['remote'],
			is_int( $row['linked_at'] ?? null ) ? $row['linked_at'] : 0,
			is_string( $row['hash'] ?? null ) ? $row['hash'] : null,
			is_int( $row['synced_at'] ?? null ) ? $row['synced_at'] : null
		);
	}

	/**
	 * The status the post should end up with.
	 *
	 * @param array<string, mixed> $options  From the push form.
	 * @param string|null          $existing The post's status here, for an update.
	 */
	public static function status( array $options, Package $package, ?string $existing ): string {
		$source = (string) ( $package->fields['post_status'] ?? 'draft' );
		$source = in_array( $source, array( 'publish', 'draft', 'pending', 'private', 'future' ), true ) ? $source : 'draft';
		if ( null !== $existing ) {
			return 'source' === ( $options['existing_status'] ?? 'keep' ) ? $source : $existing;
		}
		$choice = $options['new_status'] ?? 'draft';
		$choice = is_string( $choice ) && in_array( $choice, self::NEW_STATUSES, true ) ? $choice : 'draft';
		return 'source' === $choice ? $source : $choice;
	}
}
