<?php
/**
 * "Check environment links" row action and bulk action on enabled list screens.
 *
 * @package RedC\Sceneshifter
 */

declare(strict_types=1);

namespace RedC\Sceneshifter\Check;

use RedC\Sceneshifter\Plugin;
use WP_Post;

final class ListIntegration {

	private const BULK_ACTION = 'sceneshifter_check';

	public function __construct( private readonly Plugin $plugin ) {}

	public function register(): void {
		add_action( 'admin_init', array( $this, 'registerBulkActions' ) );
		add_filter( 'post_row_actions', array( $this, 'rowAction' ), 10, 2 );
		add_filter( 'page_row_actions', array( $this, 'rowAction' ), 10, 2 );
	}

	public function registerBulkActions(): void {
		foreach ( $this->plugin->options()->postTypes() as $postType ) {
			add_filter( "bulk_actions-edit-{$postType}", array( $this, 'addBulkAction' ) );
			add_filter( "handle_bulk_actions-edit-{$postType}", array( $this, 'handleBulkAction' ), 10, 3 );
		}
	}

	/**
	 * @param array<string, string> $actions
	 * @return array<string, string>
	 */
	public function addBulkAction( array $actions ): array {
		$actions[ self::BULK_ACTION ] = __( 'Check environment links', 'sceneshifter' );
		return $actions;
	}

	/**
	 * @param list<int|string> $postIds
	 */
	public function handleBulkAction( string $redirect, string $action, array $postIds ): string {
		if ( self::BULK_ACTION !== $action ) {
			return $redirect;
		}
		return CheckPage::url( array_map( 'intval', $postIds ) );
	}

	/**
	 * @param array<string, string> $actions
	 * @return array<string, string>
	 */
	public function rowAction( array $actions, WP_Post $post ): array {
		if ( ! $this->plugin->options()->isEnabledPostType( $post->post_type ) || ! current_user_can( 'edit_post', $post->ID ) ) {
			return $actions;
		}
		$actions['sceneshifter_check'] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( CheckPage::url( array( $post->ID ) ) ),
			esc_html__( 'Check environment links', 'sceneshifter' )
		);
		return $actions;
	}
}
