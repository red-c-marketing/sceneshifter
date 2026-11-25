<?php
/**
 * Sceneshifter → Push: choose where to push the selected posts and preview
 * what would happen. The preview changes nothing on either site.
 *
 * Reached from the Push row action and bulk action in the post lists.
 *
 * @package RedC\Sceneshifter
 */

declare(strict_types=1);

namespace RedC\Sceneshifter\Admin;

use RedC\Sceneshifter\Check\CheckPage;
use RedC\Sceneshifter\Plugin;
use RedC\Sceneshifter\Registry\Site;
use RedC\Sceneshifter\Rewrite\Rewrite;
use RedC\Sceneshifter\Transfer\PreflightResponder;
use RedC\Sceneshifter\Transfer\PreviewItem;
use RedC\Sceneshifter\Transfer\Resolver;
use WP_Post;

final class PushPage {

	public const SLUG        = 'sceneshifter-push';
	public const BULK_ACTION = 'sceneshifter_push';

	/** Most rewrites listed per post. */
	private const REWRITE_LIMIT = 200;

	public function __construct( private readonly Plugin $plugin ) {}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'addMenu' ) );
		add_action( 'admin_init', array( $this, 'registerBulkActions' ) );
		add_filter( 'post_row_actions', array( $this, 'rowAction' ), 20, 2 );
		add_filter( 'page_row_actions', array( $this, 'rowAction' ), 20, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	public function addMenu(): void {
		add_submenu_page(
			CheckPage::SLUG,
			__( 'Push content', 'sceneshifter' ),
			__( 'Push', 'sceneshifter' ),
			Plugin::CAP_TRANSFER,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	public function enqueue( string $hook ): void {
		if ( str_ends_with( $hook, '_page_' . self::SLUG ) ) {
			wp_enqueue_style( 'sceneshifter-settings', SCENESHIFTER_URL . 'assets/settings.css', array(), Plugin::assetVersion( 'assets/settings.css' ) );
		}
	}

	/**
	 * @param list<int> $postIds
	 */
	public static function url( array $postIds, string $to = '' ): string {
		$args = array(
			'page'     => self::SLUG,
			'post_ids' => implode( ',', array_map( 'intval', $postIds ) ),
		);
		if ( '' !== $to ) {
			$args['to'] = $to;
		}
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	public function registerBulkActions(): void {
		if ( ! current_user_can( Plugin::CAP_TRANSFER ) ) {
			return;
		}
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
		if ( array() !== $this->targets() ) {
			$actions[ self::BULK_ACTION ] = __( 'Push to another environment', 'sceneshifter' );
		}
		return $actions;
	}

	/**
	 * @param list<int|string> $postIds
	 */
	public function handleBulkAction( string $redirect, string $action, array $postIds ): string {
		return self::BULK_ACTION === $action ? self::url( array_map( 'intval', $postIds ) ) : $redirect;
	}

	/**
	 * @param array<string, string> $actions
	 * @return array<string, string>
	 */
	public function rowAction( array $actions, WP_Post $post ): array {
		if ( ! current_user_can( Plugin::CAP_TRANSFER ) || ! $this->plugin->options()->isEnabledPostType( $post->post_type ) ) {
			return $actions;
		}
		$targets = $this->targets();
		if ( array() === $targets ) {
			return $actions;
		}
		$to                          = 1 === count( $targets ) ? $targets[0]->id : '';
		$label                       = 1 === count( $targets )
			/* translators: %s: environment name. */
			? sprintf( __( 'Push to %s', 'sceneshifter' ), $targets[0]->label )
			: __( 'Push…', 'sceneshifter' );
		$actions['sceneshifter_push'] = '<a href="' . esc_url( self::url( array( $post->ID ), $to ) ) . '">' . esc_html( $label ) . '</a>';
		return $actions;
	}

	/**
	 * Paired environments this site can push to: they must be public, since
	 * this site makes the calls.
	 *
	 * @return list<Site>
	 */
	private function targets(): array {
		$self     = $this->plugin->sisters()->self();
		$pairings = $this->plugin->sisters()->pairings();
		$targets  = array();
		foreach ( $this->plugin->registry()->sites() as $site ) {
			if ( null !== $self && $site->id !== $self->id && $site->isPublic && null !== $pairings->get( $site->id ) ) {
				$targets[] = $site;
			}
		}
		return $targets;
	}

	public function render(): void {
		if ( ! current_user_can( Plugin::CAP_TRANSFER ) ) {
			wp_die( esc_html__( 'You do not have permission to push content.', 'sceneshifter' ) );
		}
		// Read-only page, so no nonce: the preview changes nothing.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$raw = isset( $_GET['post_ids'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['post_ids'] ) ) : '';
		$to  = isset( $_GET['to'] ) ? sanitize_key( wp_unslash( $_GET['to'] ) ) : '';
		// phpcs:enable
		$ids = array_slice( array_values( array_unique( array_filter( array_map( 'intval', explode( ',', $raw ) ) ) ) ), 0, PreflightResponder::MAX_ITEMS );
		$ids = array_values( array_filter( $ids, static fn( int $id ): bool => current_user_can( 'edit_post', $id ) ) );
		?>
		<div class="wrap sceneshifter-push">
			<h1><?php esc_html_e( 'Push content', 'sceneshifter' ); ?></h1>
			<?php
			if ( array() === $ids ) {
				echo '<p>' . esc_html__( 'To push content, open a list of posts or pages. Use "Push" under a title, or tick several and choose "Push to another environment" from Bulk actions.', 'sceneshifter' ) . '</p>';
				echo '</div>';
				return;
			}
			$targets = $this->targets();
			$target  = null;
			foreach ( $targets as $site ) {
				if ( $site->id === $to ) {
					$target = $site;
				}
			}
			if ( null === $target ) {
				$this->renderChooser( $ids, $targets );
			} else {
				$this->renderPreview( $ids, $target );
			}
			?>
		</div>
		<?php
	}

	/**
	 * @param list<int>  $ids
	 * @param list<Site> $targets
	 */
	private function renderChooser( array $ids, array $targets ): void {
		if ( array() === $targets ) {
			echo '<p>';
			printf(
				/* translators: %s: link to the Pairing settings. */
				esc_html__( 'This site isn\'t paired with an environment it can contact. Pair one in %s.', 'sceneshifter' ),
				'<a href="' . esc_url( PairingTab::url() ) . '">' . esc_html__( 'Settings → Pairing', 'sceneshifter' ) . '</a>'
			);
			echo '</p>';
			return;
		}
		echo '<p>' . esc_html( sprintf( /* translators: %d: number of posts. */ _n( 'Where should the %d selected item go?', 'Where should the %d selected items go?', count( $ids ), 'sceneshifter' ), count( $ids ) ) ) . '</p><p>';
		foreach ( $targets as $site ) {
			echo '<a class="button button-primary" style="margin-right:8px" href="' . esc_url( self::url( $ids, $site->id ) ) . '">' . esc_html( sprintf( /* translators: %s: environment name. */ __( 'Preview push to %s', 'sceneshifter' ), $site->label ) ) . '</a>';
		}
		echo '</p>';
	}

	/**
	 * @param list<int> $ids
	 */
	private function renderPreview( array $ids, Site $target ): void {
		$preview = $this->plugin->pushPreview();
		$result  = null === $preview ? array(
			'error' => __( 'Sceneshifter is inactive on this site.', 'sceneshifter' ),
			'items' => array(),
		) : $preview->build( $target->id, $ids );
		?>
		<p class="description">
			<?php
			/* translators: %s: environment name. */
			echo esc_html( sprintf( __( 'Preview of pushing to %s. Nothing has changed on either site.', 'sceneshifter' ), $target->label ) );
			?>
		</p>
		<?php
		if ( null !== $result['error'] ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $result['error'] ) . '</p></div>';
			return;
		}
		foreach ( $result['items'] as $item ) {
			$this->renderItem( $item, $target );
		}
		?>
		<div class="notice notice-info inline">
			<p><?php esc_html_e( 'Pushing for real arrives in the next update. Use this preview to check what would move and what is missing on the other site.', 'sceneshifter' ); ?></p>
		</div>
		<?php
	}

	private function renderItem( PreviewItem $item, Site $target ): void {
		$package = $item->package;
		$title   = '' === $package->title() ? __( '(no title)', 'sceneshifter' ) : $package->title();
		?>
		<div class="sceneshifter-push-item<?php echo $item->isBlocked() ? ' is-blocked' : ''; ?>">
			<h2>
				<a href="<?php echo esc_url( (string) get_edit_post_link( $package->id ) ); ?>"><?php echo esc_html( $title ); ?></a>
				<span class="sceneshifter-tag"><?php echo esc_html( self::typeLabel( $package->type() ) ); ?></span>
			</h2>
			<?php if ( null !== $item->error ) : ?>
				<p class="sceneshifter-error-text"><?php echo esc_html( $item->error ); ?></p>
				</div>
				<?php
				return;
			endif;
			?>
			<p><strong><?php echo esc_html( $this->action( $item, $target ) ); ?></strong></p>
			<?php
			$errors   = $this->errors( $item, $target );
			$warnings = $this->warnings( $item, $target );
			foreach ( $errors as $message ) {
				echo '<div class="notice notice-error inline"><p>' . esc_html( $message ) . '</p></div>';
			}
			foreach ( $warnings as $message ) {
				echo '<div class="notice notice-warning inline"><p>' . esc_html( $message ) . '</p></div>';
			}
			?>
			<table class="widefat striped sceneshifter-push-facts">
				<tbody>
					<?php if ( null !== $item->changed ) : ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'Would change', 'sceneshifter' ); ?></th>
							<td><?php echo esc_html( array() === $item->changed ? __( 'Nothing: the two are already the same.', 'sceneshifter' ) : implode( ', ', array_map( array( self::class, 'partLabel' ), $item->changed ) ) ); ?></td>
						</tr>
					<?php endif; ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Status', 'sceneshifter' ); ?></th>
						<td>
							<?php
							echo esc_html(
								$item->isUpdate()
									/* translators: %s: post status, for example "publish". */
									? sprintf( __( 'Keeps its status there (%s).', 'sceneshifter' ), (string) ( $item->target['status'] ?? '' ) )
									: __( 'Arrives as a draft.', 'sceneshifter' )
							);
							?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Author', 'sceneshifter' ); ?></th>
						<td>
							<?php
							echo esc_html(
								null !== $item->author
									/* translators: %s: user's name. */
									? sprintf( __( '%s (same email address)', 'sceneshifter' ), $item->author['name'] )
									: __( 'No user there has the same email address, so the person who pushes becomes the author.', 'sceneshifter' )
							);
							?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Media', 'sceneshifter' ); ?></th>
						<td>
							<?php
							/* translators: 1: number of files already there, 2: number to copy. */
							echo esc_html( sprintf( __( '%1$d already there, %2$d to copy', 'sceneshifter' ), $item->mediaFound, count( $item->media ) ) );
							if ( array() !== $item->media ) {
								echo '<ul class="sceneshifter-push-list">';
								foreach ( $item->media as $media ) {
									echo '<li><code>' . esc_html( $media['file'] ) . '</code>' . ( null !== $media['size'] ? ' <span class="description">' . esc_html( size_format( $media['size'] ) ) . '</span>' : '' ) . '</li>';
								}
								echo '</ul>';
							}
							?>
						</td>
					</tr>
					<?php if ( array() !== $item->newTerms ) : ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'New terms', 'sceneshifter' ); ?></th>
							<td>
								<?php echo esc_html( implode( ', ', array_map( static fn( array $t ): string => $t['name'] . ' (' . $t['taxonomy'] . ')', $item->newTerms ) ) ); ?>
								<p class="description"><?php esc_html_e( 'These don\'t exist there yet and would be created.', 'sceneshifter' ); ?></p>
							</td>
						</tr>
					<?php endif; ?>
				</tbody>
			</table>
			<?php
			$lists = array(
				array(
					array_filter( $item->missing, static fn( array $m ): bool => ! $m['gone'] ),
					__( 'Missing on the other site', 'sceneshifter' ),
					/* translators: %s: environment name. */
					sprintf( __( 'Linked content isn\'t pushed with this post. Push it to %s first, or the reference would point at nothing.', 'sceneshifter' ), $target->label ),
				),
				array(
					array_filter( $item->missing, static fn( array $m ): bool => $m['gone'] ),
					__( 'Already broken on this site', 'sceneshifter' ),
					__( 'This content refers to things that have been deleted here, so the reference points at nothing on either site. Fix it in the editor if it matters.', 'sceneshifter' ),
				),
			);
			foreach ( $lists as [ $entries, $heading, $help ] ) :
				if ( array() === $entries ) {
					continue;
				}
				?>
				<h3><?php echo esc_html( $heading ); ?></h3>
				<p class="description"><?php echo esc_html( $help ); ?></p>
				<ul class="sceneshifter-push-list">
					<?php foreach ( $entries as $missing ) : ?>
						<li><?php echo esc_html( self::missingLabel( $missing ) ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endforeach; ?>
			<?php if ( array() !== $item->rewrites ) : ?>
				<details class="sceneshifter-push-rewrites">
					<summary>
						<?php
						/* translators: %d: number of changes. */
						echo esc_html( sprintf( _n( '%d link or ID rewritten for the other site', '%d links and IDs rewritten for the other site', count( $item->rewrites ), 'sceneshifter' ), count( $item->rewrites ) ) );
						?>
					</summary>
					<table class="widefat striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Where', 'sceneshifter' ); ?></th>
								<th><?php esc_html_e( 'From', 'sceneshifter' ); ?></th>
								<th><?php esc_html_e( 'To', 'sceneshifter' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( array_slice( $item->rewrites, 0, self::REWRITE_LIMIT ) as $rewrite ) : ?>
								<tr>
									<td><?php echo esc_html( $rewrite->location ); ?></td>
									<td><code><?php echo esc_html( self::shown( $rewrite ) ? $rewrite->old : '#' . $rewrite->old ); ?></code></td>
									<td><code><?php echo esc_html( self::shown( $rewrite ) ? $rewrite->new : '#' . $rewrite->new ); ?></code></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</details>
			<?php endif; ?>
		</div>
		<?php
	}

	private function action( PreviewItem $item, Site $target ): string {
		if ( ! $item->isUpdate() ) {
			/* translators: %s: environment name. */
			return sprintf( __( 'New on %s.', 'sceneshifter' ), $target->label );
		}
		$how = match ( (string) ( $item->target['how'] ?? '' ) ) {
			Resolver::LINKED => __( 'linked', 'sceneshifter' ),
			Resolver::UUID   => __( 'same Sceneshifter ID', 'sceneshifter' ),
			Resolver::PATH   => __( 'same address, not linked yet', 'sceneshifter' ),
			default          => __( 'matched', 'sceneshifter' ),
		};
		return sprintf(
			/* translators: 1: environment name, 2: post title there, 3: post ID there, 4: how it was matched. */
			__( 'Updates "%2$s" on %1$s (ID %3$d, %4$s).', 'sceneshifter' ),
			$target->label,
			(string) ( $item->target['title'] ?? '' ),
			(int) ( $item->target['id'] ?? 0 ),
			$how
		);
	}

	/**
	 * @return list<string>
	 */
	private function errors( PreviewItem $item, Site $target ): array {
		$errors = array();
		foreach ( $item->foreign as $finding ) {
			$errors[] = sprintf(
				/* translators: 1: environment the link points at, 2: target environment, 3: URL, 4: where it is. */
				__( 'Links to %1$s, which is neither this site nor %2$s: %3$s (%4$s). Fix it before pushing.', 'sceneshifter' ),
				$finding->sister->label,
				$target->label,
				$finding->url,
				$finding->locationText()
			);
		}
		foreach ( $item->checks as $check ) {
			if ( PreflightResponder::POST_TYPE_MISSING === ( $check['code'] ?? '' ) ) {
				/* translators: 1: environment name, 2: content type. */
				$errors[] = sprintf( __( '%1$s doesn\'t have the %2$s content type. Deploy the code that adds it first.', 'sceneshifter' ), $target->label, $item->package->type() );
			}
		}
		return $errors;
	}

	/**
	 * @return list<string>
	 */
	private function warnings( PreviewItem $item, Site $target ): array {
		$warnings = array();
		if ( PreviewItem::EDITED === $item->conflict ) {
			/* translators: %s: environment name. */
			$warnings[] = sprintf( __( 'This post was edited on %s after the last push. Pushing would replace those edits.', 'sceneshifter' ), $target->label );
		} elseif ( PreviewItem::NEVER_SYNCED === $item->conflict && array() !== (array) $item->changed ) {
			/* translators: %s: environment name. */
			$warnings[] = sprintf( __( 'This post has never been pushed to %s, so any differences there would be replaced.', 'sceneshifter' ), $target->label );
		}
		if ( null !== $item->blockedBy ) {
			/* translators: 1: environment name, 2: post ID there. */
			$warnings[] = sprintf( __( '%1$s has a post at the same address (ID %2$d), but it is linked to different content here, so this would arrive as a new post with a changed address.', 'sceneshifter' ), $target->label, $item->blockedBy );
		}
		foreach ( $item->checks as $check ) {
			$names = implode( ', ', array_map( 'strval', is_array( $check['names'] ?? null ) ? $check['names'] : array() ) );
			$text  = match ( (string) ( $check['code'] ?? '' ) ) {
				/* translators: 1: environment name, 2: template file. */
				PreflightResponder::TEMPLATE_MISSING => sprintf( __( '%1$s doesn\'t have the page template %2$s. The post would use the default template.', 'sceneshifter' ), $target->label, (string) ( $check['template'] ?? '' ) ),
				/* translators: 1: environment name, 2: field keys. */
				PreflightResponder::ACF_MISSING      => sprintf( __( '%1$s is missing ACF fields used here (%2$s). Their values would be saved but not shown in the editor.', 'sceneshifter' ), $target->label, $names ),
				/* translators: 1: environment name, 2: taxonomy names. */
				PreflightResponder::TAXONOMY_MISSING => sprintf( __( '%1$s doesn\'t have these taxonomies: %2$s. Those terms would be skipped.', 'sceneshifter' ), $target->label, $names ),
				/* translators: 1: environment name, 2: block names. */
				PreflightResponder::BLOCKS_MISSING   => sprintf( __( '%1$s doesn\'t have these blocks: %2$s. They would show as unsupported in the editor.', 'sceneshifter' ), $target->label, $names ),
				default                              => '',
			};
			if ( '' !== $text ) {
				$warnings[] = $text;
			}
		}
		return $warnings;
	}

	/**
	 * @param array{id: int, kind: string, title: string, location: string, gone: bool} $missing
	 */
	private static function missingLabel( array $missing ): string {
		$what = '' === $missing['title']
			/* translators: 1: kind of item, 2: ID. */
			? sprintf( __( '%1$s (ID %2$d)', 'sceneshifter' ), self::kindLabel( $missing['kind'] ), $missing['id'] )
			/* translators: 1: kind of item, 2: title, 3: ID. */
			: sprintf( __( '%1$s "%2$s" (ID %3$d)', 'sceneshifter' ), self::kindLabel( $missing['kind'] ), $missing['title'], $missing['id'] );
		return $what . ' · ' . $missing['location'];
	}

	private static function shown( Rewrite $rewrite ): bool {
		return Rewrite::URL === $rewrite->kind;
	}

	public static function partLabel( string $part ): string {
		return match ( $part ) {
			'title'    => __( 'Title', 'sceneshifter' ),
			'content'  => __( 'Content', 'sceneshifter' ),
			'excerpt'  => __( 'Excerpt', 'sceneshifter' ),
			'slug'     => __( 'Slug', 'sceneshifter' ),
			'order'    => __( 'Order', 'sceneshifter' ),
			'template' => __( 'Template', 'sceneshifter' ),
			'parent'   => __( 'Parent', 'sceneshifter' ),
			'featured' => __( 'Featured image', 'sceneshifter' ),
			'meta'     => __( 'Custom fields', 'sceneshifter' ),
			'terms'    => __( 'Categories and tags', 'sceneshifter' ),
			default    => $part,
		};
	}

	private static function kindLabel( string $kind ): string {
		return match ( $kind ) {
			Rewrite::POST       => __( 'Post', 'sceneshifter' ),
			Rewrite::TERM       => __( 'Term', 'sceneshifter' ),
			Rewrite::ATTACHMENT => __( 'Media', 'sceneshifter' ),
			default             => $kind,
		};
	}

	private static function typeLabel( string $type ): string {
		$object = get_post_type_object( $type );
		return $object ? (string) $object->labels->singular_name : $type;
	}
}
