<?php
/**
 * Loads the block editor panel (built from assets/src/editor) on enabled
 * content types.
 *
 * @package RedC\SisterSync
 */

declare(strict_types=1);

namespace RedC\SisterSync\Check;

use RedC\SisterSync\Plugin;

final class EditorAssets {

	public function __construct( private readonly Plugin $plugin ) {}

	public function register(): void {
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue' ) );
	}

	public function enqueue(): void {
		$screen = get_current_screen();
		if ( ! $screen || 'post' !== $screen->base || ! $this->plugin->options()->isEnabledPostType( (string) $screen->post_type ) ) {
			return;
		}

		$assetFile = SISTER_SYNC_DIR . 'build/index.asset.php';
		if ( ! is_readable( $assetFile ) ) {
			return; // Not built yet: run `yarn build`.
		}
		$asset = require $assetFile;

		wp_enqueue_script(
			'sister-sync-editor',
			SISTER_SYNC_URL . 'build/index.js',
			$asset['dependencies'] ?? array(),
			$asset['version'] ?? SISTER_SYNC_VERSION,
			true
		);
		wp_set_script_translations( 'sister-sync-editor', 'sister-sync' );
		wp_add_inline_script(
			'sister-sync-editor',
			'window.sisterSyncEditor = ' . wp_json_encode( array( 'blockPublish' => $this->plugin->options()->checkSettings()->blockPublish ) ) . ';',
			'before'
		);

		wp_enqueue_style(
			'sister-sync-editor',
			SISTER_SYNC_URL . 'assets/editor.css',
			array(),
			Plugin::assetVersion( 'assets/editor.css' )
		);
	}
}
