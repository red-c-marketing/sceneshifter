<?php
/**
 * Keeps the transfer log and snapshots from growing for ever: once a day,
 * removes log entries older than the set number of months, and snapshots
 * older than the set number of days except each post's newest few
 * (Settings → Push). A push whose snapshot is gone can no longer be undone.
 *
 * @package RedC\Sceneshifter
 */

declare(strict_types=1);

namespace RedC\Sceneshifter\Log;

use RedC\Sceneshifter\Plugin;
use RedC\Sceneshifter\Store\PushSettings;
use RedC\Sceneshifter\Transfer\DbSnapshotStore;
use RedC\Sceneshifter\Transfer\SnapshotStore;

final class Retention {

	public const HOOK = 'sceneshifter_prune';

	private const DAY   = 86400;
	private const MONTH = 2629746;

	public function __construct( private readonly Plugin $plugin ) {}

	public function register(): void {
		add_action( self::HOOK, array( $this, 'run' ) );
		add_action( 'admin_init', array( $this, 'ensureScheduled' ) );
	}

	public function ensureScheduled(): void {
		if ( false === wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	public function run(): void {
		$self = $this->plugin->sisters()->self();
		$log  = $this->plugin->log();
		if ( null === $self || null === $log ) {
			return;
		}
		self::prune( $log, DbSnapshotStore::forSite( $self->id ), $this->plugin->options()->pushSettings(), time() );
	}

	/**
	 * @return array{log: int, snapshots: int} How many of each were removed.
	 */
	public static function prune( LogStore $log, SnapshotStore $snapshots, PushSettings $settings, int $now ): array {
		return array(
			'log'       => $settings->logMonths > 0 ? $log->prune( $now - $settings->logMonths * self::MONTH ) : 0,
			'snapshots' => $snapshots->prune( $now - $settings->snapshotDays * self::DAY, $settings->snapshotsPerPost ),
		);
	}
}
