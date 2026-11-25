<?php
/**
 * Settings → Pairing: pair this site with its sisters, compare key
 * fingerprints, test the connection and unpair.
 *
 * The site that pastes a code contacts the site that made it, so the code
 * is always made on the site the other one can reach.
 *
 * @package RedC\Sceneshifter
 */

declare(strict_types=1);

namespace RedC\Sceneshifter\Admin;

use RedC\Sceneshifter\Crypto\KeyPair;
use RedC\Sceneshifter\Pairing\Pairing;
use RedC\Sceneshifter\Pairing\PairingException;
use RedC\Sceneshifter\Pairing\Sisters;
use RedC\Sceneshifter\Pairing\SiteKeys;
use RedC\Sceneshifter\Plugin;
use RedC\Sceneshifter\Registry\Site;
use RedC\Sceneshifter\Transport\TransportResult;

final class PairingTab {

	public const ACTION_CODE    = 'sceneshifter_pair_code';
	public const ACTION_CHECK   = 'sceneshifter_pair_check';
	public const ACTION_CONFIRM = 'sceneshifter_pair_confirm';
	public const ACTION_TEST    = 'sceneshifter_pair_test';
	public const ACTION_UNPAIR  = 'sceneshifter_unpair';
	public const ACTION_NEW_KEY = 'sceneshifter_new_key';
	public const ACTION_DEV     = 'sceneshifter_pair_dev';
	public const AJAX_STATUS    = 'sceneshifter_pairing_status';
	public const AJAX_ROWS      = 'sceneshifter_pairing_rows';

	public function __construct( private readonly Plugin $plugin ) {}

	public function register(): void {
		foreach ( array( self::ACTION_CODE, self::ACTION_CHECK, self::ACTION_CONFIRM, self::ACTION_TEST, self::ACTION_UNPAIR, self::ACTION_NEW_KEY, self::ACTION_DEV ) as $action ) {
			add_action( 'admin_post_' . $action, array( $this, 'handle' ) );
		}
		add_action( 'wp_ajax_' . self::AJAX_STATUS, array( $this, 'ajaxStatus' ) );
		add_action( 'wp_ajax_' . self::AJAX_ROWS, array( $this, 'ajaxRows' ) );
	}

	/**
	 * @param array<string, string|int> $args Extra query arguments.
	 */
	public static function url( array $args = array() ): string {
		return SettingsPage::url( array_merge( array( 'tab' => 'pairing' ), $args ) );
	}

	public static function canManage(): bool {
		return current_user_can( 'manage_options' ) && current_user_can( Plugin::CAP_TRANSFER );
	}

