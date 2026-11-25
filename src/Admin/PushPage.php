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
use RedC\Sceneshifter\Transfer\Resolver;
use WP_Post;

final class PushPage {

	public const SLUG        = 'sceneshifter-push';
	public const BULK_ACTION = 'sceneshifter_push';
	public const AJAX_ACTION = 'sceneshifter_push_one';
	public const NONCE       = 'sceneshifter_push';

	/** Most rewrites listed per post. */
	private const REWRITE_LIMIT = 200;

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
		$this->renderForm( $result['items'], $target );
	}

	/**
	 * The choices and the button. Each post is pushed by its own request, so
	 * a big batch never hits a time limit and one failure doesn't stop the rest.
	 *
	 * @param list<PreviewItem> $items
	 */
	private function renderForm( array $items, Site $target ): void {
		$queue   = array();
		$skipped = 0;
		foreach ( $items as $item ) {
			if ( $item->isBlocked() ) {
				++$skipped;
				continue;
			}
			$queue[] = array(
				'id'       => $item->package->id,
				'title'    => '' === $item->package->title() ? __( '(no title)', 'sceneshifter' ) : $item->package->title(),
				'expected' => is_int( $item->target['id'] ?? null ) ? (string) $item->target['id'] : 'new',
			);
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
							echo esc_html( sprintf( __( 'Changes to a published post on %s show to visitors straight away. Its previous version is kept, so the push can be undone from Sceneshifter → Log.', 'sceneshifter' ), $target->label ) );
							?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Missing categories and tags', 'sceneshifter' ); ?></th>
					<td><label><input type="checkbox" name="create_terms" value="1" checked> <?php echo esc_html( sprintf( /* translators: %s: environment name. */ __( 'Create them on %s', 'sceneshifter' ), $target->label ) ); ?></label></td>
				</tr>
			</table>
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
			$result->created
				/* translators: 1: environment name, 2: post ID there, 3: status. */
				? sprintf( __( 'Created on %1$s (ID %2$d) as %3$s.', 'sceneshifter' ), $target->label, (int) $result->targetId, $label )
				/* translators: 1: environment name, 2: post ID there, 3: status. */
				: sprintf( __( 'Updated on %1$s (ID %2$d), now %3$s.', 'sceneshifter' ), $target->label, (int) $result->targetId, $label )
		);
		$links = array();
		if ( '' !== ( $result->urls['edit'] ?? '' ) ) {
			$links[] = '<a href="' . esc_url( $result->urls['edit'] ) . '" target="_blank" rel="noopener">' . esc_html__( 'Edit there', 'sceneshifter' ) . '</a>';
		}
		if ( '' !== ( $result->urls['view'] ?? '' ) ) {
			$links[] = '<a href="' . esc_url( $result->urls['view'] ) . '" target="_blank" rel="noopener">' . esc_html__( 'View there', 'sceneshifter' ) . '</a>';
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
			$warnings[] = sprintf( __( '%1$s was saved there as %2$s, so links to it may not work.', 'sceneshifter' ), $file['from'], $file['to'] );
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
	public static function missingLabel( array $missing ): string {
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
