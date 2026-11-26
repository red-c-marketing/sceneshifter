<?php
/**
 * On the site that is pulling: brings posts over from another environment.
 * A pull is a push the other way round, with this site making every call:
 *
 *   1. Asks the source for the post (POST /export) and resolves its
 *      references here, with the same preflight a push would ask for.
 *      Stops if it is blocked, or if what it matches here changed since the
 *      preview the person confirmed.
 *   2. Copies media this site doesn't have, chunk by chunk (POST /media/read
 *      there, then this site's own media receiver).
 *   3. Rewrites the package for this site and imports it with this site's
 *      import code, so it gets the same snapshot, checks and undo as a push.
 *      Pulls are never held for review: the person pulling is here.
 *   4. Tells the source (POST /pulled), so its sync map and log match.
 *
 * @package RedC\Sceneshifter
 */

declare(strict_types=1);

namespace RedC\Sceneshifter\Transfer;

use RedC\Sceneshifter\Log\LogEntry;
use RedC\Sceneshifter\Log\LogStore;
use RedC\Sceneshifter\Registry\Registry;
use RedC\Sceneshifter\Registry\Site;
use RedC\Sceneshifter\Scanner\Finding;
use RedC\Sceneshifter\SyncMap\Uuid;
use RedC\Sceneshifter\Transport\Transport;

final class PullRunner {

	/** Tries per request before giving up. */
	private const TRIES = 3;

	/** @var callable(string): ?array<int, array<string, mixed>> */
	private $parse;

	/** @var callable(array<int, array<string, mixed>>): string */
	private $serialize;

	/** @var callable(): int */
	private $clock;

	/**
	 * @param Site                                                $self      This site.
	 * @param Registry                                            $registry  The site list.
	 * @param Transport|null                                      $transport Signed transport; null when inactive.
	 * @param string                                              $format    Package format (Plugin::PACKAGE_FORMAT).
	 * @param PreflightResponder                                  $preflight This site's preflight.
	 * @param MediaReceiver                                       $receiver  This site's media receiver.
	 * @param ImportResponder                                     $importer  This site's import, not holding updates.
	 * @param LogStore                                            $log       This site's transfer log.
	 * @param callable(string): ?array<int, array<string, mixed>> $parse     Block parser.
	 * @param callable(array<int, array<string, mixed>>): string  $serialize Block serialiser.
	 * @param (callable(): int)|null                              $clock     Current Unix time.
	 */
	public function __construct(
		private readonly Site $self,
		private readonly Registry $registry,
		private readonly ?Transport $transport,
		private readonly string $format,
		private readonly PreflightResponder $preflight,
		private readonly MediaReceiver $receiver,
		private readonly ImportResponder $importer,
		private readonly LogStore $log,
		callable $parse,
		callable $serialize,
		?callable $clock = null
	) {
		$this->parse     = $parse;
		$this->serialize = $serialize;
		$this->clock     = $clock ?? 'time';
	}

	/**
	 * Posts on the source that could be pulled.
	 *
	 * @return array{error: string|null, items: list<array{id: int, title: string, type: string, status: string, modified: string, path: string, here: int|null}>, total: int, per_page: int}
	 */
	public function search( string $sourceId, string $query, string $type = '', int $page = 1 ): array {
		$source = $this->source( $sourceId );
		if ( null === $source || null === $this->transport ) {
			return array(
				'error'    => self::unavailable(),
				'items'    => array(),
				'total'    => 0,
				'per_page' => ExportResponder::PER_PAGE,
			);
		}
		$reply = $this->transport->send(
			$source->id,
			'POST',
			ExportResponder::SEARCH_ROUTE,
			array(
				'q'    => $query,
				'type' => $type,
				'page' => max( 1, $page ),
			)
		);
		if ( ! $reply->ok() ) {
			return array(
				'error'    => $reply->message,
				'items'    => array(),
				'total'    => 0,
				'per_page' => ExportResponder::PER_PAGE,
			);
		}
		$items = array();
		foreach ( is_array( $reply->data['items'] ?? null ) ? $reply->data['items'] : array() as $row ) {
			if ( ! is_array( $row ) || ! is_int( $row['id'] ?? null ) ) {
				continue;
			}
			$items[] = array(
				'id'       => $row['id'],
				'title'    => is_string( $row['title'] ?? null ) ? $row['title'] : '',
				'type'     => is_string( $row['type'] ?? null ) ? $row['type'] : '',
				'status'   => is_string( $row['status'] ?? null ) ? $row['status'] : '',
				'modified' => is_string( $row['modified'] ?? null ) ? $row['modified'] : '',
				'path'     => is_string( $row['path'] ?? null ) ? $row['path'] : '',
				'here'     => is_int( $row['here'] ?? null ) ? $row['here'] : null,
			);
		}
		return array(
			'error'    => null,
			'items'    => $items,
			'total'    => is_int( $reply->data['total'] ?? null ) ? $reply->data['total'] : count( $items ),
			'per_page' => is_int( $reply->data['per_page'] ?? null ) ? $reply->data['per_page'] : ExportResponder::PER_PAGE,
		);
	}

