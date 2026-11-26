<?php
/**
 * Writes pushed posts with WordPress's own functions, so caches, revisions
 * and hooks behave as they would for an edit in the admin.
 *
 * @package RedC\Sceneshifter
 */

declare(strict_types=1);

namespace RedC\Sceneshifter\Transfer;

use RedC\Sceneshifter\SyncMap\Uuid;
use RuntimeException;
use WP_Post;

final class WpPostWriter implements PostWriter {

	/** Post columns a snapshot keeps and a restore puts back. */
	private const COLUMNS = array(
		'post_author',
		'post_date',
		'post_date_gmt',
		'post_content',
		'post_title',
		'post_excerpt',
		'post_status',
		'comment_status',
		'ping_status',
		'post_password',
		'post_name',
		'post_parent',
		'menu_order',
	);

	/** On a held copy: the post it updates. */
	public const HELD_FOR = '_sceneshifter_held_for';

	/** On a held copy: what applying it needs, as JSON. */
	public const HELD_RECORD = '_sceneshifter_held';

	/**
	 * @param int $defaultAuthor From Settings → Push; 0 for the first administrator.
	 */
	public function __construct( private readonly int $defaultAuthor = 0 ) {}

	public function snapshot( int $id ): array {
		$post = get_post( $id );
		if ( ! $post instanceof WP_Post ) {
			return array();
		}
		$fields = array();
		foreach ( self::COLUMNS as $column ) {
			$fields[ $column ] = $post->$column;
		}
		$meta = array();
		$raw  = get_post_meta( $id );
		foreach ( is_array( $raw ) ? $raw : array() as $key => $values ) {
			// As stored, still serialised, so a restore is byte for byte.
			$meta[ (string) $key ] = array_values( array_map( 'strval', (array) $values ) );
		}
		$terms = array();
		foreach ( get_object_taxonomies( $post->post_type ) as $taxonomy ) {
			$ids                  = wp_get_object_terms( $id, $taxonomy, array( 'fields' => 'ids' ) );
			$terms[ $taxonomy ] = is_array( $ids ) ? array_map( 'intval', $ids ) : array();
		}
		return array(
			'type'   => $post->post_type,
			'fields' => $fields,
			'meta'   => $meta,
			'terms'  => $terms,
		);
	}

	public function write( ?int $id, Package $package, array $options ): int {
		$f    = $package->fields;
		$post = array(
			'post_type'      => $package->type(),
			'post_title'     => (string) ( $f['post_title'] ?? '' ),
			'post_content'   => (string) ( $f['post_content'] ?? '' ),
			'post_excerpt'   => (string) ( $f['post_excerpt'] ?? '' ),
			'post_name'      => (string) ( $f['post_name'] ?? '' ),
			'menu_order'     => (int) ( $f['menu_order'] ?? 0 ),
			'post_parent'    => max( 0, (int) ( $f['post_parent'] ?? 0 ) ),
			'comment_status' => (string) ( $f['comment_status'] ?? 'closed' ),
			'ping_status'    => (string) ( $f['ping_status'] ?? 'closed' ),
			'post_status'    => $options['status'],
		);
		if ( null !== $options['author'] ) {
			$post['post_author'] = $options['author'];
		} elseif ( null === $id ) {
			$post['post_author'] = $this->defaultAuthor();
		}
		if ( ! $options['keep_date'] && in_array( $options['status'], array( 'publish', 'future', 'private' ), true ) ) {
			$post['post_date']     = (string) ( $f['post_date'] ?? '' );
			$post['post_date_gmt'] = (string) ( $f['post_date_gmt'] ?? '' );
		}

		$created = false;
		$this->withoutFilters(
			function () use ( &$id, &$created, $post ): void {
				if ( null === $id ) {
					$result  = wp_insert_post( wp_slash( $post ), true );
					$created = true;
				} else {
					$result = wp_update_post( wp_slash( array( 'ID' => $id ) + $post ), true );
				}
				if ( is_wp_error( $result ) ) {
					throw new RuntimeException( $result->get_error_message() );
				}
				$id = (int) $result;
			}
		);
		if ( null === $id ) {
			throw new RuntimeException( 'WordPress could not save the post.' );
		}

		try {
			$this->writeRest( $id, $package, $options );
		} catch ( \Throwable $e ) {
			if ( $created ) {
				wp_delete_post( $id, true );
			}
			throw $e;
		}
		clean_post_cache( $id );
		return $id;
	}

