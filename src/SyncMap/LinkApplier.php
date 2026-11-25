<?php
/**
 * Records on one site that its items are the same as items on a sister:
 * gives each the shared UUID and adds the sync map row. Both sites run
 * this, each from its own side.
 *
 * Every link is checked first and refused, not forced, if it would break
 * an existing link or give two items the same UUID.
 *
 * @package RedC\Sceneshifter
 */

declare(strict_types=1);

namespace RedC\Sceneshifter\SyncMap;

final class LinkApplier {

	public const OK             = 'ok';
	public const INVALID        = 'invalid';
	public const MISSING        = 'missing';
	public const TYPE_MISMATCH  = 'type_mismatch';
	public const DIFFERENT_ID   = 'different_id';
	public const ID_IN_USE      = 'id_in_use';
	public const ALREADY_LINKED = 'already_linked';

	public function __construct(
		private readonly ContentIndex $index,
		private readonly MapStore $map
	) {}

	/**
	 * @param string                                                         $sister The sister these items are linked to.
	 * @param list<array{local: int, remote: int, type: string, uuid: string}> $links  Local item, the sister's item, post type, shared UUID.
	 * @param int                                                            $now    Current time.
	 * @return list<string> One of the constants above for each link, in order.
	 */
	public function apply( string $sister, array $links, int $now ): array {
		$results = array();
		foreach ( $links as $link ) {
			$result = $this->check( $sister, $link );
			if ( self::OK === $result ) {
				$item = $this->index->get( $link['local'] );
				if ( null !== $item && $item->uuid !== $link['uuid'] ) {
					$this->index->setUuid( $link['local'], $link['uuid'] );
				}
				$this->map->save( new MapEntry( $link['uuid'], MapEntry::POST, $link['local'], $sister, $link['remote'], $now ) );
			}
			$results[] = $result;
		}
		return $results;
	}

	/**
	 * @param array{local: int, remote: int, type: string, uuid: string} $link
	 */
	public function check( string $sister, array $link ): string {
		if ( $link['local'] < 1 || $link['remote'] < 1 || ! Uuid::isValid( $link['uuid'] ) ) {
			return self::INVALID;
		}
		$item = $this->index->get( $link['local'] );
		if ( null === $item ) {
			return self::MISSING;
		}
		if ( $item->type !== $link['type'] ) {
			return self::TYPE_MISMATCH;
		}
		if ( null !== $item->uuid && $item->uuid !== $link['uuid'] ) {
			return self::DIFFERENT_ID;
		}
		if ( array() !== array_diff( $this->index->uuidOwners( $link['uuid'] ), array( $link['local'] ) ) ) {
			return self::ID_IN_USE;
		}
		$existing = $this->map->get( $sister, MapEntry::POST, $link['local'] );
		if ( null !== $existing && $existing->remoteId !== $link['remote'] ) {
			return self::ALREADY_LINKED;
		}
		$other = $this->map->byRemote( $sister, MapEntry::POST, $link['remote'] );
		if ( null !== $other && $other->localId !== $link['local'] ) {
			return self::ALREADY_LINKED;
		}
		return self::OK;
	}

	/**
	 * Explains a result to an admin.
	 *
	 * @param string $label Name of the site that refused the link.
	 */
	public static function message( string $result, string $label ): string {
		return match ( $result ) {
			self::MISSING        => sprintf( /* translators: %s: site name. */ __( 'It no longer exists on %s.', 'sceneshifter' ), $label ),
			self::TYPE_MISMATCH  => sprintf( /* translators: %s: site name. */ __( 'It is a different content type on %s.', 'sceneshifter' ), $label ),
			self::DIFFERENT_ID   => sprintf( /* translators: %s: site name. */ __( 'On %s it is already linked to other content.', 'sceneshifter' ), $label ),
			self::ID_IN_USE      => sprintf( /* translators: %s: site name. */ __( 'On %s another item already has the same Sceneshifter ID.', 'sceneshifter' ), $label ),
			self::ALREADY_LINKED => sprintf( /* translators: %s: site name. */ __( 'On %s one of the two is already linked to something else.', 'sceneshifter' ), $label ),
			default              => sprintf( /* translators: %s: site name. */ __( '%s refused the link.', 'sceneshifter' ), $label ),
		};
	}
}
