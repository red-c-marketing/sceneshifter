<?php
/**
 * Settings → Link content: Link existing content with a paired environment.
 *
 * Fetching the other site's list and linking both run in small steps over
 * admin-ajax, so a large site doesn't hit a request time limit. Nothing is
 * linked until the admin chooses Link selected.
 *
 * @package RedC\Sceneshifter
 */

declare(strict_types=1);

namespace RedC\Sceneshifter\Admin;

use RedC\Sceneshifter\Plugin;
use RedC\Sceneshifter\Registry\Site;
use RedC\Sceneshifter\SyncMap\Item;
use RedC\Sceneshifter\SyncMap\LinkExisting;
use RedC\Sceneshifter\SyncMap\Matcher;
use RedC\Sceneshifter\SyncMap\MatchResult;

final class LinkTab {

	public const AJAX_FETCH = 'sceneshifter_link_fetch';
	public const AJAX_LINK  = 'sceneshifter_link_apply';
	public const NONCE      = 'sceneshifter_link';

	/** Longest list shown of items with no match. */
	private const LIST_LIMIT = 100;

	public function __construct( private readonly Plugin $plugin ) {}

	public function register(): void {
		add_action( 'wp_ajax_' . self::AJAX_FETCH, array( $this, 'ajaxFetch' ) );
		add_action( 'wp_ajax_' . self::AJAX_LINK, array( $this, 'ajaxLink' ) );
	}

	/**
	 * @param array<string, string> $args Extra query arguments.
	 */
	public static function url( array $args = array() ): string {
		return SettingsPage::url( array_merge( array( 'tab' => 'link' ), $args ) );
	}

