<?php
/**
 * On the source of a pull: answers the site that is pulling. That site
 * makes every call, so this one only has to be reachable from it.
 *
 *   POST /export/search  lists posts it could pull, with the ones it already has.
 *   POST /export         packages posts, with the links to other environments in each.
 *   POST /media/read     hands over one chunk of a media file.
 *   POST /pulled         records a finished pull, or one undone there, in this
 *                        site's sync map and log, as a push from here would.
 *
 * Nothing here writes posts: the pulling site does that with its own
 * import code, exactly as if this site had pushed.
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

final class ExportResponder {

	public const SEARCH_ROUTE = '/sceneshifter/v1/export/search';
	public const EXPORT_ROUTE = '/sceneshifter/v1/export';
	public const MEDIA_ROUTE  = '/sceneshifter/v1/media/read';
	public const PULLED_ROUTE = '/sceneshifter/v1/pulled';

	public const NOT_FOUND = 'not_found';
	public const INVALID   = 'invalid';

	/** Search results per page. */
	public const PER_PAGE = 20;

	/** @var callable(string, string, int, int): array{items: list<array<string, mixed>>, total: int} */
	private $search;

	/** @var callable(int): ?Package */
	private $packager;

	/** @var callable(int): ?string */
	private $ensureUuid;

	/** @var callable(int): list<Finding> */
	private $findings;

	/** @var callable(): int */
	private $clock;

	/**
	 * @param string                                                                                 $format     Package format (Plugin::PACKAGE_FORMAT).
	 * @param callable(string, string, int, int): array{items: list<array<string, mixed>>, total: int} $search     Finds posts: query, post type ('' for any), page, per page.
	 *                                                                                                            Each item: id, title, type, status, modified, path.
	 * @param callable(int): ?Package                                                                $packager   Builds a package for a post ID.
	 * @param callable(int): ?string                                                                 $ensureUuid Gives a post a Sceneshifter ID if it has none, and returns it.
	 * @param callable(int): list<Finding>                                                           $findings   Links to other environments in a post.
	 * @param MediaSource                                                                            $media      Reads media files.
	 * @param MapStore                                                                               $map        This site's sync map.
	 * @param LogStore                                                                               $log        This site's transfer log.
	 * @param (callable(): int)|null                                                                 $clock      Current Unix time.
	 */
	public function __construct(
		private readonly string $format,
		callable $search,
		callable $packager,
		callable $ensureUuid,
		callable $findings,
		private readonly MediaSource $media,
		private readonly MapStore $map,
		private readonly LogStore $log,
		?callable $clock = null
	) {
		$this->search     = $search;
		$this->packager   = $packager;
		$this->ensureUuid = $ensureUuid;
		$this->findings   = $findings;
		$this->clock      = $clock ?? 'time';
	}

	/**
	 * @param array<string, mixed> $payload {q, type, page}.
	 * @return array<string, mixed> {items, total, per_page}; each item also has here: the post's ID on the caller, if linked.
	 */
	public function search( string $sender, array $payload ): array {
		$query = is_string( $payload['q'] ?? null ) ? substr( trim( $payload['q'] ), 0, 200 ) : '';
		$type  = is_string( $payload['type'] ?? null ) ? substr( $payload['type'], 0, 40 ) : '';
		$page  = is_int( $payload['page'] ?? null ) ? max( 1, min( 1000, $payload['page'] ) ) : 1;
		$found = ( $this->search )( $query, $type, $page, self::PER_PAGE );
		$items = array();
		foreach ( $found['items'] as $item ) {
			$id      = is_int( $item['id'] ?? null ) ? $item['id'] : 0;
			$items[] = $item + array( 'here' => $this->map->get( $sender, MapEntry::POST, $id )?->remoteId );
		}
		return array(
			'items'    => $items,
			'total'    => $found['total'],
			'per_page' => self::PER_PAGE,
		);
	}

	/**
	 * @param array<string, mixed> $payload {format, ids}.
	 * @return array<string, mixed> {items: list of {id, package, findings} or {id, error}}, or {error, format}.
	 */
	public function export( string $sender, array $payload ): array {
		unset( $sender );
		if ( ( $payload['format'] ?? null ) !== $this->format ) {
			return array(
				'error'  => PreflightResponder::FORMAT_MISMATCH,
				'format' => $this->format,
			);
		}
		$ids   = is_array( $payload['ids'] ?? null ) ? array_values( array_filter( $payload['ids'], static fn( mixed $id ): bool => is_int( $id ) && $id > 0 ) ) : array();
		$items = array();
		foreach ( array_slice( array_values( array_unique( $ids ) ), 0, PreflightResponder::MAX_ITEMS ) as $id ) {
			( $this->ensureUuid )( $id );
			$package = ( $this->packager )( $id );
			if ( null === $package ) {
				$items[] = array(
					'id'    => $id,
					'error' => self::NOT_FOUND,
				);
				continue;
			}
			$items[] = array(
				'id'       => $id,
				'package'  => $package->toArray(),
				'findings' => array_map( static fn( Finding $f ): array => self::finding( $f ), ( $this->findings )( $id ) ),
			);
		}
		return array( 'items' => $items );
	}

	/**
	 * @param array<string, mixed> $payload {id, offset}.
	 * @return array<string, mixed> {file, size, sha256, uuid, title, alt, caption, description, offset, data (base64)} or {error}.
	 */
	public function mediaRead( string $sender, array $payload ): array {
		unset( $sender );
		$id     = $payload['id'] ?? null;
		$offset = $payload['offset'] ?? null;
		if ( ! is_int( $id ) || $id <= 0 || ! is_int( $offset ) || $offset < 0 ) {
			return array( 'error' => self::INVALID );
		}
		$file = $this->media->describe( $id );
		if ( null === $file ) {
			return array( 'error' => self::NOT_FOUND );
		}
		$bytes = $offset >= $file['size'] ? '' : $this->media->read( $file['path'], $offset, MediaReceiver::CHUNK );
		if ( false === $bytes ) {
			return array( 'error' => self::NOT_FOUND );
		}
		unset( $file['path'] );
		return $file + array(
			'offset' => $offset,
			'data'   => base64_encode( $bytes ),
		);
	}

	/**
	 * A pull finished on the caller, or one was undone there.
	 *
	 * @param array<string, mixed> $payload {transfer, post (here), target (there), created, hash, title, status, media: list of {source, id, uuid}, user, rewrites}
	 *                                      or {undone: true, transfer, target, media_removed, user}.
	 * @return array<string, mixed> {recorded: true} or {error}.
	 */
	public function pulled( string $sender, array $payload ): array {
		$transfer = $payload['transfer'] ?? null;
		$target   = $payload['target'] ?? null;
		$user     = is_string( $payload['user'] ?? null ) ? substr( $payload['user'], 0, 250 ) : '';
		if ( ! Uuid::isValid( $transfer ) || ! is_int( $target ) || $target <= 0 ) {
			return array( 'error' => self::INVALID );
		}
		if ( ! empty( $payload['undone'] ) ) {
			return $this->undone( $sender, $transfer, $target, $payload, $user );
		}

		$post = $payload['post'] ?? null;
		$uuid = is_int( $post ) && $post > 0 ? ( $this->ensureUuid )( $post ) : null;
		if ( ! is_int( $post ) || null === $uuid ) {
			return array( 'error' => self::NOT_FOUND );
		}
		$now      = ( $this->clock )();
		$existing = $this->map->get( $sender, MapEntry::POST, $post );
		$this->map->save( new MapEntry( $uuid, MapEntry::POST, $post, $sender, $target, $existing->linkedAt ?? $now, is_string( $payload['hash'] ?? null ) ? $payload['hash'] : null, $now ) );

		$copied = 0;
		foreach ( is_array( $payload['media'] ?? null ) ? $payload['media'] : array() as $row ) {
			if ( is_array( $row ) && is_int( $row['source'] ?? null ) && is_int( $row['id'] ?? null ) && Uuid::isValid( $row['uuid'] ?? null ) ) {
				$this->map->save( new MapEntry( $row['uuid'], MapEntry::POST, $row['source'], $sender, $row['id'], $now, null, $now ) );
				++$copied;
			}
		}

		$this->log->add(
			new LogEntry(
				0,
				$transfer,
				LogEntry::OUT,
				$sender,
				$user,
				$post,
				$target,
				is_string( $payload['title'] ?? null ) ? substr( $payload['title'], 0, 250 ) : '',
				LogEntry::DONE,
				! empty( $payload['created'] ),
				null,
				array(
					'pulled'   => true,
					'media'    => $copied,
					'rewrites' => is_int( $payload['rewrites'] ?? null ) ? $payload['rewrites'] : 0,
					'status'   => is_string( $payload['status'] ?? null ) ? $payload['status'] : '',
				),
				$now
			)
		);
		return array( 'recorded' => true );
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>
	 */
	private function undone( string $sender, string $transfer, int $target, array $payload, string $user ): array {
		$entry = $this->log->find( $transfer, LogEntry::OUT, $sender, $target );
		if ( null === $entry || LogEntry::DONE !== $entry->result ) {
			return array( 'error' => self::NOT_FOUND );
		}
		if ( $entry->created ) {
			$this->map->delete( $sender, MapEntry::POST, $entry->sourceId );
		}
		foreach ( is_array( $payload['media_removed'] ?? null ) ? $payload['media_removed'] : array() as $copy ) {
			$link = is_int( $copy ) ? $this->map->byRemote( $sender, MapEntry::POST, $copy ) : null;
			if ( null !== $link ) {
				$this->map->delete( $sender, MapEntry::POST, $link->localId );
			}
		}
		$this->log->setResult( $entry->id, LogEntry::UNDONE, '' === $user ? null : $user );
		return array( 'recorded' => true );
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function finding( Finding $f ): array {
		return array(
			'sister'    => $f->sister->id,
			'url'       => $f->url,
			'severity'  => $f->severity,
			'area'      => $f->area,
			'label'     => $f->label,
			'blockPath' => $f->blockPath,
			'blockName' => $f->blockName,
			'count'     => $f->count,
		);
	}
}
