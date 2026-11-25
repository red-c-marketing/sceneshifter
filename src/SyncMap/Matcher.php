<?php
/**
 * Proposes which item here matches which item on a sister, for Link
 * existing content. It only proposes: an admin reviews every match.
 *
 * In order:
 *   1. The same UUID (a database copied between the two, or linked before).
 *   2. Posts: the same path (page URI, or slug for flat post types).
 *      Media: the same file path under uploads with the same size, then the
 *      same file name with the same size.
 * A match must be one to one. When several items could match, or the two
 * already have different UUIDs, it is set aside for the admin.
 *
 * @package RedC\Sceneshifter
 */

declare(strict_types=1);

namespace RedC\Sceneshifter\SyncMap;

final class Matcher {

	public const UUID      = 'uuid';
	public const PATH      = 'path';
	public const FILE      = 'file';
	public const NAME_SIZE = 'name_size';

	public const AMBIGUOUS     = 'ambiguous';
	public const DIFFERENT_IDS = 'different_ids';
	public const DUPLICATE_ID  = 'duplicate_id';

	/** @var list<array{local: Item, remote: Item, reason: string}> */
	private array $proposals = array();

	/** @var list<array{local: Item, candidates: list<Item>, reason: string}> */
	private array $attention = array();

	/** @var array<int, Item> */
	private array $local = array();

	/** @var array<int, Item> */
	private array $remote = array();

	/**
	 * @param string          $type   Post type.
	 * @param list<Item>      $local  Items of that type here.
	 * @param list<Item>      $remote Items of that type on the sister.
	 * @param array<int, int> $linked Local ID => remote ID already in the sync map for this sister.
	 */
	public static function match( string $type, array $local, array $remote, array $linked ): MatchResult {
		$matcher     = new self();
		$linkedCount = 0;
		$taken       = array_fill_keys( array_values( $linked ), true );
		foreach ( $local as $item ) {
			if ( isset( $linked[ $item->id ] ) ) {
				++$linkedCount;
				continue;
			}
			$matcher->local[ $item->id ] = $item;
		}
		foreach ( $remote as $item ) {
			if ( ! isset( $taken[ $item->id ] ) ) {
				$matcher->remote[ $item->id ] = $item;
			}
		}

		$matcher->byUuid();
		if ( 'attachment' === $type ) {
			$matcher->byKey( self::FILE, static fn( Item $item ): string => $item->key, true );
			$matcher->byKey( self::NAME_SIZE, static fn( Item $item ): string => null === $item->size ? '' : self::fileName( $item->key ) . '|' . $item->size, false );
		} else {
			$matcher->byKey( self::PATH, static fn( Item $item ): string => $item->key, false );
		}

		return new MatchResult( $type, $linkedCount, $matcher->proposals, $matcher->attention, array_values( $matcher->local ), array_values( $matcher->remote ) );
	}

	private function byUuid(): void {
		$remoteByUuid = array();
		foreach ( $this->remote as $item ) {
			if ( null !== $item->usableUuid() ) {
				$remoteByUuid[ $item->usableUuid() ] = $item;
			}
		}
		foreach ( $this->local as $id => $item ) {
			$uuid = $item->usableUuid();
			if ( null !== $uuid && isset( $remoteByUuid[ $uuid ] ) ) {
				$this->propose( $item, $remoteByUuid[ $uuid ], self::UUID );
			}
		}
	}

	/**
	 * @param callable(Item): string $keyOf     Matching key; '' means can't match this way.
	 * @param bool                   $checkSize Sizes must agree when both are known.
	 */
	private function byKey( string $reason, callable $keyOf, bool $checkSize ): void {
		$remoteByKey = array();
		foreach ( $this->remote as $item ) {
			$key = $keyOf( $item );
			if ( '' !== $key ) {
				$remoteByKey[ $key ][] = $item;
			}
		}
		$localByKey = array();
		foreach ( $this->local as $item ) {
			$key = $keyOf( $item );
			if ( '' !== $key ) {
				$localByKey[ $key ][] = $item;
			}
		}

		$held = array();
		foreach ( $localByKey as $key => $locals ) {
			$candidates = $remoteByKey[ $key ] ?? array();
			foreach ( $locals as $item ) {
				$fits = $checkSize ? array_values( array_filter( $candidates, static fn( Item $remote ): bool => null === $item->size || null === $remote->size || $item->size === $remote->size ) ) : $candidates;
				if ( array() === $fits ) {
					continue;
				}
				if ( 1 === count( $fits ) && 1 === count( $locals ) ) {
					$this->propose( $item, $fits[0], $reason );
					continue;
				}
				$this->setAside( $item, $fits, self::AMBIGUOUS );
				foreach ( $fits as $remote ) {
					$held[ $remote->id ] = true;
				}
			}
		}
		// Candidates in an ambiguous match aren't offered again later.
		foreach ( array_keys( $held ) as $id ) {
			unset( $this->remote[ $id ] );
		}
	}

	private static function fileName( string $path ): string {
		$slash = strrpos( $path, '/' );
		return false === $slash ? $path : substr( $path, $slash + 1 );
	}

	private function propose( Item $local, Item $remote, string $reason ): void {
		unset( $this->local[ $local->id ], $this->remote[ $remote->id ] );
		if ( $local->dupe || $remote->dupe ) {
			$this->attention[] = array(
				'local'      => $local,
				'candidates' => array( $remote ),
				'reason'     => self::DUPLICATE_ID,
			);
			return;
		}
		if ( null !== $local->uuid && null !== $remote->uuid && $local->uuid !== $remote->uuid ) {
			$this->attention[] = array(
				'local'      => $local,
				'candidates' => array( $remote ),
				'reason'     => self::DIFFERENT_IDS,
			);
			return;
		}
		$this->proposals[] = array(
			'local'  => $local,
			'remote' => $remote,
			'reason' => $reason,
		);
	}

	/**
	 * @param list<Item> $candidates
	 */
	private function setAside( Item $local, array $candidates, string $reason ): void {
		unset( $this->local[ $local->id ] );
		$this->attention[] = array(
			'local'      => $local,
			'candidates' => $candidates,
			'reason'     => $reason,
		);
	}
}
