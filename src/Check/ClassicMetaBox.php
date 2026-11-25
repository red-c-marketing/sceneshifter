<?php
/**
 * The Check meta box for the Classic editor. Hidden in the block editor,
 * where the sidebar panel takes over.
 *
 * @package RedC\SisterSync
 */

declare(strict_types=1);

namespace RedC\SisterSync\Check;

use RedC\SisterSync\Plugin;
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
			'sister-sync-check',
			__( 'Sister Sync', 'sister-sync' ),
			array( $this, 'render' ),
			$postType,
			'side',
			'high',
			array( '__back_compat_meta_box' => true )
		);
	}

	public function render( WP_Post $post ): void {
		?>
		<div id="sister-sync-classic" data-post-id="<?php echo (int) $post->ID; ?>">
			<p><?php esc_html_e( 'Find links and images that point at another environment of this site.', 'sister-sync' ); ?></p>
			<p><button type="button" class="button" id="sister-sync-classic-check"><?php esc_html_e( 'Check', 'sister-sync' ); ?></button></p>
			<div id="sister-sync-classic-results" aria-live="polite"></div>
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
			'sister-sync-classic',
			SISTER_SYNC_URL . 'assets/classic-check.js',
			array( 'wp-api-fetch', 'wp-i18n' ),
			Plugin::assetVersion( 'assets/classic-check.js' ),
			true
		);
		wp_set_script_translations( 'sister-sync-classic', 'sister-sync' );
		wp_add_inline_script(
			'sister-sync-classic',
			'window.sisterSyncClassic = ' . wp_json_encode( array( 'blockPublish' => $this->plugin->options()->checkSettings()->blockPublish ) ) . ';',
			'before'
		);
	}
}
