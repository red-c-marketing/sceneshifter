<?php
/**
 * ContentIndex over this site's posts and media.
 *
 * @package RedC\Sceneshifter
 */

declare(strict_types=1);

namespace RedC\Sceneshifter\SyncMap;

final class WpContentIndex implements ContentIndex {

	/** Statuses worth linking. Bin, auto-drafts and revisions are left out. */
	private const POST_STATUSES = array( 'publish', 'future', 'draft', 'pending', 'private' );
	private const MEDIA_STATUSES = array( 'inherit', 'private' );

	/** @var list<string> */
	private array $types;

	/**
	 * @param list<string> $postTypes Enabled content types; media is always included.
	 */
	public function __construct( array $postTypes ) {
		$types       = array_values( array_diff( $postTypes, array( 'attachment' ) ) );
		$types[]     = 'attachment';
		$this->types = $types;
	}

	public function linkableTypes(): array {
		return $this->types;
	}

	public function page( string $type, int $page, int $perPage ): array {
		if ( ! in_array( $type, $this->types, true ) ) {
			return array(
				'items' => array(),
				'total' => 0,
			);
		}
		$query = new \WP_Query(
			array(
				'post_type'              => $type,
				'post_status'            => self::statuses( $type ),
				'posts_per_page'         => max( 1, $perPage ),
				'paged'                  => max( 1, $page ),
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'ignore_sticky_posts'    => true,
				'suppress_filters'       => true,
				'update_post_term_cache' => false,
			)
		);
		$posts = array_values( array_filter( $query->posts, static fn( $post ): bool => $post instanceof \WP_Post ) );
		$dupes = $this->sharedUuids( $posts );
		$items = array();
		foreach ( $posts as $post ) {
			$items[] = $this->item( $post, $dupes );
		}
		return array(
			'items' => $items,
			'total' => (int) $query->found_posts,
		);
	}

	public function get( int $id ): ?Item {
		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post || ! in_array( $post->post_type, $this->types, true ) || ! in_array( $post->post_status, self::statuses( $post->post_type ), true ) ) {
			return null;
		}
		return $this->item( $post, $this->sharedUuids( array( $post ) ) );
	}

	public function uuidOwners( string $uuid ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s", Uuid::META_KEY, $uuid ) );
		return array_values( array_unique( array_map( 'intval', $ids ) ) );
	}

	public function setUuid( int $id, string $uuid ): void {
		update_post_meta( $id, Uuid::META_KEY, $uuid );
	}

	/**
	 * @param array<string, true> $dupes UUIDs shared by more than one post.
	 */
	private function item( \WP_Post $post, array $dupes ): Item {
		$uuid = get_post_meta( $post->ID, Uuid::META_KEY, true );
		$uuid = Uuid::isValid( $uuid ) ? $uuid : null;

		if ( 'attachment' === $post->post_type ) {
			$key  = (string) get_post_meta( $post->ID, '_wp_attached_file', true );
			$size = self::fileSize( $post->ID );
		} else {
			$key  = is_post_type_hierarchical( $post->post_type ) ? (string) get_page_uri( $post ) : $post->post_name;
			$size = null;
		}

		return new Item(
			$post->ID,
			$post->post_type,
			$key,
			$size,
			$uuid,
			mb_substr( html_entity_decode( wp_strip_all_tags( $post->post_title ), ENT_QUOTES, 'UTF-8' ), 0, 120 ),
			$post->post_status,
			null !== $uuid && isset( $dupes[ $uuid ] )
		);
	}

	private static function fileSize( int $id ): ?int {
		$meta = get_post_meta( $id, '_wp_attachment_metadata', true );
		if ( is_array( $meta ) && isset( $meta['filesize'] ) && is_numeric( $meta['filesize'] ) ) {
			return (int) $meta['filesize'];
		}
		$file = get_attached_file( $id );
		if ( is_string( $file ) && '' !== $file && is_file( $file ) ) {
			$size = filesize( $file );
			return false === $size ? null : $size;
		}
		return null;
	}

	/**
	 * Which of these posts' UUIDs also belong to another post, most likely
	 * because a duplicate-post plugin copied the meta.
	 *
	 * @param list<\WP_Post> $posts
	 * @return array<string, true>
	 */
	private function sharedUuids( array $posts ): array {
		$uuids = array();
		foreach ( $posts as $post ) {
			$uuid = get_post_meta( $post->ID, Uuid::META_KEY, true );
			if ( Uuid::isValid( $uuid ) ) {
				$uuids[] = $uuid;
			}
		}
		if ( array() === $uuids ) {
			return array();
		}
		global $wpdb;
		$placeholders = implode( ',', array_fill( 0, count( $uuids ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders built above.
		$shared = $wpdb->get_col( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value IN ({$placeholders}) GROUP BY meta_value HAVING COUNT(DISTINCT post_id) > 1", Uuid::META_KEY, ...$uuids ) );
		return array_fill_keys( array_map( 'strval', $shared ), true );
	}

	/**
	 * @return list<string>
	 */
	private static function statuses( string $type ): array {
		return 'attachment' === $type ? self::MEDIA_STATUSES : self::POST_STATUSES;
	}
}
