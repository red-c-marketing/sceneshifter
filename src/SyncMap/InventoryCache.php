<?php
/**
 * Holds what a sister listed for Link existing content, one page per
 * option, between the requests that fetch it and the review.
 *
 * @package RedC\Sceneshifter
 */

declare(strict_types=1);

namespace RedC\Sceneshifter\SyncMap;

use RedC\Sceneshifter\Store\KeyValueStore;

final class InventoryCache {

	public const PREFIX = 'sceneshifter_inventory_';

	public function __construct( private readonly KeyValueStore $store ) {}

	/**
	 * Progress of the fetch, or null when nothing has been fetched.
	 *
	 * @return array{types: list<string>, t: int, page: int, pages: array<string, int>, totals: array<string, int>, skipped: array<string, string>, started: int, finished: int|null}|null
	 */
	public function state( string $sister ): ?array {
		$state = $this->store->get( self::key( $sister ) );
		if ( ! is_array( $state ) || ! is_array( $state['types'] ?? null ) ) {
			return null;
		}
		return array(
			'types'    => array_values( array_filter( $state['types'], 'is_string' ) ),
			't'        => (int) ( $state['t'] ?? 0 ),
			'page'     => max( 1, (int) ( $state['page'] ?? 1 ) ),
			'pages'    => array_map( 'intval', is_array( $state['pages'] ?? null ) ? $state['pages'] : array() ),
			'totals'   => array_map( 'intval', is_array( $state['totals'] ?? null ) ? $state['totals'] : array() ),
			'skipped'  => array_map( 'strval', is_array( $state['skipped'] ?? null ) ? $state['skipped'] : array() ),
			'started'  => (int) ( $state['started'] ?? 0 ),
			'finished' => isset( $state['finished'] ) ? (int) $state['finished'] : null,
		);
	}

	/**
	 * @param array{types: list<string>, t: int, page: int, pages: array<string, int>, totals: array<string, int>, skipped: array<string, string>, started: int, finished: int|null} $state
	 */
	public function saveState( string $sister, array $state ): void {
		$this->store->set( self::key( $sister ), $state );
	}

	/**
	 * @param list<array<string, mixed>> $items
	 */
	public function savePage( string $sister, string $type, int $page, array $items ): void {
		$this->store->set( self::key( $sister, $type, $page ), $items );
	}

	/**
	 * Everything fetched for one type.
	 *
	 * @return list<Item>
	 */
	public function items( string $sister, string $type ): array {
		$state = $this->state( $sister );
		$items = array();
		for ( $page = 1; $page <= ( $state['pages'][ $type ] ?? 0 ); $page++ ) {
			$rows = $this->store->get( self::key( $sister, $type, $page ), array() );
			foreach ( is_array( $rows ) ? $rows : array() as $row ) {
				$item = Item::fromArray( $row );
				if ( null !== $item && $item->type === $type ) {
					$items[] = $item;
				}
			}
		}
		return $items;
	}

	public function clear( string $sister ): void {
		$state = $this->state( $sister );
		foreach ( $state['pages'] ?? array() as $type => $pages ) {
			for ( $page = 1; $page <= $pages; $page++ ) {
				$this->store->delete( self::key( $sister, (string) $type, $page ) );
			}
		}
		$this->store->delete( self::key( $sister ) );
	}

	private static function key( string $sister, string $type = '', int $page = 0 ): string {
		$key = self::PREFIX . preg_replace( '/[^a-z0-9_-]/', '', strtolower( $sister ) );
		if ( '' !== $type ) {
			$key .= '_' . preg_replace( '/[^a-z0-9_-]/', '', strtolower( $type ) ) . '_' . $page;
		}
		return $key;
	}
}
