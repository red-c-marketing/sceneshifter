<?php
/**
 * When "Block publishing" is on, refuses to publish, schedule or update
 * content that links to another environment with error severity.
 *
 * The editors warn first (the block editor's pre-publish panel and the
 * Classic editor's Publish button); this is the server-side rule behind
 * them, so it also holds for anything that skips the editor's script.
 *
 * @package RedC\SisterSync
 */

declare(strict_types=1);

namespace RedC\SisterSync\Check;

use RedC\SisterSync\Plugin;
use RedC\SisterSync\Scanner\Finding;
use stdClass;
use WP_Error;
use WP_Post;
use WP_REST_Request;

final class PublishGuard {

	private const PUBLISHED  = array( 'publish', 'future' );
	private const NOTICE_KEY = 'sister_sync_publish_blocked_';

	public function __construct( private readonly Plugin $plugin ) {}

	public function register(): void {
		if ( ! $this->plugin->options()->checkSettings()->blockPublish ) {
			return;
		}
		foreach ( $this->plugin->options()->postTypes() as $postType ) {
			add_filter( "rest_pre_insert_{$postType}", array( $this, 'checkRest' ), 10, 2 );
		}
		add_filter( 'wp_insert_post_data', array( $this, 'checkClassic' ), 10, 2 );
		add_filter( 'redirect_post_location', array( $this, 'redirectMessage' ) );
		add_action( 'admin_notices', array( $this, 'notice' ) );
	}

	/**
	 * Block editor and other REST saves: refuse with an error the editor
	 * shows as a notice.
	 *
	 * @param stdClass|WP_Error $prepared The post about to be saved.
	 */
	public function checkRest( stdClass|WP_Error $prepared, WP_REST_Request $request ): stdClass|WP_Error {
		if ( is_wp_error( $prepared ) || empty( $prepared->ID ) ) {
			return $prepared;
		}
		$post = get_post( (int) $prepared->ID );
		if ( ! $post ) {
			return $prepared;
		}
		$status = isset( $prepared->post_status ) ? (string) $prepared->post_status : $post->post_status;
		if ( ! in_array( $status, self::PUBLISHED, true ) ) {
			return $prepared;
		}

		$errors = $this->errors(
			$post,
			isset( $prepared->post_content ) ? (string) $prepared->post_content : null,
			isset( $prepared->post_excerpt ) ? (string) $prepared->post_excerpt : null
		);
		if ( array() === $errors ) {
			return $prepared;
		}
		return new WP_Error( 'sister_sync_publish_blocked', self::message( $errors ), array( 'status' => 400 ) );
	}

	/**
	 * Classic editor and quick edit saves.
	 *
	 * A post that isn't published yet is saved as a draft instead, so no
	 * work is lost. A published post can't be quietly unpublished, so that
	 * save is stopped with an explanation; the Classic editor's script
	 * normally catches this before the form is sent.
	 *
	 * @param array<string, mixed> $data    Slashed post data about to be saved.
	 * @param array<string, mixed> $postarr Slashed submitted data.
	 * @return array<string, mixed>
	 */
	public function checkClassic( array $data, array $postarr ): array {
		if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ! is_admin() || empty( $postarr['ID'] ) ) {
			return $data;
		}
		if ( ! in_array( (string) ( $data['post_status'] ?? '' ), self::PUBLISHED, true ) || ! $this->plugin->options()->isEnabledPostType( (string) ( $data['post_type'] ?? '' ) ) ) {
			return $data;
		}
		$post = get_post( (int) $postarr['ID'] );
		if ( ! $post ) {
			return $data;
		}

		$errors = $this->errors(
			$post,
			(string) wp_unslash( (string) ( $data['post_content'] ?? '' ) ),
			(string) wp_unslash( (string) ( $data['post_excerpt'] ?? '' ) )
		);
		if ( array() === $errors ) {
			return $data;
		}

		if ( in_array( $post->post_status, self::PUBLISHED, true ) ) {
			wp_die(
				esc_html( self::message( $errors ) ),
				esc_html__( 'Not updated', 'sister-sync' ),
				array(
					'back_link' => true,
					'response'  => 400,
				)
			);
		}

		$data['post_status'] = 'draft';
		set_transient( self::NOTICE_KEY . get_current_user_id(), self::message( $errors ), 5 * MINUTE_IN_SECONDS );
		return $data;
	}

	/**
	 * Swaps "Post published." for "Post draft updated." after a blocked publish.
	 */
	public function redirectMessage( string $location ): string {
		if ( false === get_transient( self::NOTICE_KEY . get_current_user_id() ) ) {
			return $location;
		}
		return add_query_arg( 'message', 10, $location );
	}

	public function notice(): void {
		$key     = self::NOTICE_KEY . get_current_user_id();
		$message = get_transient( $key );
		if ( ! is_string( $message ) ) {
			return;
		}
		delete_transient( $key );
		printf( '<div class="notice notice-error"><p><strong>%1$s</strong> %2$s</p></div>', esc_html__( 'Not published.', 'sister-sync' ), esc_html( $message ) );
	}

	/**
	 * @return list<Finding>
	 */
	private function errors( WP_Post $post, ?string $content, ?string $excerpt ): array {
		return array_values(
			array_filter(
				$this->plugin->postScanner()->scan( $post, $content, $excerpt ),
				static fn( Finding $f ): bool => Finding::ERROR === $f->severity
			)
		);
	}

	/**
	 * @param list<Finding> $errors
	 */
	public static function message( array $errors ): string {
		$first = $errors[0];
		$total = array_sum( array_map( static fn( Finding $f ): int => $f->count, $errors ) );
		return sprintf(
			/* translators: 1: number of links, 2: environment name, 3: first URL. */
			_n(
				'This content links to another environment %1$d time (%2$s: %3$s), and publishing is blocked on this site until it is fixed. Use the Sister Sync Check to find it.',
				'This content links to other environments %1$d times (for example %2$s: %3$s), and publishing is blocked on this site until they are fixed. Use the Sister Sync Check to find them.',
				$total,
				'sister-sync'
			),
			$total,
			$first->sister->label,
			$first->url
		);
	}
}
