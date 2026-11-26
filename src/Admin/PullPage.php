<?php
/**
 * Sceneshifter → Pull: bring posts here from another environment. Choose
 * where from, find the posts, preview what would happen, then pull them
 * one request at a time. The search and preview change nothing here.
 *
 * Also adds "Pull from …" under posts in the lists that are linked to a
 * post on an environment this site can reach.
 *
 * @package RedC\Sceneshifter
 */

declare(strict_types=1);

namespace RedC\Sceneshifter\Admin;

use RedC\Sceneshifter\Check\CheckPage;
use RedC\Sceneshifter\Plugin;
use RedC\Sceneshifter\Registry\Site;
use RedC\Sceneshifter\SyncMap\MapEntry;
use RedC\Sceneshifter\SyncMap\Uuid;
use RedC\Sceneshifter\Transfer\PreflightResponder;
use RedC\Sceneshifter\Transfer\PreviewItem;
use RedC\Sceneshifter\Transfer\PushResult;
use WP_Post;

final class PullPage {

	public const SLUG        = 'sceneshifter-pull';
	public const AJAX_ACTION = 'sceneshifter_pull_one';
	public const NONCE       = 'sceneshifter_pull';

	public function __construct( private readonly Plugin $plugin ) {}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'addMenu' ) );
		add_filter( 'post_row_actions', array( $this, 'rowAction' ), 21, 2 );
		add_filter( 'page_row_actions', array( $this, 'rowAction' ), 21, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'ajaxPull' ) );
	}

	public function addMenu(): void {
		add_submenu_page(
			CheckPage::SLUG,
			__( 'Pull content', 'sceneshifter' ),
			__( 'Pull', 'sceneshifter' ),
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
	 * @param array<string, string|int> $args from, ids (comma-separated IDs there), q, type, paged.
	 */
	public static function url( array $args = array() ): string {
		return add_query_arg( array( 'page' => self::SLUG ) + array_filter( $args, static fn( string|int $v ): bool => '' !== $v && 0 !== $v ), admin_url( 'admin.php' ) );
	}

	/**
	 * "Pull from Staging" under a post linked to one there.
	 *
	 * @param array<string, string> $actions
	 * @return array<string, string>
	 */
	public function rowAction( array $actions, WP_Post $post ): array {
		if ( ! current_user_can( Plugin::CAP_TRANSFER ) || ! current_user_can( 'edit_post', $post->ID ) || ! $this->plugin->options()->isEnabledPostType( $post->post_type ) || HeldNotices::isHeldCopy( $post->ID ) ) {
			return $actions;
		}
		$map = $this->plugin->syncMap()?->map();
		if ( null === $map ) {
			return $actions;
		}
		foreach ( PushPage::reachable( $this->plugin ) as $site ) {
			$link = $map->get( $site->id, MapEntry::POST, $post->ID );
			if ( null !== $link ) {
				$actions[ 'sceneshifter_pull_' . $site->id ] = '<a href="' . esc_url(
					self::url(
						array(
							'from' => $site->id,
							'ids'  => (string) $link->remoteId,
						)
					)
				) . '">' . esc_html( sprintf( /* translators: %s: environment name. */ __( 'Pull from %s', 'sceneshifter' ), $site->label ) ) . '</a>';
			}
		}
		return $actions;
	}

	public function render(): void {
		if ( ! current_user_can( Plugin::CAP_TRANSFER ) ) {
			wp_die( esc_html__( 'You do not have permission to pull content.', 'sceneshifter' ) );
		}
		// Read-only page, so no nonce: searching and previewing change nothing here.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$from  = isset( $_GET['from'] ) ? sanitize_key( wp_unslash( $_GET['from'] ) ) : '';
		$raw   = isset( $_GET['ids'] ) ? wp_unslash( $_GET['ids'] ) : '';
		$query = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['q'] ) ) : '';
		$type  = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : '';
		$paged = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		// phpcs:enable
		$list = is_array( $raw ) ? $raw : explode( ',', sanitize_text_field( (string) $raw ) );
		$ids  = array_slice( array_values( array_unique( array_filter( array_map( 'absint', $list ) ) ) ), 0, PreflightResponder::MAX_ITEMS );

		$sources = PushPage::reachable( $this->plugin );
		$source  = null;
		foreach ( $sources as $site ) {
			if ( $site->id === $from || ( '' === $from && 1 === count( $sources ) ) ) {
				$source = $site;
			}
		}
		?>
		<div class="wrap sceneshifter-push">
			<h1><?php esc_html_e( 'Pull content', 'sceneshifter' ); ?></h1>
			<?php
			if ( null === $source ) {
				$this->renderChooser( $sources );
			} elseif ( array() === $ids ) {
				$this->renderSearch( $source, $query, $type, $paged );
			} else {
				$this->renderPreview( $source, $ids );
			}
			?>
		</div>
		<?php
	}

	/**
	 * @param list<Site> $sources
	 */
	private function renderChooser( array $sources ): void {
		if ( array() === $sources ) {
			echo '<p>';
			printf(
				/* translators: %s: link to the Pairing settings. */
				esc_html__( 'This site isn\'t paired with an environment it can contact. Pair one in %s. Content can only be pulled from environments this site can reach, so a local development site can pull from staging or live, but not the other way round.', 'sceneshifter' ),
				'<a href="' . esc_url( PairingTab::url() ) . '">' . esc_html__( 'Settings → Pairing', 'sceneshifter' ) . '</a>'
			);
			echo '</p>';
			return;
		}
		echo '<p>' . esc_html__( 'Where should the content come from?', 'sceneshifter' ) . '</p><p>';
		foreach ( $sources as $site ) {
			echo '<a class="button button-primary" style="margin-right:8px" href="' . esc_url( self::url( array( 'from' => $site->id ) ) ) . '">' . esc_html( sprintf( /* translators: %s: environment name. */ __( 'Find content on %s', 'sceneshifter' ), $site->label ) ) . '</a>';
		}
		echo '</p>';
	}

	private function renderSearch( Site $source, string $query, string $type, int $paged ): void {
		$runner = $this->plugin->pullRunner();
		$types  = $this->plugin->options()->postTypes();
		$type   = in_array( $type, $types, true ) ? $type : '';
		$result = null === $runner ? array(
			'error'    => __( 'Sceneshifter is inactive on this site.', 'sceneshifter' ),
			'items'    => array(),
			'total'    => 0,
			'per_page' => 20,
		) : $runner->search( $source->id, $query, $type, $paged );
		?>
		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="sceneshifter-pull-search">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
			<input type="hidden" name="from" value="<?php echo esc_attr( $source->id ); ?>">
			<p class="search-box" style="float:none">
				<label class="screen-reader-text" for="sceneshifter-pull-q"><?php esc_html_e( 'Search', 'sceneshifter' ); ?></label>
				<input type="search" id="sceneshifter-pull-q" name="q" value="<?php echo esc_attr( $query ); ?>" placeholder="<?php echo esc_attr( sprintf( /* translators: %s: environment name. */ __( 'Search %s', 'sceneshifter' ), $source->label ) ); ?>">
				<label class="screen-reader-text" for="sceneshifter-pull-type"><?php esc_html_e( 'Content type', 'sceneshifter' ); ?></label>
				<select id="sceneshifter-pull-type" name="type">
					<option value=""><?php esc_html_e( 'All content types', 'sceneshifter' ); ?></option>
					<?php foreach ( $types as $postType ) : ?>
						<option value="<?php echo esc_attr( $postType ); ?>" <?php selected( $type, $postType ); ?>><?php echo esc_html( PreviewView::typeLabel( $postType ) ); ?></option>
					<?php endforeach; ?>
				</select>
				<button type="submit" class="button"><?php esc_html_e( 'Search', 'sceneshifter' ); ?></button>
			</p>
		</form>
		<?php
		if ( null !== $result['error'] ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $result['error'] ) . '</p></div>';
			return;
		}
		if ( array() === $result['items'] ) {
			/* translators: %s: environment name. */
			echo '<p>' . esc_html( sprintf( __( 'Nothing on %s matches.', 'sceneshifter' ), $source->label ) ) . '</p>';
			return;
		}
		?>
		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
			<input type="hidden" name="from" value="<?php echo esc_attr( $source->id ); ?>">
			<p class="description">
				<?php
				/* translators: 1: environment name, 2: most items per preview. */
				echo esc_html( sprintf( __( 'Tick what to bring here from %1$s, up to %2$d at a time, then preview. Nothing changes until you confirm.', 'sceneshifter' ), $source->label, PreflightResponder::MAX_ITEMS ) );
				?>
			</p>
			<table class="widefat striped">
				<thead>
					<tr>
						<td class="check-column"><span class="screen-reader-text"><?php esc_html_e( 'Select', 'sceneshifter' ); ?></span></td>
						<th scope="col"><?php esc_html_e( 'Title', 'sceneshifter' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Type', 'sceneshifter' ); ?></th>
						<th scope="col"><?php echo esc_html( sprintf( /* translators: %s: environment name. */ __( 'Status on %s', 'sceneshifter' ), $source->label ) ); ?></th>
						<th scope="col"><?php esc_html_e( 'Address', 'sceneshifter' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Here', 'sceneshifter' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $result['items'] as $row ) : ?>
						<tr>
							<th scope="row" class="check-column"><input type="checkbox" name="ids[]" value="<?php echo esc_attr( (string) $row['id'] ); ?>" id="sceneshifter-pull-<?php echo esc_attr( (string) $row['id'] ); ?>"></th>
							<td><label for="sceneshifter-pull-<?php echo esc_attr( (string) $row['id'] ); ?>"><strong><?php echo esc_html( '' === $row['title'] ? __( '(no title)', 'sceneshifter' ) : $row['title'] ); ?></strong></label></td>
							<td><?php echo esc_html( PreviewView::typeLabel( $row['type'] ) ); ?></td>
							<td><?php echo esc_html( $row['status'] ); ?></td>
							<td><code><?php echo esc_html( $row['path'] ); ?></code></td>
							<td>
								<?php
								$here = null === $row['here'] ? null : get_post( $row['here'] );
								if ( $here instanceof WP_Post ) {
									echo '<a href="' . esc_url( (string) get_edit_post_link( $here->ID ) ) . '">' . esc_html( sprintf( /* translators: %d: post ID. */ __( 'Linked (ID %d)', 'sceneshifter' ), $here->ID ) ) . '</a>';
								} else {
									esc_html_e( 'Not linked yet', 'sceneshifter' );
								}
								?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p><button type="submit" class="button button-primary"><?php echo esc_html( sprintf( /* translators: %s: environment name. */ __( 'Preview pulling from %s', 'sceneshifter' ), $source->label ) ); ?></button></p>
		</form>
		<?php
		$pages = (int) ceil( $result['total'] / max( 1, $result['per_page'] ) );
		if ( $pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">';
			echo wp_kses_post(
				(string) paginate_links(
					array(
						'base'    => add_query_arg( 'paged', '%#%', self::url( array( 'from' => $source->id, 'q' => $query, 'type' => $type ) ) ),
						'format'  => '',
						'current' => $paged,
						'total'   => $pages,
					)
				)
			);
			echo '</div></div>';
		}
	}

	/**
	 * @param list<int> $ids Post IDs on the source.
	 */
	private function renderPreview( Site $source, array $ids ): void {
		$runner = $this->plugin->pullRunner();
		$result = null === $runner ? array(
			'error' => __( 'Sceneshifter is inactive on this site.', 'sceneshifter' ),
			'items' => array(),
		) : $runner->preview( $source->id, $ids );
		?>
		<p class="description">
			<?php
			/* translators: %s: environment name. */
			echo esc_html( sprintf( __( 'Preview of pulling from %s. Nothing has changed on either site.', 'sceneshifter' ), $source->label ) );
			?>
			<a href="<?php echo esc_url( self::url( array( 'from' => $source->id ) ) ); ?>"><?php esc_html_e( 'Choose other content', 'sceneshifter' ); ?></a>
		</p>
		<?php
		if ( null !== $result['error'] ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $result['error'] ) . '</p></div>';
			return;
		}
		$view = new PreviewView( $source, true );
		foreach ( $result['items'] as $item ) {
			$view->render( $item );
		}
		$this->renderForm( $result['items'], $source );
	}

	/**
	 * @param list<PreviewItem> $items
	 */
	private function renderForm( array $items, Site $source ): void {
		$queue    = array();
		$unlinked = array();
		$skipped  = 0;
		foreach ( $items as $item ) {
			$local = is_int( $item->target['id'] ?? null ) ? $item->target['id'] : null;
			if ( $item->isBlocked() || ( null !== $local && ! current_user_can( 'edit_post', $local ) ) ) {
				++$skipped;
				continue;
			}
			$queue[] = array(
				'id'       => $item->package->id,
				'title'    => '' === $item->package->title() ? __( '(no title)', 'sceneshifter' ) : $item->package->title(),
				'expected' => null === $local ? 'new' : (string) $local,
				'confirm'  => $item->overwritesUnlinked(),
			);
			if ( $item->overwritesUnlinked() ) {
				$unlinked[] = $item;
			}
		}
		if ( array() === $queue ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'Nothing here can be pulled until the problems above are fixed.', 'sceneshifter' ) . '</p></div>';
			return;
		}
		$texts = array(
			'waiting' => __( 'Waiting', 'sceneshifter' ),
			'pushing' => __( 'Pulling…', 'sceneshifter' ),
			'failed'  => __( 'Failed', 'sceneshifter' ),
			/* translators: 1: number pulled, 2: number failed. */
			'done'    => __( 'Finished: %1$d pulled, %2$d failed.', 'sceneshifter' ),
			/* translators: 1: number pulled, 2: number failed, 3: number left out. */
			'doneOut' => __( 'Finished: %1$d pulled, %2$d failed, %3$d left out.', 'sceneshifter' ),
			'leftOut' => __( 'Left out: you didn\'t agree to overwrite the post here.', 'sceneshifter' ),
			'leave'   => __( 'A pull is still running. Leaving now stops it after the current post.', 'sceneshifter' ),
		);
		?>
		<form class="sceneshifter-push-form" data-sceneshifter-push
			data-ajax="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
			data-queue="<?php echo esc_attr( (string) wp_json_encode( $queue ) ); ?>"
			data-text="<?php echo esc_attr( (string) wp_json_encode( $texts ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::AJAX_ACTION ); ?>">
			<input type="hidden" name="from" value="<?php echo esc_attr( $source->id ); ?>">
			<input type="hidden" name="transfer" value="<?php echo esc_attr( Uuid::generate() ); ?>">
			<?php wp_nonce_field( self::NONCE, '_wpnonce', false ); ?>
			<h2><?php esc_html_e( 'Pull', 'sceneshifter' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="sceneshifter-new-status"><?php esc_html_e( 'New posts arrive as', 'sceneshifter' ); ?></label></th>
					<td>
						<select id="sceneshifter-new-status" name="new_status">
							<option value="draft"><?php esc_html_e( 'Draft', 'sceneshifter' ); ?></option>
							<option value="pending"><?php esc_html_e( 'Pending review', 'sceneshifter' ); ?></option>
							<option value="publish"><?php esc_html_e( 'Published', 'sceneshifter' ); ?></option>
							<option value="source"><?php echo esc_html( sprintf( /* translators: %s: environment name. */ __( 'The same status as on %s', 'sceneshifter' ), $source->label ) ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="sceneshifter-existing-status"><?php esc_html_e( 'Posts that already exist here', 'sceneshifter' ); ?></label></th>
					<td>
						<select id="sceneshifter-existing-status" name="existing_status">
							<option value="keep"><?php esc_html_e( 'Keep their status here', 'sceneshifter' ); ?></option>
							<option value="source"><?php echo esc_html( sprintf( /* translators: %s: environment name. */ __( 'Take the status they have on %s', 'sceneshifter' ), $source->label ) ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'Changes to a published post here show to visitors straight away; pulls are never held for review. The previous version is kept, so the pull can be undone from Sceneshifter → Log.', 'sceneshifter' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Missing categories and tags', 'sceneshifter' ); ?></th>
					<td><label><input type="checkbox" name="create_terms" value="1" checked> <?php esc_html_e( 'Create them here', 'sceneshifter' ); ?></label></td>
				</tr>
			</table>
			<?php if ( array() !== $unlinked ) : ?>
				<h3><?php esc_html_e( 'Overwrite posts that were never linked?', 'sceneshifter' ); ?></h3>
				<p class="description">
					<?php
					/* translators: %s: environment name. */
					echo esc_html( sprintf( __( 'This site already has posts at the same addresses as these, but they were never linked to the ones on %s. Pulling replaces their content and links them from now on. Anything you leave unticked is left out of the pull.', 'sceneshifter' ), $source->label ) );
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
									/* translators: 1: post title here, 2: post ID here. */
									__( 'Overwrite "%1$s" (ID %2$d) here', 'sceneshifter' ),
									(string) ( $item->target['title'] ?? '' ),
									(int) ( $item->target['id'] ?? 0 )
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
					<?php echo esc_html( sprintf( /* translators: 1: number of posts, 2: environment name. */ _n( 'Pull %1$d item from %2$s', 'Pull %1$d items from %2$s', count( $queue ), 'sceneshifter' ), count( $queue ), $source->label ) ); ?>
				</button>
			</p>
			<ol class="sceneshifter-push-progress" data-sceneshifter-push-progress hidden></ol>
			<p class="sceneshifter-push-summary" data-sceneshifter-push-summary role="status"></p>
		</form>
		<?php
	}

	/**
	 * Pulls one post: admin-ajax action sceneshifter_pull_one.
	 */
	public function ajaxPull(): void {
		check_ajax_referer( self::NONCE );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked above.
		$remoteId = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$from     = isset( $_POST['from'] ) ? sanitize_key( wp_unslash( $_POST['from'] ) ) : '';
		$transfer = isset( $_POST['transfer'] ) ? sanitize_text_field( wp_unslash( $_POST['transfer'] ) ) : '';
		$expected = isset( $_POST['expected'] ) ? sanitize_key( wp_unslash( $_POST['expected'] ) ) : '';
		$options  = array(
			'new_status'      => isset( $_POST['new_status'] ) ? sanitize_key( wp_unslash( $_POST['new_status'] ) ) : 'draft',
			'existing_status' => isset( $_POST['existing_status'] ) ? sanitize_key( wp_unslash( $_POST['existing_status'] ) ) : 'keep',
			'create_terms'    => ! empty( $_POST['create_terms'] ),
		);
		$overwrite                     = isset( $_POST['overwrite'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['overwrite'] ) ) : array();
		$options['overwrite_unlinked'] = in_array( $remoteId, $overwrite, true );
		// phpcs:enable

		$local = ctype_digit( $expected ) ? (int) $expected : null;
		if ( ! current_user_can( Plugin::CAP_TRANSFER ) || 0 === $remoteId || ( null !== $local && ! current_user_can( 'edit_post', $local ) ) ) {
			wp_send_json_error( array( 'html' => esc_html__( 'You do not have permission to pull this.', 'sceneshifter' ) ), 403 );
		}
		$source = null;
		foreach ( PushPage::reachable( $this->plugin ) as $site ) {
			if ( $site->id === $from ) {
				$source = $site;
			}
		}
		$runner = $this->plugin->pullRunner();
		if ( null === $source || null === $runner || ! Uuid::isValid( $transfer ) ) {
			wp_send_json_error( array( 'html' => esc_html__( 'That environment can\'t be pulled from here.', 'sceneshifter' ) ), 400 );
		}

		wp_raise_memory_limit( 'admin' );
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 300 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- copying media can take a while.
		}
		$result = $runner->run( $source->id, $remoteId, $transfer, $options, 'new' === $expected ? null : ( $local ?? false ), wp_get_current_user()->display_name );
		wp_send_json_success(
			array(
				'ok'   => $result->ok(),
				'html' => self::resultHtml( $result, $source ),
			)
		);
	}

	/**
	 * What happened to one post, for the progress list.
	 */
	public static function resultHtml( PushResult $result, Site $source ): string {
		if ( ! $result->ok() ) {
			return '<strong>' . esc_html__( 'Failed:', 'sceneshifter' ) . '</strong> ' . esc_html( (string) $result->error );
		}
		$status = get_post_status_object( $result->status );
		$label  = null === $status ? $result->status : (string) $status->label;
		$html   = esc_html(
			$result->created
				/* translators: 1: post ID here, 2: status. */
				? sprintf( __( 'Created here (ID %1$d) as %2$s.', 'sceneshifter' ), (int) $result->targetId, $label )
				/* translators: 1: post ID here, 2: status. */
				: sprintf( __( 'Updated here (ID %1$d), now %2$s.', 'sceneshifter' ), (int) $result->targetId, $label )
		);
		$links = array();
		if ( '' !== ( $result->urls['edit'] ?? '' ) ) {
			$links[] = '<a href="' . esc_url( $result->urls['edit'] ) . '">' . esc_html__( 'Edit', 'sceneshifter' ) . '</a>';
		}
		if ( '' !== ( $result->urls['view'] ?? '' ) ) {
			$links[] = '<a href="' . esc_url( $result->urls['view'] ) . '" target="_blank" rel="noopener">' . esc_html__( 'View', 'sceneshifter' ) . '</a>';
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
			$warnings[] = sprintf( __( 'After saving, these didn\'t match what was pulled: %s. WordPress may have changed them, for example to keep an address unique.', 'sceneshifter' ), implode( ', ', array_map( array( PushPage::class, 'partLabel' ), $result->mismatch ) ) );
		}
		$empty = array_filter( $result->missing, static fn( array $m ): bool => ! $m['gone'] );
		if ( array() !== $empty ) {
			/* translators: 1: list of items, 2: environment name. */
			$warnings[] = sprintf( __( 'Left empty because this site doesn\'t have them: %1$s. Pull them from %2$s too if they matter.', 'sceneshifter' ), implode( '; ', array_map( array( PushPage::class, 'missingLabel' ), $empty ) ), $source->label );
		}
		foreach ( $result->renamed as $file ) {
			/* translators: 1: file name there, 2: file name here. */
			$warnings[] = sprintf( __( '%1$s was saved here as %2$s. Links to it in this post use the new name.', 'sceneshifter' ), $file['from'], $file['to'] );
		}
		if ( $result->linksTotal > 0 ) {
			/* translators: %d: number of links. */
			$warnings[] = sprintf( _n( 'It has %d link to another environment here:', 'It has %d links to other environments here:', $result->linksTotal, 'sceneshifter' ), $result->linksTotal )
				. ' ' . implode( '; ', array_map( static fn( array $l ): string => $l['url'] . ' (' . $l['where'] . ')', $result->links ) );
		}
		if ( array() !== $warnings ) {
			$html .= '<ul class="sceneshifter-push-warnings"><li>' . implode( '</li><li>', array_map( 'esc_html', $warnings ) ) . '</li></ul>';
		}
		return $html;
	}
}
