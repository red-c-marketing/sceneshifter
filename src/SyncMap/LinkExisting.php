<?php
/**
 * Link existing content, from the site that starts it: fetches what a
 * sister has, proposes matches, and links the ones an admin accepts on
 * both sites.
 *
 * The sister is told first. Only links it accepted are recorded here, so
 * the two sites never disagree about a link this site has recorded. If
 * the sister accepts but its reply is lost, the next run finds the pair
 * by its shared UUID.
 *
 * @package RedC\Sceneshifter
 */

declare(strict_types=1);

namespace RedC\Sceneshifter\SyncMap;

use RedC\Sceneshifter\Registry\Registry;
use RedC\Sceneshifter\Transport\Transport;

final class LinkExisting {

	public const PER_PAGE   = 250;
	public const LINK_BATCH = 100;

	private LinkApplier $applier;

	public function __construct(
		private readonly ContentIndex $index,
		private readonly MapStore $map,
		private readonly InventoryCache $cache,
		private readonly Registry $registry,
		private readonly ?Transport $transport
	) {
		$this->applier = new LinkApplier( $index, $map );
	}

	public function cache(): InventoryCache {
		return $this->cache;
	}

	/**
	 * Forgets anything fetched before and starts a new fetch.
	 */
	public function start( string $sister, int $now ): void {
		$this->cache->clear( $sister );
		$this->cache->saveState(
			$sister,
			array(
				'types'    => $this->index->linkableTypes(),
				't'        => 0,
				'page'     => 1,
				'pages'    => array(),
				'totals'   => array(),
				'skipped'  => array(),
				'started'  => $now,
				'finished' => null,
			)
		);
	}

	/**
	 * Fetches the next page from the sister. Call until done.
	 *
	 * @return array{done: bool, error: string|null, type: string, page: int, pages: int}
	 */
	public function step( string $sister, int $now ): array {
		$state = $this->cache->state( $sister );
		if ( null === $state ) {
			$this->start( $sister, $now );
			$state = $this->cache->state( $sister );
			if ( null === $state ) {
				return self::failed( '', 1, __( 'Couldn\'t save progress on this site.', 'sceneshifter' ) );
			}
		}
		$type = $state['types'][ $state['t'] ] ?? null;
		if ( null === $type || null !== $state['finished'] ) {
			return array(
				'done'  => true,
				'error' => null,
				'type'  => '',
				'page'  => 0,
				'pages' => 0,
			);
		}
		if ( null === $this->transport ) {
			return self::failed( $type, $state['page'], __( 'Sceneshifter is inactive on this site.', 'sceneshifter' ) );
		}

		$result = $this->transport->send(
			$sister,
			'POST',
			Responder::INVENTORY_ROUTE,
			array(
				'type'     => $type,
				'page'     => $state['page'],
				'per_page' => self::PER_PAGE,
			)
		);
		if ( ! $result->ok() ) {
			return self::failed( $type, $state['page'], $result->message );
		}

		$pages = 1;
		if ( Responder::TYPE_NOT_ENABLED === ( $result->data['error'] ?? null ) ) {
			$state['skipped'][ $type ] = Responder::TYPE_NOT_ENABLED;
			$state['pages'][ $type ]   = 0;
			$state['totals'][ $type ]  = 0;
			$done                      = true;
		} else {
			$items = array();
			foreach ( is_array( $result->data['items'] ?? null ) ? $result->data['items'] : array() as $row ) {
				$item = Item::fromArray( $row );
				if ( null !== $item && $item->type === $type ) {
					$items[] = $item->toArray();
				}
			}
			$pages = max( 1, (int) ( $result->data['pages'] ?? 1 ) );
			$this->cache->savePage( $sister, $type, $state['page'], $items );
			$state['pages'][ $type ]  = $state['page'];
			$state['totals'][ $type ] = (int) ( $result->data['total'] ?? 0 );
			$done                     = $state['page'] >= $pages;
		}

		$page = $state['page'];
		if ( $done ) {
			++$state['t'];
			$state['page'] = 1;
		} else {
			++$state['page'];
		}
		if ( $state['t'] >= count( $state['types'] ) ) {
			$state['finished'] = $now;
		}
		$this->cache->saveState( $sister, $state );

		return array(
			'done'  => null !== $state['finished'],
			'error' => null,
			'type'  => $type,
			'page'  => $page,
			'pages' => $pages,
		);
	}

	/**
	 * Proposed matches for each post type fetched, or null when the fetch
	 * hasn't finished.
	 *
	 * @return list<MatchResult>|null
	 */
	public function review( string $sister ): ?array {
		$state = $this->cache->state( $sister );
		if ( null === $state || null === $state['finished'] ) {
			return null;
		}
		$results = array();
		foreach ( $state['types'] as $type ) {
			if ( isset( $state['skipped'][ $type ] ) || ! in_array( $type, $this->index->linkableTypes(), true ) ) {
				continue;
			}
			$results[] = Matcher::match( $type, $this->localItems( $type ), $this->cache->items( $sister, $type ), $this->map->linked( $sister, MapEntry::POST ) );
		}
		return $results;
	}