	/**
	 * Template, meta, featured image, terms and the Sceneshifter ID.
	 *
	 * @param array{status: string, author: int|null, keep_date: bool, create_terms: bool} $options
	 */
	private function writeRest( int $id, Package $package, array $options ): void {
		$template = (string) ( $package->fields['page_template'] ?? '' );
		if ( '' === $template || 'default' === $template ) {
			delete_post_meta( $id, '_wp_page_template' );
		} else {
			update_post_meta( $id, '_wp_page_template', $template );
		}

		foreach ( $package->meta as $key => $values ) {
			delete_post_meta( $id, $key );
			foreach ( $values as $value ) {
				if ( false === add_post_meta( $id, $key, wp_slash( $value ) ) ) {
					throw new RuntimeException( sprintf( 'WordPress could not save the custom field %s.', $key ) );
				}
			}
		}

		$featured = (int) ( $package->fields['featured'] ?? 0 );
		if ( $featured > 0 && 'attachment' === get_post_type( $featured ) ) {
			set_post_thumbnail( $id, $featured );
		} else {
			delete_post_thumbnail( $id );
		}

		$this->writeTerms( $id, $package, $options['create_terms'] );
		update_post_meta( $id, Uuid::META_KEY, (string) $package->uuid );
	}

	private function writeTerms( int $id, Package $package, bool $create ): void {
		$registered = get_object_taxonomies( $package->type() );
		$carried    = $package->fields['taxonomies'] ?? array_column( $package->terms, 'taxonomy' );
		$taxonomies = array_values( array_intersect( array_unique( array_filter( (array) $carried, 'is_string' ) ), $registered ) );
		foreach ( $taxonomies as $taxonomy ) {
			$ids = array();
			foreach ( $package->terms as $term ) {
				if ( $term['taxonomy'] !== $taxonomy ) {
					continue;
				}
				$termId = self::termId( $taxonomy, $term['slug'] );
				if ( null === $termId && $create ) {
					$parent = '' === $term['parent'] ? null : self::termId( $taxonomy, $term['parent'] );
					$made   = wp_insert_term(
						$term['name'],
						$taxonomy,
						array(
							'slug'   => $term['slug'],
							'parent' => $parent ?? 0,
						)
					);
					if ( is_wp_error( $made ) ) {
						throw new RuntimeException( sprintf( 'WordPress could not create the term %1$s (%2$s): %3$s', $term['name'], $taxonomy, $made->get_error_message() ) );
					}
					$termId = (int) $made['term_id'];
				}
				if ( null !== $termId ) {
					$ids[] = $termId;
				}
			}
			$set = wp_set_object_terms( $id, $ids, $taxonomy );
			if ( is_wp_error( $set ) ) {
				throw new RuntimeException( $set->get_error_message() );
			}
		}
	}

	public function restore( int $id, array $snapshot ): void {
		$fields = is_array( $snapshot['fields'] ?? null ) ? $snapshot['fields'] : array();
		if ( array() === $fields ) {
			throw new RuntimeException( 'The snapshot is empty.' );
		}
		$this->withoutFilters(
			static function () use ( $id, $fields ): void {
				$result = wp_update_post( wp_slash( array( 'ID' => $id ) + array_intersect_key( $fields, array_flip( self::COLUMNS ) ) ), true );
				if ( is_wp_error( $result ) ) {
					throw new RuntimeException( $result->get_error_message() );
				}
			}
		);

		$current = get_post_meta( $id );
		foreach ( array_keys( is_array( $current ) ? $current : array() ) as $key ) {
			delete_post_meta( $id, (string) $key );
		}
		foreach ( is_array( $snapshot['meta'] ?? null ) ? $snapshot['meta'] : array() as $key => $values ) {
			foreach ( (array) $values as $value ) {
				add_post_meta( $id, (string) $key, wp_slash( maybe_unserialize( (string) $value ) ) );
			}
		}
		foreach ( is_array( $snapshot['terms'] ?? null ) ? $snapshot['terms'] : array() as $taxonomy => $ids ) {
			if ( taxonomy_exists( (string) $taxonomy ) ) {
				wp_set_object_terms( $id, array_map( 'intval', (array) $ids ), (string) $taxonomy );
			}
		}
		clean_post_cache( $id );
	}

