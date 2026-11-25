<?php
/**
 * Rewrites a whole package for the target: content (parsed blocks or
 * Classic HTML), excerpt, parent, featured image and meta. Meta values
 * that ACF types as images, posts or terms have their IDs mapped; every
 * value also has source URLs moved onto the target.
 *
 * Run with an empty IdMap, every ID comes back unresolved, which is how
 * the packager lists what a post refers to.
 *
 * @package RedC\Sceneshifter
 */

declare(strict_types=1);

namespace RedC\Sceneshifter\Transfer;

use RedC\Sceneshifter\Rewrite\AcfValues;
use RedC\Sceneshifter\Rewrite\BlockRewriter;
use RedC\Sceneshifter\Rewrite\IdMap;
use RedC\Sceneshifter\Rewrite\Rewrite;
use RedC\Sceneshifter\Rewrite\Unresolved;
use RedC\Sceneshifter\Rewrite\UrlRewriter;

final class PackageRewriter {

	/** @var callable(string): ?array<int, array<string, mixed>> */
	private $parse;

	/** @var callable(array<int, array<string, mixed>>): string */
	private $serialize;

	/**
	 * @param UrlRewriter                                         $urls      Source URLs onto the target.
	 * @param IdMap                                               $ids       Source => target IDs.
	 * @param callable(string): ?array<int, array<string, mixed>> $parse     Parsed blocks, or null for Classic content.
	 * @param callable(array<int, array<string, mixed>>): string  $serialize Blocks back to post content.
	 */
	public function __construct(
		private readonly UrlRewriter $urls,
		private readonly IdMap $ids,
		callable $parse,
		callable $serialize
	) {
		$this->parse     = $parse;
		$this->serialize = $serialize;
	}

	/**
	 * @return array{package: Package, rewrites: list<Rewrite>, unresolved: list<Unresolved>}
	 */
	public function rewrite( Package $package ): array {
		$rewriter = new BlockRewriter( $this->urls, $this->ids, $package->acf );
		$rewriter->begin();
		$fields = $package->fields;

		$content = (string) ( $fields['post_content'] ?? '' );
		$blocks  = ( $this->parse )( $content );
		$fields['post_content'] = null === $blocks
			? $rewriter->rewriteHtmlText( $content, 'Content' )
			: ( $this->serialize )( $rewriter->rewriteBlocks( $blocks ) );

		$fields['post_excerpt'] = $rewriter->rewriteUrls( (string) ( $fields['post_excerpt'] ?? '' ), 'Excerpt' );

		if ( (int) ( $fields['featured'] ?? 0 ) > 0 ) {
			$fields['featured'] = $rewriter->mapValue( Rewrite::ATTACHMENT, (int) $fields['featured'], 'Featured image' );
		}
		if ( (int) ( $fields['post_parent'] ?? 0 ) > 0 ) {
			$fields['post_parent'] = $rewriter->mapValue( Rewrite::POST, (int) $fields['post_parent'], 'Parent' );
		}

		$meta = array();
		foreach ( $package->meta as $key => $values ) {
			$kind     = AcfValues::idKind( AcfValues::type( $package->acf, AcfValues::fieldKey( $package->meta, $key ) ) );
			$location = 'Meta ' . $key;
			foreach ( $values as $value ) {
				if ( null !== $kind ) {
					$value = $rewriter->mapValue( $kind, $value, $location );
				}
				$meta[ $key ][] = $rewriter->rewriteUrls( $value, $location );
			}
		}

		$result = $rewriter->finish();
		return array(
			'package'    => $package->with( $fields, $meta ),
			'rewrites'   => $result['rewrites'],
			'unresolved' => self::unique( $result['unresolved'] ),
		);
	}

	/**
	 * Every ID a package refers to, by kind.
	 *
	 * @return array{attachment: list<int>, post: list<int>, term: list<int>}
	 */
	public function references( Package $package ): array {
		$refs = array(
			Rewrite::ATTACHMENT => array(),
			Rewrite::POST       => array(),
			Rewrite::TERM       => array(),
		);
		foreach ( ( new self( $this->urls, new IdMap(), $this->parse, $this->serialize ) )->rewrite( $package )['unresolved'] as $item ) {
			$refs[ $item->kind ][] = $item->id;
		}
		foreach ( $refs as $kind => $ids ) {
			$refs[ $kind ] = array_values( array_unique( $ids ) );
		}
		return $refs;
	}

	/**
	 * One entry per missing ID, keeping the first place it was found.
	 *
	 * @param list<Unresolved> $items
	 * @return list<Unresolved>
	 */
	private static function unique( array $items ): array {
		$seen = array();
		foreach ( $items as $item ) {
			$seen[ $item->kind . ':' . $item->id ] ??= $item;
		}
		return array_values( $seen );
	}
}
