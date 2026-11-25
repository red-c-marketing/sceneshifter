<?php
/**
 * On the source: pushes one post to a sister.
 *
 *   1. Packages the post and runs a fresh preflight, so nothing acts on a
 *      stale preview. Stops if the post is blocked, or if what it matches
 *      on the target changed since the preview the person confirmed.
 *   2. Copies media the target doesn't have, in chunks.
 *   3. Rewrites the package with the target's IDs (unmatched IDs become 0)
 *      and sends it to POST /import, where the target snapshots, writes and
 *      verifies it, and restores the snapshot if anything fails.
 *   4. Records the link in this site's sync map.
 *
 * Each call is one post, so a batch is a series of calls and one failure
 * doesn't affect the others.
 *
 * @package RedC\Sceneshifter
 */

declare(strict_types=1);

namespace RedC\Sceneshifter\Transfer;

use RedC\Sceneshifter\Log\LogEntry;
use RedC\Sceneshifter\Log\LogStore;
use RedC\Sceneshifter\Registry\Registry;
use RedC\Sceneshifter\Registry\Site;
use RedC\Sceneshifter\SyncMap\MapEntry;
use RedC\Sceneshifter\SyncMap\MapStore;
use RedC\Sceneshifter\SyncMap\Uuid;
use RedC\Sceneshifter\Transport\Transport;

final class PushRunner {

	/** Tries per chunk before giving up on a file. */
	private const TRIES = 3;

	/** @var callable(int): ?Package */
	private $packager;

	/** @var callable(int): ?string */
	private $ensureUuid;

	/** @var callable(): int */
	private $clock;

	/**
	 * @param Registry                $registry   The site list.
	 * @param Transport|null          $transport  Signed transport; null when inactive.
	 * @param string                  $format     Package format (Plugin::PACKAGE_FORMAT).
	 * @param PushPreview             $preview    Preflight and rewriting.
	 * @param callable(int): ?Package $packager   Builds a package for a post ID.
	 * @param callable(int): ?string  $ensureUuid Gives a post a Sceneshifter ID if it has none, and returns it.
	 * @param MapStore                $map        This site's sync map.
	 * @param MediaSource             $media      Reads media files.
	 * @param LogStore                $log        This site's transfer log.
	 * @param (callable(): int)|null  $clock      Current Unix time.
	 */
	public function __construct(
		private readonly Registry $registry,
		private readonly ?Transport $transport,
		private readonly string $format,
		private readonly PushPreview $preview,
		callable $packager,
		callable $ensureUuid,
		private readonly MapStore $map,
		private readonly MediaSource $media,
		private readonly LogStore $log,
		?callable $clock = null
	) {
		$this->packager   = $packager;
		$this->ensureUuid = $ensureUuid;
		$this->clock      = $clock ?? 'time';
	}

	/**
	 * @param string               $targetId Registry ID of the sister.
	 * @param int                  $postId   The post here.
	 * @param string               $transfer UUID shared by every post in the batch.
	 * @param array<string, mixed> $options  new_status, existing_status, create_terms.
	 * @param int|null|false       $expected The post the preview said it would update (null for new); false to skip the check.
	 * @param string               $user     Who started it, for both sites' logs.
	 */
	public function run( string $targetId, int $postId, string $transfer, array $options = array(), int|null|false $expected = false, string $user = '' ): PushResult {
		$result = $this->push( $targetId, $postId, $transfer, $options, $expected, $user );
		$this->log->add(
			new LogEntry(
				0,
				$transfer,
				LogEntry::OUT,
				$targetId,
				$user,
				$postId,
				$result->targetId,
				$result->title,
				$result->ok() ? LogEntry::DONE : LogEntry::FAILED,
				$result->created,
				$result->error,
				array(
					'media'    => $result->mediaCopied,
					'rewrites' => $result->rewrites,
					'mismatch' => $result->mismatch,
					'links'    => $result->linksTotal,
					'missing'  => count( $result->missing ),
					'status'   => $result->status,
				),
				( $this->clock )()
			)
		);
		return $result;
	}

