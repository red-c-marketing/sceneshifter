<?php
/**
 * The target's answer to POST /preflight: for each post in a push, what it
 * would replace, how each reference resolves here, and what this site's
 * code is missing. It reads only.
 *
 * @package RedC\Sceneshifter
 */

declare(strict_types=1);

namespace RedC\Sceneshifter\Transfer;

use RedC\Sceneshifter\SyncMap\MapEntry;
use RedC\Sceneshifter\SyncMap\MapStore;

final class PreflightResponder {

	public const ROUTE     = '/sceneshifter/v1/preflight';
	public const MAX_ITEMS = 20;

	public const FORMAT_MISMATCH   = 'format';
	public const POST_TYPE_MISSING = 'post_type_missing';
	public const TEMPLATE_MISSING  = 'template_missing';
	public const ACF_MISSING       = 'acf_missing';
	public const TAXONOMY_MISSING  = 'taxonomy_missing';
	public const BLOCKS_MISSING    = 'blocks_missing';

	public function __construct(
		private readonly string $format,
		private readonly MapStore $map,
		private readonly TargetLookup $lookup,
		private readonly SiteFacts $facts,
		private readonly ?PostWriter $writer = null
	) {}

	/**
	 * @param array<string, mixed> $payload format, items: list of Package::manifest().
	 * @return array<string, mixed>
	 */
	public function preflight( string $sender, array $payload ): array {
		if ( ( $payload['format'] ?? null ) !== $this->format ) {
			return array(
				'error'  => self::FORMAT_MISMATCH,
				'format' => $this->format,
			);
		}
		$resolver = new Resolver( $sender, $this->map, $this->lookup );
		$items    = array();
		foreach ( array_slice( is_array( $payload['items'] ?? null ) ? array_values( $payload['items'] ) : array(), 0, self::MAX_ITEMS ) as $manifest ) {
			$items[] = is_array( $manifest ) ? $this->item( $sender, $resolver, $manifest ) : array( 'error' => 'invalid' );
		}
		return array( 'items' => $items );
	}

	/**
	 * @param array<string, mixed> $manifest
	 * @return array<string, mixed>
	 */
	private function item( string $sender, Resolver $resolver, array $manifest ): array {
		$resolved = $resolver->resolve( $manifest );
		$type     = is_string( $manifest['type'] ?? null ) ? $manifest['type'] : '';
		$checks   = array();

		if ( ! $this->facts->postTypeExists( $type ) ) {
			$checks[] = array( 'code' => self::POST_TYPE_MISSING );
		}
		$template = is_string( $manifest['template'] ?? null ) ? $manifest['template'] : '';
		if ( ! $this->facts->templateExists( $type, $template ) ) {
			$checks[] = array(
				'code'     => self::TEMPLATE_MISSING,
				'template' => $template,
			);
		}
		foreach (
			array(
				self::ACF_MISSING      => $this->facts->missingAcfFields( self::strings( $manifest['acf'] ?? null ) ),
				self::TAXONOMY_MISSING => $this->facts->missingTaxonomies( self::strings( $manifest['taxonomies'] ?? null ) ),
				self::BLOCKS_MISSING   => $this->facts->missingBlocks( self::strings( $manifest['blocks'] ?? null ) ),
			) as $code => $missing
		) {
			if ( array() !== $missing ) {
				$checks[] = array(
					'code'  => $code,
					'names' => $missing,
				);
			}
		}

		$target = null;
		if ( null !== $resolved['target'] ) {
			$state = $this->facts->state( $resolved['target']['id'], self::strings( $manifest['meta_keys'] ?? null ) );
			$entry = $this->map->get( $sender, MapEntry::POST, $resolved['target']['id'] );
			if ( null !== $state ) {
				$target = $resolved['target'] + $state + array(
					'hash'        => ContentHash::overall( $state['parts'] ),
					'synced_hash' => $entry?->hash,
					'synced_at'   => $entry?->syncedAt,
					'held'        => $this->waiting( $resolved['target']['id'] ),
				);
			}
		}

		return array(
			'target'      => $target,
			'blocked_by'  => $resolved['blocked_by'],
			'posts'       => self::listed( $resolved['posts'] ),
			'attachments' => self::listed( $resolved['attachments'] ),
			'term_refs'   => self::listed( array_map( static fn( ?int $id ): ?array => null === $id ? null : array( 'id' => $id ), $resolved['term_refs'] ) ),
			'terms'       => $resolved['terms'],
			'author'      => $resolved['author'],
			'checks'      => $checks,
		);
	}

	/**
	 * An earlier update waiting for review for this post, which this one
	 * would replace: who pushed it, from where and when.
	 *
	 * @return array{sister: string, user: string, at: int}|null
	 */
	private function waiting( int $postId ): ?array {
		if ( null === $this->writer ) {
			return null;
		}
		$heldId = $this->writer->heldFor( $postId );
		$record = null === $heldId ? null : $this->writer->held( $heldId )['record'] ?? null;
		if ( ! is_array( $record ) ) {
			return null;
		}
		return array(
			'sister' => is_string( $record['sister'] ?? null ) ? $record['sister'] : '',
			'user'   => is_string( $record['user'] ?? null ) ? $record['user'] : '',
			'at'     => is_int( $record['at'] ?? null ) ? $record['at'] : 0,
		);
	}

	/**
	 * @param array<int, array<string, mixed>|null> $map Source ID => match.
	 * @return list<array<string, mixed>>
	 */
	private static function listed( array $map ): array {
		$out = array();
		foreach ( $map as $source => $match ) {
			$out[] = array( 'source' => $source ) + ( $match ?? array( 'id' => null ) );
		}
		return $out;
	}

	/**
	 * @return list<string>
	 */
	private static function strings( mixed $value ): array {
		return is_array( $value ) ? array_values( array_filter( $value, 'is_string' ) ) : array();
	}
}
