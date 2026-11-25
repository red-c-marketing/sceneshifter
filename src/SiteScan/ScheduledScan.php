<?php
/**
 * Runs the site scan on a schedule and emails a summary when it finishes.
 *
 * WP-Cron starts the scan; its batches then run like any other scan.
 *
 * @package RedC\Sceneshifter
 */

declare(strict_types=1);

namespace RedC\Sceneshifter\SiteScan;

use DateTimeImmutable;
use RedC\Sceneshifter\Plugin;
use RedC\Sceneshifter\Scanner\Finding;
use RedC\Sceneshifter\Store\CheckSettings;

final class ScheduledScan {

	public const HOOK = 'sceneshifter_scheduled_scan';

	/** Local time scheduled scans start, when the site is quiet. */
	private const START_TIME = '03:00';

	public function __construct(
		private readonly Plugin $plugin,
		private readonly SiteScanner $scanner,
		private readonly ResultStore $store = new ResultStore()
	) {}

	public function register(): void {
		add_action( self::HOOK, array( $this, 'run' ) );
		add_action( 'sceneshifter_scan_complete', array( $this, 'notify' ), 10, 2 );
		add_action( 'admin_init', array( $this, 'ensureScheduled' ) );
	}

	/**
	 * Sets the cron event to match the setting. Called when Check settings
	 * are saved.
	 */
	public static function reschedule( string $schedule ): void {
		wp_unschedule_hook( self::HOOK );
		if ( CheckSettings::SCHEDULE_OFF === $schedule ) {
			return;
		}
		$first = new DateTimeImmutable( 'tomorrow ' . self::START_TIME, wp_timezone() );
		wp_schedule_event( $first->getTimestamp(), $schedule, self::HOOK );
	}

	/**
	 * Repairs the cron event if it has gone missing or no longer matches the
	 * setting, for example after a database was copied from another site.
	 */
	public function ensureScheduled(): void {
		$schedule = $this->plugin->options()->checkSettings()->schedule;
		$event    = wp_get_scheduled_event( self::HOOK );
		$current  = false === $event ? CheckSettings::SCHEDULE_OFF : (string) $event->schedule;
		if ( $current !== $schedule ) {
			self::reschedule( $schedule );
		}
	}

	public function run(): void {
		if ( ! $this->plugin->identity()->isActive() ) {
			return;
		}
		$state = ScanState::load();
		if ( null !== $state && $state->isRunning() ) {
			return; // Someone is already scanning; don't throw their scan away.
		}
		$this->scanner->start( 0, ScanState::TRIGGER_SCHEDULED );
	}

	/**
	 * Emails the summary of a scheduled scan.
	 *
	 * @param ScanState                  $state       The finished scan.
	 * @param list<array<string, mixed>> $newFindings Findings that weren't in the previous report.
	 */
	public function notify( ScanState $state, array $newFindings ): void {
		if ( ScanState::TRIGGER_SCHEDULED !== $state->trigger ) {
			return;
		}
		$settings = $this->plugin->options()->checkSettings();
		if ( array() === $settings->recipients ) {
			return;
		}
		if ( CheckSettings::NOTIFY_NEW === $settings->notify && 0 === $state->newFindings ) {
			return;
		}

		$summary = $this->store->summary( $state->id );
		$message = self::message(
			$this->plugin->identity()->site->label ?? '',
			home_url(),
			$state->newFindings,
			$summary,
			$newFindings,
			admin_url( 'admin.php?page=' . ReportPage::SLUG ),
			fn( string $sisterId ): string => $this->plugin->registry()->get( $sisterId )->label ?? $sisterId
		);

		$subject = 0 === $state->newFindings
			/* translators: %s: site name. */
			? sprintf( __( '[%s] Sceneshifter scan: no new links to other environments', 'sceneshifter' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) )
			: sprintf(
				/* translators: 1: site name, 2: number of new findings. */
				_n( '[%1$s] Sceneshifter scan: %2$d new link to another environment', '[%1$s] Sceneshifter scan: %2$d new links to other environments', $state->newFindings, 'sceneshifter' ),
				wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
				$state->newFindings
			);

		wp_mail( $settings->recipients, $subject, $message );
	}

	/**
	 * The plain-text email body.
	 *
	 * @param array{error: int, warning: int, objects: int} $summary
	 * @param list<array<string, mixed>>                    $newFindings
	 * @param callable(string): string                      $sisterLabel
	 */
	public static function message( string $siteLabel, string $homeUrl, int $newCount, array $summary, array $newFindings, string $reportUrl, callable $sisterLabel ): string {
		$lines = array();

		$lines[] = sprintf(
			/* translators: 1: site name in the registry, 2: site address. */
			__( 'Sceneshifter has finished its scheduled scan of %1$s (%2$s).', 'sceneshifter' ),
			$siteLabel,
			$homeUrl
		);
		$lines[] = '';
		$lines[] = sprintf(
			/* translators: 1: number of items, 2: errors, 3: warnings. */
			__( 'In total, %1$d items link to other environments: %2$d errors and %3$d warnings.', 'sceneshifter' ),
			(int) $summary['objects'],
			(int) $summary['error'],
			(int) $summary['warning']
		);
		$lines[] = 0 === $newCount
			? __( 'Nothing new since the last scan.', 'sceneshifter' )
			/* translators: %d: number of new findings. */
			: sprintf( _n( '%d of these is new since the last scan:', '%d of these are new since the last scan:', $newCount, 'sceneshifter' ), $newCount );

		if ( array() !== $newFindings ) {
			$lines[] = '';
			foreach ( $newFindings as $row ) {
				$lines[] = sprintf(
					'- %1$s [%2$s, %3$s]',
					(string) $row['object_title'],
					Finding::ERROR === $row['severity'] ? __( 'error', 'sceneshifter' ) : __( 'warning', 'sceneshifter' ),
					$sisterLabel( (string) $row['sister_id'] )
				);
				$lines[] = '  ' . (string) $row['url'];
				$lines[] = '  ' . (string) $row['location'];
			}
			if ( $newCount > count( $newFindings ) ) {
				/* translators: %d: number of findings not listed. */
				$lines[] = sprintf( __( '…and %d more.', 'sceneshifter' ), $newCount - count( $newFindings ) );
			}
		}

		$lines[] = '';
		/* translators: %s: report page address. */
		$lines[] = sprintf( __( 'See the full report: %s', 'sceneshifter' ), $reportUrl );

		return implode( "\n", $lines ) . "\n";
	}
}
