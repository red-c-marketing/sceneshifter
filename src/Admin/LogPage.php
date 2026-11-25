<?php
/**
 * Sceneshifter → Log: every post pushed from or to this site, newest first,
 * with an Undo button for pushes that worked.
 *
 * Undo on an outgoing entry asks the other site to undo it; on an incoming
 * entry it is undone here. Either way, if the post has been edited since,
 * the page asks before throwing those edits away.
 *
 * @package RedC\Sceneshifter
 */

declare(strict_types=1);

namespace RedC\Sceneshifter\Admin;

use RedC\Sceneshifter\Check\CheckPage;
use RedC\Sceneshifter\Log\LogEntry;
use RedC\Sceneshifter\Plugin;
use RedC\Sceneshifter\Transfer\Rollback;

final class LogPage {

	public const SLUG   = 'sceneshifter-log';
	public const ACTION = 'sceneshifter_undo';

	private const PER_PAGE = 50;

	public function __construct( private readonly Plugin $plugin ) {}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'addMenu' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handleUndo' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	public function addMenu(): void {
		add_submenu_page( CheckPage::SLUG, __( 'Transfer log', 'sceneshifter' ), __( 'Log', 'sceneshifter' ), Plugin::CAP_VIEW, self::SLUG, array( $this, 'render' ) );
	}

	public function enqueue( string $hook ): void {
		if ( str_ends_with( $hook, '_page_' . self::SLUG ) ) {
			wp_enqueue_style( 'sceneshifter-settings', SCENESHIFTER_URL . 'assets/settings.css', array(), Plugin::assetVersion( 'assets/settings.css' ) );
		}
	}

	/**
	 * @param array<string, string|int> $args Extra query arguments.
	 */
	public static function url( array $args = array() ): string {
		return add_query_arg( array( 'page' => self::SLUG ) + $args, admin_url( 'admin.php' ) );
	}

	public function render(): void {
		if ( ! current_user_can( Plugin::CAP_VIEW ) ) {
			wp_die( esc_html__( 'You do not have permission to see the transfer log.', 'sceneshifter' ) );
		}
		$log = $this->plugin->log();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only.
		$page   = max( 1, isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 );
		$notice = isset( $_GET['undo'] ) ? sanitize_key( wp_unslash( $_GET['undo'] ) ) : '';
		$which  = isset( $_GET['entry'] ) ? absint( $_GET['entry'] ) : 0;
		$detail = isset( $_GET['detail'] ) ? sanitize_text_field( wp_unslash( $_GET['detail'] ) ) : '';
		// phpcs:enable
		?>
		<div class="wrap sceneshifter-log">
			<h1><?php esc_html_e( 'Transfer log', 'sceneshifter' ); ?></h1>
			<?php
			$this->renderNotice( $notice, $which, $detail );
			if ( null === $log ) {
				echo '<p>' . esc_html__( 'Sceneshifter is inactive on this site.', 'sceneshifter' ) . '</p></div>';
				return;
			}
			$total   = $log->total();
			$entries = $log->recent( self::PER_PAGE, ( $page - 1 ) * self::PER_PAGE );
			if ( array() === $entries ) {
				echo '<p>' . esc_html__( 'Nothing has been pushed from or to this site yet.', 'sceneshifter' ) . '</p></div>';
				return;
			}
			?>
			<table class="widefat striped sceneshifter-log-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'When', 'sceneshifter' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Who', 'sceneshifter' ); ?></th>
						<th scope="col"><?php esc_html_e( 'From → to', 'sceneshifter' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Post', 'sceneshifter' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Result', 'sceneshifter' ); ?></th>
						<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'sceneshifter' ); ?></span></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $entries as $entry ) : ?>
						<tr>
							<td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $entry->at ) ?: '' ); ?></td>
							<td><?php echo esc_html( '' === $entry->user ? '—' : $entry->user ); ?></td>
							<td><?php echo esc_html( $this->direction( $entry ) ); ?></td>
							<td><?php echo wp_kses_post( $this->postCell( $entry ) ); ?></td>
							<td><?php echo wp_kses_post( self::resultCell( $entry ) ); ?></td>
							<td><?php $this->undoButton( $entry, false ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php
			$pages = (int) ceil( $total / self::PER_PAGE );
			if ( $pages > 1 ) {
				echo '<div class="tablenav"><div class="tablenav-pages">';
				echo wp_kses_post(
					(string) paginate_links(
						array(
							'base'    => add_query_arg( 'paged', '%#%', self::url() ),
							'format'  => '',
							'current' => $page,
							'total'   => $pages,
						)
					)
				);
				echo '</div></div>';
			}
			?>
		</div>
		<?php
	}

	private function renderNotice( string $notice, int $which, string $detail ): void {
		if ( '' === $notice ) {
			return;
		}
		if ( 'done' === $notice ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Undone.', 'sceneshifter' ) . '</p></div>';
			return;
		}
		if ( Rollback::CHANGED_SINCE === $notice ) {
			$entry = $this->plugin->log()?->get( $which );
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'This post has been edited since the push. Undoing it now would throw those edits away as well.', 'sceneshifter' ) . '</p>';
			if ( null !== $entry ) {
				$this->undoButton( $entry, true );
			}
			echo '</div>';
			return;
		}
		$text = match ( $notice ) {
			Rollback::ALREADY   => __( 'That push had already been undone.', 'sceneshifter' ),
			Rollback::NOT_FOUND => __( 'There is nothing to undo for that entry. The snapshot may have been removed.', 'sceneshifter' ),
			'denied'            => __( 'You do not have permission to undo pushes.', 'sceneshifter' ),
			/* translators: %s: reason. */
			default             => '' === $detail ? __( 'The push couldn\'t be undone.', 'sceneshifter' ) : sprintf( __( 'The push couldn\'t be undone: %s', 'sceneshifter' ), $detail ),
		};
		echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $text ) . '</p></div>';
	}

	private function undoButton( LogEntry $entry, bool $force ): void {
		if ( LogEntry::DONE !== $entry->result || null === $entry->targetId || ! current_user_can( Plugin::CAP_TRANSFER ) ) {
			return;
		}
		$label = $force
			? __( 'Undo anyway', 'sceneshifter' )
			: ( $entry->created ? __( 'Undo (move to bin)', 'sceneshifter' ) : __( 'Undo', 'sceneshifter' ) );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sceneshifter-inline-form">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
			<input type="hidden" name="entry" value="<?php echo esc_attr( (string) $entry->id ); ?>">
			<?php if ( $force ) : ?>
				<input type="hidden" name="force" value="1">
			<?php endif; ?>
			<?php wp_nonce_field( self::ACTION . '_' . $entry->id ); ?>
			<button type="submit" class="button<?php echo $force ? ' button-primary' : ''; ?>"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	public function handleUndo(): void {
		$id = isset( $_POST['entry'] ) ? absint( $_POST['entry'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked next.
		check_admin_referer( self::ACTION . '_' . $id );
		if ( ! current_user_can( Plugin::CAP_TRANSFER ) ) {
			wp_safe_redirect( self::url( array( 'undo' => 'denied' ) ) );
			exit;
		}
		$force = ! empty( $_POST['force'] );
		$user  = wp_get_current_user()->display_name;
		$entry = $this->plugin->log()?->get( $id );
		if ( null === $entry ) {
			$result = array(
				'code'    => Rollback::NOT_FOUND,
				'message' => null,
			);
		} elseif ( LogEntry::OUT === $entry->direction ) {
			$runner = $this->plugin->pushRunner();
			$result = null === $runner ? array(
				'code'    => Rollback::FAILED,
				'message' => null,
			) : $runner->undo( $entry, $force, $user );
		} else {
			$rollback = $this->plugin->rollback();
			$reply    = null === $rollback || null === $entry->targetId ? array( 'error' => Rollback::NOT_FOUND ) : $rollback->undo( $entry->sister, $entry->transfer, $entry->targetId, $force, $user );
			$result   = array(
				'code'    => empty( $reply['undone'] ) ? (string) ( $reply['error'] ?? Rollback::FAILED ) : null,
				'message' => is_string( $reply['message'] ?? null ) ? $reply['message'] : null,
			);
		}
		$args = null === $result['code'] ? array( 'undo' => 'done' ) : array(
			'undo'   => $result['code'],
			'entry'  => $id,
			'detail' => (string) $result['message'],
		);
		wp_safe_redirect( self::url( $args ) );
		exit;
	}

	private function direction( LogEntry $entry ): string {
		$self  = $this->plugin->sisters()->self();
		$other = $this->plugin->registry()->get( $entry->sister );
		$here  = null === $self ? __( 'This site', 'sceneshifter' ) : $self->label;
		$there = null === $other ? $entry->sister : $other->label;
		return LogEntry::OUT === $entry->direction ? $here . ' → ' . $there : $there . ' → ' . $here;
	}

	private function postCell( LogEntry $entry ): string {
		$local = LogEntry::OUT === $entry->direction ? $entry->sourceId : $entry->targetId;
		$title = '' === $entry->title ? __( '(no title)', 'sceneshifter' ) : $entry->title;
		$link  = null === $local ? null : get_edit_post_link( $local, 'raw' );
		return null === $link || '' === $link ? esc_html( $title ) : '<a href="' . esc_url( $link ) . '">' . esc_html( $title ) . '</a>';
	}

	private static function resultCell( LogEntry $entry ): string {
		$text = match ( $entry->result ) {
			LogEntry::DONE   => $entry->created ? __( 'Created', 'sceneshifter' ) : __( 'Updated', 'sceneshifter' ),
			/* translators: %s: person's name. */
			LogEntry::UNDONE => null === $entry->message ? __( 'Undone', 'sceneshifter' ) : sprintf( __( 'Undone by %s', 'sceneshifter' ), $entry->message ),
			default          => __( 'Failed', 'sceneshifter' ),
		};
		$html = '<strong>' . esc_html( $text ) . '</strong>';
		if ( LogEntry::FAILED === $entry->result && null !== $entry->message ) {
			$html .= '<br><span class="description">' . esc_html( $entry->message ) . '</span>';
		}
		$facts = array();
		$media = (int) ( $entry->details['media'] ?? 0 );
		if ( $media > 0 ) {
			/* translators: %d: number of files. */
			$facts[] = sprintf( _n( '%d file copied', '%d files copied', $media, 'sceneshifter' ), $media );
		}
		$mismatch = (array) ( $entry->details['mismatch'] ?? array() );
		if ( array() !== $mismatch ) {
			/* translators: %s: list of parts. */
			$facts[] = sprintf( __( 'differed after saving: %s', 'sceneshifter' ), implode( ', ', array_map( static fn( mixed $p ): string => PushPage::partLabel( (string) $p ), $mismatch ) ) );
		}
		$links = (int) ( $entry->details['links'] ?? $entry->details['links_total'] ?? 0 );
		if ( $links > 0 ) {
			/* translators: %d: number of links. */
			$facts[] = sprintf( _n( '%d link to another environment', '%d links to other environments', $links, 'sceneshifter' ), $links );
		}
		if ( array() !== $facts ) {
			$html .= '<br><span class="description">' . esc_html( implode( '; ', $facts ) ) . '</span>';
		}
		return $html;
	}
}