	/**
	 * Asks the receiving site to undo one post from a push logged here.
	 *
	 * @param bool $force Undo even if the post was edited there since.
	 * @return array{ok: bool, code: string|null, message: string|null}
	 */
	public function undo( LogEntry $entry, bool $force, string $user ): array {
		$target = $this->registry->get( $entry->sister );
		if ( LogEntry::OUT !== $entry->direction || LogEntry::DONE !== $entry->result || null === $entry->targetId || null === $target || null === $this->transport ) {
			return array(
				'ok'      => false,
				'code'    => Rollback::NOT_FOUND,
				'message' => null,
			);
		}
		$reply = $this->transport->send(
			$target->id,
			'POST',
			Rollback::ROUTE,
			array(
				'transfer' => $entry->transfer,
				'post'     => $entry->targetId,
				'force'    => $force,
				'user'     => $user,
			)
		);
		if ( ! $reply->ok() ) {
			return array(
				'ok'      => false,
				'code'    => Rollback::FAILED,
				'message' => $reply->message,
			);
		}
		if ( empty( $reply->data['undone'] ) ) {
			$code = is_string( $reply->data['error'] ?? null ) ? $reply->data['error'] : Rollback::FAILED;
			if ( Rollback::ALREADY === $code ) {
				$this->log->setResult( $entry->id, LogEntry::UNDONE, null );
			}
			return array(
				'ok'      => false,
				'code'    => $code,
				'message' => is_string( $reply->data['message'] ?? null ) ? $reply->data['message'] : null,
			);
		}
		if ( $entry->created ) {
			$this->map->delete( $target->id, MapEntry::POST, $entry->sourceId );
		}
		$this->log->setResult( $entry->id, LogEntry::UNDONE, '' === $user ? null : $user );
		return array(
			'ok'      => true,
			'code'    => null,
			'message' => null,
		);
	}

	/**
	 * @param array<string, mixed> $options
	 */
	private function push( string $targetId, int $postId, string $transfer, array $options, int|null|false $expected, string $user ): PushResult {
		$target    = $this->registry->get( $targetId );
		$transport = $this->transport;
		if ( null === $target || null === $transport ) {
			return PushResult::failed( $postId, '', __( 'That environment isn\'t in the site list, or Sceneshifter is inactive here.', 'sceneshifter' ) );
		}
		( $this->ensureUuid )( $postId );
		$package = ( $this->packager )( $postId );
		if ( null === $package ) {
			return PushResult::failed( $postId, '', __( 'This post can\'t be pushed.', 'sceneshifter' ) );
		}
		$title = $package->title();

		$reply = $this->preview->preflight( $target, array( $package ) );
		$answer = $reply['answers'][0] ?? null;
		if ( null !== $reply['error'] || null === $answer ) {
			return PushResult::failed( $postId, $title, $reply['error'] ?? __( 'The other site didn\'t answer for this post.', 'sceneshifter' ) );
		}
		$item = $this->preview->item( $package, $answer, $target );
		if ( $item->isBlocked() ) {
			return PushResult::failed( $postId, $title, self::blockedReason( $item, $target ) );
		}
		$targetPost = is_int( $item->target['id'] ?? null ) ? $item->target['id'] : null;
		if ( false !== $expected && $expected !== $targetPost ) {
			/* translators: %s: environment name. */
			return PushResult::failed( $postId, $title, sprintf( __( 'What this post matches on %s has changed since the preview. Preview it again.', 'sceneshifter' ), $target->label ) );
		}

		$uploaded = array();
		$uuids    = array();
		$renamed  = array();
		foreach ( $item->media as $file ) {
			$copied = $this->upload( $transport, $target, $file['id'] );
			if ( isset( $copied['error'] ) ) {
				return PushResult::failed(
					$postId,
					$title,
					/* translators: 1: file name, 2: reason. */
					sprintf( __( 'Couldn\'t copy %1$s: %2$s Nothing else was changed.', 'sceneshifter' ), '' === $file['file'] ? $file['title'] : $file['file'], $copied['error'] ),
					count( $uploaded )
				);
			}
			$uploaded[ $file['id'] ] = $copied['id'];
			$uuids[ $file['id'] ]    = $copied['uuid'];
			if ( '' !== $file['file'] && $copied['file'] !== $file['file'] ) {
				$renamed[] = array(
					'from' => $file['file'],
					'to'   => $copied['file'],
				);
			}
		}

		$final  = $this->preview->item( $package, $answer, $target, $uploaded, true );
		$result = $transport->send(
			$target->id,
			'POST',
			ImportResponder::ROUTE,
			array(
				'format'      => $this->format,
				'transfer'    => $transfer,
				'package'     => ( $final->rewritten ?? $package )->toArray(),
				'target'      => $targetPost,
				'expect_hash' => $item->target['hash'] ?? null,
				'author'      => $item->author['id'] ?? null,
				'user'        => $user,
				'options'     => array(
					'new_status'      => (string) ( $options['new_status'] ?? 'draft' ),
					'existing_status' => (string) ( $options['existing_status'] ?? 'keep' ),
					'create_terms'    => (bool) ( $options['create_terms'] ?? true ),
				),
			)
		);
		if ( ! $result->ok() ) {
			return PushResult::failed(
				$postId,
				$title,
				/* translators: 1: environment name, 2: reason. */
				sprintf( __( 'Lost contact with %1$s while saving (%2$s). Preview again to see whether the post arrived.', 'sceneshifter' ), $target->label, $result->message ),
				count( $uploaded )
			);
		}
		$data = $result->data;
		if ( isset( $data['error'] ) || ! is_int( $data['id'] ?? null ) ) {
			return PushResult::failed( $postId, $title, self::importError( $data, $target, $package ), count( $uploaded ) );
		}

		$now      = ( $this->clock )();
		$existing = $this->map->get( $target->id, MapEntry::POST, $postId );
		if ( null !== $package->uuid ) {
			$this->map->save( new MapEntry( $package->uuid, MapEntry::POST, $postId, $target->id, $data['id'], $existing->linkedAt ?? $now, ContentHash::overall( ContentHash::parts( $package ) ), $now ) );
		}
		foreach ( $uploaded as $source => $copy ) {
			$this->map->save( new MapEntry( $uuids[ $source ], MapEntry::POST, $source, $target->id, $copy, $now, null, $now ) );
		}

		return new PushResult(
			$postId,
			$title,
			null,
			$data['id'],
			(bool) ( $data['created'] ?? false ),
			(string) ( $data['status'] ?? '' ),
			array_map( 'strval', is_array( $data['urls'] ?? null ) ? array_intersect_key( $data['urls'], array_flip( array( 'edit', 'view' ) ) ) : array() ),
			count( $uploaded ),
			count( $final->rewrites ),
			array_values( array_map( 'strval', is_array( $data['mismatch'] ?? null ) ? $data['mismatch'] : array() ) ),
			self::links( $data['links'] ?? null ),
			is_int( $data['links_total'] ?? null ) ? $data['links_total'] : 0,
			$renamed,
			$final->missing,
			is_int( $data['snapshot'] ?? null ) ? $data['snapshot'] : null
		);
	}