	public function render(): void {
		$this->renderFlash();

		if ( ! self::canManage() ) {
			echo '<p>' . esc_html__( 'Only administrators with the Sceneshifter transfer permission can pair sites.', 'sceneshifter' ) . '</p>';
			return;
		}

		$sisters = $this->plugin->sisters();
		$self    = $sisters->self();
		$keys    = $sisters->keys();
		if ( null === $self || null === $keys ) {
			echo '<p>' . esc_html__( 'Sceneshifter is inactive on this site, so it can\'t pair. Fix the list on the Sites tab first.', 'sceneshifter' ) . '</p>';
			return;
		}

		$status = $keys->status();
		if ( SiteKeys::NO_SALTS === $status ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( Sisters::keyProblem( $status ) ) . '</p></div>';
			return;
		}
		if ( $keys->needsNewKey() ) {
			$this->renderNewKey( $status );
			return;
		}

		// First need: make this site's key if it has none yet.
		$pair = $keys->keyPair( true );
		if ( null === $pair ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( Sisters::keyProblem( $keys->status() ) ) . '</p></div>';
			return;
		}
		$fingerprint = KeyPair::fingerprint( $pair->publicKey );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- choosing a view only.
		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : '';
		if ( 'code' === $view && $this->renderCode( $fingerprint ) ) {
			return;
		}
		if ( 'confirm' === $view && $this->renderConfirm( $fingerprint ) ) {
			return;
		}

		$others = array_values( array_filter( $this->plugin->registry()->sites(), static fn( Site $s ): bool => $s->id !== $self->id ) );
		?>
		<div class="sceneshifter-identity is-active">
			<h2><?php esc_html_e( 'This site', 'sceneshifter' ); ?></h2>
			<p>
				<?php
				printf(
					/* translators: %s: site name. */
					esc_html__( 'This is %s.', 'sceneshifter' ),
					esc_html( $self->label )
				);
				echo ' ';
				echo esc_html( $self->isPublic ? __( 'Other sites can reach it.', 'sceneshifter' ) : __( 'It isn\'t public, so other sites can\'t reach it.', 'sceneshifter' ) );
				?>
			</p>
			<p><?php esc_html_e( 'Key fingerprint:', 'sceneshifter' ); ?> <code class="sceneshifter-fingerprint"><?php echo esc_html( $fingerprint ); ?></code></p>
		</div>

		<p class="description">
			<?php esc_html_e( 'Pairing lets two sites trust each other, so content can later move between them. Make a code on one site and paste it on the other. The site you paste the code into contacts the site that made it, so make the code on a site the other one can reach. Both sites show key fingerprints so you can check they match.', 'sceneshifter' ); ?>
		</p>

		<?php if ( array() === $others ) : ?>
			<p><?php esc_html_e( 'There are no other sites in the list yet. Add them on the Sites tab.', 'sceneshifter' ); ?></p>
		<?php else : ?>
			<table class="widefat sceneshifter-pairings">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Site', 'sceneshifter' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Status', 'sceneshifter' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Key fingerprint', 'sceneshifter' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Last contact', 'sceneshifter' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Last error', 'sceneshifter' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Actions', 'sceneshifter' ); ?></th>
					</tr>
				</thead>
				<?php $rows = $this->rows( $self, $others ); ?>
				<tbody
					data-sceneshifter-pairing-rows
					data-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
					data-action="<?php echo esc_attr( self::AJAX_ROWS ); ?>"
					data-nonce="<?php echo esc_attr( wp_create_nonce( self::AJAX_ROWS ) ); ?>"
					data-hash="<?php echo esc_attr( $rows['hash'] ); ?>">
					<?php echo $rows['html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in renderRow(). ?>
				</tbody>
			</table>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sceneshifter-paste" id="sceneshifter-paste">
			<h2><?php esc_html_e( 'Paste a pairing code', 'sceneshifter' ); ?></h2>
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_CHECK ); ?>">
			<?php wp_nonce_field( self::ACTION_CHECK ); ?>
			<p class="description"><?php esc_html_e( 'Paste a code made on another site. You\'ll see both key fingerprints before anything is sent.', 'sceneshifter' ); ?></p>
			<p>
				<label for="sceneshifter-code" class="screen-reader-text"><?php esc_html_e( 'Pairing code', 'sceneshifter' ); ?></label>
				<textarea id="sceneshifter-code" name="code" rows="3" class="large-text code" spellcheck="false" autocomplete="off" required placeholder="SSPAIR1-…"></textarea>
			</p>
			<?php submit_button( __( 'Check code', 'sceneshifter' ), 'secondary', 'submit', false ); ?>
		</form>

