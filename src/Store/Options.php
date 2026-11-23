<?php
/**
 * Reads and writes the plugin's options.
 *
 * @package RedC\SisterSync
 */

declare(strict_types=1);

namespace RedC\SisterSync\Store;

use RedC\SisterSync\Registry\Registry;
use Throwable;

final class Options {

	public const REGISTRY   = 'sister_sync_registry';
	public const POST_TYPES = 'sister_sync_post_types';
	public const CHECK      = 'sister_sync_check';

	public const DEFAULT_POST_TYPES = array( 'post', 'page' );

	public function registry(): Registry {
		$data = get_option( self::REGISTRY, array() );
		if ( ! is_array( $data ) || array() === $data ) {
			return Registry::empty();
		}
		try {
			return Registry::fromArray( $data );
		} catch ( Throwable $e ) {
			// Stored data is validated on save, so this only happens if it was edited directly.
			return Registry::empty();
		}
	}

	public function saveRegistry( Registry $registry ): void {
		update_option( self::REGISTRY, $registry->toArray(), true );
	}

	/**
	 * Enabled post types that actually exist on this site.
	 *
	 * @return list<string>
	 */
	public function postTypes(): array {
		$saved = get_option( self::POST_TYPES, self::DEFAULT_POST_TYPES );
		if ( ! is_array( $saved ) ) {
			$saved = self::DEFAULT_POST_TYPES;
		}
		return array_values( array_filter( array_map( 'strval', $saved ), 'post_type_exists' ) );
	}

	/**
	 * @param list<string> $postTypes
	 */
	public function savePostTypes( array $postTypes ): void {
		update_option( self::POST_TYPES, array_values( array_unique( $postTypes ) ), true );
	}

	public function checkSettings(): CheckSettings {
		$data = get_option( self::CHECK, array() );
		return CheckSettings::fromArray( is_array( $data ) ? $data : array() );
	}

	public function saveCheckSettings( CheckSettings $settings ): void {
		update_option( self::CHECK, $settings->toArray(), true );
	}

	public function isEnabledPostType( string $postType ): bool {
		return in_array( $postType, $this->postTypes(), true );
	}
}