	/**
	 * Copies one file to the target.
	 *
	 * @return array{id: int, file: string, uuid: string}|array{error: string}
	 */
	private function upload( Transport $transport, Site $target, int $attachmentId ): array {
		$file = $this->media->describe( $attachmentId );
		if ( null === $file ) {
			return array( 'error' => __( 'the file is missing on this site.', 'sceneshifter' ) );
		}
		$upload = Uuid::generate();
		$offset = 0;
		$rounds = 3 + 3 * intdiv( $file['size'], MediaReceiver::CHUNK );
		do {
			if ( --$rounds < 0 ) {
				/* translators: %s: environment name. */
				return array( 'error' => sprintf( __( '%s kept asking for parts of the file it already had.', 'sceneshifter' ), $target->label ) );
			}
			$bytes = $this->media->read( $file['path'], $offset, MediaReceiver::CHUNK );
			if ( false === $bytes ) {
				return array( 'error' => __( 'the file can\'t be read on this site.', 'sceneshifter' ) );
			}
			$reply = null;
			for ( $try = 1; $try <= self::TRIES && null === $reply; $try++ ) {
				$sent  = $transport->send(
					$target->id,
					'POST',
					MediaReceiver::CHUNK_ROUTE,
					array(
						'upload' => $upload,
						'offset' => $offset,
						'data'   => base64_encode( $bytes ),
					)
				);
				$reply = $sent->ok() ? $sent->data : null;
			}
			if ( null === $reply ) {
				/* translators: %s: environment name. */
				return array( 'error' => sprintf( __( 'lost contact with %s.', 'sceneshifter' ), $target->label ) );
			}
			if ( MediaReceiver::OFFSET === ( $reply['error'] ?? null ) && is_int( $reply['size'] ?? null ) ) {
				$offset = $reply['size'];
				continue;
			}
			if ( isset( $reply['error'] ) || ! is_int( $reply['size'] ?? null ) ) {
				return array( 'error' => self::mediaError( $reply, $target ) );
			}
			$offset = $reply['size'];
		} while ( $offset < $file['size'] );

		$sent  = $transport->send(
			$target->id,
			'POST',
			MediaReceiver::COMMIT_ROUTE,
			array(
				'upload'      => $upload,
				'source'      => $attachmentId,
				'file'        => $file['file'],
				'size'        => $file['size'],
				'sha256'      => $file['sha256'],
				'uuid'        => $file['uuid'],
				'title'       => $file['title'],
				'alt'         => $file['alt'],
				'caption'     => $file['caption'],
				'description' => $file['description'],
			)
		);
		$reply = $sent->ok() ? $sent->data : null;
		if ( null === $reply ) {
			/* translators: %s: environment name. */
			return array( 'error' => sprintf( __( 'lost contact with %s.', 'sceneshifter' ), $target->label ) );
		}
		if ( isset( $reply['error'] ) || ! is_int( $reply['id'] ?? null ) ) {
			return array( 'error' => self::mediaError( $reply, $target ) );
		}
		return array(
			'id'   => $reply['id'],
			'file' => is_string( $reply['file'] ?? null ) ? $reply['file'] : '',
			'uuid' => $file['uuid'],
		);
	}