		<details class="sceneshifter-help sceneshifter-dev"<?php echo $sisters->allowsDevHttp() ? ' open' : ''; ?>>
			<summary><?php esc_html_e( 'Local development', 'sceneshifter' ); ?></summary>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_DEV ); ?>">
				<?php wp_nonce_field( self::ACTION_DEV ); ?>
				<p>
					<label>
						<input type="checkbox" name="allow_dev_http" value="1" <?php checked( $sisters->allowsDevHttp() ); ?>>
						<?php esc_html_e( 'Allow plain HTTP to sites ending in .test or .localhost', 'sceneshifter' ); ?>
					</label>
				</p>
				<p class="description"><?php esc_html_e( 'Only for local development sites without HTTPS. Every other address must use HTTPS. This setting applies to this site only.', 'sceneshifter' ); ?></p>
				<?php submit_button( __( 'Save', 'sceneshifter' ), 'secondary', 'submit', false ); ?>
			</form>
		</details>
		<?php
	}

	/**
	 * The table rows, and a hash of what they show, so the open page can
	 * tell when the other site has paired or unpaired.
	 *
	 * @param list<Site> $others
	 * @return array{hash: string, html: string}
	 */
	private function rows( Site $self, array $others ): array {
		$pairings = $this->plugin->sisters()->pairings();
		$state    = array( $self->id, $this->plugin->registry()->version );
		ob_start();
		foreach ( $others as $site ) {
			$pairing = $pairings->get( $site->id );
			$state[] = array( $site->id, $site->isPublic, $pairing?->toArray() );
			$this->renderRow( $self, $site, $pairing );
		}
		return array(
			'hash' => md5( (string) wp_json_encode( $state ) ),
			'html' => (string) ob_get_clean(),
		);
	}

	/**
	 * Polled by the open Pairing tab. Sends fresh rows only when they changed.
	 */
	public function ajaxRows(): void {
		check_ajax_referer( self::AJAX_ROWS );
		if ( ! self::canManage() ) {
			wp_send_json_error( null, 403 );
		}
		$self = $this->plugin->sisters()->self();
		if ( null === $self ) {
			wp_send_json_error( null, 409 );
		}
		$others = array_values( array_filter( $this->plugin->registry()->sites(), static fn( Site $s ): bool => $s->id !== $self->id ) );
		$rows   = $this->rows( $self, $others );
		$known  = isset( $_GET['hash'] ) ? sanitize_key( wp_unslash( $_GET['hash'] ) ) : '';
		wp_send_json_success( $known === $rows['hash'] ? array( 'hash' => $rows['hash'] ) : $rows );
	}

	private function renderRow( Site $self, Site $site, ?Pairing $pairing ): void {
		$side = Sisters::codeSide( $self, $site );
		?>
		<tr>
			<td data-label="<?php esc_attr_e( 'Site', 'sceneshifter' ); ?>">
				<span class="sceneshifter-role-dot" style="background:<?php echo esc_attr( $site->colour() ); ?>"></span>
				<strong><?php echo esc_html( $site->label ); ?></strong>
				<?php if ( ! $site->isPublic ) : ?>
					<span class="sceneshifter-tag"><?php esc_html_e( 'Not public', 'sceneshifter' ); ?></span>
				<?php endif; ?>
				<br><code><?php echo esc_html( $site->url ); ?></code>
			</td>
			<td data-label="<?php esc_attr_e( 'Status', 'sceneshifter' ); ?>">
				<?php if ( null !== $pairing ) : ?>
					<span class="sceneshifter-paired"><?php esc_html_e( 'Paired', 'sceneshifter' ); ?></span><br>
					<span class="description">
						<?php
						/* translators: %s: date. */
						echo esc_html( sprintf( __( 'since %s', 'sceneshifter' ), self::date( $pairing->pairedAt ) ) );
						?>
					</span>
				<?php else : ?>
					<?php esc_html_e( 'Not paired', 'sceneshifter' ); ?>
				<?php endif; ?>
			</td>
			<td data-label="<?php esc_attr_e( 'Key fingerprint', 'sceneshifter' ); ?>">
				<?php echo null !== $pairing ? '<code class="sceneshifter-fingerprint">' . esc_html( $pairing->fingerprint() ) . '</code>' : '&mdash;'; ?>
			</td>
			<td data-label="<?php esc_attr_e( 'Last contact', 'sceneshifter' ); ?>">
				<?php echo esc_html( null !== $pairing && null !== $pairing->lastContact ? self::ago( $pairing->lastContact ) : ( null !== $pairing ? __( 'Never', 'sceneshifter' ) : '—' ) ); ?>
			</td>
			<td data-label="<?php esc_attr_e( 'Last error', 'sceneshifter' ); ?>">
				<?php if ( null !== $pairing && null !== $pairing->lastError ) : ?>
					<span class="sceneshifter-error-text"><?php echo esc_html( $pairing->lastError ); ?></span>
					<?php if ( null !== $pairing->lastErrorAt ) : ?>
						<br><span class="description"><?php echo esc_html( self::ago( $pairing->lastErrorAt ) ); ?></span>
					<?php endif; ?>
				<?php else : ?>
					&mdash;
				<?php endif; ?>
			</td>
			<td data-label="<?php esc_attr_e( 'Actions', 'sceneshifter' ); ?>" class="sceneshifter-pair-actions">
				<?php if ( null !== $pairing ) : ?>
					<?php
					if ( $site->isPublic ) {
						self::actionButton( self::ACTION_TEST, $site->id, __( 'Test connection', 'sceneshifter' ) );
						echo '<a class="button" href="' . esc_url( LinkTab::url( array( 'sister' => $site->id ) ) ) . '">' . esc_html__( 'Link existing content', 'sceneshifter' ) . '</a>';
					}
					?>
					<?php if ( ! $site->isPublic ) : ?>
						<p class="description">
							<?php
							/* translators: %s: site name. */
							echo esc_html( sprintf( __( 'To test the connection, use Test connection on %s. This site can\'t contact it.', 'sceneshifter' ), $site->label ) );
							?>
						</p>
					<?php endif; ?>
					<?php
					self::actionButton(
						self::ACTION_UNPAIR,
						$site->id,
						__( 'Unpair', 'sceneshifter' ),
						/* translators: %s: site name. */
						sprintf( __( 'Unpair from %s? Content can\'t move between the two sites until you pair them again.', 'sceneshifter' ), $site->label )
					);
					?>
				<?php else : ?>
					<?php if ( 'either' === $side || 'here' === $side ) : ?>
						<?php self::actionButton( self::ACTION_CODE, $site->id, __( 'Show a pairing code', 'sceneshifter' ), '', 'primary' ); ?>
					<?php endif; ?>
					<?php if ( 'either' === $side || 'there' === $side ) : ?>
						<a class="button" href="#sceneshifter-paste" data-sceneshifter-paste>
							<?php
							/* translators: %s: site name. */
							echo esc_html( sprintf( __( 'Paste a code from %s', 'sceneshifter' ), $site->label ) );
							?>
						</a>
					<?php endif; ?>
					<p class="description"><?php echo esc_html( self::sideHint( $side, $site ) ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	private static function sideHint( string $side, Site $site ): string {
		return match ( $side ) {
			'either' => __( 'Both sites are public: make the code on either one and paste it on the other.', 'sceneshifter' ),
			/* translators: %s: site name. */
			'here'   => sprintf( __( '%1$s isn\'t public, so it can\'t be contacted. Show a code here and paste it on %1$s.', 'sceneshifter' ), $site->label ),
			/* translators: %s: site name. */
			'there'  => sprintf( __( 'This site isn\'t public, so %1$s can\'t contact it. Make a code on %1$s and paste it here.', 'sceneshifter' ), $site->label ),
			default  => __( 'Neither site is public, so they can\'t contact each other. If one of them can be reached, mark it as public on the Sites tab.', 'sceneshifter' ),
		};
	}

	private static function actionButton( string $action, string $siteId, string $label, string $confirm = '', string $style = 'secondary' ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"<?php echo '' !== $confirm ? ' data-sceneshifter-confirm="' . esc_attr( $confirm ) . '"' : ''; ?>>
			<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>">
			<input type="hidden" name="site" value="<?php echo esc_attr( $siteId ); ?>">
			<?php wp_nonce_field( $action . '_' . $siteId ); ?>
			<button type="submit" class="button<?php echo 'primary' === $style ? ' button-primary' : ''; ?>"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	/**
	 * The code this admin just made, while it is still valid.
	 */
	private function renderCode( string $fingerprint ): bool {
		$shown = get_transient( self::userKey( 'code' ) );
		if ( ! is_array( $shown ) || (int) ( $shown['expires'] ?? 0 ) < time() ) {
			return false;
		}
		$site  = $this->plugin->registry()->get( (string) $shown['for'] );
		$label = null === $site ? (string) $shown['for'] : $site->label;
		?>
		<div class="sceneshifter-code-box">
			<h2>
				<?php
				/* translators: %s: site name. */
				echo esc_html( sprintf( __( 'Pairing code for %s', 'sceneshifter' ), $label ) );
				?>
			</h2>
			<p>
				<?php
				printf(
					/* translators: 1: site name, 2: time. */
					esc_html__( 'On %1$s, open Sceneshifter → Settings → Pairing and paste this code under "Paste a pairing code". It works once and expires at %2$s.', 'sceneshifter' ),
					esc_html( $label ),
					esc_html( wp_date( (string) get_option( 'time_format' ), (int) $shown['expires'] ) )
				);
				?>
			</p>
			<p>
				<label for="sceneshifter-pairing-code" class="screen-reader-text"><?php esc_html_e( 'Pairing code', 'sceneshifter' ); ?></label>
				<textarea id="sceneshifter-pairing-code" class="large-text code" rows="4" readonly><?php echo esc_textarea( (string) $shown['code'] ); ?></textarea>
			</p>
			<p>
				<button type="button" class="button button-primary" data-sceneshifter-copy="sceneshifter-pairing-code" data-copied="<?php esc_attr_e( 'Copied', 'sceneshifter' ); ?>"><?php esc_html_e( 'Copy code', 'sceneshifter' ); ?></button>
			</p>
			<p>
				<?php
				printf(
					/* translators: 1: fingerprint, 2: site name. */
					esc_html__( 'This site\'s key fingerprint is %1$s. %2$s shows it when the code is pasted. Check that the two match.', 'sceneshifter' ),
					'<code class="sceneshifter-fingerprint">' . esc_html( $fingerprint ) . '</code>',
					esc_html( $label )
				);
				?>
			</p>
			<p class="sceneshifter-pairing-wait" aria-live="polite"
				data-sceneshifter-pairing-wait
				data-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
				data-action="<?php echo esc_attr( self::AJAX_STATUS ); ?>"
				data-nonce="<?php echo esc_attr( wp_create_nonce( self::AJAX_STATUS ) ); ?>"
				data-site="<?php echo esc_attr( (string) $shown['for'] ); ?>"
				data-since="<?php echo esc_attr( (string) (int) $shown['created'] ); ?>"
				data-until="<?php echo esc_attr( (string) (int) $shown['expires'] ); ?>"
				data-expired="<?php esc_attr_e( 'The code has expired. Go back and make a new one.', 'sceneshifter' ); ?>">
				<?php
				/* translators: %s: site name. */
				echo esc_html( sprintf( __( 'Waiting for %s to use the code…', 'sceneshifter' ), $label ) );
				?>
			</p>
			<p><a href="<?php echo esc_url( self::url() ); ?>"><?php esc_html_e( 'Back to pairing', 'sceneshifter' ); ?></a></p>
		</div>
		<?php
		return true;
	}

	/**
	 * The step between pasting a code and contacting the other site.
	 */
	private function renderConfirm( string $fingerprint ): bool {
		$input = get_transient( self::userKey( 'paste' ) );
		if ( ! is_string( $input ) ) {
			return false;
		}
		try {
			$code = $this->plugin->sisters()->checkCode( $input );
		} catch ( PairingException $e ) {
			delete_transient( self::userKey( 'paste' ) );
			echo '<div class="notice notice-error inline"><p>' . esc_html( $e->getMessage() ) . '</p></div>';
			echo '<p><a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Back to pairing', 'sceneshifter' ) . '</a></p>';
			return true;
		}
		$site = $this->plugin->registry()->get( $code->siteId );
		if ( null === $site ) {
			return false;
		}
		$theirs = KeyPair::fingerprint( $code->publicKey );
		?>
		<div class="sceneshifter-code-box">
			<h2>
				<?php
				/* translators: %s: site name. */
				echo esc_html( sprintf( __( 'Pair with %s?', 'sceneshifter' ), $site->label ) );
				?>
			</h2>
			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row">
							<?php
							/* translators: %s: site name. */
							echo esc_html( sprintf( __( '%s\'s key fingerprint', 'sceneshifter' ), $site->label ) );
							?>
						</th>
						<td><code class="sceneshifter-fingerprint"><?php echo esc_html( $theirs ); ?></code></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'This site\'s key fingerprint', 'sceneshifter' ); ?></th>
						<td><code class="sceneshifter-fingerprint"><?php echo esc_html( $fingerprint ); ?></code></td>
					</tr>
				</tbody>
			</table>
			<p>
				<?php
				printf(
					/* translators: 1: site name, 2: fingerprint. */
					esc_html__( 'Check that %1$s shows %2$s next to the code. If it doesn\'t, cancel: the code may have been changed on the way.', 'sceneshifter' ),
					esc_html( $site->label ),
					'<code>' . esc_html( $theirs ) . '</code>'
				);
				?>
			</p>
			<p>
				<?php
				printf(
					/* translators: 1: site name, 2: address. */
					esc_html__( 'When you pair, this site contacts %1$s at %2$s.', 'sceneshifter' ),
					esc_html( $site->label ),
					'<code>' . esc_html( $site->url ) . '</code>'
				);
				?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sceneshifter-confirm-actions">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_CONFIRM ); ?>">
				<?php wp_nonce_field( self::ACTION_CONFIRM ); ?>
				<button type="submit" class="button button-primary">
					<?php
					/* translators: %s: site name. */
					echo esc_html( sprintf( __( 'Pair with %s', 'sceneshifter' ), $site->label ) );
					?>
				</button>
				<button type="submit" name="cancel" value="1" class="button"><?php esc_html_e( 'Cancel', 'sceneshifter' ); ?></button>
			</form>
		</div>
		<?php
		return true;
	}

	private function renderNewKey( string $status ): void {
		?>
		<div class="notice notice-error inline">
			<p><?php echo esc_html( Sisters::keyProblem( $status ) ); ?></p>
		</div>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-sceneshifter-confirm="<?php esc_attr_e( 'Make a new key? Every pairing on this site is removed, and you\'ll need to pair it again with each site.', 'sceneshifter' ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_NEW_KEY ); ?>">
			<?php wp_nonce_field( self::ACTION_NEW_KEY ); ?>
			<p class="description"><?php esc_html_e( 'Making a new key removes every pairing on this site. The other environments still list this site as paired until you unpair it there or pair again.', 'sceneshifter' ); ?></p>
			<?php submit_button( __( 'Make a new key', 'sceneshifter' ), 'primary', 'submit', false ); ?>
		</form>
		<?php
	}

	/**
	 * Handles every form on the tab.
	 */
	public function handle(): void {
		if ( ! self::canManage() ) {
			wp_die( esc_html__( 'You do not have permission to pair sites.', 'sceneshifter' ) );
		}
		$action = current_action();
		$action = str_starts_with( $action, 'admin_post_' ) ? substr( $action, strlen( 'admin_post_' ) ) : $action;
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- each branch checks its nonce first.
		$siteId = isset( $_POST['site'] ) ? sanitize_key( wp_unslash( $_POST['site'] ) ) : '';
		$args   = array();

		switch ( $action ) {
			case self::ACTION_CODE:
				check_admin_referer( $action . '_' . $siteId );
				try {
					$code = $this->plugin->sisters()->createCode( $siteId );
					set_transient(
						self::userKey( 'code' ),
						array(
							'code'    => $code->encode(),
							'for'     => $siteId,
							'created' => time(),
							'expires' => $code->expiresAt,
						),
						$code->expiresAt - time()
					);
					$args['view'] = 'code';
				} catch ( PairingException $e ) {
					self::flash( 'error', array( $e->getMessage() ) );
				}
				break;

			case self::ACTION_CHECK:
				check_admin_referer( $action );
				$input = isset( $_POST['code'] ) ? sanitize_textarea_field( wp_unslash( $_POST['code'] ) ) : '';
				try {
					$this->plugin->sisters()->checkCode( $input );
					set_transient( self::userKey( 'paste' ), $input, 15 * MINUTE_IN_SECONDS );
					$args['view'] = 'confirm';
				} catch ( PairingException $e ) {
					self::flash( 'error', array( $e->getMessage() ) );
				}
				break;

			case self::ACTION_CONFIRM:
				check_admin_referer( $action );
				$input = get_transient( self::userKey( 'paste' ) );
				delete_transient( self::userKey( 'paste' ) );
				if ( ! empty( $_POST['cancel'] ) ) {
					break;
				}
				if ( ! is_string( $input ) ) {
					self::flash( 'error', array( __( 'The pasted code has gone. Paste it again.', 'sceneshifter' ) ) );
					break;
				}
				try {
					$pairing = $this->plugin->sisters()->pair( $input );
					$site    = $this->plugin->registry()->get( $pairing->siteId );
					$label   = null === $site ? $pairing->siteId : $site->label;
					self::flash(
						'success',
						array(
							/* translators: %s: site name. */
							sprintf( __( 'Paired with %s.', 'sceneshifter' ), $label ),
							sprintf(
								/* translators: 1: site name, 2: fingerprint. */
								__( '%1$s now lists this site with key fingerprint %2$s. Check that it matches the one shown here under This site.', 'sceneshifter' ),
								$label,
								(string) $this->plugin->sisters()->keys()?->fingerprint()
							),
						)
					);
				} catch ( PairingException $e ) {
					self::flash( 'error', array( $e->getMessage() ) );
				}
				break;

			case self::ACTION_TEST:
				check_admin_referer( $action . '_' . $siteId );
				$this->flashTest( $siteId, $this->plugin->sisters()->status( $siteId ) );
				break;

			case self::ACTION_UNPAIR:
				check_admin_referer( $action . '_' . $siteId );
				$this->flashUnpair( $siteId, $this->plugin->sisters()->unpair( $siteId ) );
				break;

			case self::ACTION_NEW_KEY:
				check_admin_referer( $action );
				if ( $this->plugin->sisters()->replaceKeys() ) {
					self::flash( 'success', array( __( 'This site has a new key. Pair it again with each site you need.', 'sceneshifter' ) ) );
				} else {
					self::flash( 'error', array( __( 'No new key could be made.', 'sceneshifter' ) ) );
				}
				break;

			case self::ACTION_DEV:
				check_admin_referer( $action );
				$this->plugin->options()->saveAllowDevHttp( ! empty( $_POST['allow_dev_http'] ) );
				self::flash( 'success', array( __( 'Settings saved.', 'sceneshifter' ) ) );
				break;
		}
		// phpcs:enable

		wp_safe_redirect( self::url( $args ) );
		exit;
	}

	/**
	 * Polled by the code screen to say when the other site has used the code.
	 */
	public function ajaxStatus(): void {
		check_ajax_referer( self::AJAX_STATUS );
		if ( ! self::canManage() ) {
			wp_send_json_error( null, 403 );
		}
		$siteId  = isset( $_GET['site'] ) ? sanitize_key( wp_unslash( $_GET['site'] ) ) : '';
		$since   = isset( $_GET['since'] ) ? (int) $_GET['since'] : 0;
		$pairing = $this->plugin->sisters()->pairings()->get( $siteId );
		if ( null === $pairing || $pairing->pairedAt < $since ) {
			wp_send_json_success( array( 'paired' => false ) );
		}
		$site  = $this->plugin->registry()->get( $siteId );
		$label = null === $site ? $siteId : $site->label;
		wp_send_json_success(
			array(
				'paired'  => true,
				'message' => sprintf(
					/* translators: 1: site name, 2: fingerprint. */
					__( 'Paired with %1$s. Its key fingerprint is %2$s. Check that %1$s shows the same fingerprint for itself.', 'sceneshifter' ),
					$label,
					$pairing->fingerprint()
				),
				'back'    => __( 'Back to pairing', 'sceneshifter' ),
			)
		);
	}

	private function flashTest( string $siteId, TransportResult $result ): void {
		$site  = $this->plugin->registry()->get( $siteId );
		$label = null === $site ? $siteId : $site->label;
		if ( ! $result->ok() ) {
			self::flash( 'error', array( $result->message ) );
			return;
		}

		$data     = $result->data;
		$messages = array(
			sprintf(
				/* translators: 1: site name, 2: milliseconds. */
				__( 'Connected to %1$s in %2$d ms.', 'sceneshifter' ),
				$label,
				$result->elapsedMs
			),
			sprintf(
				/* translators: 1: plugin version, 2: site list version. */
				__( 'It runs Sceneshifter %1$s and has version %2$d of the site list.', 'sceneshifter' ),
				(string) ( $data['plugin_version'] ?? '?' ),
				(int) ( $data['registry_version'] ?? 0 )
			),
		);
		$type = 'success';
		foreach ( self::compatibility( $data, $this->plugin->registry()->version, $label ) as $warning ) {
			$messages[] = $warning;
			$type       = 'warning';
		}
		self::flash( $type, $messages );
	}

	/**
	 * Warnings about a sister's /status answer.
	 *
	 * @param array<string, mixed> $data
	 * @return list<string>
	 */
	public static function compatibility( array $data, int $registryVersion, string $label ): array {
		$warnings = array();
		if ( (string) ( $data['format'] ?? '' ) !== Plugin::PACKAGE_FORMAT ) {
			$warnings[] = sprintf(
				/* translators: %s: site name. */
				__( '%s uses a different package format, so content can\'t move between the two sites. Update Sceneshifter on both to the same version.', 'sceneshifter' ),
				$label
			);
		}
		if ( (int) ( $data['registry_version'] ?? 0 ) !== $registryVersion ) {
			$warnings[] = sprintf(
				/* translators: 1: site name, 2: this site's site list version. */
				__( 'This site has version %2$d of the site list, so the two lists may differ. Send the list to %1$s from the Sites tab, or from %1$s to here.', 'sceneshifter' ),
				$label,
				$registryVersion
			);
		}
		if ( isset( $data['time'] ) && abs( time() - (int) $data['time'] ) > 60 ) {
			$warnings[] = sprintf(
				/* translators: %s: site name. */
				__( 'The clocks on this server and %s differ by more than a minute. Requests fail when they differ by more than 5 minutes.', 'sceneshifter' ),
				$label
			);
		}
		return $warnings;
	}

	private function flashUnpair( string $siteId, ?TransportResult $told ): void {
		$site  = $this->plugin->registry()->get( $siteId );
		$label = null === $site ? $siteId : $site->label;
		if ( null !== $told && $told->ok() ) {
			/* translators: %s: site name. */
			self::flash( 'success', array( sprintf( __( 'Unpaired. %s has forgotten this site too.', 'sceneshifter' ), $label ) ) );
			return;
		}
		if ( null === $told ) {
			self::flash(
				'warning',
				array(
					/* translators: %s: site name. */
					sprintf( __( 'Unpaired here. %1$s isn\'t public, so it couldn\'t be told: unpair this site on %1$s too.', 'sceneshifter' ), $label ),
				)
			);
			return;
		}
		self::flash(
			'warning',
			array(
				/* translators: %s: site name. */
				sprintf( __( 'Unpaired here, but %1$s couldn\'t be told, so unpair this site on %1$s too.', 'sceneshifter' ), $label ),
				$told->message,
			)
		);
	}

	/**
	 * @param list<string> $messages
	 */
	private static function flash( string $type, array $messages ): void {
		set_transient(
			self::userKey( 'notice' ),
			array(
				'type'     => $type,
				'messages' => $messages,
			),
			5 * MINUTE_IN_SECONDS
		);
	}

	private function renderFlash(): void {
		$notice = get_transient( self::userKey( 'notice' ) );
		if ( ! is_array( $notice ) ) {
			return;
		}
		delete_transient( self::userKey( 'notice' ) );
		$type = in_array( $notice['type'] ?? '', array( 'success', 'error', 'warning', 'info' ), true ) ? $notice['type'] : 'info';
		echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible">';
		foreach ( (array) ( $notice['messages'] ?? array() ) as $message ) {
			echo '<p>' . esc_html( (string) $message ) . '</p>';
		}
		echo '</div>';
	}

	private static function userKey( string $what ): string {
		return 'sceneshifter_pairing_' . $what . '_' . get_current_user_id();
	}

	private static function date( int $time ): string {
		return wp_date( (string) get_option( 'date_format' ), $time ) ?: '';
	}

	private static function ago( int $time ): string {
		/* translators: %s: time span, e.g. 5 mins. */
		return sprintf( __( '%s ago', 'sceneshifter' ), human_time_diff( $time ) );
	}
}
