<?php
/**
 * TargetLookup over this site's database.
 *
 * @package RedC\Sceneshifter
 */

declare(strict_types=1);

namespace RedC\Sceneshifter\Transfer;

use RedC\Sceneshifter\SyncMap\Uuid;
use RedC\Sceneshifter\SyncMap\WpContentIndex;

final class WpTargetLookup implements TargetLookup {

	private const LIVE_STATUSES = array( 'publish', 'future', 'draft', 'pending', 'private', 'inherit' );

	public function byUuid( string $uuid ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery
		$ids   = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s", Uuid::META_KEY, $uuid ) ) );
		$posts = array_values( array_filter( array_map( 'get_post', array_unique( $ids ) ), fn( $p ): bool => $p instanceof \WP_Post && $this->live( $p ) ) );
		if ( 1 !== count( $posts ) ) {
			return null;
		}
		return array(
			'id'   => $posts[0]->ID,
			'type' => $posts[0]->post_type,
		);
	}

	public function byPath( string $type, string $path ): array {
		if ( ! is_post_type_hierarchical( $type ) ) {
			return $this->bySlug( $type, $path );
		}
		$post = get_page_by_path( $path, OBJECT, $type );
		return $post instanceof \WP_Post && $this->live( $post ) && WpContentIndex::path( $post ) === $path ? array( $post->ID ) : array();
	}

	public function bySlug( string $type, string $slug ): array {
		global $wpdb;
		$statuses     = self::LIVE_STATUSES;
		$placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders built above.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_name = %s AND post_status IN ({$placeholders}) LIMIT 5", $type, $slug, ...$statuses ) );
		return array_map( 'intval', $ids );
	}

	public function mediaByFile( string $file ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s LIMIT 5", $file ) );
		return self::withSizes( $ids );
	}

	public function mediaByName( string $name ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND ( meta_value = %s OR meta_value LIKE %s ) LIMIT 20", $name, '%/' . $wpdb->esc_like( $name ) ) );
		return self::withSizes( $ids );
	}

	public function termBySlug( string $taxonomy, string $slug ): ?int {
		if ( '' === $taxonomy || '' === $slug || ! taxonomy_exists( $taxonomy ) ) {
			return null;
		}
		$term = get_term_by( 'slug', $slug, $taxonomy );
		return $term instanceof \WP_Term ? $term->term_id : null;
	}

	public function userByEmail( string $email ): ?array {
		$user = get_user_by( 'email', $email );
		return $user instanceof \WP_User ? array(
			'id'   => $user->ID,
			'name' => $user->display_name,
		) : null;
	}

	private function live( \WP_Post $post ): bool {
		return in_array( $post->post_status, self::LIVE_STATUSES, true );
	}

	/**
	 * @param array<int|string> $ids
	 * @return list<array{id: int, size: int|null}>
	 */
	private static function withSizes( array $ids ): array {
		$out = array();
		foreach ( array_unique( array_map( 'intval', $ids ) ) as $id ) {
			if ( 'attachment' === get_post_type( $id ) ) {
				$out[] = array(
					'id'   => $id,
					'size' => WpContentIndex::fileSize( $id ),
				);
			}
		}
		return $out;
	}
}