	/**
	 * @param array<string, mixed> $reply
	 */
	private static function mediaError( array $reply, Site $target ): string {
		if ( is_string( $reply['message'] ?? null ) && '' !== $reply['message'] ) {
			return $reply['message'];
		}
		return match ( $reply['error'] ?? null ) {
			/* translators: %s: environment name. */
			MediaReceiver::TOO_LARGE  => sprintf( __( 'the file is larger than %s accepts.', 'sceneshifter' ), $target->label ),
			MediaReceiver::INCOMPLETE => __( 'the file arrived incomplete.', 'sceneshifter' ),
			/* translators: %s: environment name. */
			default                   => sprintf( __( '%s refused it.', 'sceneshifter' ), $target->label ),
		};
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private static function importError( array $data, Site $target, Package $package ): string {
		return match ( $data['error'] ?? null ) {
			PreflightResponder::FORMAT_MISMATCH   => PushPreview::formatMismatch( $target ),
			/* translators: 1: environment name, 2: content type. */
			PreflightResponder::POST_TYPE_MISSING => sprintf( __( '%1$s doesn\'t have the %2$s content type.', 'sceneshifter' ), $target->label, $package->type() ),
			/* translators: %s: environment name. */
			ImportResponder::MOVED                => sprintf( __( 'What this post matches on %s has changed since the preview. Preview it again.', 'sceneshifter' ), $target->label ),
			/* translators: %s: environment name. */
			ImportResponder::CHANGED              => sprintf( __( 'The post on %s was edited moments ago. Preview it again.', 'sceneshifter' ), $target->label ),
			ImportResponder::FAILED               => ! empty( $data['restored'] ) || ! isset( $data['restored'] )
				/* translators: 1: environment name, 2: reason. */
				? sprintf( __( '%1$s couldn\'t save the post (%2$s), so it was put back as it was.', 'sceneshifter' ), $target->label, (string) ( $data['message'] ?? '' ) )
				/* translators: 1: environment name, 2: reason. */
				: sprintf( __( '%1$s couldn\'t save the post (%2$s) and couldn\'t put it back as it was. Check it there.', 'sceneshifter' ), $target->label, (string) ( $data['message'] ?? '' ) ),
			/* translators: %s: environment name. */
			default                               => sprintf( __( '%s couldn\'t read what was sent.', 'sceneshifter' ), $target->label ),
		};
	}

	private static function blockedReason( PreviewItem $item, Site $target ): string {
		if ( null !== $item->error ) {
			return $item->error;
		}
		if ( array() !== $item->foreign ) {
			/* translators: 1: environment name, 2: URL. */
			return sprintf( __( 'It links to %1$s (%2$s). Fix that before pushing.', 'sceneshifter' ), $item->foreign[0]->sister->label, $item->foreign[0]->url );
		}
		/* translators: 1: environment name, 2: content type. */
		return sprintf( __( '%1$s doesn\'t have the %2$s content type.', 'sceneshifter' ), $target->label, $item->package->type() );
	}

	/**
	 * @return list<array{site: string, url: string, where: string}>
	 */
	private static function links( mixed $list ): array {
		$out = array();
		foreach ( is_array( $list ) ? $list : array() as $row ) {
			if ( is_array( $row ) ) {
				$out[] = array(
					'site'  => (string) ( $row['site'] ?? '' ),
					'url'   => (string) ( $row['url'] ?? '' ),
					'where' => (string) ( $row['where'] ?? '' ),
				);
			}
		}
		return $out;
	}
}