	/**
	 * What pulling these posts would do here. Changes nothing on either site,
	 * apart from giving the posts there a Sceneshifter ID if they had none.
	 *
	 * @param list<int> $ids Post IDs on the source.
	 * @return array{error: string|null, items: list<PreviewItem>}
	 */
	public function preview( string $sourceId, array $ids ): array {
		$source = $this->source( $sourceId );
		if ( null === $source || null === $this->transport ) {
			return array(
				'error' => self::unavailable(),
				'items' => array(),
			);
		}
		$export = $this->export( $this->transport, $source, array_slice( $ids, 0, PreflightResponder::MAX_ITEMS ) );
		if ( null !== $export['error'] ) {
			return array(
				'error' => $export['error'],
				'items' => array(),
			);
		}
		$packages = array();
		foreach ( $export['items'] as $row ) {
			if ( null !== $row['package'] ) {
				$packages[] = $row['package'];
			}
		}
		if ( array() === $packages ) {
			/* translators: %s: environment name. */
			return array(
				'error' => sprintf( __( 'None of those posts can be pulled from %s.', 'sceneshifter' ), $source->label ),
				'items' => array(),
			);
		}
		$answers  = $this->preflight( $source, $packages );
		$findings = array();
		foreach ( $export['items'] as $row ) {
			$findings[ $row['id'] ] = $row['findings'];
		}
		$items = array();
		foreach ( $packages as $i => $package ) {
			$answer  = $answers[ $i ] ?? null;
			$items[] = null === $answer
				? new PreviewItem( $package, error: PushPreview::formatMismatch( $source ) )
				: $this->previewer( $source, $findings )->item( $package, $answer, $this->self );
		}
		return array(
			'error' => null,
			'items' => $items,
		);
	}

	/**
	 * Pulls one post.
	 *
	 * @param string               $sourceId Registry ID of the environment to pull from.
	 * @param int                  $remoteId The post there.
	 * @param string               $transfer UUID shared by every post in the batch.
	 * @param array<string, mixed> $options  new_status, existing_status, create_terms, overwrite_unlinked (as for a push).
	 * @param int|null|false       $expected The post here the preview said it would update (null for new); false to skip the check.
	 * @param string               $user     Who started it, for both sites' logs.
	 */
	public function run( string $sourceId, int $remoteId, string $transfer, array $options = array(), int|null|false $expected = false, string $user = '' ): PushResult {
		[ $result, $logged ] = $this->pull( $sourceId, $remoteId, $transfer, $options, $expected, $user );
		$now                 = ( $this->clock )();
		if ( ! $result->ok() && ! $logged && Uuid::isValid( $transfer ) ) {
			$this->log->add( new LogEntry( 0, $transfer, LogEntry::IN, $sourceId, $user, $remoteId, $result->targetId, $result->title, LogEntry::FAILED, false, $result->error, array( 'pulled' => true ), $now ) );
		}
		if ( $result->ok() && null !== $result->targetId ) {
			$entry = $this->log->find( $transfer, LogEntry::IN, $sourceId, $result->targetId );
			if ( null !== $entry ) {
				$this->log->setDetails(
					$entry->id,
					array(
						'pulled'   => true,
						'media'    => $result->mediaCopied,
						'rewrites' => $result->rewrites,
						'missing'  => count( $result->missing ),
					) + $entry->details
				);
			}
		}
		return $result;
	}

