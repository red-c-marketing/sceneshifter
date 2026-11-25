<?php
/**
 * REST endpoints that only paired sisters can call.
 *
 *   POST /wp-json/sceneshifter/v1/pair    Pairing confirmation (see Handshake).
 *   GET  /wp-json/sceneshifter/v1/status  Site ID, versions, and that the caller is paired.
 *   POST /wp-json/sceneshifter/v1/unpair  The caller asks this site to forget it.
 *
 * Every request is checked before anything else runs. Anything that fails,
 * including any request while this site is inactive or has no usable key,
 * gets the same plain 404 WordPress gives for a route that doesn't exist.
 * Successful answers are signed with this site's key.
 *
 * @package RedC\Sceneshifter
 */

declare(strict_types=1);

namespace RedC\Sceneshifter\Transport;

use RedC\Sceneshifter\Check\RestController;
use RedC\Sceneshifter\Crypto\KeyPair;
use RedC\Sceneshifter\Crypto\SignedRequest;
use RedC\Sceneshifter\Crypto\SignedResponse;
use RedC\Sceneshifter\Plugin;
use WP_Error;
use WP_HTTP_Response;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class SignedEndpoints {

	/**
	 * Exact bodies of signed answers, by response object, so what is sent
	 * is byte for byte what was signed.
	 *
	 * @var array<int, string>
	 */
	private array $bodies = array();

	/**
	 * Verified sender of each request, by request object.
	 *
	 * @var array<int, string>
	 */
	private array $senders = array();

	public function __construct( private readonly Plugin $plugin ) {}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		add_filter( 'rest_pre_serve_request', array( $this, 'serveSigned' ), 20, 2 );
	}

	public function routes(): void {
		register_rest_route(
			RestController::NAMESPACE,
			'/pair',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'pair' ),
				'permission_callback' => array( $this, 'gateOpen' ),
			)
		);
		register_rest_route(
			RestController::NAMESPACE,
			'/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'status' ),
				'permission_callback' => array( $this, 'verified' ),
			)
		);
		register_rest_route(
			RestController::NAMESPACE,
			'/unpair',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'unpair' ),
				'permission_callback' => array( $this, 'verified' ),
			)
		);
	}

	/**
	 * Permission check for /pair. The sender's key arrives inside the
	 * request, so the handshake does the full check in the callback.
	 */
	public function gateOpen( WP_REST_Request $request ): bool|WP_Error {
		return $this->plugin->sisters()->gate()->isOpen() ? true : self::notFound();
	}

	/**
	 * Permission check for every other signed endpoint.
	 */
	public function verified( WP_REST_Request $request ): bool|WP_Error {
		$sender = $this->plugin->sisters()->gate()->verify(
			$request->get_method(),
			$request->get_route(),
			self::headers( $request ),
			$request->get_body()
		);
		if ( null === $sender ) {
			return self::notFound();
		}
		$this->senders[ spl_object_id( $request ) ] = $sender;
		return true;
	}

	public function pair( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$handshake = $this->plugin->sisters()->handshake();
		if ( null === $handshake ) {
			return self::notFound();
		}
		$peer = $handshake->accept( $request->get_method(), $request->get_route(), self::headers( $request ), $request->get_body(), time() );
		if ( null === $peer ) {
			return self::notFound();
		}
		return $this->signed( $request, $peer->siteId, $handshake->responseData( $peer ) );
	}

	public function status( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$sender = $this->senders[ spl_object_id( $request ) ] ?? null;
		$self   = $this->plugin->sisters()->self();
		if ( null === $sender || null === $self ) {
			return self::notFound();
		}
		$this->plugin->sisters()->pairings()->recordContact( $sender );
		return $this->signed(
			$request,
			$sender,
			array(
				'site'             => $self->id,
				'label'            => $self->label,
				'plugin_version'   => SCENESHIFTER_VERSION,
				'format'           => Plugin::PACKAGE_FORMAT,
				'registry_version' => $this->plugin->registry()->version,
				'paired'           => true,
				'fingerprint'      => $this->plugin->sisters()->keys()?->fingerprint(),
				'time'             => time(),
			)
		);
	}

	public function unpair( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$sender = $this->senders[ spl_object_id( $request ) ] ?? null;
		if ( null === $sender ) {
			return self::notFound();
		}
		$this->plugin->sisters()->pairings()->remove( $sender );
		return $this->signed( $request, $sender, array( 'unpaired' => true ) );
	}

	/**
	 * Sends the exact signed body instead of letting the REST server encode
	 * the data again.
	 *
	 * @param bool  $served Whether the request has already been served.
	 * @param mixed $result The response.
	 */
	public function serveSigned( $served, $result ): bool {
		if ( $served || ! $result instanceof WP_HTTP_Response ) {
			return (bool) $served;
		}
		$id = spl_object_id( $result );
		if ( ! isset( $this->bodies[ $id ] ) ) {
			return false;
		}
		echo $this->bodies[ $id ]; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON body.
		unset( $this->bodies[ $id ] );
		return true;
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private function signed( WP_REST_Request $request, string $sender, array $data ): WP_REST_Response|WP_Error {
		$keys = $this->plugin->sisters()->keys()?->keyPair();
		$self = $this->plugin->sisters()->self();
		if ( ! $keys instanceof KeyPair || null === $self ) {
			return self::notFound();
		}
		$body     = (string) wp_json_encode( $data );
		$response = new WP_REST_Response( $data, 200 );
		$response->header( 'Content-Type', 'application/json; charset=' . get_option( 'blog_charset' ) );
		$response->header( 'Cache-Control', 'no-store, private' );
		$response->header(
			SignedResponse::HEADER_SIGNATURE,
			SignedResponse::sign( $keys, $sender, $self->id, (string) $request->get_header( SignedRequest::HEADER_NONCE ), 200, $body )
		);
		$this->bodies[ spl_object_id( $response ) ] = $body;
		return $response;
	}

	/**
	 * @return array<string, string>
	 */
	private static function headers( WP_REST_Request $request ): array {
		$headers = array();
		foreach ( array( SignedRequest::HEADER_VERSION, SignedRequest::HEADER_SENDER, SignedRequest::HEADER_TARGET, SignedRequest::HEADER_TIMESTAMP, SignedRequest::HEADER_NONCE, SignedRequest::HEADER_SIGNATURE ) as $name ) {
			$headers[ $name ] = (string) $request->get_header( $name );
		}
		return $headers;
	}

	/**
	 * The same answer WordPress gives for a route that doesn't exist.
	 */
	public static function notFound(): WP_Error {
		return new WP_Error( 'rest_no_route', __( 'No route was found matching the URL and request method.' ), array( 'status' => 404 ) ); // phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- WordPress's own message, so it reads identically.
	}
}
