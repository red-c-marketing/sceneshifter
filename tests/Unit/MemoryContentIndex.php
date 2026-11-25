<?php

declare(strict_types=1);

namespace RedC\Sceneshifter\Tests\Unit;

use RedC\Sceneshifter\SyncMap\ContentIndex;
use RedC\Sceneshifter\SyncMap\Item;

/**
 * Posts held in memory, for sync map tests.
 */
final class MemoryContentIndex implements ContentIndex {

	/** @var array<int, Item> */
	public array $items = array();

	/**
	 * @param list<string> $types
	 */
	public function __construct( private readonly array $types = array( 'page', 'post', 'attachment' ) ) {}

	public function add( int $id, string $type, string $key, ?string $uuid = null, ?int $size = null, string $title = '' ): self {
		$this->items[ $id ] = new Item( $id, $type, $key, $size, $uuid, '' === $title ? "Item {$id}" : $title, 'attachment' === $type ? 'inherit' : 'publish' );
		$this->markDupes();
		return $this;
	}

	public function linkableTypes(): array {
		return $this->types;
	}

	public function page( string $type, int $page, int $perPage ): array {
		$all = array_values( array_filter( $this->items, static fn( Item $item ): bool => $item->type === $type ) );
		usort( $all, static fn( Item $a, Item $b ): int => $a->id <=> $b->id );
		return array(
			'items' => array_slice( $all, ( $page - 1 ) * $perPage, $perPage ),
			'total' => count( $all ),
		);
	}

	public function get( int $id ): ?Item {
		return $this->items[ $id ] ?? null;
	}

	public function uuidOwners( string $uuid ): array {
		return array_values( array_map( static fn( Item $item ): int => $item->id, array_filter( $this->items, static fn( Item $item ): bool => $item->uuid === $uuid ) ) );
	}

	public function setUuid( int $id, string $uuid ): void {
		$item               = $this->items[ $id ];
		$this->items[ $id ] = new Item( $item->id, $item->type, $item->key, $item->size, $uuid, $item->title, $item->status );
		$this->markDupes();
	}

	private function markDupes(): void {
		$counts = array_count_values( array_filter( array_map( static fn( Item $item ): string => (string) $item->uuid, $this->items ) ) );
		foreach ( $this->items as $id => $item ) {
			$dupe = null !== $item->uuid && ( $counts[ $item->uuid ] ?? 0 ) > 1;
			if ( $dupe !== $item->dupe ) {
				$this->items[ $id ] = new Item( $item->id, $item->type, $item->key, $item->size, $item->uuid, $item->title, $item->status, $dupe );
			}
		}
	}
}
