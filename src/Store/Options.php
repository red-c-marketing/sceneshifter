<?php
/**
 * Reads and writes the plugin's options.
 *
 * @package RedC\Sceneshifter
 */

declare(strict_types=1);

namespace RedC\Sceneshifter\Store;

use RedC\Sceneshifter\Registry\Registry;
use Throwable;

final class Options {

	public const REGISTRY   = 'sceneshifter_registry';
	public const POST_TYPES = 'sceneshifter_post_types';
	public const CHECK      = 'sceneshifter_check';
	public const PUSH       = 'sceneshifter_push';

	/** Allow plain HTTP to sisters on .test and .localhost hosts. */
	public const ALLOW_DEV_HTTP = 'sceneshifter_allow_dev_http';

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

	public function pushSettings(): PushSettings {
		$data = get_option( self::PUSH, array() );
		return PushSettings::fromArray( is_array( $data ) ? $data : array() );
	}

	public function savePushSettings( PushSettings $settings ): void {
		update_option( self::PUSH, $settings->toArray(), false );
	}

	public function allowDevHttp(): bool {
		return (bool) get_option( self::ALLOW_DEV_HTTP, false );
	}

	public function saveAllowDevHttp( bool $allow ): void {
		update_option( self::ALLOW_DEV_HTTP, $allow, false );
	}

	public function isEnabledPostType( string $postType ): bool {
		return in_array( $postType, $this->postTypes(), true );
	}
}
