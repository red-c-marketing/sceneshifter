<?php
/**
 * Pushed updates held for review (Settings → Push), on the site that holds
 * them: a notice on the published post's edit screen and on the waiting
 * copy's, in both editors, with Apply and Discard; a "Pushed update" label
 * in the post lists; and the admin-post handler behind the buttons.
 *
 * @package RedC\Sceneshifter
 */

declare(strict_types=1);

namespace RedC\Sceneshifter\Admin;

use RedC\Sceneshifter\Plugin;
use RedC\Sceneshifter\Transfer\HeldUpdates;
use RedC\Sceneshifter\Transfer\WpPostWriter;
use WP_Post;

final class HeldNotices {

	public const ACTION = 'sceneshifter_held';

	/** Query argument carrying the outcome back to the edit screen. */
	private const RESULT = 'sceneshifter_held';

	public function __construct( private readonly Plugin $plugin ) {}

	public function register(): void {
		add_action( 'admin_notices', array( $this, 'classicNotice' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'editorNotice' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
		add_filter( 'display_post_states', array( $this, 'postStates' ), 10, 2 );
	}

	public static function isHeldCopy( int $postId ): bool {
		return '' !== (string) get_post_meta( $postId, WpPostWriter::HELD_FOR, true );
	}

	/**
	 * A link that applies or discards a held update. Like WordPress's own
	 * Trash links, it is a GET protected by a nonce.
	 *
	 * @param string $op     apply or discard.
	 * @param string $return edit (the post's edit screen) or log.
	 */
	public static function actionUrl( string $op, int $heldId, string $return = 'edit' ): string {
		// Not wp_nonce_url(): it HTML-escapes the &s, which breaks the URL in the block editor's notices.
		return add_query_arg(
			array(
				'action'   => self::ACTION,
				'op'       => $op,
				'held'     => $heldId,
				'return'   => $return,
				'_wpnonce' => wp_create_nonce( self::ACTION . '_' . $op . '_' . $heldId ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * @param array<string, string> $states
	 * @return array<string, string>
	 */
	public function postStates( array $states, WP_Post $post ): array {
		if ( self::isHeldCopy( $post->ID ) ) {
			$states['sceneshifter_held'] = __( 'Pushed update, waiting for review', 'sceneshifter' );
		}
		return $states;
	}

	public function classicNotice(): void {
		$postId = $this->screenPost();
		if ( null === $postId || $this->isBlockEditor() ) {
			return;
		}
		foreach ( $this->notices( $postId ) as $notice ) {
			$links = array_map(
				static fn( array $a ): string => '<a href="' . esc_url( $a['url'] ) . '"' . ( $a['primary'] ? ' class="button button-primary"' : ' class="button"' ) . '>' . esc_html( $a['label'] ) . '</a>',
				$notice['actions']
			);
			printf(
				'<div class="notice notice-%1$s"><p>%2$s</p>%3$s</div>',
				esc_attr( $notice['status'] ),
				esc_html( $notice['text'] ),
				array() === $links ? '' : '<p>' . implode( ' ', $links ) . '</p>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
			);
		}
	}

	public function editorNotice(): void {
		$postId = $this->screenPost();
		if ( null === $postId ) {
			return;
		}
		$notices = $this->notices( $postId );
		if ( array() === $notices ) {
			return;
		}
		$data = array_map(
			static fn( array $n ): array => array(
				'status'  => $n['status'],
				'text'    => $n['text'],
				'actions' => array_map(
					static fn( array $a ): array => array(
						'label' => $a['label'],
						'url'   => $a['url'],
					),
					$n['actions']
				),
			),
			$notices
		);
		wp_add_inline_script(
			'wp-edit-post',
			'( function ( notices ) { wp.domReady( function () { notices.forEach( function ( n, i ) { wp.data.dispatch( "core/notices" ).createNotice( n.status, n.text, { id: "sceneshifter-held-" + i, isDismissible: "success" === n.status, actions: n.actions } ); } ); } ); }( ' . wp_json_encode( $data ) . ' ) );'
		);
	}

	public function handle(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- checked next.
		$op     = isset( $_GET['op'] ) ? sanitize_key( wp_unslash( $_GET['op'] ) ) : '';
		$heldId = isset( $_GET['held'] ) ? absint( $_GET['held'] ) : 0;
		$return = isset( $_GET['return'] ) && 'log' === sanitize_key( wp_unslash( $_GET['return'] ) ) ? 'log' : 'edit';
		// phpcs:enable
		check_admin_referer( self::ACTION . '_' . $op . '_' . $heldId );
		$held = $this->plugin->heldUpdates();
		if ( ! current_user_can( Plugin::CAP_TRANSFER ) || null === $held || ! in_array( $op, array( 'apply', 'discard' ), true ) ) {
			wp_die( esc_html__( 'You do not have permission to apply or discard pushed updates.', 'sceneshifter' ) );
		}
		$found  = $held->find( $heldId );
		$user   = wp_get_current_user()->display_name;
		$result = 'apply' === $op ? $held->apply( $heldId, $user ) : $held->discard( $heldId, $user );
		$code   = isset( $result['error'] ) ? 'held_' . $result['error'] : ( 'apply' === $op ? 'applied' : 'discarded' );
		$detail = isset( $result['message'] ) ? (string) $result['message'] : '';

		if ( 'log' === $return ) {
			wp_safe_redirect( LogPage::url( array_filter( array( 'undo' => $code, 'detail' => $detail ) ) ) );
			exit;
		}
		$postId = $result['post'] ?? $found['post'] ?? null;
		$edit   = null === $postId ? '' : (string) get_edit_post_link( $postId, 'raw' );
		wp_safe_redirect( add_query_arg( array_filter( array( self::RESULT => $code, 'detail' => $detail ) ), '' === $edit ? admin_url() : $edit ) );
		exit;
	}

	/**
	 * What to say on a post's edit screen.
	 *
	 * @return list<array{status: string, text: string, actions: list<array{label: string, url: string, primary: bool}>}>
	 */
	private function notices( int $postId ): array {
		$notices = array();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only.
		$outcome = isset( $_GET[ self::RESULT ] ) ? sanitize_key( wp_unslash( $_GET[ self::RESULT ] ) ) : '';
		$detail  = isset( $_GET['detail'] ) ? sanitize_text_field( wp_unslash( $_GET['detail'] ) ) : '';
		// phpcs:enable
		if ( '' !== $outcome ) {
			$notices[] = array(
				'status'  => in_array( $outcome, array( 'applied', 'discarded' ), true ) ? 'success' : 'error',
				'text'    => self::outcomeText( $outcome, $detail ),
				'actions' => array(),
			);
		}

		$held = $this->plugin->heldUpdates();
		if ( null === $held ) {
			return $notices;
		}
		$canAct = current_user_can( Plugin::CAP_TRANSFER );
		if ( self::isHeldCopy( $postId ) ) {
			$found = $held->find( $postId );
			if ( null === $found ) {
				return $notices;
			}
			$actions = array();
			if ( $canAct ) {
				$actions[] = array(
					'label'   => __( 'Apply it', 'sceneshifter' ),
					'url'     => self::actionUrl( 'apply', $postId ),
					'primary' => true,
				);
				$actions[] = array(
					'label'   => __( 'Discard it', 'sceneshifter' ),
					'url'     => self::actionUrl( 'discard', $postId ),
					'primary' => false,
				);
			}
			$actions[] = array(
				'label'   => __( 'Open the published post', 'sceneshifter' ),
				'url'     => (string) get_edit_post_link( $found['post'], 'raw' ),
				'primary' => false,
			);
			$notices[] = array(
				'status'  => 'warning',
				'text'    => sprintf(
					/* translators: 1: who pushed it, from where and when, 2: title of the published post. */
					__( 'This is an update %1$s, waiting for review before it replaces "%2$s". Applying it puts this version live; changes made to this copy aren\'t kept.', 'sceneshifter' ),
					$this->origin( $found['record'] ),
					get_the_title( $found['post'] )
				),
				'actions' => $actions,
			);
			return $notices;
		}

		$heldId = $held->heldFor( $postId );
		$found  = null === $heldId ? null : $held->find( $heldId );
		if ( null === $heldId || null === $found ) {
			return $notices;
		}
		$preview = get_preview_post_link( $heldId );
		$actions = array();
		if ( is_string( $preview ) && '' !== $preview ) {
			$actions[] = array(
				'label'   => __( 'Preview it', 'sceneshifter' ),
				'url'     => $preview,
				'primary' => false,
			);
		}
		if ( $canAct ) {
			$actions[] = array(
				'label'   => __( 'Apply it', 'sceneshifter' ),
				'url'     => self::actionUrl( 'apply', $heldId ),
				'primary' => true,
			);
			$actions[] = array(
				'label'   => __( 'Discard it', 'sceneshifter' ),
				'url'     => self::actionUrl( 'discard', $heldId ),
				'primary' => false,
			);
		}
		$notices[] = array(
			'status'  => 'warning',
			'text'    => sprintf(
				/* translators: %s: who pushed it, from where and when. */
				__( 'An update %s is waiting for review. This post won\'t change until someone applies it.', 'sceneshifter' ),
				$this->origin( $found['record'] )
			),
			'actions' => $actions,
		);
		return $notices;
	}

	/**
	 * "pushed from Dev by Ann on 3 May 2026 10:00".
	 *
	 * @param array<string, mixed> $record
	 */
	private function origin( array $record ): string {
		$sister = is_string( $record['sister'] ?? null ) ? $record['sister'] : '';
		$site   = $this->plugin->registry()->get( $sister );
		$from   = null === $site ? $sister : $site->label;
		$who    = is_string( $record['user'] ?? null ) ? $record['user'] : '';
		$when   = is_int( $record['at'] ?? null ) ? (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $record['at'] ) : '';
		if ( '' === $who ) {
			/* translators: 1: environment name, 2: date and time. */
			return sprintf( __( 'pushed from %1$s on %2$s', 'sceneshifter' ), $from, $when );
		}
		/* translators: 1: environment name, 2: person's name, 3: date and time. */
		return sprintf( __( 'pushed from %1$s by %2$s on %3$s', 'sceneshifter' ), $from, $who, $when );
	}

	public static function outcomeText( string $code, string $detail ): string {
		return match ( $code ) {
			'applied'                         => __( 'The pushed update was applied and is now live.', 'sceneshifter' ),
			'discarded'                       => __( 'The pushed update was discarded.', 'sceneshifter' ),
			'held_' . HeldUpdates::NOT_FOUND  => __( 'That pushed update is no longer waiting. It may have been applied, discarded or replaced by a later push.', 'sceneshifter' ),
			'held_' . HeldUpdates::GONE       => __( 'The post this update was for no longer exists.', 'sceneshifter' ),
			/* translators: %s: reason. */
			default                           => '' === $detail ? __( 'The pushed update couldn\'t be applied, so the post was left as it was.', 'sceneshifter' ) : sprintf( __( 'The pushed update couldn\'t be applied, so the post was left as it was: %s', 'sceneshifter' ), $detail ),
		};
	}

	private function screenPost(): ?int {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( null === $screen || 'post' !== $screen->base ) {
			return null;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$postId = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		return $postId > 0 ? $postId : null;
	}

	private function isBlockEditor(): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return null !== $screen && $screen->is_block_editor();
	}
}
