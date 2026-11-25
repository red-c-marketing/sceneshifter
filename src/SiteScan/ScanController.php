<?php
/**
 * REST endpoints the report page uses while a scan runs.
 *
 * GET  /sceneshifter/v1/scan       current status
 * POST /sceneshifter/v1/scan/tick  run one batch (keeps the scan moving while
 *                                 the page is open), then return the status
 *
 * @package RedC\Sceneshifter
 */

declare(strict_types=1);

namespace RedC\Sceneshifter\SiteScan;

use RedC\Sceneshifter\Check\RestController;
use RedC\Sceneshifter\Plugin;
use WP_REST_Response;
use WP_REST_Server;

final class ScanController {

	public function __construct(
		private readonly SiteScanner $scanner,
		private readonly ResultStore $store = new ResultStore()
	) {}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	public function routes(): void {
		$canView = static fn(): bool => current_user_can( Plugin::CAP_VIEW );

		register_rest_route(
			RestController::NAMESPACE,
			'/scan',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => fn() => new WP_REST_Response( self::status( ScanState::load(), $this->store ) ),
				'permission_callback' => $canView,
			)
		);
		register_rest_route(
			RestController::NAMESPACE,
			'/scan/tick',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => fn() => new WP_REST_Response( self::status( $this->scanner->advance(), $this->store ) ),
				'permission_callback' => $canView,
			)
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function status( ?ScanState $state, ResultStore $store ): array {
		if ( null === $state ) {
			return array( 'status' => 'none' );
		}
		$total = max( 1, $state->total );
		return array(
			'status'    => $state->status,
			'phase'     => $state->phase,
			'processed' => $state->processed,
			'total'     => $state->total,
			'percent'   => ScanState::PHASE_EXTRAS === $state->phase || ScanState::COMPLETE === $state->status
				? ( ScanState::COMPLETE === $state->status ? 100 : 99 )
				: (int) floor( 99 * min( $state->processed, $total ) / $total ),
			'error'     => $state->error,
			'summary'   => $state->isRunning() ? null : $store->summary( $store->reportScanId() ),
		);
	}
}
