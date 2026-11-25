<?php
/**
 * This site's side of Link existing content, answering a paired sister:
 *
 *   POST /map/inventory  One page of one post type, for matching.
 *   POST /map/link       Links items here to the sister's items.
 *
 * The caller has already been verified; these only see the sender's ID.
 *
 * @package RedC\Sceneshifter
 */

declare(strict_types=1);

namespace RedC\Sceneshifter\SyncMap;

final class Responder {

	public const INVENTORY_ROUTE = '/sceneshifter/v1/map/inventory';
	public const LINK_ROUTE      = '/sceneshifter/v1/map/link';

	public const MAX_PER_PAGE = 500;
	public const MAX_LINKS    = 200;

	public const TYPE_NOT_ENABLED = 'type_not_enabled';

	public function __construct(
		private readonly ContentIndex $index,
		private readonly MapStore $map
	) {}

	/**
	 * @param array<string, mixed> $payload type, page, per_page.
	 * @return array<string, mixed>
	 */
	public function inventory( array $payload ): array {
		$type    = is_string( $payload['type'] ?? null ) ? $payload['type'] : '';
		$page    = is_int( $payload['page'] ?? null ) ? max( 1, $payload['page'] ) : 1;
		$perPage = is_int( $payload['per_page'] ?? null ) ? min( self::MAX_PER_PAGE, max( 1, $payload['per_page'] ) ) : 250;
		if ( ! in_array( $type, $this->index->linkableTypes(), true ) ) {
			return array(
				'type'  => $type,
				'error' => self::TYPE_NOT_ENABLED,
			);
		}
		$result = $this->index->page( $type, $page, $perPage );
		return array(
			'type'  => $type,
			'page'  => $page,
			'pages' => max( 1, (int) ceil( $result['total'] / $perPage ) ),
			'total' => $result['total'],
			'items' => array_map( static fn( Item $item ): array => $item->toArray(), $result['items'] ),
		);
	}

	/**
	 * @param string               $sender  The verified sister.
	 * @param array<string, mixed> $payload links: list of {id, sister_id, type, uuid}, where id is this site's post.
	 * @return array<string, mixed>
	 */
	public function link( string $sender, array $payload, int $now ): array {
		$raw = is_array( $payload['links'] ?? null ) ? array_slice( array_values( $payload['links'] ), 0, self::MAX_LINKS ) : array();
		$links = array();
		foreach ( $raw as $link ) {
			$links[] = array(
				'local'  => is_array( $link ) && is_int( $link['id'] ?? null ) ? $link['id'] : 0,
				'remote' => is_array( $link ) && is_int( $link['sister_id'] ?? null ) ? $link['sister_id'] : 0,
				'type'   => is_array( $link ) && is_string( $link['type'] ?? null ) ? $link['type'] : '',
				'uuid'   => is_array( $link ) && is_string( $link['uuid'] ?? null ) ? $link['uuid'] : '',
			);
		}
		return array( 'results' => ( new LinkApplier( $this->index, $this->map ) )->apply( $sender, $links, $now ) );
	}
}
