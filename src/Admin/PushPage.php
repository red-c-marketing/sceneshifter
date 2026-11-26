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
use RedC\Sceneshifter\SyncMap\Uuid;
use RedC\Sceneshifter\Transfer\PreviewItem;
use RedC\Sceneshifter\Transfer\PushResult;
use WP_Post;

final class PushPage {

	public const SLUG        = 'sceneshifter-push';
	public const BULK_ACTION = 'sceneshifter_push';
	public const AJAX_ACTION = 'sceneshifter_push_one';
	public const NONCE       = 'sceneshifter_push';

	public function __construct( private readonly Plugin $plugin ) {}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'addMenu' ) );
		add_action( 'admin_init', array( $this, 'registerBulkActions' ) );
		add_filter( 'post_row_actions', array( $this, 'rowAction' ), 20, 2 );
		add_filter( 'page_row_actions', array( $this, 'rowAction' ), 20, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'ajaxPush' ) );
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
			wp_enqueue_script( 'sceneshifter-push', SCENESHIFTER_URL . 'assets/push.js', array(), Plugin::assetVersion( 'assets/push.js' ), true );
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
		if ( ! current_user_can( Plugin::CAP_TRANSFER ) || ! $this->plugin->options()->isEnabledPostType( $post->post_type ) || HeldNotices::isHeldCopy( $post->ID ) ) {
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
	 * @return list<Site>
	 */
	private function targets(): array {
		return self::reachable( $this->plugin );
	}

	/**
	 * Paired environments this site can push to or pull from: they must be
	 * public, since this site makes the calls.
	 *
	 * @return list<Site>
	 */
	public static function reachable( Plugin $plugin ): array {
		$self     = $plugin->sisters()->self();
		$pairings = $plugin->sisters()->pairings();
		$targets  = array();
		foreach ( $plugin->registry()->sites() as $site ) {
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
			( new PreviewView( $target ) )->render( $item );
		}
		$this->renderForm( $result['items'], $target );
	}

	/**
	 * The choices and the button. Each post is pushed by its own request, so
	 * a big batch never hits a time limit and one failure doesn't stop the rest.
	 *
	 * @param list<PreviewItem> $items
	 */
	private function renderForm( array $items, Site $target ): void {
		$queue    = array();
		$unlinked = array();
		$skipped  = 0;
		foreach ( $items as $item ) {
			if ( $item->isBlocked() ) {
				++$skipped;
				continue;
			}
			$queue[] = array(
				'id'       => $item->package->id,
				'title'    => '' === $item->package->title() ? __( '(no title)', 'sceneshifter' ) : $item->package->title(),
				'expected' => is_int( $item->target['id'] ?? null ) ? (string) $item->target['id'] : 'new',
				'confirm'  => $item->overwritesUnlinked(),
			);
			if ( $item->overwritesUnlinked() ) {
				$unlinked[] = $item;
			}
		}
		if ( array() === $queue ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'Nothing here can be pushed until the problems above are fixed.', 'sceneshifter' ) . '</p></div>';
			return;
		}
		$texts = array(
			'waiting' => __( 'Waiting', 'sceneshifter' ),
			'pushing' => __( 'Pushing…', 'sceneshifter' ),
			'failed'  => __( 'Failed', 'sceneshifter' ),
			/* translators: 1: number pushed, 2: number failed. */
			'done'    => __( 'Finished: %1$d pushed, %2$d failed.', 'sceneshifter' ),
			/* translators: 1: number pushed, 2: number failed, 3: number left out. */
			'doneOut' => __( 'Finished: %1$d pushed, %2$d failed, %3$d left out.', 'sceneshifter' ),
			'leftOut' => __( 'Left out: you didn\'t agree to overwrite the post there.', 'sceneshifter' ),
			'leave'   => __( 'A push is still running. Leaving now stops it after the current post.', 'sceneshifter' ),
		);
		?>
		<form class="sceneshifter-push-form" data-sceneshifter-push
			data-ajax="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
			data-queue="<?php echo esc_attr( (string) wp_json_encode( $queue ) ); ?>"
			data-text="<?php echo esc_attr( (string) wp_json_encode( $texts ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::AJAX_ACTION ); ?>">
			<input type="hidden" name="to" value="<?php echo esc_attr( $target->id ); ?>">
			<input type="hidden" name="transfer" value="<?php echo esc_attr( Uuid::generate() ); ?>">
			<?php wp_nonce_field( self::NONCE, '_wpnonce', false ); ?>
			<h2><?php esc_html_e( 'Push', 'sceneshifter' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="sceneshifter-new-status"><?php esc_html_e( 'New posts arrive as', 'sceneshifter' ); ?></label></th>
					<td>
						<select id="sceneshifter-new-status" name="new_status">
							<option value="draft"><?php esc_html_e( 'Draft', 'sceneshifter' ); ?></option>
							<option value="pending"><?php esc_html_e( 'Pending review', 'sceneshifter' ); ?></option>
							<option value="publish"><?php esc_html_e( 'Published', 'sceneshifter' ); ?></option>
							<option value="source"><?php esc_html_e( 'The same status as here', 'sceneshifter' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="sceneshifter-existing-status"><?php esc_html_e( 'Posts that already exist there', 'sceneshifter' ); ?></label></th>
					<td>
						<select id="sceneshifter-existing-status" name="existing_status">
							<option value="keep"><?php esc_html_e( 'Keep their status there', 'sceneshifter' ); ?></option>
							<option value="source"><?php esc_html_e( 'Take the status they have here', 'sceneshifter' ); ?></option>
						</select>
						<p class="description">
							<?php
							/* translators: %s: environment name. */
							echo esc_html( sprintf( __( 'Changes to a published post on %s show to visitors straight away, unless that site is set to hold them for review. Its previous version is kept, so the push can be undone from Sceneshifter → Log.', 'sceneshifter' ), $target->label ) );
							?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Missing categories and tags', 'sceneshifter' ); ?></th>
					<td><label><input type="checkbox" name="create_terms" value="1" checked> <?php echo esc_html( sprintf( /* translators: %s: environment name. */ __( 'Create them on %s', 'sceneshifter' ), $target->label ) ); ?></label></td>
				</tr>
			</table>
			<?php if ( array() !== $unlinked ) : ?>
				<h3><?php esc_html_e( 'Overwrite posts that were never linked?', 'sceneshifter' ); ?></h3>
				<p class="description">
					<?php
					/* translators: %s: environment name. */
					echo esc_html( sprintf( __( '%s already has posts at the same addresses as these, but they were never linked to the ones here. Pushing replaces their content and links them from now on. Anything you leave unticked is left out of the push.', 'sceneshifter' ), $target->label ) );
					?>
				</p>
				<fieldset class="sceneshifter-push-overwrite">
					<legend class="screen-reader-text"><?php esc_html_e( 'Posts to overwrite', 'sceneshifter' ); ?></legend>
					<?php foreach ( $unlinked as $item ) : ?>
						<label>
							<input type="checkbox" name="overwrite[]" value="<?php echo esc_attr( (string) $item->package->id ); ?>">
							<?php
							echo esc_html(
								sprintf(
									/* translators: 1: post title there, 2: post ID there, 3: environment name. */
									__( 'Overwrite "%1$s" (ID %2$d) on %3$s', 'sceneshifter' ),
									(string) ( $item->target['title'] ?? '' ),
									(int) ( $item->target['id'] ?? 0 ),
									$target->label
								)
							);
							?>
						</label><br>
					<?php endforeach; ?>
				</fieldset>
			<?php endif; ?>
			<?php if ( $skipped > 0 ) : ?>
				<p class="description"><?php echo esc_html( sprintf( /* translators: %d: number of posts. */ _n( '%d item with problems will be skipped.', '%d items with problems will be skipped.', $skipped, 'sceneshifter' ), $skipped ) ); ?></p>
			<?php endif; ?>
			<p>
				<button type="submit" class="button button-primary" data-sceneshifter-push-start>
					<?php echo esc_html( sprintf( /* translators: 1: number of posts, 2: environment name. */ _n( 'Push %1$d item to %2$s', 'Push %1$d items to %2$s', count( $queue ), 'sceneshifter' ), count( $queue ), $target->label ) ); ?>
				</button>
			</p>
			<ol class="sceneshifter-push-progress" data-sceneshifter-push-progress hidden></ol>
			<p class="sceneshifter-push-summary" data-sceneshifter-push-summary role="status"></p>
		</form>
		<?php
	}

	/**
	 * Pushes one post: admin-ajax action sceneshifter_push_one.
	 */
	public function ajaxPush(): void {
		check_ajax_referer( self::NONCE );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked above.
		$postId   = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$to       = isset( $_POST['to'] ) ? sanitize_key( wp_unslash( $_POST['to'] ) ) : '';
		$transfer = isset( $_POST['transfer'] ) ? sanitize_text_field( wp_unslash( $_POST['transfer'] ) ) : '';
		$expected = isset( $_POST['expected'] ) ? sanitize_key( wp_unslash( $_POST['expected'] ) ) : '';
		$options  = array(
			'new_status'      => isset( $_POST['new_status'] ) ? sanitize_key( wp_unslash( $_POST['new_status'] ) ) : 'draft',
			'existing_status' => isset( $_POST['existing_status'] ) ? sanitize_key( wp_unslash( $_POST['existing_status'] ) ) : 'keep',
			'create_terms'    => ! empty( $_POST['create_terms'] ),
		);
		$overwrite                     = isset( $_POST['overwrite'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['overwrite'] ) ) : array();
		$options['overwrite_unlinked'] = in_array( $postId, $overwrite, true );
		// phpcs:enable

		if ( ! current_user_can( Plugin::CAP_TRANSFER ) || 0 === $postId || ! current_user_can( 'edit_post', $postId ) ) {
			wp_send_json_error( array( 'html' => esc_html__( 'You do not have permission to push this.', 'sceneshifter' ) ), 403 );
		}
		$target = null;
		foreach ( $this->targets() as $site ) {
			if ( $site->id === $to ) {
				$target = $site;
			}
		}
		$runner = $this->plugin->pushRunner();
		if ( null === $target || null === $runner || ! Uuid::isValid( $transfer ) ) {
			wp_send_json_error( array( 'html' => esc_html__( 'That environment can\'t be pushed to from here.', 'sceneshifter' ) ), 400 );
		}

		wp_raise_memory_limit( 'admin' );
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 300 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- copying media can take a while.
		}
		$result = $runner->run( $target->id, $postId, $transfer, $options, 'new' === $expected ? null : ( ctype_digit( $expected ) ? (int) $expected : false ), wp_get_current_user()->display_name );
		wp_send_json_success(
			array(
				'ok'   => $result->ok(),
				'html' => self::resultHtml( $result, $target ),
			)
		);
	}

	/**
	 * What happened to one post, for the progress list.
	 */
	public static function resultHtml( PushResult $result, Site $target ): string {
		if ( ! $result->ok() ) {
			return '<strong>' . esc_html__( 'Failed:', 'sceneshifter' ) . '</strong> ' . esc_html( (string) $result->error );
		}
		$status = get_post_status_object( $result->status );
		$label  = null === $status ? $result->status : (string) $status->label;
		$html   = esc_html(
			$result->isHeld()
				/* translators: 1: environment name, 2: post ID there. */
				? sprintf( __( 'Held for review on %1$s. The published post there (ID %2$d) hasn\'t changed; someone there needs to apply the update.', 'sceneshifter' ), $target->label, (int) $result->targetId )
				: ( $result->created
				/* translators: 1: environment name, 2: post ID there, 3: status. */
				? sprintf( __( 'Created on %1$s (ID %2$d) as %3$s.', 'sceneshifter' ), $target->label, (int) $result->targetId, $label )
				/* translators: 1: environment name, 2: post ID there, 3: status. */
				: sprintf( __( 'Updated on %1$s (ID %2$d), now %3$s.', 'sceneshifter' ), $target->label, (int) $result->targetId, $label ) )
		);
		$links = array();
		if ( '' !== ( $result->urls['edit'] ?? '' ) ) {
			$links[] = '<a href="' . esc_url( $result->urls['edit'] ) . '" target="_blank" rel="noopener">' . ( $result->isHeld() ? esc_html__( 'Review it there', 'sceneshifter' ) : esc_html__( 'Edit there', 'sceneshifter' ) ) . '</a>';
		}
		if ( '' !== ( $result->urls['view'] ?? '' ) ) {
			$links[] = '<a href="' . esc_url( $result->urls['view'] ) . '" target="_blank" rel="noopener">' . ( $result->isHeld() ? esc_html__( 'Preview it there', 'sceneshifter' ) : esc_html__( 'View there', 'sceneshifter' ) ) . '</a>';
		}
		if ( array() !== $links ) {
			$html .= ' ' . implode( ' · ', $links );
		}
		$facts = array();
		if ( $result->mediaCopied > 0 ) {
			/* translators: %d: number of files. */
			$facts[] = sprintf( _n( '%d file copied', '%d files copied', $result->mediaCopied, 'sceneshifter' ), $result->mediaCopied );
		}
		if ( $result->rewrites > 0 ) {
			/* translators: %d: number of changes. */
			$facts[] = sprintf( _n( '%d link or ID rewritten', '%d links and IDs rewritten', $result->rewrites, 'sceneshifter' ), $result->rewrites );
		}
		if ( array() !== $facts ) {
			$html .= '<br><span class="description">' . esc_html( implode( ', ', $facts ) . '.' ) . '</span>';
		}

		$warnings = array();
		if ( array() !== $result->mismatch ) {
			/* translators: %s: list of parts, e.g. "Slug, Categories and tags". */
			$warnings[] = sprintf( __( 'After saving, these didn\'t match what was sent: %s. WordPress may have changed them, for example to keep an address unique.', 'sceneshifter' ), implode( ', ', array_map( array( self::class, 'partLabel' ), $result->mismatch ) ) );
		}
		$empty = array_filter( $result->missing, static fn( array $m ): bool => ! $m['gone'] );
		if ( array() !== $empty ) {
			/* translators: 1: environment name, 2: list of items. */
			$warnings[] = sprintf( __( 'Left empty because %1$s doesn\'t have them: %2$s.', 'sceneshifter' ), $target->label, implode( '; ', array_map( array( self::class, 'missingLabel' ), $empty ) ) );
		}
		foreach ( $result->renamed as $file ) {
			/* translators: 1: file name here, 2: file name there. */
			$warnings[] = sprintf( __( '%1$s was saved there as %2$s. Links to it in this post use the new name.', 'sceneshifter' ), $file['from'], $file['to'] );
		}
		if ( $result->linksTotal > 0 ) {
			/* translators: 1: environment name, 2: number of links. */
			$warnings[] = sprintf( _n( '%1$s found %2$d link to another environment in it:', '%1$s found %2$d links to other environments in it:', $result->linksTotal, 'sceneshifter' ), $target->label, $result->linksTotal )
				. ' ' . implode( '; ', array_map( static fn( array $l ): string => $l['url'] . ' (' . $l['where'] . ')', $result->links ) );
		}
		if ( array() !== $warnings ) {
			$html .= '<ul class="sceneshifter-push-warnings"><li>' . implode( '</li><li>', array_map( 'esc_html', $warnings ) ) . '</li></ul>';
		}
		return $html;
	}

	/**
	 * @param array{id: int, kind: string, title: string, location: string, gone: bool} $missing
	 */
	public static function missingLabel( array $missing ): string {
		$what = '' === $missing['title']
			/* translators: 1: kind of item, 2: ID. */
			? sprintf( __( '%1$s (ID %2$d)', 'sceneshifter' ), self::kindLabel( $missing['kind'] ), $missing['id'] )
			/* translators: 1: kind of item, 2: title, 3: ID. */
			: sprintf( __( '%1$s "%2$s" (ID %3$d)', 'sceneshifter' ), self::kindLabel( $missing['kind'] ), $missing['title'], $missing['id'] );
		return $what . ' · ' . $missing['location'];
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
}