	/**
	 * After a pull was undone here: tells the source, so its sync map and
	 * log stop showing the post as linked. Best effort: the undo here has
	 * already happened.
	 *
	 * @param array<string, mixed> $reply What Rollback::undo() returned.
	 */
	public function undone( LogEntry $entry, array $reply, string $user ): bool {
		if ( LogEntry::IN !== $entry->direction || empty( $entry->details['pulled'] ) || empty( $reply['undone'] ) || null === $entry->targetId || null === $this->transport || null === $this->source( $entry->sister ) ) {
			return false;
		}
		$sent = $this->transport->send(
			$entry->sister,
			'POST',
			ExportResponder::PULLED_ROUTE,
			array(
				'undone'        => true,
				'transfer'      => $entry->transfer,
				'target'        => $entry->targetId,
				'media_removed' => array_values( array_filter( is_array( $reply['media_removed'] ?? null ) ? $reply['media_removed'] : array(), 'is_int' ) ),
				'user'          => $user,
			)
		);
		return $sent->ok() && ! empty( $sent->data['recorded'] );
	}

	/**
	 * @param array<string, mixed> $options
	 * @return array{0: PushResult, 1: bool} The result, and whether the import already logged it.
	 */
	private function pull( string $sourceId, int $remoteId, string $transfer, array $options, int|null|false $expected, string $user ): array {
		$source    = $this->source( $sourceId );
		$transport = $this->transport;
		if ( null === $source || null === $transport ) {
			return array( PushResult::failed( $remoteId, '', self::unavailable() ), false );
		}
		if ( ! Uuid::isValid( $transfer ) ) {
			return array( PushResult::failed( $remoteId, '', __( 'The pull was started without a batch ID.', 'sceneshifter' ) ), false );
		}

		$export = $this->export( $transport, $source, array( $remoteId ) );
		$row    = $export['items'][0] ?? null;
		if ( null !== $export['error'] || null === $row || null === $row['package'] ) {
			/* translators: %s: environment name. */
			return array( PushResult::failed( $remoteId, '', $export['error'] ?? sprintf( __( 'That post no longer exists on %s, or it can\'t be pulled.', 'sceneshifter' ), $source->label ) ), false );
		}
		$package = $row['package'];
		$title   = $package->title();
		$answer  = $this->preflight( $source, array( $package ) )[0] ?? null;
		if ( null === $answer ) {
			return array( PushResult::failed( $remoteId, $title, PushPreview::formatMismatch( $source ) ), false );
		}
		$previewer = $this->previewer( $source, array( $remoteId => $row['findings'] ) );
		$item      = $previewer->item( $package, $answer, $this->self );
		if ( $item->isBlocked() ) {
			return array( PushResult::failed( $remoteId, $title, self::blockedReason( $item, $source ) ), false );
		}
		$localId = is_int( $item->target['id'] ?? null ) ? $item->target['id'] : null;
		if ( false !== $expected && $expected !== $localId ) {
			return array( PushResult::failed( $remoteId, $title, __( 'What this post matches here has changed since the preview. Preview it again.', 'sceneshifter' ) ), false );
		}
		if ( $item->overwritesUnlinked() && empty( $options['overwrite_unlinked'] ) ) {
			/* translators: %d: post ID here. */
			return array( PushResult::failed( $remoteId, $title, sprintf( __( 'This site has a post at the same address (ID %d) that was never linked to this one. It was left alone; agree to overwrite it to pull this post.', 'sceneshifter' ), (int) $localId ) ), false );
		}

		$copied  = array();
		$uploaded = array();
		$files   = array();
		$renamed = array();
		foreach ( $item->media as $file ) {
			$got = $this->download( $transport, $source, $file['id'], $transfer, $remoteId );
			if ( isset( $got['error'] ) ) {
				return array(
					PushResult::failed(
						$remoteId,
						$title,
						/* translators: 1: file name, 2: reason. */
						sprintf( __( 'Couldn\'t copy %1$s: %2$s The post wasn\'t changed.', 'sceneshifter' ), '' === $file['file'] ? $file['title'] : $file['file'], $got['error'] ),
						count( $uploaded )
					),
					false,
				);
			}
			$uploaded[ $file['id'] ] = $got['id'];
			$files[ $file['id'] ]    = $got['file'];
			$copied[]                = array(
				'source' => $file['id'],
				'id'     => $got['id'],
				'uuid'   => $got['uuid'],
			);
			if ( '' !== $file['file'] && '' !== $got['file'] && MediaPath::stem( $got['file'] ) !== MediaPath::stem( $file['file'] ) ) {
				$renamed[] = array(
					'from' => $file['file'],
					'to'   => $got['file'],
				);
			}
		}

		$final = $previewer->item( $package, $answer, $this->self, $uploaded, true, $files );
		$data  = $this->importer->import(
			$source->id,
			array(
				'format'      => $this->format,
				'transfer'    => $transfer,
				'package'     => ( $final->rewritten ?? $package )->toArray(),
				'target'      => $localId,
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
		if ( isset( $data['error'] ) || ! is_int( $data['id'] ?? null ) ) {
			return array( PushResult::failed( $remoteId, $title, self::importError( $data, $package ), count( $uploaded ) ), ImportResponder::FAILED === ( $data['error'] ?? null ) );
		}

		$transport->send(
			$source->id,
			'POST',
			ExportResponder::PULLED_ROUTE,
			array(
				'transfer' => $transfer,
				'post'     => $remoteId,
				'target'   => $data['id'],
				'created'  => (bool) ( $data['created'] ?? false ),
				'hash'     => ContentHash::overall( ContentHash::parts( $package ) ),
				'title'    => $title,
				'status'   => is_string( $data['status'] ?? null ) ? $data['status'] : '',
				'media'    => $copied,
				'rewrites' => count( $final->rewrites ),
				'user'     => $user,
			)
		);

		return array(
			new PushResult(
				$remoteId,
				$title,
				null,
				$data['id'],
				(bool) ( $data['created'] ?? false ),
				is_string( $data['status'] ?? null ) ? $data['status'] : '',
				array_map( 'strval', is_array( $data['urls'] ?? null ) ? array_intersect_key( $data['urls'], array_flip( array( 'edit', 'view' ) ) ) : array() ),
				count( $uploaded ),
				count( $final->rewrites ),
				array_values( array_map( 'strval', is_array( $data['mismatch'] ?? null ) ? $data['mismatch'] : array() ) ),
				self::links( $data['links'] ?? null ),
				is_int( $data['links_total'] ?? null ) ? $data['links_total'] : 0,
				$renamed,
				$final->missing,
				is_int( $data['snapshot'] ?? null ) ? $data['snapshot'] : null
			),
			true,
		);
	}

	/**
	 * Asks the source for packages.
	 *
	 * @param list<int> $ids
	 * @return array{error: string|null, items: list<array{id: int, package: Package|null, findings: list<Finding>}>}
	 */
	private function export( Transport $transport, Site $source, array $ids ): array {
		$reply = null;
		for ( $try = 1; $try <= self::TRIES && null === $reply; $try++ ) {
			$sent  = $transport->send(
				$source->id,
				'POST',
				ExportResponder::EXPORT_ROUTE,
				array(
					'format' => $this->format,
					'ids'    => $ids,
				)
			);
			$reply = $sent->ok() ? $sent->data : null;
			$error = $sent->message;
		}
		if ( null === $reply ) {
			return array(
				'error' => $error,
				'items' => array(),
			);
		}
		if ( PreflightResponder::FORMAT_MISMATCH === ( $reply['error'] ?? null ) ) {
			return array(
				'error' => PushPreview::formatMismatch( $source ),
				'items' => array(),
			);
		}
		$items = array();
		foreach ( is_array( $reply['items'] ?? null ) ? $reply['items'] : array() as $row ) {
			if ( ! is_array( $row ) || ! is_int( $row['id'] ?? null ) ) {
				continue;
			}
			$package = Package::fromArray( $row['package'] ?? null );
			$items[] = array(
				'id'       => $row['id'],
				'package'  => null !== $package && $package->id === $row['id'] ? $package : null,
				'findings' => $this->findings( $row['findings'] ?? null ),
			);
		}
		return array(
			'error' => null,
			'items' => $items,
		);
	}

	/**
	 * This site's preflight answers for packages from the source.
	 *
	 * @param list<Package> $packages
	 * @return list<array<string, mixed>|null> One per package, in order.
	 */
	private function preflight( Site $source, array $packages ): array {
		$reply   = $this->preflight->preflight(
			$source->id,
			array(
				'format' => $this->format,
				'items'  => array_map( static fn( Package $p ): array => $p->manifest(), $packages ),
			)
		);
		$answers = is_array( $reply['items'] ?? null ) ? array_values( $reply['items'] ) : array();
		$out     = array();
		foreach ( array_keys( $packages ) as $i ) {
			$out[] = is_array( $answers[ $i ] ?? null ) ? $answers[ $i ] : null;
		}
		return $out;
	}

	/**
	 * The push preview, seen from the source: its rewriting turns the
	 * source's URLs and IDs into this site's.
	 *
	 * @param array<int, list<Finding>> $findings Links to other environments, by post ID on the source.
	 */
	private function previewer( Site $source, array $findings ): PushPreview {
		return new PushPreview(
			$source,
			$this->registry,
			null,
			$this->format,
			static fn( int $id ): ?Package => null,
			static fn( int $id ): array => $findings[ $id ] ?? array(),
			$this->parse,
			$this->serialize
		);
	}

	/**
	 * Copies one file from the source into this site's uploads.
	 *
	 * @return array{id: int, file: string, uuid: string}|array{error: string}
	 */
	private function download( Transport $transport, Site $source, int $attachmentId, string $transfer, int $postId ): array {
		$upload = Uuid::generate();
		$offset = 0;
		$about  = null;
		$rounds = 3;
		do {
			if ( --$rounds < 0 ) {
				return array( 'error' => __( 'the copy here kept going out of step.', 'sceneshifter' ) );
			}
			$reply = null;
			for ( $try = 1; $try <= self::TRIES && null === $reply; $try++ ) {
				$sent  = $transport->send(
					$source->id,
					'POST',
					ExportResponder::MEDIA_ROUTE,
					array(
						'id'     => $attachmentId,
						'offset' => $offset,
					)
				);
				$reply = $sent->ok() ? $sent->data : null;
			}
			if ( null === $reply ) {
				/* translators: %s: environment name. */
				return array( 'error' => sprintf( __( 'lost contact with %s.', 'sceneshifter' ), $source->label ) );
			}
			if ( isset( $reply['error'] ) || ! is_int( $reply['size'] ?? null ) || ! is_string( $reply['data'] ?? null ) || ! is_string( $reply['sha256'] ?? null ) || ! is_string( $reply['file'] ?? null ) ) {
				/* translators: %s: environment name. */
				return array( 'error' => sprintf( __( 'the file is missing on %s.', 'sceneshifter' ), $source->label ) );
			}
			if ( null === $about ) {
				$about  = $reply;
				$rounds = 3 + 3 * intdiv( $reply['size'], MediaReceiver::CHUNK );
			} elseif ( $reply['size'] !== $about['size'] || $reply['sha256'] !== $about['sha256'] ) {
				/* translators: %s: environment name. */
				return array( 'error' => sprintf( __( 'the file changed on %s while it was being copied.', 'sceneshifter' ), $source->label ) );
			}
			if ( '' === $reply['data'] && $offset < $about['size'] ) {
				return array( 'error' => __( 'the file arrived incomplete.', 'sceneshifter' ) );
			}
			$stored = $this->receiver->chunk(
				$source->id,
				array(
					'upload' => $upload,
					'offset' => $offset,
					'data'   => $reply['data'],
				)
			);
			if ( MediaReceiver::OFFSET === ( $stored['error'] ?? null ) && is_int( $stored['size'] ?? null ) ) {
				$offset = $stored['size'];
				continue;
			}
			if ( isset( $stored['error'] ) || ! is_int( $stored['size'] ?? null ) ) {
				return array( 'error' => self::mediaError( $stored ) );
			}
			$offset = $stored['size'];
		} while ( $offset < $about['size'] );

		$placed = $this->receiver->commit(
			$source->id,
			array(
				'upload'      => $upload,
				'source'      => $attachmentId,
				'file'        => $about['file'],
				'size'        => $about['size'],
				'sha256'      => $about['sha256'],
				'uuid'        => $about['uuid'] ?? null,
				'title'       => $about['title'] ?? '',
				'alt'         => $about['alt'] ?? '',
				'caption'     => $about['caption'] ?? '',
				'description' => $about['description'] ?? '',
				'transfer'    => $transfer,
				'post'        => $postId,
			)
		);
		if ( isset( $placed['error'] ) || ! is_int( $placed['id'] ?? null ) ) {
			return array( 'error' => self::mediaError( $placed ) );
		}
		return array(
			'id'   => $placed['id'],
			'file' => is_string( $placed['file'] ?? null ) ? $placed['file'] : '',
			'uuid' => Uuid::isValid( $about['uuid'] ?? null ) ? (string) $about['uuid'] : '',
		);
	}

	/**
	 * @return list<Finding>
	 */
	private function findings( mixed $rows ): array {
		$out = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( ! is_array( $row ) || ! is_string( $row['sister'] ?? null ) || ! is_string( $row['url'] ?? null ) ) {
				continue;
			}
			$sister = $this->registry->get( $row['sister'] );
			if ( null === $sister ) {
				continue;
			}
			$path  = is_array( $row['blockPath'] ?? null ) ? array_values( array_filter( $row['blockPath'], 'is_int' ) ) : null;
			$out[] = new Finding(
				$sister,
				$row['url'],
				Finding::WARNING === ( $row['severity'] ?? null ) ? Finding::WARNING : Finding::ERROR,
				is_string( $row['area'] ?? null ) ? $row['area'] : '',
				is_string( $row['label'] ?? null ) ? $row['label'] : '',
				$path,
				is_string( $row['blockName'] ?? null ) ? $row['blockName'] : null,
				is_int( $row['count'] ?? null ) ? max( 1, $row['count'] ) : 1
			);
		}
		return $out;
	}

	/**
	 * A paired environment, other than this one, to pull from.
	 */
	private function source( string $id ): ?Site {
		$site = $this->registry->get( $id );
		return null === $site || $site->id === $this->self->id ? null : $site;
	}

	private static function unavailable(): string {
		return __( 'That environment isn\'t in the site list, or Sceneshifter is inactive here.', 'sceneshifter' );
	}

	private static function blockedReason( PreviewItem $item, Site $source ): string {
		if ( null !== $item->error ) {
			return $item->error;
		}
		if ( array() !== $item->foreign ) {
			/* translators: 1: environment the link points at, 2: URL, 3: environment pulled from. */
			return sprintf( __( 'It links to %1$s (%2$s). Fix that on %3$s before pulling.', 'sceneshifter' ), $item->foreign[0]->sister->label, $item->foreign[0]->url, $source->label );
		}
		/* translators: %s: content type. */
		return sprintf( __( 'This site doesn\'t have the %s content type.', 'sceneshifter' ), $item->package->type() );
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private static function importError( array $data, Package $package ): string {
		return match ( $data['error'] ?? null ) {
			/* translators: %s: content type. */
			PreflightResponder::POST_TYPE_MISSING => sprintf( __( 'This site doesn\'t have the %s content type.', 'sceneshifter' ), $package->type() ),
			ImportResponder::MOVED                => __( 'What this post matches here has changed since the preview. Preview it again.', 'sceneshifter' ),
			ImportResponder::CHANGED              => __( 'The post here was edited moments ago. Preview it again.', 'sceneshifter' ),
			ImportResponder::FAILED               => ! empty( $data['restored'] ) || ! isset( $data['restored'] )
				/* translators: %s: reason. */
				? sprintf( __( 'The post couldn\'t be saved here (%s), so it was put back as it was.', 'sceneshifter' ), (string) ( $data['message'] ?? '' ) )
				/* translators: %s: reason. */
				: sprintf( __( 'The post couldn\'t be saved here (%s) and couldn\'t be put back as it was. Check it.', 'sceneshifter' ), (string) ( $data['message'] ?? '' ) ),
			default                               => __( 'What came back from the other site couldn\'t be read.', 'sceneshifter' ),
		};
	}

	/**
	 * @param array<string, mixed> $reply
	 */
	private static function mediaError( array $reply ): string {
		if ( is_string( $reply['message'] ?? null ) && '' !== $reply['message'] ) {
			return $reply['message'];
		}
		return match ( $reply['error'] ?? null ) {
			MediaReceiver::TOO_LARGE  => __( 'the file is larger than this site accepts.', 'sceneshifter' ),
			MediaReceiver::INCOMPLETE => __( 'the file arrived incomplete.', 'sceneshifter' ),
			default                   => __( 'this site couldn\'t save it.', 'sceneshifter' ),
		};
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
