<?php
/**
 * A coloured badge in the admin bar showing which environment you are on.
 *
 * @package RedC\SisterSync
 */

declare(strict_types=1);

namespace RedC\SisterSync\Admin;

use RedC\SisterSync\Plugin;
use WP_Admin_Bar;

final class AdminBar {

	public function __construct( private readonly Plugin $plugin ) {}

	public function register(): void {
		add_action( 'admin_bar_menu', array( $this, 'addNode' ), 100 );
	}

	public function addNode( WP_Admin_Bar $bar ): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		$identity = $this->plugin->identity();
		$site     = $identity->isActive() ? $identity->site : null;

		if ( null === $site && ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$label  = null !== $site ? $site->label : __( 'Unknown environment', 'sister-sync' );
		$colour = null !== $site ? $site->colour() : '#787c82';

		$bar->add_node(
			array(
				'id'    => 'sister-sync-env',
				'title' => sprintf(
					'<span style="background:%1$s;color:#fff;padding:2px 8px;border-radius:3px;font-weight:600;">%2$s</span>',
					esc_attr( $colour ),
					esc_html( $label )
				),
				'href'  => current_user_can( 'manage_options' ) ? admin_url( 'admin.php?page=' . SettingsPage::SLUG ) : false,
				'meta'  => array(
					'title' => __( 'Sister Sync environment', 'sister-sync' ),
				),
			)
		);
	}
}