	public function render(): void {
		$syncMap = $this->plugin->syncMap();
		$self    = $this->plugin->sisters()->self();
		if ( null === $syncMap || null === $self ) {
			echo '<p>' . esc_html__( 'Sceneshifter is inactive on this site. Check the site list first.', 'sceneshifter' ) . '</p>';
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- choosing a view only.
		$chosen = isset( $_GET['sister'] ) ? sanitize_key( wp_unslash( $_GET['sister'] ) ) : '';
		$sister = $this->linkable( $chosen );
		?>
		<p class="description">
			<?php esc_html_e( 'When two environments already have the same content, made separately on each, link each item to its copy on the other environment before the first push. Otherwise a push would create a second copy. Sceneshifter suggests matches by content type and address, and media by file name and size. Nothing is linked until you choose Link selected.', 'sceneshifter' ); ?>
		</p>
		<?php
		if ( null === $sister ) {
			$this->renderChoices( $self );
			return;
		}
		$this->renderSister( $sister, $syncMap->linkExisting() );
	}

	private function renderChoices( Site $self ): void {
		$pairings = $this->plugin->sisters()->pairings();
		$syncMap  = $this->plugin->syncMap();
		$rows     = array();
		foreach ( $this->plugin->registry()->sites() as $site ) {
			if ( $site->id !== $self->id && null !== $pairings->get( $site->id ) ) {
				$rows[] = $site;
			}
		}
		if ( array() === $rows ) {
			echo '<p>';
			printf(
				/* translators: %s: link to the Pairing tab. */
				esc_html__( 'This site isn\'t paired with any other environment yet. Pair them first on the %s tab.', 'sceneshifter' ),
				'<a href="' . esc_url( PairingTab::url() ) . '">' . esc_html__( 'Pairing', 'sceneshifter' ) . '</a>'
			);
			echo '</p>';
			return;
		}
		?>
		<table class="widefat striped sceneshifter-pairings">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Environment', 'sceneshifter' ); ?></th>
					<th><?php esc_html_e( 'Items linked', 'sceneshifter' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'sceneshifter' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $site ) : ?>
					<tr>
						<td>
							<span class="sceneshifter-role-dot" style="background:<?php echo esc_attr( $site->colour() ); ?>"></span>
							<strong><?php echo esc_html( $site->label ); ?></strong>
						</td>
						<td><?php echo esc_html( number_format_i18n( null === $syncMap ? 0 : $syncMap->map()->count( $site->id ) ) ); ?></td>
						<td class="sceneshifter-pair-actions">
							<?php if ( $site->isPublic ) : ?>
								<a class="button" href="<?php echo esc_url( self::url( array( 'sister' => $site->id ) ) ); ?>"><?php esc_html_e( 'Link existing content', 'sceneshifter' ); ?></a>
							<?php else : ?>
								<p class="description">
									<?php
									/* translators: %s: site name. */
									echo esc_html( sprintf( __( '%1$s isn\'t public, so this site can\'t contact it. Link content from %1$s instead.', 'sceneshifter' ), $site->label ) );
									?>
								</p>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private function renderSister( Site $sister, LinkExisting $linker ): void {
		$state  = $linker->cache()->state( $sister->id );
		$review = $linker->review( $sister->id );
		?>
		<p><a href="<?php echo esc_url( self::url() ); ?>">&larr; <?php esc_html_e( 'All environments', 'sceneshifter' ); ?></a></p>
		<h2>
			<?php
			/* translators: %s: site name. */
			echo esc_html( sprintf( __( 'Link content with %s', 'sceneshifter' ), $sister->label ) );
			?>
		</h2>
		<div class="sceneshifter-link-fetch" data-sceneshifter-fetch data-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" data-action="<?php echo esc_attr( self::AJAX_FETCH ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( self::NONCE ) ); ?>" data-sister="<?php echo esc_attr( $sister->id ); ?>" data-done-url="<?php echo esc_url( self::url( array( 'sister' => $sister->id ) ) ); ?>" data-text="<?php echo esc_attr( (string) wp_json_encode( self::scriptText() ) ); ?>">
			<p>
				<?php if ( null !== $review && null !== $state ) : ?>
					<?php
					/* translators: 1: site name, 2: how long ago, for example "5 mins". */
					echo esc_html( sprintf( __( 'Matches below use the list fetched from %1$s %2$s ago.', 'sceneshifter' ), $sister->label, human_time_diff( (int) $state['finished'] ) ) );
					?>
				<?php else : ?>
					<?php
					/* translators: %s: site name. */
					echo esc_html( sprintf( __( 'First, fetch the list of content on %s. This only reads: nothing changes on either site.', 'sceneshifter' ), $sister->label ) );
					?>
				<?php endif; ?>
			</p>
			<p>
				<button type="button" class="button<?php echo null === $review ? ' button-primary' : ''; ?>" data-sceneshifter-fetch-start>
					<?php echo esc_html( null === $review ? __( 'Find matches', 'sceneshifter' ) : __( 'Find matches again', 'sceneshifter' ) ); ?>
				</button>
				<span class="sceneshifter-link-progress" role="status" aria-live="polite" data-sceneshifter-fetch-status></span>
			</p>
		</div>
		<?php
		if ( null === $review || null === $state ) {
			return;
		}
		foreach ( $state['skipped'] as $type => $why ) {
			echo '<div class="notice notice-warning inline"><p>';
			/* translators: 1: content type, 2: site name. */
			echo esc_html( sprintf( __( '%1$s were skipped: %2$s doesn\'t have that content type enabled.', 'sceneshifter' ), self::typeLabel( $type ), $sister->label ) );
			echo '</p></div>';
		}
		$this->renderSummary( $sister, $review );
		$this->renderReview( $sister, $review );
	}

	/**
	 * @param list<MatchResult> $review
	 */
	private function renderSummary( Site $sister, array $review ): void {
		?>
		<div class="sceneshifter-scroll">
		<table class="widefat striped sceneshifter-link-summary">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Content type', 'sceneshifter' ); ?></th>
					<th><?php esc_html_e( 'Already linked', 'sceneshifter' ); ?></th>
					<th><?php esc_html_e( 'Suggested matches', 'sceneshifter' ); ?></th>
					<th><?php esc_html_e( 'Need a decision', 'sceneshifter' ); ?></th>
					<th><?php esc_html_e( 'Only on this site', 'sceneshifter' ); ?></th>
					<th>
						<?php
						/* translators: %s: site name. */
						echo esc_html( sprintf( __( 'Only on %s', 'sceneshifter' ), $sister->label ) );
						?>
					</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $review as $result ) : ?>
					<tr>
						<th scope="row"><?php echo esc_html( self::typeLabel( $result->type ) ); ?></th>
						<td><?php echo esc_html( number_format_i18n( $result->linked ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( count( $result->proposals ) ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( count( $result->attention ) ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( count( $result->onlyHere ) ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( count( $result->onlyThere ) ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		</div>
		<?php
	}

	/**
	 * @param list<MatchResult> $review
	 */
	private function renderReview( Site $sister, array $review ): void {
		$proposals = array_sum( array_map( static fn( MatchResult $r ): int => count( $r->proposals ), $review ) );
		?>
		<form class="sceneshifter-link-review" data-sceneshifter-link data-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" data-action="<?php echo esc_attr( self::AJAX_LINK ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( self::NONCE ) ); ?>" data-sister="<?php echo esc_attr( $sister->id ); ?>" data-batch="<?php echo esc_attr( (string) LinkExisting::LINK_BATCH ); ?>" data-text="<?php echo esc_attr( (string) wp_json_encode( self::scriptText() ) ); ?>">
			<?php foreach ( $review as $result ) : ?>
				<?php
				if ( array() === $result->proposals && array() === $result->attention && array() === $result->onlyHere && array() === $result->onlyThere ) {
					continue;
				}
				?>
				<h3><?php echo esc_html( self::typeLabel( $result->type ) ); ?></h3>
				<?php if ( array() !== $result->proposals ) : ?>
					<details open>
						<summary>
							<?php
							/* translators: %s: number of matches. */
							echo esc_html( sprintf( _n( '%s suggested match', '%s suggested matches', count( $result->proposals ), 'sceneshifter' ), number_format_i18n( count( $result->proposals ) ) ) );
							?>
						</summary>
						<table class="widefat striped sceneshifter-link-table">
							<thead>
								<tr>
									<td class="check-column"><input type="checkbox" checked data-sceneshifter-check-all aria-label="<?php esc_attr_e( 'Select all', 'sceneshifter' ); ?>"></td>
									<th><?php esc_html_e( 'On this site', 'sceneshifter' ); ?></th>
									<th><?php echo esc_html( sprintf( /* translators: %s: site name. */ __( 'On %s', 'sceneshifter' ), $sister->label ) ); ?></th>
									<th><?php esc_html_e( 'Matched by', 'sceneshifter' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $result->proposals as $proposal ) : ?>
									<tr>
										<th scope="row" class="check-column">
											<input type="checkbox" checked data-local="<?php echo esc_attr( (string) $proposal['local']->id ); ?>" data-remote="<?php echo esc_attr( (string) $proposal['remote']->id ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: title. */ __( 'Link %s', 'sceneshifter' ), self::title( $proposal['local'] ) ) ); ?>">
										</th>
										<td><?php self::renderItem( $proposal['local'], true ); ?></td>
										<td><?php self::renderItem( $proposal['remote'], false ); ?></td>
										<td><?php echo esc_html( self::reason( $proposal['reason'] ) ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</details>
				<?php endif; ?>
				<?php if ( array() !== $result->attention ) : ?>
					<details>
						<summary>
							<?php
							/* translators: %s: number of items. */
							echo esc_html( sprintf( _n( '%s item needs a decision', '%s items need a decision', count( $result->attention ), 'sceneshifter' ), number_format_i18n( count( $result->attention ) ) ) );
							?>
						</summary>
						<p class="description"><?php esc_html_e( 'These aren\'t linked automatically. Linking them one by one is coming in a later version. Until then, give one of the items a different address, or remove the duplicate, and find matches again.', 'sceneshifter' ); ?></p>
						<table class="widefat striped sceneshifter-link-table">
							<thead>
								<tr>
									<th><?php esc_html_e( 'On this site', 'sceneshifter' ); ?></th>
									<th><?php echo esc_html( sprintf( /* translators: %s: site name. */ __( 'Possible matches on %s', 'sceneshifter' ), $sister->label ) ); ?></th>
									<th><?php esc_html_e( 'Why', 'sceneshifter' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $result->attention as $row ) : ?>
									<tr>
										<td><?php self::renderItem( $row['local'], true ); ?></td>
										<td>
											<?php foreach ( $row['candidates'] as $candidate ) : ?>
												<div class="sceneshifter-link-candidate"><?php self::renderItem( $candidate, false ); ?></div>
											<?php endforeach; ?>
										</td>
										<td><?php echo esc_html( self::reason( $row['reason'] ) ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</details>
				<?php endif; ?>
				<?php self::renderUnmatched( __( 'Only on this site', 'sceneshifter' ), $result->onlyHere, true ); ?>
				<?php self::renderUnmatched( sprintf( /* translators: %s: site name. */ __( 'Only on %s', 'sceneshifter' ), $sister->label ), $result->onlyThere, false ); ?>
			<?php endforeach; ?>

			<?php if ( $proposals > 0 ) : ?>
				<p class="submit">
					<button type="submit" class="button button-primary" data-sceneshifter-link-submit><?php esc_html_e( 'Link selected', 'sceneshifter' ); ?></button>
					<span class="sceneshifter-link-progress" role="status" aria-live="polite" data-sceneshifter-link-status></span>
				</p>
				<div data-sceneshifter-link-failures></div>
			<?php else : ?>
				<p><?php esc_html_e( 'There is nothing new to link.', 'sceneshifter' ); ?></p>
			<?php endif; ?>
		</form>
		<?php
	}

	/**
	 * @param list<Item> $items
	 */
	private static function renderUnmatched( string $heading, array $items, bool $here ): void {
		if ( array() === $items ) {
			return;
		}
		?>
		<details>
			<summary><?php echo esc_html( sprintf( '%s (%s)', $heading, number_format_i18n( count( $items ) ) ) ); ?></summary>
			<ul class="sceneshifter-link-unmatched">
				<?php foreach ( array_slice( $items, 0, self::LIST_LIMIT ) as $item ) : ?>
					<li><?php self::renderItem( $item, $here ); ?></li>
				<?php endforeach; ?>
			</ul>
			<?php if ( count( $items ) > self::LIST_LIMIT ) : ?>
				<p class="description">
					<?php
					/* translators: %s: number of items. */
					echo esc_html( sprintf( __( 'Showing the first %s.', 'sceneshifter' ), number_format_i18n( self::LIST_LIMIT ) ) );
					?>
				</p>
			<?php endif; ?>
		</details>
		<?php
	}

	private static function renderItem( Item $item, bool $here ): void {
		$title = esc_html( self::title( $item ) );
		$edit  = $here ? get_edit_post_link( $item->id ) : null;
		echo is_string( $edit ) && '' !== $edit ? '<a href="' . esc_url( $edit ) . '">' . $title . '</a>' : '<strong>' . $title . '</strong>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
		echo '<br><code>' . esc_html( '' === $item->key ? __( '(no address)', 'sceneshifter' ) : $item->key ) . '</code>';
		$meta = array( sprintf( /* translators: %d: post ID. */ __( 'ID %d', 'sceneshifter' ), $item->id ) );
		if ( null !== $item->size ) {
			$meta[] = size_format( $item->size );
		}
		if ( ! in_array( $item->status, array( 'publish', 'inherit' ), true ) ) {
			$meta[] = $item->status;
		}
		echo ' <span class="description">' . esc_html( implode( ' · ', $meta ) ) . '</span>';
	}

	/**
	 * Text the script shows. %1$d and %2$d are counts.
	 *
	 * @return array<string, string>
	 */
	private static function scriptText(): array {
		return array(
			/* translators: 1: number linked so far, 2: number selected. */
			'linking' => __( 'Linking… %1$d of %2$d', 'sceneshifter' ),
			/* translators: 1: number linked, 2: number selected. */
			'linked'  => __( '%1$d of %2$d linked.', 'sceneshifter' ),
			'failed'  => __( 'The request failed. Check your connection and try again.', 'sceneshifter' ),
			'refresh' => __( 'Refresh the list', 'sceneshifter' ),
		);
	}

	private static function title( Item $item ): string {
		return '' === $item->title ? __( '(no title)', 'sceneshifter' ) : $item->title;
	}

	private static function reason( string $reason ): string {
		return match ( $reason ) {
			Matcher::UUID          => __( 'Same Sceneshifter ID (copied database, or linked before)', 'sceneshifter' ),
			Matcher::PATH          => __( 'Same address', 'sceneshifter' ),
			Matcher::FILE          => __( 'Same file and size', 'sceneshifter' ),
			Matcher::NAME_SIZE     => __( 'Same file name and size', 'sceneshifter' ),
			Matcher::AMBIGUOUS     => __( 'More than one possible match', 'sceneshifter' ),
			Matcher::DIFFERENT_IDS => __( 'Already linked to different content', 'sceneshifter' ),
			Matcher::DUPLICATE_ID  => __( 'Shares its Sceneshifter ID with another item, most likely copied by a duplicate-post plugin', 'sceneshifter' ),
			default                => $reason,
		};
	}

	private static function typeLabel( string $type ): string {
		$object = get_post_type_object( $type );
		return $object ? (string) $object->labels->name : $type;
	}

	/**
	 * A paired, public sister, or null.
	 */
	private function linkable( string $id ): ?Site {
		$site = '' === $id ? null : $this->plugin->registry()->get( $id );
		if ( null === $site || ! $site->isPublic || $site->id === $this->plugin->sisters()->self()?->id || null === $this->plugin->sisters()->pairings()->get( $site->id ) ) {
			return null;
		}
		return $site;
	}

	/**
	 * One step of fetching the sister's list.
	 */
	public function ajaxFetch(): void {
		check_ajax_referer( self::NONCE );
		$sister  = $this->linkable( isset( $_POST['sister'] ) ? sanitize_key( wp_unslash( $_POST['sister'] ) ) : '' );
		$syncMap = $this->plugin->syncMap();
		if ( ! PairingTab::canManage() || null === $sister || null === $syncMap ) {
			wp_send_json_error( array( 'message' => __( 'You can\'t do that here.', 'sceneshifter' ) ), 403 );
		}
		$linker = $syncMap->linkExisting();
		if ( ! empty( $_POST['start'] ) ) {
			$linker->start( $sister->id, time() );
		}
		$step = $linker->step( $sister->id, time() );
		if ( null !== $step['error'] ) {
			wp_send_json_error( array( 'message' => $step['error'] ) );
		}
		wp_send_json_success(
			array(
				'done'    => $step['done'],
				'message' => $step['done']
					? __( 'Done. Loading the matches…', 'sceneshifter' )
					/* translators: 1: content type, 2: page number, 3: number of pages. */
					: sprintf( __( 'Fetched %1$s, page %2$d of %3$d…', 'sceneshifter' ), self::typeLabel( $step['type'] ), $step['page'], $step['pages'] ),
			)
		);
	}

	/**
	 * Links one batch of pairs.
	 */
	public function ajaxLink(): void {
		check_ajax_referer( self::NONCE );
		$sister  = $this->linkable( isset( $_POST['sister'] ) ? sanitize_key( wp_unslash( $_POST['sister'] ) ) : '' );
		$syncMap = $this->plugin->syncMap();
		if ( ! PairingTab::canManage() || null === $sister || null === $syncMap ) {
			wp_send_json_error( array( 'message' => __( 'You can\'t do that here.', 'sceneshifter' ) ), 403 );
		}
		$raw   = json_decode( isset( $_POST['pairs'] ) ? wp_unslash( (string) $_POST['pairs'] ) : '[]', true ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON, each value cast to int below.
		$pairs = array();
		foreach ( is_array( $raw ) ? array_slice( $raw, 0, LinkExisting::LINK_BATCH ) : array() as $pair ) {
			if ( is_array( $pair ) && isset( $pair[0], $pair[1] ) ) {
				$pairs[] = array( (int) $pair[0], (int) $pair[1] );
			}
		}
		$result = $syncMap->linkExisting()->link( $sister->id, $pairs, time() );
		if ( null !== $result['error'] ) {
			wp_send_json_error( array( 'message' => $result['error'] ) );
		}
		$failed = array();
		foreach ( $result['failed'] as $failure ) {
			$item     = $syncMap->index()->get( $failure['local'] );
			$failed[] = sprintf( '%s (ID %d): %s', null === $item ? '' : self::title( $item ), $failure['local'], $failure['message'] );
		}
		wp_send_json_success(
			array(
				'linked' => $result['linked'],
				'failed' => $failed,
			)
		);
	}
}
