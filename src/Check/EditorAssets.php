<?php
/**
 * Loads the block editor panel (built from assets/src/editor) on enabled
 * content types.
 *
 * @package RedC\Sceneshifter
 */

declare(strict_types=1);

namespace RedC\Sceneshifter\Check;

use RedC\Sceneshifter\Plugin;

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

		$assetFile = SCENESHIFTER_DIR . 'build/index.asset.php';
		if ( ! is_readable( $assetFile ) ) {
			return; // Not built yet: run `yarn build`.
		}
		$asset = require $assetFile;

		wp_enqueue_script(
			'sceneshifter-editor',
			SCENESHIFTER_URL . 'build/index.js',
			$asset['dependencies'] ?? array(),
			$asset['version'] ?? SCENESHIFTER_VERSION,
			true
		);
		wp_set_script_translations( 'sceneshifter-editor', 'sceneshifter' );
		wp_add_inline_script(
			'sceneshifter-editor',
			'window.sceneshifterEditor = ' . wp_json_encode( array( 'blockPublish' => $this->plugin->options()->checkSettings()->blockPublish ) ) . ';',
			'before'
		);

		wp_enqueue_style(
			'sceneshifter-editor',
			SCENESHIFTER_URL . 'assets/editor.css',
			array(),
			Plugin::assetVersion( 'assets/editor.css' )
		);
	}
}
