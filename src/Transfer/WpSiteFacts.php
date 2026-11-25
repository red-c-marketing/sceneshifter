<?php
/**
 * SiteFacts for this WordPress site.
 *
 * @package RedC\Sceneshifter
 */

declare(strict_types=1);

namespace RedC\Sceneshifter\Transfer;

final class WpSiteFacts implements SiteFacts {

	public function __construct( private readonly Packager $packager ) {}

	public function postTypeExists( string $type ): bool {
		return '' !== $type && post_type_exists( $type );
	}

	public function templateExists( string $type, string $template ): bool {
		if ( '' === $template || 'default' === $template ) {
			return true;
		}
		$templates = wp_get_theme()->get_page_templates( null, $type );
		if ( isset( $templates[ $template ] ) ) {
			return true;
		}
		if ( function_exists( 'get_block_templates' ) && wp_is_block_theme() ) {
			return array() !== get_block_templates( array( 'slug__in' => array( $template ) ) );
		}
		return false;
	}

	public function missingAcfFields( array $keys ): array {
		if ( ! function_exists( 'acf_get_field' ) ) {
			return $keys;
		}
		return array_values( array_filter( $keys, static fn( string $key ): bool => ! acf_get_field( $key ) ) );
	}

	public function missingTaxonomies( array $taxonomies ): array {
		return array_values( array_filter( $taxonomies, static fn( string $t ): bool => ! taxonomy_exists( $t ) ) );
	}

	public function missingBlocks( array $blocks ): array {
		$registry = \WP_Block_Type_Registry::get_instance();
		return array_values( array_filter( $blocks, static fn( string $b ): bool => ! $registry->is_registered( $b ) ) );
	}

	public function state( int $id, array $metaKeys ): ?array {
		$post    = get_post( $id );
		$package = $this->packager->build( $id );
		if ( ! $post instanceof \WP_Post || null === $package ) {
			return null;
		}
		return array(
			'status'   => $post->post_status,
			'title'    => $post->post_title,
			'modified' => $post->post_modified_gmt,
			'parts'    => ContentHash::parts( $package, $metaKeys ),
		);
	}
}
