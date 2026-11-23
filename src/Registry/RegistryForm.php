<?php
/**
 * Turns the rows posted by the settings form into a registry.
 *
 * @package RedC\SisterSync
 */

declare(strict_types=1);

namespace RedC\SisterSync\Registry;

use InvalidArgumentException;

final class RegistryForm {

	/**
	 * Parses posted rows. Blank rows are dropped. New rows get an ID made
	 * from their name; existing rows keep theirs, because later phases store
	 * site IDs in sync records.
	 *
	 * @param array<int|string, mixed> $posted   Rows with label, role, url, aliases (one per line), public, colour, id.
	 * @param Registry                 $previous The saved registry, for the version number.
	 * @return array{registry: ?Registry, rows: list<array{id: string, label: string, role: string, url: string, aliases: string, public: bool, colour: string}>, rowErrors: array<int, string>, error: ?string}
	 */
	public static function parse( array $posted, Registry $previous ): array {
		$rows = array();
		foreach ( $posted as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$clean = self::cleanRow( $row );
			if ( '' === $clean['label'] && '' === $clean['url'] && '' === $clean['aliases'] ) {
				continue;
			}
			$rows[] = $clean;
		}

		// Assign IDs to new rows after collecting the kept ones, so a new
		// "Live" can't take the ID of an existing site lower down the list.
		$taken = array();
		foreach ( $rows as $row ) {
			if ( '' !== $row['id'] ) {
				$taken[ $row['id'] ] = true;
			}
		}
		foreach ( $rows as $i => $row ) {
			if ( '' === $row['id'] ) {
				$rows[ $i ]['id']         = self::uniqueId( self::suggestId( $row['label'], $row['role'] ), $taken );
				$taken[ $rows[ $i ]['id'] ] = true;
			}
		}

		$sites     = array();
		$rowErrors = array();
		foreach ( $rows as $i => $row ) {
			try {
				$sites[] = new Site(
					$row['id'],
					$row['label'],
					$row['role'],
					$row['url'],
					self::splitAliases( $row['aliases'] ),
					$row['public'],
					self::storedColour( $row['colour'], $row['role'] )
				);
			} catch ( InvalidArgumentException $e ) {
				$rowErrors[ $i ] = self::rowMessage( $row, $e );
			}
		}

		$registry = null;
		$error    = null;
		if ( array() === $rowErrors ) {
			try {
				$registry = new Registry( $sites, max( 1, $previous->version ) );
				if ( $registry->toArray()['sites'] !== $previous->toArray()['sites'] ) {
					$registry = $registry->withVersion( $previous->version + 1 );
				}
			} catch ( InvalidArgumentException $e ) {
				$error = $e->getMessage();
			}
		}

		return array(
			'registry'  => $registry,
			'rows'      => $rows,
			'rowErrors' => $rowErrors,
			'error'     => $error,
		);
	}

	/**
	 * The registry's sites as form rows.
	 *
	 * @return list<array{id: string, label: string, role: string, url: string, aliases: string, public: bool, colour: string}>
	 */
	public static function rowsFrom( Registry $registry ): array {
		return array_map(
			static fn( Site $site ): array => array(
				'id'      => $site->id,
				'label'   => $site->label,
				'role'    => $site->role,
				'url'     => $site->url,
				'aliases' => implode( "\n", $site->aliases ),
				'public'  => $site->isPublic,
				'colour'  => $site->colour(),
			),
			$registry->sites()
		);
	}

	public static function suggestId( string $label, string $role ): string {
		$id = strtolower( $label );
		$id = (string) preg_replace( '/[^a-z0-9]+/', '-', $id );
		$id = trim( substr( trim( $id, '-' ), 0, 32 ), '-' );
		if ( '' === $id || ! preg_match( '/^[a-z0-9]/', $id ) ) {
			$id = in_array( $role, Site::ROLES, true ) ? $role : 'site';
		}
		return $id;
	}

	/**
	 * Accepts one address per line, or separated by commas or spaces.
	 *
	 * @return list<string>
	 */
	public static function splitAliases( string $aliases ): array {
		$parts = preg_split( '/[\s,]+/', $aliases ) ?: array();
		return array_values( array_unique( array_filter( array_map( 'trim', $parts ), static fn( string $a ): bool => '' !== $a ) ) );
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array{id: string, label: string, role: string, url: string, aliases: string, public: bool, colour: string}
	 */
	private static function cleanRow( array $row ): array {
		$url = trim( (string) ( $row['url'] ?? '' ) );
		if ( '' !== $url && ! preg_match( '#^[a-z][a-z0-9+.-]*://#i', $url ) ) {
			$url = 'https://' . $url;
		}
		$role = (string) ( $row['role'] ?? 'other' );

		return array(
			'id'      => strtolower( trim( (string) ( $row['id'] ?? '' ) ) ),
			'label'   => trim( (string) ( $row['label'] ?? '' ) ),
			'role'    => in_array( $role, Site::ROLES, true ) ? $role : 'other',
			'url'     => rtrim( $url, '/' ),
			'aliases' => implode( "\n", self::splitAliases( (string) ( $row['aliases'] ?? '' ) ) ),
			'public'  => ! empty( $row['public'] ),
			'colour'  => strtolower( trim( (string) ( $row['colour'] ?? '' ) ) ),
		);
	}

	/**
	 * Stores no colour when it is the role's default, so changing a site's
	 * role also changes its badge colour.
	 */
	private static function storedColour( string $colour, string $role ): string {
		return ( Site::DEFAULT_COLOURS[ $role ] ?? '' ) === $colour ? '' : $colour;
	}

	/**
	 * @param array{label: string, url: string} $row
	 */
	private static function rowMessage( array $row, InvalidArgumentException $e ): string {
		if ( '' === $row['label'] ) {
			return 'Give this site a name.';
		}
		if ( '' === $row['url'] ) {
			return 'Give this site an address.';
		}
		return $e->getMessage();
	}

	/**
	 * @param array<string, true> $taken
	 */
	private static function uniqueId( string $base, array $taken ): string {
		$id = $base;
		for ( $n = 2; isset( $taken[ $id ] ); $n++ ) {
			$suffix = '-' . $n;
			$id     = substr( $base, 0, 32 - strlen( $suffix ) ) . $suffix;
		}
		return $id;
	}
}
