<?php
/**
 * What would change if an incoming registry replaced this site's.
 *
 * @package RedC\Sceneshifter
 */

declare(strict_types=1);

namespace RedC\Sceneshifter\Registry;

final class RegistryDiff {

	public const ADDED     = 'added';
	public const REMOVED   = 'removed';
	public const CHANGED   = 'changed';
	public const UNCHANGED = 'unchanged';

	/** Fields compared, in display order. */
	public const FIELDS = array( 'label', 'role', 'url', 'aliases', 'public', 'colour' );

	/**
	 * One entry per site in either registry: incoming order first, then
	 * sites that would be removed.
	 *
	 * @return list<array{status: string, site: Site, before: ?Site, fields: list<string>}>
	 */
	public static function compare( Registry $current, Registry $incoming ): array {
		$entries = array();
		foreach ( $incoming->sites() as $site ) {
			$before = $current->get( $site->id );
			if ( null === $before ) {
				$entries[] = array(
					'status' => self::ADDED,
					'site'   => $site,
					'before' => null,
					'fields' => array(),
				);
				continue;
			}
			$fields    = self::changedFields( $before, $site );
			$entries[] = array(
				'status' => array() === $fields ? self::UNCHANGED : self::CHANGED,
				'site'   => $site,
				'before' => $before,
				'fields' => $fields,
			);
		}
		foreach ( $current->sites() as $site ) {
			if ( null === $incoming->get( $site->id ) ) {
				$entries[] = array(
					'status' => self::REMOVED,
					'site'   => $site,
					'before' => $site,
					'fields' => array(),
				);
			}
		}
		return $entries;
	}

	public static function identical( Registry $current, Registry $incoming ): bool {
		return $current->toArray()['sites'] === $incoming->toArray()['sites'];
	}

	/**
	 * @return list<string>
	 */
	private static function changedFields( Site $before, Site $after ): array {
		$a = $before->toArray() + array( 'colour' => '' );
		$b = $after->toArray() + array( 'colour' => '' );
		// Compare the colour shown, so '' and the role's default count as the same.
		$a['colour'] = $before->colour();
		$b['colour'] = $after->colour();

		$changed = array();
		foreach ( self::FIELDS as $field ) {
			if ( $a[ $field ] !== $b[ $field ] ) {
				$changed[] = $field;
			}
		}
		return $changed;
	}
}
