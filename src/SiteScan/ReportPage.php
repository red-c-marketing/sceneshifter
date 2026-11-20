<?php
/**
 * Sister Sync → Report: start a site-wide scan, watch its progress and browse
 * the findings of the last completed scan.
 *
 * @package RedC\SisterSync
 */

declare(strict_types=1);

namespace RedC\SisterSync\SiteScan;

use RedC\SisterSync\Check\CheckPage;
use RedC\SisterSync\Plugin;
use RedC\SisterSync\Scanner\Finding;

final class ReportPage {

	public const SLUG = 'sister-sync-report';

	private const START  = 'sister_sync_scan_start';
	private const CANCEL = 'sister_sync_scan_cancel';
	private const EXPORT = 'sister_sync_scan_export';

	public function __construct(
		private readonly Plugin $plugin,
		private readonly SiteScanner $scanner,
		private readonly ResultStore $store = new ResultStore()
	) {}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'addMenu' ), 15 );
		add_action( 'admin_post_' . self::START, array( $this, 'start' ) );
		add_action( 'admin_post_' . self::CANCEL, array( $this, 'cancel' ) );
		add_action( 'admin_post_' . self::EXPORT, array( $this, 'export' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	public function addMenu(): void {
		add_submenu_page(
			CheckPage::SLUG,
			__( 'Sister references report', 'sister-sync' ),
			__( 'Report', 'sister-sync' ),
			Plugin::CAP_VIEW,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	public static function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	public static function exportUrl( ReportFilters $filters ): string {
		return wp_nonce_url(
			add_query_arg( array_merge( array( 'action' => self::EXPORT ), $filters->toQuery() ), admin_url( 'admin-post.php' ) ),
			self::EXPORT
		);
	}

	public function enqueue( string $hook ): void {
		if ( ! str_ends_with( $hook, '_page_' . self::SLUG ) ) {
			return;
		}
		wp_enqueue_script( 'sister-sync-report', SISTER_SYNC_URL . 'assets/report.js', array( 'wp-api-fetch', 'wp-i18n' ), SISTER_SYNC_VERSION, true );
		wp_set_script_translations( 'sister-sync-report', 'sister-sync' );
	}

	public function render(): void {
		if ( ! current_user_can( Plugin::CAP_VIEW ) ) {
			wp_die( esc_html__( 'You do not have permission to view this report.', 'sister-sync' ) );
		}

		$state    = ScanState::load();
		$scanId   = $this->store->reportScanId();
		$filters  = ReportFilters::fromQuery( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filters.
		$canStart = current_user_can( 'manage_options' );
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Sister references report', 'sister-sync' ); ?></h1>
			<hr class="wp-header-end">

			<p><?php esc_html_e( 'Every link and image on this site that points at another environment. Covers the enabled content types, synced patterns, templates and navigation, plus menu custom links, widgets, ACF options pages and theme settings.', 'sister-sync' ); ?></p>

			<?php if ( ! SiteScanner::queueAvailable() ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'Background processing is unavailable because Action Scheduler is not loaded (run composer install). Scans still work, but only while this page is open.', 'sister-sync' ); ?></p></div>
			<?php endif; ?>

			<?php $this->renderStatus( $state, $canStart ); ?>

			<?php if ( '' !== $scanId ) : ?>
				<?php $this->renderSummary( $scanId, $state ); ?>
				<form method="get">
					<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
					<?php
					$table = new ReportTable( $this->store, $this->plugin->registry(), $scanId, $filters );
					$table->prepare_items();
					$table->search_box( __( 'Search URLs and items', 'sister-sync' ), 'sister-sync-search' );
					$table->display();
					?>
				</form>
			<?php elseif ( null === $state || ! $state->isRunning() ) : ?>
				<p><?php esc_html_e( 'No scan has finished yet.', 'sister-sync' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	private function renderStatus( ?ScanState $state, bool $canStart ): void {
		$status = ScanController::status( $state, $this->store );
		?>
		<div id="sister-sync-scan" class="card" style="max-width:none;" data-status="<?php echo esc_attr( (string) $status['status'] ); ?>">
			<?php if ( null !== $state && $state->isRunning() ) : ?>
				<h2><?php esc_html_e( 'Scan in progress', 'sister-sync' ); ?></h2>
				<progress id="sister-sync-scan-progress" max="100" value="<?php echo (int) $status['percent']; ?>" style="width:100%;height:20px;"></progress>
				<p id="sister-sync-scan-text"><?php echo esc_html( self::progressText( $state ) ); ?></p>
				<p class="description"><?php esc_html_e( 'The scan carries on in the background if you leave this page. Results replace the report below when it finishes.', 'sister-sync' ); ?></p>
				<?php if ( $canStart ) : ?>
					<?php $this->actionButton( self::CANCEL, __( 'Cancel scan', 'sister-sync' ), 'secondary' ); ?>
				<?php endif; ?>
			<?php else : ?>
				<?php if ( null !== $state && ScanState::FAILED === $state->status ) : ?>
					<div class="notice notice-error inline"><p>
						<?php
						/* translators: %s: error message. */
						printf( esc_html__( 'The last scan failed: %s', 'sister-sync' ), esc_html( $state->error ) );
						?>
					</p></div>
				<?php elseif ( null !== $state && ScanState::CANCELLED === $state->status ) : ?>
					<div class="notice notice-info inline"><p><?php esc_html_e( 'The last scan was cancelled. The report shows the scan before it.', 'sister-sync' ); ?></p></div>
				<?php endif; ?>
				<?php if ( $canStart ) : ?>
					<?php $this->actionButton( self::START, null === $state ? __( 'Run first scan', 'sister-sync' ) : __( 'Run new scan', 'sister-sync' ), 'primary' ); ?>
				<?php else : ?>
					<p class="description"><?php esc_html_e( 'An administrator can run a new scan.', 'sister-sync' ); ?></p>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	private function renderSummary( string $scanId, ?ScanState $state ): void {
		$summary = $this->store->summary( $scanId );

		// The state describes the report's scan only once it has completed.
		$finished = null !== $state && $state->id === $scanId ? $state : null;
		?>
		<h2>
			<?php
			if ( 0 === $summary['objects'] ) {
				esc_html_e( 'No sister links found.', 'sister-sync' );
			} else {
				printf(
					/* translators: 1: number of items, 2: errors, 3: warnings. */
					esc_html__( '%1$d items with sister links: %2$d errors, %3$d warnings', 'sister-sync' ),
					(int) $summary['objects'],
					(int) $summary[ Finding::ERROR ],
					(int) $summary[ Finding::WARNING ]
				);
			}
			?>
		</h2>
		<?php if ( null !== $finished && $finished->finishedAt > 0 ) : ?>
			<p class="description">
				<?php
				$user = get_userdata( $finished->startedBy );
				printf(
					/* translators: 1: date and time, 2: user name, 3: number of posts. */
					esc_html__( 'Scan finished %1$s, started by %2$s. %3$d posts scanned.', 'sister-sync' ),
					esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $finished->finishedAt ) ),
					esc_html( $user ? $user->display_name : __( 'WP-CLI', 'sister-sync' ) ),
					(int) $finished->processed
				);
				?>
			</p>
		<?php endif; ?>
		<?php
	}

	private function actionButton( string $action, string $label, string $type ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
			<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>">
			<?php wp_nonce_field( $action ); ?>
			<?php submit_button( $label, $type, 'submit', false ); ?>
		</form>
		<?php
	}

	public static function progressText( ScanState $state ): string {
		if ( ScanState::PHASE_EXTRAS === $state->phase ) {
			return __( 'Posts done. Scanning menus, widgets, ACF options and theme settings…', 'sister-sync' );
		}
		return sprintf(
			/* translators: 1: posts scanned so far, 2: total posts. */
			__( 'Scanning posts: %1$d of %2$d', 'sister-sync' ),
			$state->processed,
			$state->total
		);
	}

	public function start(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to run a scan.', 'sister-sync' ) );
		}
		check_admin_referer( self::START );
		$this->scanner->start( get_current_user_id() );
		wp_safe_redirect( self::url() );
		exit;
	}

	public function cancel(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to cancel a scan.', 'sister-sync' ) );
		}
		check_admin_referer( self::CANCEL );
		$this->scanner->cancel();
		wp_safe_redirect( self::url() );
		exit;
	}

	public function export(): void {
		if ( ! current_user_can( Plugin::CAP_VIEW ) ) {
			wp_die( esc_html__( 'You do not have permission to export this report.', 'sister-sync' ) );
		}
		check_admin_referer( self::EXPORT );

		$scanId  = $this->store->reportScanId();
		$filters = ReportFilters::fromQuery( $_GET );
		$table   = new ReportTable( $this->store, $this->plugin->registry(), $scanId, $filters );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="sister-references-' . gmdate( 'Y-m-d' ) . '.csv"' );

		$out = fopen( 'php://output', 'w' );
		if ( false === $out ) {
			exit;
		}
		fputcsv( $out, array( 'Item', 'Type', 'Severity', 'Points to', 'URL', 'Where', 'Times', 'Edit link' ), ',', '"', '' );
		if ( '' !== $scanId ) {
			foreach ( $this->store->all( $scanId, $filters ) as $row ) {
				fputcsv(
					$out,
					array_map(
						array( self::class, 'csvCell' ),
						array(
							(string) $row['object_title'],
							$table->typeLabel( $row ),
							(string) $row['severity'],
							$this->plugin->registry()->get( (string) $row['sister_id'] )?->label ?? (string) $row['sister_id'],
							(string) $row['url'],
							(string) $row['location'],
							(string) $row['hits'],
							(string) ( ReportTable::editLink( $row ) ?? '' ),
						)
					),
					',',
					'"',
					''
				);
			}
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/**
	 * Stops spreadsheet apps treating a cell as a formula.
	 */
	public static function csvCell( string $value ): string {
		return '' !== $value && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ? "'" . $value : $value;
	}
}
