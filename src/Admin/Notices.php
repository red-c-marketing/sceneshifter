<?php
/**
 * Tells administrators when Sister Sync is inactive, and why.
 *
 * @package RedC\SisterSync
 */

declare(strict_types=1);

namespace RedC\SisterSync\Admin;

use RedC\SisterSync\Pairing\Sisters;
use RedC\SisterSync\Plugin;
use RedC\SisterSync\Registry\Identity;

final class Notices {

	public function __construct( private readonly Plugin $plugin ) {}

	public function register(): void {
		add_action( 'admin_notices', array( $this, 'render' ) );
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$identity = $this->plugin->identity();
		$screen   = get_current_screen();
		if ( $identity->isActive() ) {
			$this->renderKeyProblem( $screen );
			return;
		}

		if ( Identity::UNCONFIGURED === $identity->status && $screen && str_contains( (string) $screen->id, SettingsPage::SLUG ) ) {
			return; // The settings page explains this itself.
		}

		$message = match ( $identity->status ) {
			Identity::UNCONFIGURED          => __( 'Sister Sync has no sites set up yet, so it is inactive.', 'sister-sync' ),
			Identity::HOME_NOT_IN_REGISTRY  => sprintf(
				/* translators: %s: the site's home URL. */
				__( 'Sister Sync is inactive: this site\'s address (%s) is not in the site registry.', 'sister-sync' ),
				$identity->detail
			),
			Identity::REQUEST_HOST_MISMATCH => sprintf(
				/* translators: 1: host the request came in on, 2: registry label of the site home_url() points to. */
				__( 'Sister Sync is inactive: this request came in on %1$s, but the site\'s home URL says it is %2$s. If this database was just copied from another environment, finish the search-replace first.', 'sister-sync' ),
				$identity->detail,
				$identity->site->label ?? ''
			),
			default                         => __( 'Sister Sync is inactive.', 'sister-sync' ),
		};

		printf(
			'<div class="notice notice-warning"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
			esc_html( $message ),
			esc_url( admin_url( 'admin.php?page=' . SettingsPage::SLUG ) ),
			esc_html__( 'Open Sister Sync settings', 'sister-sync' )
		);
	}

	/**
	 * On Sister Sync screens, says when this site's key was made on another
	 * environment, so it needs a new key and new pairings.
	 */
	private function renderKeyProblem( ?\WP_Screen $screen ): void {
		if ( ! $screen || ! str_contains( (string) $screen->id, 'sister-sync' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the Pairing tab explains this itself.
		if ( 'pairing' === ( $_GET['tab'] ?? '' ) ) {
			return;
		}
		$keys = $this->plugin->sisters()->keys();
		if ( null === $keys || ! $keys->needsNewKey() ) {
			return;
		}
		printf(
			'<div class="notice notice-error"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
			esc_html( Sisters::keyProblem( $keys->status() ) ),
			esc_url( PairingTab::url() ),
			esc_html__( 'Open the Pairing settings', 'sister-sync' )
		);
	}
}