	public function discard( int $id, bool $trash ): void {
		if ( $trash ) {
			wp_trash_post( $id );
		} else {
			wp_delete_post( $id, true );
		}
	}

	public function urls( int $id ): array {
		$post = get_post( $id );
		$view = $post instanceof WP_Post && 'publish' === $post->post_status ? get_permalink( $post ) : get_preview_post_link( $post );
		return array(
			'edit' => admin_url( 'post.php?post=' . $id . '&action=edit' ),
			'view' => is_string( $view ) ? $view : '',
		);
	}

	public function heldFor( int $postId ): ?int {
		$type = get_post_type( $postId );
		if ( false === $type ) {
			return null;
		}
		$ids = get_posts(
			array(
				'post_type'        => $type,
				'post_status'      => array( 'pending', 'draft', 'private', 'publish', 'future' ),
				'meta_key'         => self::HELD_FOR, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'       => (string) $postId, // phpcs:ignore WordPress.DB.SlowDBQuery
				'fields'           => 'ids',
				'numberposts'      => 1,
				'orderby'          => 'ID',
				'order'            => 'DESC',
				'suppress_filters' => true,
			)
		);
		return array() === $ids ? null : (int) $ids[0];
	}

	public function hold( int $postId, ?int $heldId, Package $package, array $record ): int {
		$post = get_post( $postId );
		if ( ! $post instanceof WP_Post ) {
			throw new RuntimeException( 'The post this update is for no longer exists.' );
		}
		$copy = $package->with( array_merge( $package->fields, array( 'post_name' => '' ) ), $package->meta );
		$id   = $this->write(
			$heldId,
			$copy,
			array(
				'status'       => 'pending',
				'author'       => (int) $post->post_author,
				'keep_date'    => true,
				'create_terms' => ! empty( $record['options']['create_terms'] ),
			)
		);
		delete_post_meta( $id, Uuid::META_KEY );
		update_post_meta( $id, self::HELD_FOR, $postId );
		update_post_meta( $id, self::HELD_RECORD, wp_slash( (string) wp_json_encode( $record ) ) );
		return $id;
	}

	public function held( int $heldId ): ?array {
		if ( ! get_post( $heldId ) instanceof WP_Post ) {
			return null;
		}
		$postId = (int) get_post_meta( $heldId, self::HELD_FOR, true );
		$raw    = get_post_meta( $heldId, self::HELD_RECORD, true );
		$record = is_string( $raw ) ? json_decode( $raw, true, 512 ) : null;
		return $postId > 0 && is_array( $record ) ? array(
			'post'   => $postId,
			'record' => $record,
		) : null;
	}

	/**
	 * Signed requests have no logged-in user, so WordPress would run the
	 * post through KSES and strip embeds and scripts an administrator wrote.
	 * The content comes from an administrator on a paired site, so it is
	 * saved as written.
	 */
	private function withoutFilters( callable $write ): void {
		$kses = false !== has_filter( 'content_save_pre', 'wp_filter_post_kses' );
		kses_remove_filters();
		try {
			$write();
		} finally {
			if ( $kses ) {
				kses_init_filters();
			}
		}
	}

	private static function termId( string $taxonomy, string $slug ): ?int {
		$term = get_term_by( 'slug', $slug, $taxonomy );
		return $term instanceof \WP_Term ? (int) $term->term_id : null;
	}

	/**
	 * The author for a new post when no user here has the source author's
	 * email address: the one chosen in Settings → Push if they can still
	 * edit posts, otherwise the first administrator.
	 */
	private function defaultAuthor(): int {
		if ( $this->defaultAuthor > 0 && user_can( $this->defaultAuthor, 'edit_posts' ) ) {
			return $this->defaultAuthor;
		}
		$admins = get_users(
			array(
				'role'    => 'administrator',
				'number'  => 1,
				'orderby' => 'ID',
				'fields'  => 'ID',
			)
		);
		return (int) ( $admins[0] ?? 1 );
	}
}
