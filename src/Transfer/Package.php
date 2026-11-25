<?php
/**
 * One post as the source site sends it: its fields, filtered meta, terms,
 * ACF field types, and a description of everything it refers to, so the
 * target can find each reference by UUID, path or file.
 *
 * IDs inside are the source site's until PackageRewriter maps them.
 *
 * @package RedC\Sceneshifter
 */

declare(strict_types=1);

namespace RedC\Sceneshifter\Transfer;

use RedC\Sceneshifter\Scanner\ContentScanner;
use RedC\Sceneshifter\SyncMap\Uuid;

final class Package {

	/** Meta never sent: caches, edit locks, and what travels as a field. */
	public const META_EXCLUDES = array(
		'_wp_desired_post_slug',
		'_wp_trash_meta_comments_status',
	);

	/**
	 * @param int                                   $id          Post ID on the source.
	 * @param string|null                           $uuid        Sync map UUID, when it has one.
	 * @param string                                $path        Page URI or slug, as Link existing content matches it.
	 * @param array<string, mixed>                  $fields      Post fields (see Packager::fields()).
	 * @param array<string, list<mixed>>            $meta        Meta key => values, unserialised.
	 * @param array<string, string>                 $acf         ACF field key => field type, for every field used.
	 * @param list<array{taxonomy: string, slug: string, name: string, parent: string}> $terms Terms assigned.
	 * @param list<string>                          $blocks      Block names used in the content.
	 * @param array<int, array<string, mixed>>      $posts       Referenced posts by source ID: uuid, type, path, slug, title.
	 * @param array<int, array<string, mixed>>      $attachments Referenced media by source ID: uuid, file, size, mime, title, url.
	 * @param array<int, array<string, mixed>>      $termRefs    Terms referenced by ID (ACF taxonomy fields): taxonomy, slug, name.
	 */
	public function __construct(
		public readonly int $id,
		public readonly ?string $uuid,
		public readonly string $path,
		public readonly array $fields,
		public readonly array $meta,
		public readonly array $acf,
		public readonly array $terms,
		public readonly array $blocks,
		public readonly array $posts,
		public readonly array $attachments,
		public readonly array $termRefs
	) {}

	public function type(): string {
		return (string) ( $this->fields['post_type'] ?? '' );
	}

	public function title(): string {
		return (string) ( $this->fields['post_title'] ?? '' );
	}

	public static function skipsMeta( string $key ): bool {
		if ( in_array( $key, ContentScanner::DEFAULT_META_EXCLUDES, true ) || in_array( $key, self::META_EXCLUDES, true ) ) {
			return true;
		}
		foreach ( ContentScanner::DEFAULT_META_EXCLUDE_PREFIXES as $prefix ) {
			if ( str_starts_with( $key, $prefix ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * A copy with different fields, meta and references, as the rewriter
	 * returns it.
	 *
	 * @param array<string, mixed>       $fields
	 * @param array<string, list<mixed>> $meta
	 */
	public function with( array $fields, array $meta ): self {
		return new self( $this->id, $this->uuid, $this->path, $fields, $meta, $this->acf, $this->terms, $this->blocks, $this->posts, $this->attachments, $this->termRefs );
	}

	/**
	 * What the target needs to resolve references and check for drift,
	 * without the content itself.
	 *
	 * @return array<string, mixed>
	 */
	public function manifest(): array {
		return array(
			'id'          => $this->id,
			'uuid'        => $this->uuid,
			'type'        => $this->type(),
			'path'        => $this->path,
			'slug'        => (string) ( $this->fields['post_name'] ?? '' ),
			'template'    => (string) ( $this->fields['page_template'] ?? '' ),
			'author'      => (string) ( $this->fields['author_email'] ?? '' ),
			'acf'         => array_keys( $this->acf ),
			'meta_keys'   => array_map( 'strval', array_keys( $this->meta ) ),
			'taxonomies'  => array_values( array_unique( array_column( $this->terms, 'taxonomy' ) ) ),
			'terms'       => $this->terms,
			'blocks'      => $this->blocks,
			'posts'       => self::listed( $this->posts ),
			'attachments' => self::listed( $this->attachments ),
			'term_refs'   => self::listed( $this->termRefs ),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return array(
			'id'          => $this->id,
			'uuid'        => $this->uuid,
			'path'        => $this->path,
			'fields'      => $this->fields,
			'meta'        => $this->meta,
			'acf'         => $this->acf,
			'terms'       => $this->terms,
			'blocks'      => $this->blocks,
			'posts'       => self::listed( $this->posts ),
			'attachments' => self::listed( $this->attachments ),
			'term_refs'   => self::listed( $this->termRefs ),
		);
	}

	/**
	 * @param mixed $data A package another site sent.
	 */
	public static function fromArray( mixed $data ): ?self {
		if ( ! is_array( $data ) || ! is_int( $data['id'] ?? null ) || ! is_array( $data['fields'] ?? null ) || ! is_string( $data['fields']['post_type'] ?? null ) ) {
			return null;
		}
		$meta = array();
		foreach ( is_array( $data['meta'] ?? null ) ? $data['meta'] : array() as $key => $values ) {
			if ( is_string( $key ) && is_array( $values ) && ! self::skipsMeta( $key ) ) {
				$meta[ $key ] = array_values( $values );
			}
		}
		$terms = array();
		foreach ( is_array( $data['terms'] ?? null ) ? $data['terms'] : array() as $term ) {
			if ( is_array( $term ) && is_string( $term['taxonomy'] ?? null ) && is_string( $term['slug'] ?? null ) ) {
				$terms[] = array(
					'taxonomy' => $term['taxonomy'],
					'slug'     => $term['slug'],
					'name'     => is_string( $term['name'] ?? null ) ? $term['name'] : $term['slug'],
					'parent'   => is_string( $term['parent'] ?? null ) ? $term['parent'] : '',
				);
			}
		}
		$uuid = $data['uuid'] ?? null;
		return new self(
			$data['id'],
			Uuid::isValid( $uuid ) ? $uuid : null,
			is_string( $data['path'] ?? null ) ? $data['path'] : '',
			$data['fields'],
			$meta,
			array_filter( is_array( $data['acf'] ?? null ) ? $data['acf'] : array(), 'is_string' ),
			$terms,
			array_values( array_filter( is_array( $data['blocks'] ?? null ) ? $data['blocks'] : array(), 'is_string' ) ),
			self::keyed( $data['posts'] ?? null ),
			self::keyed( $data['attachments'] ?? null ),
			self::keyed( $data['term_refs'] ?? null )
		);
	}

	/**
	 * References as a list, because JSON turns integer keys into an object
	 * whose order and types PHP may not keep.
	 *
	 * @param array<int, array<string, mixed>> $refs
	 * @return list<array<string, mixed>>
	 */
	private static function listed( array $refs ): array {
		$list = array();
		foreach ( $refs as $id => $ref ) {
			$list[] = array( 'id' => $id ) + $ref;
		}
		return $list;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public static function keyed( mixed $list ): array {
		$refs = array();
		foreach ( is_array( $list ) ? $list : array() as $ref ) {
			if ( is_array( $ref ) && is_int( $ref['id'] ?? null ) && $ref['id'] > 0 ) {
				$id = $ref['id'];
				unset( $ref['id'] );
				$refs[ $id ] = $ref;
			}
		}
		return $refs;
	}
}
