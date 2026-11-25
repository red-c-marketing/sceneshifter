<?php

declare(strict_types=1);

namespace RedC\Sceneshifter\Tests\Unit;

use RedC\Sceneshifter\Transfer\ContentHash;
use RedC\Sceneshifter\Transfer\Package;
use RedC\Sceneshifter\Transfer\SiteFacts;

/**
 * A target site's code and posts, as far as preflight asks.
 */
final class FakeSiteFacts implements SiteFacts {

	/** @var list<string> */
	public array $postTypes = array( 'page', 'post' );

	/** @var list<string> */
	public array $templates = array();

	/** @var list<string> */
	public array $acf = array();

	/** @var list<string> */
	public array $taxonomies = array( 'category', 'post_tag' );

	/** @var list<string> */
	public array $blocks = array( 'core/paragraph', 'core/image' );

	/** @var array<int, Package> The target's own posts, as packages with its IDs. */
	public array $packages = array();

	/**
	 * @var array<int, string> Post ID => status; publish when not set.
	 */
	public array $statuses = array();

	public function postTypeExists( string $type ): bool {
		return in_array( $type, $this->postTypes, true );
	}

	public function templateExists( string $type, string $template ): bool {
		return '' === $template || 'default' === $template || in_array( $template, $this->templates, true );
	}

	public function missingAcfFields( array $keys ): array {
		return array_values( array_diff( $keys, $this->acf ) );
	}

	public function missingTaxonomies( array $taxonomies ): array {
		return array_values( array_diff( $taxonomies, $this->taxonomies ) );
	}

	public function missingBlocks( array $blocks ): array {
		return array_values( array_diff( $blocks, $this->blocks ) );
	}

	public function state( int $id, array $metaKeys ): ?array {
		$package = $this->packages[ $id ] ?? null;
		return null === $package ? null : array(
			'status'   => $this->statuses[ $id ] ?? 'publish',
			'title'    => $package->title(),
			'modified' => '2026-11-20 10:00:00',
			'parts'    => ContentHash::parts( $package, $metaKeys ),
		);
	}
}
