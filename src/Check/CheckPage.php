<?php
/**
 * Sceneshifter → Check: results for one or more posts, reached from the list
 * view row action and bulk action.
 *
 * @package RedC\Sceneshifter
 */

declare(strict_types=1);

namespace RedC\Sceneshifter\Check;

use RedC\Sceneshifter\Plugin;
use RedC\Sceneshifter\Scanner\Finding;

final class CheckPage {

	public const SLUG = 'sceneshifter';

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
			__( 'Sceneshifter', 'sceneshifter' ),
			__( 'Sceneshifter', 'sceneshifter' ),
			'edit_posts',
			self::SLUG,
			array( $this, 'render' ),
			'dashicons-randomize',
			81
		);
		add_submenu_page(
			self::SLUG,
			__( 'Check sister links', 'sceneshifter' ),
			__( 'Check', 'sceneshifter' ),
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
			<h1><?php esc_html_e( 'Check sister links', 'sceneshifter' ); ?></h1>
			<p>
				<?php
				printf(
					/* translators: %s: environment label. */
					esc_html__( 'You are on %s. Links listed here point at another environment of this site.', 'sceneshifter' ),
					'<strong>' . esc_html( (string) $site?->label ) . '</strong>'
				);
				?>
			</p>

			<?php
			if ( array() === $ids ) {
				$this->renderStart();
			}
			?>

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
	 * What to do when no posts were sent here: links to the list screens
	 * that have the Check actions, and to the site-wide report.
	 */
	private function renderStart(): void {
		$links = array();
		foreach ( $this->plugin->options()->postTypes() as $postType ) {
			$object = get_post_type_object( $postType );
			if ( ! $object || ! $object->show_ui || ! current_user_can( $object->cap->edit_posts ) ) {
				continue;
			}
			$links[] = sprintf(
				'<a href="%1$s">%2$s</a>',
				esc_url( admin_url( 'edit.php?post_type=' . $postType ) ),
				esc_html( $object->labels->name )
			);
		}
		?>
		<p><?php esc_html_e( 'To check particular posts, open one of the lists below. Use "Check sister links" under a post\'s title, or tick several and choose "Check sister links" from Bulk actions. The results appear on this page.', 'sceneshifter' ); ?></p>
		<?php if ( array() !== $links ) : ?>
			<ul class="ul-disc">
				<?php foreach ( $links as $link ) : ?>
					<li><?php echo $link; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?></li>
				<?php endforeach; ?>
			</ul>
		<?php else : ?>
			<p><em><?php esc_html_e( 'No content types are enabled for checking. An administrator can choose them in Sceneshifter → Settings → Check.', 'sceneshifter' ); ?></em></p>
		<?php endif; ?>
		<?php if ( current_user_can( 'manage_options' ) ) : ?>
			<p>
				<?php
				printf(
					/* translators: %s: link to the report page. */
					esc_html__( 'To check the whole site at once, including menus, widgets, ACF options and synced patterns, use the %s.', 'sceneshifter' ),
					'<a href="' . esc_url( \RedC\Sceneshifter\SiteScan\ReportPage::url() ) . '">' . esc_html__( 'Report', 'sceneshifter' ) . '</a>'
				);
				?>
			</p>
		<?php endif; ?>
		<p><?php esc_html_e( 'The editor also checks each post as you write, in the Sceneshifter panel.', 'sceneshifter' ); ?></p>
		<?php
	}

	/**
	 * @param list<Finding> $findings
	 */
	private function renderPost( \WP_Post $post, array $findings ): void {
		$counts = RestController::counts( $findings );
		?>
		<h2 style="margin-top:2em;">
			<a href="<?php echo esc_url( (string) get_edit_post_link( $post->ID ) ); ?>"><?php echo esc_html( get_the_title( $post ) ?: __( '(no title)', 'sceneshifter' ) ); ?></a>
			<span style="font-weight:normal;font-size:13px;">
				<?php
				if ( array() === $findings ) {
					esc_html_e( 'No sister links found.', 'sceneshifter' );
				} else {
					printf(
						/* translators: 1: number of errors, 2: number of warnings. */
						esc_html__( '%1$d errors, %2$d warnings', 'sceneshifter' ),
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
						<th><?php esc_html_e( 'Severity', 'sceneshifter' ); ?></th>
						<th><?php esc_html_e( 'Points to', 'sceneshifter' ); ?></th>
						<th><?php esc_html_e( 'URL', 'sceneshifter' ); ?></th>
						<th><?php esc_html_e( 'Where', 'sceneshifter' ); ?></th>
						<th><?php esc_html_e( 'Times', 'sceneshifter' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $findings as $finding ) : ?>
						<tr>
							<td><?php echo esc_html( Finding::ERROR === $finding->severity ? __( 'Error', 'sceneshifter' ) : __( 'Warning', 'sceneshifter' ) ); ?></td>
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
