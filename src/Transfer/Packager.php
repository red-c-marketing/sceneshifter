<?php
/**
 * Builds a Package from a post on this site. Both sides use it: the
 * source to send a post, and the target to hash the post it would replace.
 * It only reads: a post without a UUID is packaged without one.
 *
 * @package RedC\Sceneshifter
 */

declare(strict_types=1);

namespace RedC\Sceneshifter\Transfer;

use RedC\Sceneshifter\Registry\Site;
use RedC\Sceneshifter\Rewrite\IdMap;
use RedC\Sceneshifter\Rewrite\Rewrite;
use RedC\Sceneshifter\Rewrite\UrlRewriter;
use RedC\Sceneshifter\SyncMap\Uuid;
use RedC\Sceneshifter\SyncMap\WpContentIndex;
use WP_Post;

final class Packager {

	public function __construct( private readonly Site $self ) {}

	public function build( int $postId ): ?Package {
		$post = get_post( $postId );
		if ( ! $post instanceof WP_Post || 'attachment' === $post->post_type ) {
			return null;
		}

		$meta = array();
		$raw  = get_post_meta( $post->ID );
		foreach ( is_array( $raw ) ? $raw : array() as $key => $values ) {
			$key = (string) $key;
			if ( ! Package::skipsMeta( $key ) ) {
				$meta[ $key ] = array_values( array_map( 'maybe_unserialize', (array) $values ) );
			}
		}

		$content = (string) $post->post_content;
		$blocks  = has_blocks( $content ) ? parse_blocks( $content ) : array();
		if ( array() !== $blocks ) {
			// Re-serialised, as the push writes it, so that block attributes
			// saved in an older format (0.82999999999999996 for 0.83) don't
			// count as a change on either site.
			$content = self::serialize( $blocks );
		}
		$names   = array();
		$acfKeys = self::acfKeysInMeta( $meta );
		self::walkBlocks( $blocks, $names, $acfKeys );

		$package = new Package(
			$post->ID,
			self::uuid( $post->ID ),
			WpContentIndex::path( $post ),
			self::fields( $post, $content ),
			$meta,
			self::acfTypes( $acfKeys ),
			self::terms( $post ),
			array_values( array_unique( $names ) ),
			array(),
			array(),
			array()
		);

		$refs = $this->rewriter()->references( $package );
		return new Package(
			$package->id,
			$package->uuid,
			$package->path,
			$package->fields,
			$package->meta,
			$package->acf,
			$package->terms,
			$package->blocks,
			self::describePosts( array_diff( $refs[ Rewrite::POST ], array( $post->ID ) ) ),
			self::describeAttachments( $refs[ Rewrite::ATTACHMENT ] ),
			self::describeTerms( $refs[ Rewrite::TERM ] )
		);
	}

	/**
	 * A rewriter that maps nothing, so every reference comes back unresolved.
	 */
	private function rewriter(): PackageRewriter {
		return new PackageRewriter( new UrlRewriter( $this->self, $this->self ), new IdMap(), array( self::class, 'parse' ), array( self::class, 'serialize' ) );
	}

	/**
	 * @return array<int, array<string, mixed>>|null
	 */
	public static function parse( string $content ): ?array {
		return has_blocks( $content ) ? parse_blocks( $content ) : null;
	}

