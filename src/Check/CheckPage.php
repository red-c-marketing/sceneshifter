<?php
/**
 * Sister Sync → Check: results for one or more posts, reached from the list
 * view row action and bulk action.
 *
 * @package RedC\SisterSync
 */

declare(strict_types=1);

namespace RedC\SisterSync\Check;

use RedC\SisterSync\Plugin;
use RedC\SisterSync\Scanner\Finding;

final class CheckPage {

	public const SLUG = 'sister-sync';

	/**
	 * Upper limit on posts checked in one page load.
	 */
	private const MAX_POSTS = 100;

	public function __construct( private readonly Plugin $plugin ) {}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'addMenu' ) );
	}

	public function addMenu(): void {
		add_menu_page(
			__( 'Sister Sync', 'sister-sync' ),
			__( 'Sister Sync', 'sister-sync' ),
			'edit_posts',
			self::SLUG,
			array( $this, 'render' ),
			'dashicons-randomize',
			81
		);
		add_submenu_page(
			self::SLUG,
			__( 'Check sister links', 'sister-sync' ),
			__( 'Check', 'sister-sync' ),
			'edit_posts',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * @param list<int> $postIds
	 */
	public static function url( array $postIds ): string {
		return add_query_arg(
			array(
				'page'     => self::SLUG,
				'post_ids' => implode( ',', array_map( 'intval', $postIds ) ),
			),
			admin_url( 'admin.php' )
		);
	}

	public function render(): void {
		// Read-only page, so no nonce: it changes nothing.
		$raw = isset( $_GET['post_ids'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['post_ids'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$ids = array_slice( array_values( array_unique( array_filter( array_map( 'intval', explode( ',', $raw ) ) ) ) ), 0, self::MAX_POSTS );

		$site = $this->plugin->identity()->site;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Check sister links', 'sister-sync' ); ?></h1>
			<p>
				<?php
				printf(
					/* translators: %s: environment label. */
					esc_html__( 'You are on %s. Links listed here point at another environment of this site.', 'sister-sync' ),
					'<strong>' . esc_html( (string) $site?->label ) . '</strong>'
				);
				?>
			</p>

			<?php if ( array() === $ids ) : ?>
				<p><?php esc_html_e( 'Choose posts to check from any enabled list screen, using the "Check sister links" row action or bulk action. A site-wide scan report is coming next.', 'sister-sync' ); ?></p>
			<?php endif; ?>

			<?php
			foreach ( $ids as $id ) {
				$post = get_post( $id );
				if ( ! $post || ! current_user_can( 'edit_post', $id ) ) {
					continue;
				}
				$this->renderPost( $post, $this->plugin->postScanner()->scan( $post ) );
			}
			?>
		</div>
		<?php
	}

	/**
	 * @param list<Finding> $findings
	 */
	private function renderPost( \WP_Post $post, array $findings ): void {
		$counts = RestController::counts( $findings );
		?>
		<h2 style="margin-top:2em;">
			<a href="<?php echo esc_url( (string) get_edit_post_link( $post->ID ) ); ?>"><?php echo esc_html( get_the_title( $post ) ?: __( '(no title)', 'sister-sync' ) ); ?></a>
			<span style="font-weight:normal;font-size:13px;">
				<?php
				if ( array() === $findings ) {
					esc_html_e( 'No sister links found.', 'sister-sync' );
				} else {
					printf(
						/* translators: 1: number of errors, 2: number of warnings. */
						esc_html__( '%1$d errors, %2$d warnings', 'sister-sync' ),
						(int) $counts[ Finding::ERROR ],
						(int) $counts[ Finding::WARNING ]
					);
				}
				?>
			</span>
		</h2>
		<?php if ( array() !== $findings ) : ?>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Severity', 'sister-sync' ); ?></th>
						<th><?php esc_html_e( 'Points to', 'sister-sync' ); ?></th>
						<th><?php esc_html_e( 'URL', 'sister-sync' ); ?></th>
						<th><?php esc_html_e( 'Where', 'sister-sync' ); ?></th>
						<th><?php esc_html_e( 'Times', 'sister-sync' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $findings as $finding ) : ?>
						<tr>
							<td><?php echo esc_html( Finding::ERROR === $finding->severity ? __( 'Error', 'sister-sync' ) : __( 'Warning', 'sister-sync' ) ); ?></td>
							<td><span style="background:<?php echo esc_attr( $finding->sister->colour() ); ?>;color:#fff;padding:1px 6px;border-radius:3px;"><?php echo esc_html( $finding->sister->label ); ?></span></td>
							<td><code style="word-break:break-all;"><?php echo esc_html( $finding->url ); ?></code></td>
							<td><?php echo esc_html( $finding->locationText() ); ?></td>
							<td><?php echo (int) $finding->count; ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
	}
}
