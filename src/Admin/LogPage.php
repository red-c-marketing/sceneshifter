<?php
/**
 * Sceneshifter → Log: every post pushed or pulled from or to this site,
 * newest first, with an Undo button for transfers that worked.
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
use RedC\Sceneshifter\Log\LogCsv;
use RedC\Sceneshifter\Log\LogEntry;
use RedC\Sceneshifter\Log\LogFilter;
use RedC\Sceneshifter\Plugin;
use RedC\Sceneshifter\Transfer\Rollback;

final class LogPage {

	public const SLUG   = 'sceneshifter-log';
	public const ACTION = 'sceneshifter_undo';
	public const CSV    = 'sceneshifter_log_csv';

	/** Rows read at a time while writing the CSV. */
	private const CSV_BATCH = 500;

	private const PER_PAGE = 50;

	public function __construct( private readonly Plugin $plugin ) {}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'addMenu' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handleUndo' ) );
		add_action( 'admin_post_' . self::CSV, array( $this, 'downloadCsv' ) );
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
		$show   = isset( $_GET['show'] ) ? absint( $_GET['show'] ) : 0;
		$filter = self::filter();
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
			$shown = $show > 0 ? $log->get( $show ) : null;
			if ( null !== $shown ) {
				$this->renderEntry( $shown, $filter );
				echo '</div>';
				return;
			}
			$total = $log->total( $filter );
			$this->renderFilters( $filter, $total );
			$entries = $log->recent( self::PER_PAGE, ( $page - 1 ) * self::PER_PAGE, $filter );
			if ( array() === $entries ) {
				echo '<p>' . esc_html( $filter->isEmpty() ? __( 'Nothing has been pushed or pulled from or to this site yet.', 'sceneshifter' ) : __( 'No entries match these filters.', 'sceneshifter' ) ) . '</p></div>';
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
							<td>
								<a class="button-link" href="<?php echo esc_url( self::url( array( 'show' => $entry->id ) + $filter->toQuery( wp_timezone() ) ) ); ?>"><?php esc_html_e( 'Details', 'sceneshifter' ); ?></a>
								<?php $this->undoButton( $entry, false ); ?><?php $this->heldButtons( $entry ); ?>
							</td>
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
							'base'    => add_query_arg( 'paged', '%#%', self::url( $filter->toQuery( wp_timezone() ) ) ),
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

	/**
	 * The filters in the current request.
	 */
	private static function filter(): LogFilter {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only; LogFilter validates every value.
		$input = array();
		foreach ( array( 'direction', 'sister', 'result', 'search', 'from', 'to', 'transfer' ) as $key ) {
			if ( isset( $_GET[ $key ] ) && is_string( $_GET[ $key ] ) ) {
				$input[ $key ] = sanitize_text_field( wp_unslash( $_GET[ $key ] ) );
			}
		}
		// phpcs:enable
		return LogFilter::fromInput( $input, wp_timezone() );
	}

	private function renderFilters( LogFilter $filter, int $total ): void {
		$self  = $this->plugin->sisters()->self();
		$sites = array_filter( $this->plugin->registry()->sites(), static fn( $site ): bool => null === $self || $site->id !== $self->id );
		$query = $filter->toQuery( wp_timezone() );
		?>
		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="sceneshifter-log-filters">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
			<?php if ( '' !== $filter->transfer ) : ?>
				<input type="hidden" name="transfer" value="<?php echo esc_attr( $filter->transfer ); ?>">
			<?php endif; ?>
			<label class="screen-reader-text" for="sceneshifter-log-direction"><?php esc_html_e( 'Direction', 'sceneshifter' ); ?></label>
			<select id="sceneshifter-log-direction" name="direction">
				<option value=""><?php esc_html_e( 'Both directions', 'sceneshifter' ); ?></option>
				<option value="<?php echo esc_attr( LogEntry::OUT ); ?>" <?php selected( $filter->direction, LogEntry::OUT ); ?>><?php esc_html_e( 'From this site', 'sceneshifter' ); ?></option>
				<option value="<?php echo esc_attr( LogEntry::IN ); ?>" <?php selected( $filter->direction, LogEntry::IN ); ?>><?php esc_html_e( 'To this site', 'sceneshifter' ); ?></option>
			</select>
			<label class="screen-reader-text" for="sceneshifter-log-sister"><?php esc_html_e( 'Environment', 'sceneshifter' ); ?></label>
			<select id="sceneshifter-log-sister" name="sister">
				<option value=""><?php esc_html_e( 'All environments', 'sceneshifter' ); ?></option>
				<?php foreach ( $sites as $site ) : ?>
					<option value="<?php echo esc_attr( $site->id ); ?>" <?php selected( $filter->sister, $site->id ); ?>><?php echo esc_html( $site->label ); ?></option>
				<?php endforeach; ?>
			</select>
			<label class="screen-reader-text" for="sceneshifter-log-result"><?php esc_html_e( 'Result', 'sceneshifter' ); ?></label>
			<select id="sceneshifter-log-result" name="result">
				<option value=""><?php esc_html_e( 'Any result', 'sceneshifter' ); ?></option>
				<option value="<?php echo esc_attr( LogEntry::DONE ); ?>" <?php selected( $filter->result, LogEntry::DONE ); ?>><?php esc_html_e( 'Worked', 'sceneshifter' ); ?></option>
				<option value="<?php echo esc_attr( LogEntry::FAILED ); ?>" <?php selected( $filter->result, LogEntry::FAILED ); ?>><?php esc_html_e( 'Failed', 'sceneshifter' ); ?></option>
				<option value="<?php echo esc_attr( LogEntry::UNDONE ); ?>" <?php selected( $filter->result, LogEntry::UNDONE ); ?>><?php esc_html_e( 'Undone', 'sceneshifter' ); ?></option>
				<option value="<?php echo esc_attr( LogEntry::HELD ); ?>" <?php selected( $filter->result, LogEntry::HELD ); ?>><?php esc_html_e( 'Waiting for review', 'sceneshifter' ); ?></option>
			</select>
			<label for="sceneshifter-log-from"><?php esc_html_e( 'From', 'sceneshifter' ); ?></label>
			<input type="date" id="sceneshifter-log-from" name="from" value="<?php echo esc_attr( $query['from'] ?? '' ); ?>">
			<label for="sceneshifter-log-to"><?php esc_html_e( 'to', 'sceneshifter' ); ?></label>
			<input type="date" id="sceneshifter-log-to" name="to" value="<?php echo esc_attr( $query['to'] ?? '' ); ?>">
			<label class="screen-reader-text" for="sceneshifter-log-search"><?php esc_html_e( 'Title or person', 'sceneshifter' ); ?></label>
			<input type="search" id="sceneshifter-log-search" name="search" value="<?php echo esc_attr( $filter->search ); ?>" placeholder="<?php esc_attr_e( 'Title or person', 'sceneshifter' ); ?>">
			<button type="submit" class="button"><?php esc_html_e( 'Filter', 'sceneshifter' ); ?></button>
			<?php if ( ! $filter->isEmpty() ) : ?>
				<a class="button-link" href="<?php echo esc_url( self::url() ); ?>"><?php esc_html_e( 'Clear filters', 'sceneshifter' ); ?></a>
			<?php endif; ?>
		</form>
		<p class="sceneshifter-log-count">
			<?php
			if ( '' !== $filter->transfer ) {
				esc_html_e( 'Showing one batch.', 'sceneshifter' );
				echo ' ';
			}
			/* translators: %d: number of entries. */
			echo esc_html( sprintf( _n( '%d entry', '%d entries', $total, 'sceneshifter' ), $total ) );
			if ( $total > 0 ) {
				echo ' · <a href="' . esc_url( add_query_arg( $query + array( 'action' => self::CSV, '_wpnonce' => wp_create_nonce( self::CSV ) ), admin_url( 'admin-post.php' ) ) ) . '">' . esc_html__( 'Download as CSV', 'sceneshifter' ) . '</a>';
			}
			?>
		</p>
		<?php
	}

	/**
	 * Everything recorded about one entry.
	 */
	private function renderEntry( LogEntry $entry, LogFilter $filter ): void {
		$out     = LogEntry::OUT === $entry->direction;
		$details = $entry->details;
		$rows    = array(
			__( 'When', 'sceneshifter' )          => (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $entry->at ),
			__( 'Who', 'sceneshifter' )           => '' === $entry->user ? '—' : $entry->user,
			__( 'From → to', 'sceneshifter' )     => $this->direction( $entry ) . ( empty( $details['pulled'] ) ? '' : ' (' . __( 'pulled', 'sceneshifter' ) . ')' ),
			__( 'Post here', 'sceneshifter' )     => (string) ( ( $out ? $entry->sourceId : $entry->targetId ) ?? '—' ),
			__( 'Post there', 'sceneshifter' )    => (string) ( ( $out ? $entry->targetId : $entry->sourceId ) ?? '—' ),
			__( 'Batch', 'sceneshifter' )         => $entry->transfer,
		);
		$facts = array(
			'status'      => __( 'Status there afterwards', 'sceneshifter' ),
			'media'       => __( 'Files copied', 'sceneshifter' ),
			'rewrites'    => __( 'Links and IDs rewritten', 'sceneshifter' ),
			'missing'     => __( 'References left empty', 'sceneshifter' ),
			'links'       => __( 'Links to other environments afterwards', 'sceneshifter' ),
			'links_total' => __( 'Links to other environments afterwards', 'sceneshifter' ),
			'snapshot'    => __( 'Snapshot', 'sceneshifter' ),
			'held'        => __( 'Copy waiting for review', 'sceneshifter' ),
			'applied_by'  => __( 'Applied by', 'sceneshifter' ),
			'restored'    => __( 'Put back after failing', 'sceneshifter' ),
		);
		foreach ( $facts as $key => $label ) {
			$value = $details[ $key ] ?? null;
			if ( is_int( $value ) || ( is_string( $value ) && '' !== $value ) ) {
				$rows[ $label ] = (string) $value;
			} elseif ( is_bool( $value ) ) {
				$rows[ $label ] = $value ? __( 'Yes', 'sceneshifter' ) : __( 'No', 'sceneshifter' );
			}
		}
		if ( is_int( $details['applied_at'] ?? null ) ) {
			$rows[ __( 'Applied at', 'sceneshifter' ) ] = (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $details['applied_at'] );
		}
		$mismatch = is_array( $details['mismatch'] ?? null ) ? $details['mismatch'] : array();
		if ( array() !== $mismatch ) {
			$rows[ __( 'Differed after saving', 'sceneshifter' ) ] = implode( ', ', array_map( static fn( mixed $p ): string => PushPage::partLabel( (string) $p ), $mismatch ) );
		}
		?>
		<p><a href="<?php echo esc_url( self::url( $filter->toQuery( wp_timezone() ) ) ); ?>">&larr; <?php esc_html_e( 'Back to the log', 'sceneshifter' ); ?></a></p>
		<h2><?php echo wp_kses_post( $this->postCell( $entry ) ); ?></h2>
		<p><?php echo wp_kses_post( self::resultCell( $entry ) ); ?></p>
		<table class="widefat striped sceneshifter-push-facts">
			<tbody>
				<?php foreach ( $rows as $label => $value ) : ?>
					<tr>
						<th scope="row"><?php echo esc_html( $label ); ?></th>
						<td><?php echo esc_html( $value ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p>
			<a class="button" href="<?php echo esc_url( self::url( array( 'transfer' => $entry->transfer ) ) ); ?>"><?php esc_html_e( 'Show the whole batch', 'sceneshifter' ); ?></a>
			<?php $this->undoButton( $entry, false ); ?><?php $this->heldButtons( $entry ); ?>
		</p>
		<?php
	}

	/**
	 * Streams the filtered log as CSV: admin-post action sceneshifter_log_csv.
	 */
	public function downloadCsv(): void {
		check_admin_referer( self::CSV );
		$log = $this->plugin->log();
		if ( ! current_user_can( Plugin::CAP_VIEW ) || null === $log ) {
			wp_die( esc_html__( 'You do not have permission to see the transfer log.', 'sceneshifter' ) );
		}
		$filter = self::filter();
		$self   = $this->plugin->sisters()->self();
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="sceneshifter-log-' . sanitize_file_name( null === $self ? 'site' : $self->id ) . '-' . gmdate( 'Y-m-d' ) . '.csv"' );
		echo LogCsv::line( LogCsv::header() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV, not HTML.
		for ( $offset = 0; ; $offset += self::CSV_BATCH ) {
			$entries = $log->recent( self::CSV_BATCH, $offset, $filter );
			foreach ( $entries as $entry ) {
				echo LogCsv::line( LogCsv::row( $entry ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV, not HTML.
			}
			if ( count( $entries ) < self::CSV_BATCH ) {
				break;
			}
		}
		exit;
	}

	private function renderNotice( string $notice, int $which, string $detail ): void {
		if ( '' === $notice ) {
			return;
		}
		if ( 'done' === $notice ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
			$removed = isset( $_GET['removed'] ) ? absint( $_GET['removed'] ) : 0;
			$text    = 0 === $removed
				? __( 'Undone.', 'sceneshifter' )
				/* translators: %d: number of files. */
				: sprintf( _n( 'Undone. %d copied file that nothing else used was deleted.', 'Undone. %d copied files that nothing else used were deleted.', $removed, 'sceneshifter' ), $removed );
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $text ) . '</p></div>';
			return;
		}
		if ( in_array( $notice, array( 'applied', 'discarded' ), true ) || str_starts_with( $notice, 'held_' ) ) {
			$class = in_array( $notice, array( 'applied', 'discarded' ), true ) ? 'notice-success' : 'notice-error';
			echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( HeldNotices::outcomeText( $notice, $detail ) ) . '</p></div>';
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
			Rollback::ALREADY   => __( 'That had already been undone.', 'sceneshifter' ),
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

	/**
	 * Apply and Discard for an update waiting for review here.
	 */
	private function heldButtons( LogEntry $entry ): void {
		$heldId = $entry->details['held'] ?? null;
		if ( LogEntry::IN !== $entry->direction || LogEntry::HELD !== $entry->result || ! is_int( $heldId ) || ! current_user_can( Plugin::CAP_TRANSFER ) ) {
			return;
		}
		$preview = get_preview_post_link( $heldId );
		if ( is_string( $preview ) && '' !== $preview ) {
			echo '<a class="button" href="' . esc_url( $preview ) . '">' . esc_html__( 'Preview', 'sceneshifter' ) . '</a> ';
		}
		echo '<a class="button button-primary" href="' . esc_url( HeldNotices::actionUrl( 'apply', $heldId, 'log' ) ) . '">' . esc_html__( 'Apply', 'sceneshifter' ) . '</a> ';
		echo '<a class="button" href="' . esc_url( HeldNotices::actionUrl( 'discard', $heldId, 'log' ) ) . '">' . esc_html__( 'Discard', 'sceneshifter' ) . '</a>';
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
		$entry   = $this->plugin->log()?->get( $id );
		$removed = 0;
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
			$removed = $result['removed'] ?? 0;
		} else {
			$rollback = $this->plugin->rollback();
			$reply    = null === $rollback || null === $entry->targetId ? array( 'error' => Rollback::NOT_FOUND ) : $rollback->undo( $entry->sister, $entry->transfer, $entry->targetId, $force, $user );
			$result   = array(
				'code'    => empty( $reply['undone'] ) ? (string) ( $reply['error'] ?? Rollback::FAILED ) : null,
				'message' => is_string( $reply['message'] ?? null ) ? $reply['message'] : null,
			);
			$removed  = is_array( $reply['media_removed'] ?? null ) ? count( $reply['media_removed'] ) : 0;
			if ( ! empty( $reply['undone'] ) && ! empty( $entry->details['pulled'] ) ) {
				// Best effort: the pull is already undone here.
				$this->plugin->pullRunner()?->undone( $entry, $reply, $user );
			}
		}
		$args = null === $result['code'] ? array(
			'undo'    => 'done',
			'removed' => $removed,
		) : array(
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
		$heldThere = LogEntry::OUT === $entry->direction && 'held' === ( $entry->details['status'] ?? null );
		$text      = match ( $entry->result ) {
			LogEntry::DONE   => $heldThere ? __( 'Held for review there', 'sceneshifter' ) : ( $entry->created ? __( 'Created', 'sceneshifter' ) : __( 'Updated', 'sceneshifter' ) ),
			LogEntry::HELD   => __( 'Waiting for review', 'sceneshifter' ),
			/* translators: %s: person's name. */
			LogEntry::UNDONE => null === $entry->message ? __( 'Undone', 'sceneshifter' ) : sprintf( __( 'Undone by %s', 'sceneshifter' ), $entry->message ),
			default          => __( 'Failed', 'sceneshifter' ),
		};
		$html = '<strong>' . esc_html( $text ) . '</strong>';
		if ( LogEntry::FAILED === $entry->result && null !== $entry->message ) {
			$html .= '<br><span class="description">' . esc_html( $entry->message ) . '</span>';
		}
		$facts = array();
		if ( ! empty( $entry->details['pulled'] ) ) {
			$facts[] = LogEntry::IN === $entry->direction ? __( 'pulled by this site', 'sceneshifter' ) : __( 'pulled by the other site', 'sceneshifter' );
		}
		if ( is_string( $entry->details['applied_by'] ?? null ) && '' !== $entry->details['applied_by'] ) {
			/* translators: %s: person's name. */
			$facts[] = sprintf( __( 'held for review, applied by %s', 'sceneshifter' ), $entry->details['applied_by'] );
		}
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