	/**
	 * @param array<int, array<string, mixed>> $blocks
	 */
	public static function serialize( array $blocks ): string {
		return serialize_blocks( $blocks ); // phpcs:ignore -- WordPress's block serialiser, not PHP's.
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function fields( WP_Post $post, string $content ): array {
		$author = get_userdata( (int) $post->post_author );
		return array(
			'post_type'      => $post->post_type,
			'post_title'     => $post->post_title,
			'post_content'   => $content,
			'post_excerpt'   => $post->post_excerpt,
			'post_name'      => $post->post_name,
			'menu_order'     => (int) $post->menu_order,
			'post_status'    => $post->post_status,
			'post_date'      => $post->post_date,
			'post_date_gmt'  => $post->post_date_gmt,
			'comment_status' => $post->comment_status,
			'ping_status'    => $post->ping_status,
			'page_template'  => (string) get_page_template_slug( $post ),
			'post_parent'    => (int) $post->post_parent,
			'featured'       => (int) get_post_thumbnail_id( $post ),
			'author_email'   => $author ? (string) $author->user_email : '',
			'author_name'    => $author ? (string) $author->display_name : '',
		);
	}

	private static function uuid( int $id ): ?string {
		$uuid = get_post_meta( $id, Uuid::META_KEY, true );
		return Uuid::isValid( $uuid ) ? $uuid : null;
	}

	/**
	 * @return list<array{taxonomy: string, slug: string, name: string, parent: string}>
	 */
	private static function terms( WP_Post $post ): array {
		$taxonomies = get_object_taxonomies( $post->post_type );
		if ( array() === $taxonomies ) {
			return array();
		}
		$terms = wp_get_object_terms( $post->ID, $taxonomies );
		$out   = array();
		foreach ( is_array( $terms ) ? $terms : array() as $term ) {
			$parent = $term->parent ? get_term( $term->parent, $term->taxonomy ) : null;
			$out[]  = array(
				'taxonomy' => $term->taxonomy,
				'slug'     => $term->slug,
				'name'     => $term->name,
				'parent'   => $parent instanceof \WP_Term ? $parent->slug : '',
			);
		}
		return $out;
	}

	/**
	 * @param array<string, list<mixed>> $meta
	 * @return list<string>
	 */
	private static function acfKeysInMeta( array $meta ): array {
		$keys = array();
		foreach ( $meta as $key => $values ) {
			if ( str_starts_with( $key, '_' ) && is_string( $values[0] ?? null ) && str_starts_with( $values[0], 'field_' ) ) {
				$keys[] = $values[0];
			}
		}
		return $keys;
	}

	/**
	 * Collects block names, and the ACF field keys inside ACF block data.
	 *
	 * @param array<int, array<string, mixed>> $blocks
	 * @param list<string>                     $names
	 * @param list<string>                     $acfKeys
	 */
	private static function walkBlocks( array $blocks, array &$names, array &$acfKeys ): void {
		foreach ( $blocks as $block ) {
			$name = $block['blockName'] ?? null;
			if ( is_string( $name ) && '' !== $name ) {
				$names[] = $name;
				$data    = $block['attrs']['data'] ?? null;
				if ( str_starts_with( $name, 'acf/' ) && is_array( $data ) ) {
					foreach ( $data as $key => $value ) {
						if ( is_string( $key ) && str_starts_with( $key, '_' ) && is_string( $value ) && str_starts_with( $value, 'field_' ) ) {
							$acfKeys[] = $value;
						}
					}
				}
			}
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				self::walkBlocks( $block['innerBlocks'], $names, $acfKeys );
			}
		}
	}

	/**
	 * @param list<string> $keys
	 * @return array<string, string> Field key => type.
	 */
	private static function acfTypes( array $keys ): array {
		if ( ! function_exists( 'acf_get_field' ) ) {
			return array();
		}
		$types = array();
		foreach ( array_unique( $keys ) as $key ) {
			$field = acf_get_field( $key );
			if ( ! is_array( $field ) ) {
				// A clone stores "field_a_field_b": look up the cloned field.
				$last  = strrpos( $key, '_field_' );
				$key   = false === $last ? $key : substr( $key, $last + 1 );
				$field = false === $last ? null : acf_get_field( $key );
			}
			if ( is_array( $field ) && is_string( $field['type'] ?? null ) ) {
				$types[ $key ] = $field['type'];
			}
		}
		return $types;
	}

	/**
	 * @param array<int> $ids
	 * @return array<int, array<string, mixed>>
	 */
	private static function describePosts( array $ids ): array {
		$out = array();
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( ! $post instanceof WP_Post ) {
				$out[ $id ] = array( 'missing' => true );
				continue;
			}
			$out[ $id ] = array(
				'uuid'   => self::uuid( $id ),
				'type'   => $post->post_type,
				'path'   => WpContentIndex::path( $post ),
				'slug'   => $post->post_name,
				'title'  => $post->post_title,
				'status' => $post->post_status,
			);
		}
		return $out;
	}

	/**
	 * @param array<int> $ids
	 * @return array<int, array<string, mixed>>
	 */
	private static function describeAttachments( array $ids ): array {
		$out = array();
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( ! $post instanceof WP_Post || 'attachment' !== $post->post_type ) {
				$out[ $id ] = array( 'missing' => true );
				continue;
			}
			$out[ $id ] = array(
				'uuid'    => self::uuid( $id ),
				'file'    => WpContentIndex::path( $post ),
				'size'    => WpContentIndex::fileSize( $id ),
				'mime'    => $post->post_mime_type,
				'title'   => $post->post_title,
				'alt'     => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
				'caption' => $post->post_excerpt,
				'url'     => (string) wp_get_attachment_url( $id ),
			);
		}
		return $out;
	}

	/**
	 * @param array<int> $ids
	 * @return array<int, array<string, mixed>>
	 */
	private static function describeTerms( array $ids ): array {
		$out = array();
		foreach ( $ids as $id ) {
			$term = get_term( $id );
			$out[ $id ] = $term instanceof \WP_Term
				? array(
					'taxonomy' => $term->taxonomy,
					'slug'     => $term->slug,
					'name'     => $term->name,
				)
				: array( 'missing' => true );
		}
		return $out;
	}
}