	/**
	 * Links pairs on the sister, then here.
	 *
	 * @param list<array{0: int, 1: int}> $pairs Local ID, the sister's ID.
	 * @return array{linked: int, failed: list<array{local: int, message: string}>, error: string|null}
	 */
	public function link( string $sister, array $pairs, int $now ): array {
		$label  = $this->registry->get( $sister )->label ?? $sister;
		$here   = __( 'this site', 'sceneshifter' );
		$out    = array(
			'linked' => 0,
			'failed' => array(),
			'error'  => null,
		);
		$remote = array();
		$send   = array();

		foreach ( array_slice( $pairs, 0, self::LINK_BATCH ) as [ $localId, $remoteId ] ) {
			$local = $this->index->get( $localId );
			if ( null === $local ) {
				$out['failed'][] = array(
					'local'   => $localId,
					'message' => LinkApplier::message( LinkApplier::MISSING, $here ),
				);
				continue;
			}
			$remote[ $local->type ] ??= self::byId( $this->cache->items( $sister, $local->type ) );
			$theirs                   = $remote[ $local->type ][ $remoteId ] ?? null;
			if ( null === $theirs ) {
				$out['failed'][] = array(
					'local'   => $localId,
					/* translators: %s: site name. */
					'message' => sprintf( __( 'That item isn\'t in the list fetched from %s. Find matches again.', 'sceneshifter' ), $label ),
				);
				continue;
			}
			$problem = self::problem( $local, $theirs );
			if ( null !== $problem ) {
				$out['failed'][] = array(
					'local'   => $localId,
					'message' => $problem,
				);
				continue;
			}
			$link  = array(
				'local'  => $localId,
				'remote' => $remoteId,
				'type'   => $local->type,
				'uuid'   => MatchResult::uuidFor( $local, $theirs ) ?? Uuid::generate(),
			);
			$check = $this->applier->check( $sister, $link );
			if ( LinkApplier::OK !== $check ) {
				$out['failed'][] = array(
					'local'   => $localId,
					'message' => LinkApplier::message( $check, $here ),
				);
				continue;
			}
			$send[] = $link;
		}

		if ( array() === $send ) {
			return $out;
		}
		if ( null === $this->transport ) {
			$out['error'] = __( 'Sceneshifter is inactive on this site.', 'sceneshifter' );
			return $out;
		}

		$result = $this->transport->send(
			$sister,
			'POST',
			Responder::LINK_ROUTE,
			array(
				'links' => array_map(
					static fn( array $link ): array => array(
						'id'        => $link['remote'],
						'sister_id' => $link['local'],
						'type'      => $link['type'],
						'uuid'      => $link['uuid'],
					),
					$send
				),
			)
		);
		$answers = is_array( $result->data['results'] ?? null ) ? array_values( $result->data['results'] ) : array();
		if ( ! $result->ok() || count( $answers ) !== count( $send ) ) {
			$out['error'] = $result->ok()
				/* translators: %s: site name. */
				? sprintf( __( 'The reply from %s couldn\'t be read, so nothing was linked here. Find matches again to pick up anything it did link.', 'sceneshifter' ), $label )
				: $result->message;
			return $out;
		}

		foreach ( $send as $i => $link ) {
			$answer = is_string( $answers[ $i ] ) ? $answers[ $i ] : LinkApplier::INVALID;
			if ( LinkApplier::OK !== $answer ) {
				$out['failed'][] = array(
					'local'   => $link['local'],
					'message' => LinkApplier::message( $answer, $label ),
				);
				continue;
			}
			$applied = $this->applier->apply( $sister, array( $link ), $now )[0];
			if ( LinkApplier::OK === $applied ) {
				++$out['linked'];
			} else {
				$out['failed'][] = array(
					'local'   => $link['local'],
					'message' => LinkApplier::message( $applied, $here ),
				);
			}
		}
		return $out;
	}

	/**
	 * Every proposal, as pairs for link().
	 *
	 * @param list<MatchResult> $results
	 * @return list<array{0: int, 1: int}>
	 */
	public static function proposedPairs( array $results ): array {
		$pairs = array();
		foreach ( $results as $result ) {
			foreach ( $result->proposals as $proposal ) {
				$pairs[] = array( $proposal['local']->id, $proposal['remote']->id );
			}
		}
		return $pairs;
	}

	/**
	 * Why two items can't be linked, or null when they can.
	 */
	private static function problem( Item $local, Item $theirs ): ?string {
		if ( $local->dupe || $theirs->dupe ) {
			return __( 'Another item has the same Sceneshifter ID, most likely because a duplicate-post plugin copied it. Remove the copy\'s ID first.', 'sceneshifter' );
		}
		if ( null !== $local->uuid && null !== $theirs->uuid && $local->uuid !== $theirs->uuid ) {
			return __( 'The two are already linked to different content.', 'sceneshifter' );
		}
		return null;
	}

	/**
	 * @return list<Item>
	 */
	private function localItems( string $type ): array {
		$items = array();
		$page  = 1;
		do {
			$batch = $this->index->page( $type, $page, 500 );
			$items = array_merge( $items, $batch['items'] );
			++$page;
		} while ( count( $batch['items'] ) === 500 && count( $items ) < $batch['total'] );
		return $items;
	}

	/**
	 * @param list<Item> $items
	 * @return array<int, Item>
	 */
	private static function byId( array $items ): array {
		$byId = array();
		foreach ( $items as $item ) {
			$byId[ $item->id ] = $item;
		}
		return $byId;
	}

	/**
	 * @return array{done: bool, error: string|null, type: string, page: int, pages: int}
	 */
	private static function failed( string $type, int $page, string $message ): array {
		return array(
			'done'  => false,
			'error' => $message,
			'type'  => $type,
			'page'  => $page,
			'pages' => 0,
		);
	}
}
