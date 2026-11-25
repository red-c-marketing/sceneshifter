<?php
/**
 * The Check meta box for the Classic editor. Hidden in the block editor,
 * where the sidebar panel takes over.
 *
 * @package RedC\Sceneshifter
 */

declare(strict_types=1);

namespace RedC\Sceneshifter\Check;

use RedC\Sceneshifter\Plugin;
use WP_Post;

final class ClassicMetaBox {

	public function __construct( private readonly Plugin $plugin ) {}

	public function register(): void {
		add_action( 'add_meta_boxes', array( $this, 'add' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	public function add( string $postType, mixed $post = null ): void {
		if ( ! $this->plugin->options()->isEnabledPostType( $postType ) ) {
			return;
		}
		add_meta_box(
			'sceneshifter-check',
			__( 'Sceneshifter', 'sceneshifter' ),
			array( $this, 'render' ),
			$postType,
			'side',
			'high',
			array( '__back_compat_meta_box' => true )
		);
	}

	public function render( WP_Post $post ): void {
		?>
		<div id="sceneshifter-classic" data-post-id="<?php echo (int) $post->ID; ?>">
			<p><?php esc_html_e( 'Find links and images that point at another environment of this site.', 'sceneshifter' ); ?></p>
			<p><button type="button" class="button" id="sceneshifter-classic-check"><?php esc_html_e( 'Check', 'sceneshifter' ); ?></button></p>
			<div id="sceneshifter-classic-results" aria-live="polite"></div>
		</div>
		<?php
	}

	public function enqueue( string $hook ): void {
		if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || $screen->is_block_editor() || ! $this->plugin->options()->isEnabledPostType( (string) $screen->post_type ) ) {
			return;
		}
		wp_enqueue_script(
			'sceneshifter-classic',
			SCENESHIFTER_URL . 'assets/classic-check.js',
			array( 'wp-api-fetch', 'wp-i18n' ),
			Plugin::assetVersion( 'assets/classic-check.js' ),
			true
		);
		wp_set_script_translations( 'sceneshifter-classic', 'sceneshifter' );
		wp_add_inline_script(
			'sceneshifter-classic',
			'window.sceneshifterClassic = ' . wp_json_encode( array( 'blockPublish' => $this->plugin->options()->checkSettings()->blockPublish ) ) . ';',
			'before'
		);
	}
}
