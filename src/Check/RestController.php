<?php
/**
 * POST /wp-json/sceneshifter/v1/check
 *
 * Scans a post for sister references. Accepts unsaved content and excerpt so
 * the editor can check before saving. Uses normal logged-in REST auth.
 *
 * @package RedC\Sceneshifter
 */

declare(strict_types=1);

namespace RedC\Sceneshifter\Check;

use RedC\Sceneshifter\Plugin;
use RedC\Sceneshifter\Scanner\Finding;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class RestController {

	public const NAMESPACE = 'sceneshifter/v1';

	public function __construct( private readonly Plugin $plugin ) {}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	public function routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/check',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'check' ),
				'permission_callback' => static fn( WP_REST_Request $r ): bool => current_user_can( 'edit_post', (int) $r->get_param( 'post_id' ) ),
				'args'                => array(
					'post_id' => array(
						'type'     => 'integer',
						'required' => true,
						'minimum'  => 1,
					),
					'content' => array(
						'type' => 'string',
					),
					'excerpt' => array(
						'type' => 'string',
					),
				),
			)
		);
	}

	public function check( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post = get_post( (int) $request->get_param( 'post_id' ) );
		if ( ! $post ) {
			return new WP_Error( 'sceneshifter_no_post', __( 'Post not found.', 'sceneshifter' ), array( 'status' => 404 ) );
		}

		$content = $request->get_param( 'content' );
		$excerpt = $request->get_param( 'excerpt' );

		$findings = $this->plugin->postScanner()->scan(
			$post,
			is_string( $content ) ? $content : null,
			is_string( $excerpt ) ? $excerpt : null
		);

		$site = $this->plugin->identity()->site;

		return new WP_REST_Response(
			array(
				'site'     => null === $site ? null : array(
					'id'    => $site->id,
					'label' => $site->label,
					'role'  => $site->role,
				),
				'counts'   => self::counts( $findings ),
				'findings' => array_map( static fn( Finding $f ) => $f->toArray(), $findings ),
			)
		);
	}

	/**
	 * @param list<Finding> $findings
	 * @return array{error: int, warning: int}
	 */
	public static function counts( array $findings ): array {
		$counts = array(
			Finding::ERROR   => 0,
			Finding::WARNING => 0,
		);
		foreach ( $findings as $finding ) {
			$counts[ $finding->severity ] += $finding->count;
		}
		return $counts;
	}
}
