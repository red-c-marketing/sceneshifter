<?php
/**
 * Shares the site list between environments.
 *
 * Sending works through the administrator's browser, so it reaches sites
 * that aren't public, such as local dev sites, and needs no stored
 * credentials. The Send button opens the other site with the list in the
 * link's #fragment, which browsers never send to servers or put in logs.
 * The other site shows what would change and applies nothing until one of
 * its administrators confirms. A downloadable file covers the cases a link
 * can't, such as a colleague's site.
 *
 * Phase 1 adds signed sharing between paired sites over HTTP.
 *
 * @package RedC\Sceneshifter
 */

declare(strict_types=1);

namespace RedC\Sceneshifter\Admin;

use InvalidArgumentException;
use RedC\Sceneshifter\Plugin;
use RedC\Sceneshifter\Registry\IncomingRegistry;
use RedC\Sceneshifter\Registry\RegistryBundle;
use RedC\Sceneshifter\Registry\RegistryDiff;
use RedC\Sceneshifter\Registry\Site;

final class RegistryTransfer {

	/** Front-end query that forwards to the review screen: ?sceneshifter=receive. */
	public const RECEIVE_QUERY = 'sceneshifter';

	private const REVIEW = 'sceneshifter_review_registry';
	private const APPLY  = 'sceneshifter_apply_registry';
	private const EXPORT = 'sceneshifter_export_registry';

	public function __construct( private readonly Plugin $plugin ) {}

	public function register(): void {
		add_action( 'init', array( $this, 'forwardToReview' ), 0 );
		add_action( 'admin_post_' . self::APPLY, array( $this, 'apply' ) );
		add_action( 'admin_post_' . self::EXPORT, array( $this, 'export' ) );
	}

	/**
	 * Send links point at the other site's home address, which always
	 * exists, rather than guessing where its admin lives (Bedrock moves it).
	 * This forwards to the admin review screen; the browser keeps the
	 * #fragment across the redirect.
	 */
	public function forwardToReview(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only redirects to a fixed admin URL.
		if ( is_admin() || 'receive' !== ( $_GET[ self::RECEIVE_QUERY ] ?? '' ) ) {
			return;
		}
		nocache_headers();
		wp_safe_redirect( self::reviewUrl() );
		exit;
	}

	public static function reviewUrl(): string {
		return SettingsPage::url( array( 'view' => 'receive' ) );
	}

	public static function sendUrl( Site $target, RegistryBundle $bundle ): string {
		return trailingslashit( $target->url ) . '?' . self::RECEIVE_QUERY . '=receive#' . $bundle->encode();
	}

	/**
	 * The "Share with your other sites" section of the settings page.
	 */
	public function renderShare(): void {
		$registry = $this->plugin->registry();
		$identity = $this->plugin->identity();
		?>
		<div class="sceneshifter-share" id="sceneshifter-share">
			<h2><?php esc_html_e( 'Share with your other sites', 'sceneshifter' ); ?></h2>

			<?php if ( $registry->isEmpty() ) : ?>
				<p class="description"><?php esc_html_e( 'Save the list of sites first. You can then send it to each of them from here.', 'sceneshifter' ); ?></p>
			<?php else : ?>
				<p class="description">
					<?php
					printf(
						/* translators: %d: version number of the site list. */
						esc_html__( 'Every site needs the same list. After each change, send it to the others. The other site opens in a new tab and shows what would change; nothing changes there until you confirm. You need to be logged in to that site as an administrator. This list is version %d, and each site shows its version here, so you can tell when one is out of date.', 'sceneshifter' ),
						(int) $registry->version
					);
					?>
				</p>
				<p class="sceneshifter-unsaved" hidden><?php esc_html_e( 'You have unsaved changes. Save the settings before sending.', 'sceneshifter' ); ?></p>

				<?php if ( $identity->isActive() && null !== $identity->site ) : ?>
					<?php
					$selfId = $identity->site->id;
					$bundle = new RegistryBundle( $registry, $selfId );
					$others = array_filter( $registry->sites(), static fn( Site $s ): bool => $s->id !== $selfId );
					?>
					<?php if ( array() === $others ) : ?>
						<p><?php esc_html_e( 'Add your other sites above to send the list to them.', 'sceneshifter' ); ?></p>
					<?php else : ?>
						<ul class="sceneshifter-send-list">
							<?php foreach ( $others as $site ) : ?>
								<li>
									<span class="sceneshifter-role-dot" style="background:<?php echo esc_attr( $site->colour() ); ?>"></span>
									<strong><?php echo esc_html( $site->label ); ?></strong>
									<code><?php echo esc_html( $site->url ); ?></code>
									<a class="button" data-sceneshifter-send target="_blank" rel="noopener" href="<?php echo esc_url( self::sendUrl( $site, $bundle ) ); ?>">
										<?php
										/* translators: %s: site name. */
										echo esc_html( sprintf( __( 'Send to %s', 'sceneshifter' ), $site->label ) );
										?>
									</a>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				<?php else : ?>
					<p><?php esc_html_e( 'This site isn\'t in its own list, so it can\'t send it. Add this site above and save first.', 'sceneshifter' ); ?></p>
				<?php endif; ?>
			<?php endif; ?>

			<h3><?php esc_html_e( 'Using a file instead', 'sceneshifter' ); ?></h3>
			<p class="description"><?php esc_html_e( 'For a site you can\'t open from this browser, such as a colleague\'s dev site: download the file here, then import it on that site.', 'sceneshifter' ); ?></p>
			<div class="sceneshifter-file-actions">
				<?php if ( ! $registry->isEmpty() ) : ?>
					<div class="sceneshifter-file-step">
						<h4><?php esc_html_e( 'Download from this site', 'sceneshifter' ); ?></h4>
						<p><?php esc_html_e( 'Saves this site\'s list as a .json file.', 'sceneshifter' ); ?></p>
						<p>
							<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=' . self::EXPORT ), self::EXPORT ) ); ?>">
								<?php esc_html_e( 'Download the site list', 'sceneshifter' ); ?>
							</a>
						</p>
					</div>
				<?php endif; ?>
				<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( self::reviewUrl() ); ?>" class="sceneshifter-file-step sceneshifter-import">
					<h4><?php esc_html_e( 'Import on this site', 'sceneshifter' ); ?></h4>
					<?php wp_nonce_field( self::REVIEW ); ?>
					<input type="hidden" name="source" value="<?php echo esc_attr( IncomingRegistry::SOURCE_FILE ); ?>">
					<p>
						<label for="sceneshifter-registry-file"><?php esc_html_e( 'Site list file, downloaded from another site:', 'sceneshifter' ); ?></label>
						<input type="file" id="sceneshifter-registry-file" name="registry_file" accept=".json,application/json" required>
					</p>
					<p>
						<button type="submit" class="button"><?php esc_html_e( 'Review import', 'sceneshifter' ); ?></button>
					</p>
					<p class="description"><?php esc_html_e( 'Nothing changes until you confirm on the next screen.', 'sceneshifter' ); ?></p>
				</form>
			</div>
		</div>
		<?php
	}

	/**
	 * The review screen: Settings with view=receive.
	 */
	public function renderReceive(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked just below when present.
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && isset( $_POST['source'] ) ) {
			check_admin_referer( self::REVIEW );
			$this->renderReview();
			return;
		}
		$this->renderCatcher();
	}

	/**
	 * A page that reads the list from the link's #fragment and posts it back
	 * to this site for review. Also records which site the browser says the
	 * link was clicked on, signed so it can't be altered on the way.
	 */
	private function renderCatcher(): void {
		$origin = self::originOf( isset( $_SERVER['HTTP_REFERER'] ) ? (string) wp_unslash( $_SERVER['HTTP_REFERER'] ) : '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- reduced to an origin.
		?>
		<div class="wrap sceneshifter-settings">
			<h1><?php esc_html_e( 'Receive a site list', 'sceneshifter' ); ?></h1>
			<form method="post" action="<?php echo esc_url( self::reviewUrl() ); ?>" id="sceneshifter-receive-form">
				<?php wp_nonce_field( self::REVIEW ); ?>
				<input type="hidden" name="source" value="<?php echo esc_attr( IncomingRegistry::SOURCE_LINK ); ?>">
				<input type="hidden" name="bundle" value="">
				<input type="hidden" name="origin" value="<?php echo esc_attr( $origin ); ?>">
				<input type="hidden" name="origin_sig" value="<?php echo esc_attr( self::signOrigin( $origin ) ); ?>">
			</form>
			<p class="sceneshifter-receiving"><?php esc_html_e( 'Reading the site list…', 'sceneshifter' ); ?></p>
			<div class="notice notice-warning inline sceneshifter-nothing" hidden>
				<p><?php esc_html_e( 'Nothing was received. Go back to the other site and click Send again. If you had to log in here first, the list was lost on the way, so sending it again now will work.', 'sceneshifter' ); ?></p>
				<p><a href="<?php echo esc_url( SettingsPage::url() ); ?>"><?php esc_html_e( 'Go to Sceneshifter settings', 'sceneshifter' ); ?></a></p>
			</div>
			<noscript><div class="notice notice-error inline"><p><?php esc_html_e( 'Receiving a site list needs JavaScript. Use the file download and import instead.', 'sceneshifter' ); ?></p></div></noscript>
		</div>
		<?php
	}

	private function renderReview(): void {
		$check = null;
		$error = null;
		try {
			$check = $this->checkFromRequest();
		} catch ( InvalidArgumentException $e ) {
			$error = $e->getMessage();
		}
		?>
		<div class="wrap sceneshifter-settings">
			<h1><?php esc_html_e( 'Review the incoming site list', 'sceneshifter' ); ?></h1>
			<?php if ( null === $check ) : ?>
				<div class="notice notice-error inline"><p><?php echo esc_html( (string) $error ); ?></p></div>
				<p><a class="button" href="<?php echo esc_url( SettingsPage::url() ); ?>"><?php esc_html_e( 'Back to settings', 'sceneshifter' ); ?></a></p>
			<?php else : ?>
				<?php $this->renderCheck( $check ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	private function renderCheck( IncomingRegistry $check ): void {
		$current  = $this->plugin->registry();
		$incoming = $check->incoming();
		$sender   = $check->sender();
		$self     = $check->self();
		?>
		<div class="sceneshifter-review-source">
			<p>
				<?php
				if ( IncomingRegistry::SOURCE_LINK === $check->source ) {
					echo esc_html(
						null !== $sender
							/* translators: 1: sending site's name, 2: its address. */
							? sprintf( __( 'Sent from %1$s (%2$s).', 'sceneshifter' ), $sender->label, $sender->url )
							: __( 'The sender didn\'t say which site it is.', 'sceneshifter' )
					);
					if ( $check->originVerified() ) {
						echo ' <span class="sceneshifter-verified">' . esc_html__( 'Your browser confirmed the link was opened from that site.', 'sceneshifter' ) . '</span>';
					}
				} else {
					echo esc_html(
						null !== $sender
							/* translators: %s: name of the site that exported the file. */
							? sprintf( __( 'Imported from a file downloaded from %s.', 'sceneshifter' ), $sender->label )
							: __( 'Imported from a file.', 'sceneshifter' )
					);
				}
				?>
			</p>
			<p>
				<?php
				printf(
					/* translators: 1: incoming version, 2: this site's version. */
					esc_html__( 'Incoming list: version %1$d. This site\'s list: version %2$d.', 'sceneshifter' ),
					(int) $incoming->version,
					(int) $current->version
				);
				?>
			</p>
		</div>

		<?php foreach ( $check->blockers() as $code ) : ?>
			<div class="notice notice-error inline"><p><?php echo esc_html( $this->message( $code, $check ) ); ?></p></div>
		<?php endforeach; ?>
		<?php foreach ( $check->warnings() as $code ) : ?>
			<div class="notice notice-warning inline"><p><?php echo esc_html( $this->message( $code, $check ) ); ?></p></div>
		<?php endforeach; ?>
		<?php if ( $check->isUpToDate() ) : ?>
			<div class="notice notice-success inline"><p><?php esc_html_e( 'This site already has this list. There is nothing to change.', 'sceneshifter' ); ?></p></div>
		<?php endif; ?>

		<table class="widefat striped sceneshifter-diff">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Site', 'sceneshifter' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Change', 'sceneshifter' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Details', 'sceneshifter' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( RegistryDiff::compare( $current, $incoming ) as $entry ) : ?>
					<tr class="is-<?php echo esc_attr( $entry['status'] ); ?>">
						<td>
							<span class="sceneshifter-role-dot" style="background:<?php echo esc_attr( $entry['site']->colour() ); ?>"></span>
							<strong><?php echo esc_html( $entry['site']->label ); ?></strong>
							<?php if ( null !== $self && $self->id === $entry['site']->id ) : ?>
								<span class="sceneshifter-this-site"><?php esc_html_e( 'This site', 'sceneshifter' ); ?></span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( self::statusLabel( $entry['status'] ) ); ?></td>
						<td><?php $this->renderDetails( $entry ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sceneshifter-apply">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::APPLY ); ?>">
			<?php wp_nonce_field( self::APPLY ); ?>
			<input type="hidden" name="source" value="<?php echo esc_attr( $check->source ); ?>">
			<input type="hidden" name="bundle" value="<?php echo esc_attr( $check->bundle->encode() ); ?>">
			<?php $origin = $this->verifiedOriginFromRequest() ?? ''; ?>
			<input type="hidden" name="origin" value="<?php echo esc_attr( $origin ); ?>">
			<input type="hidden" name="origin_sig" value="<?php echo esc_attr( self::signOrigin( $origin ) ); ?>">
			<p>
				<?php if ( $check->canApply() ) : ?>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Apply this site list', 'sceneshifter' ); ?></button>
				<?php endif; ?>
				<a class="button" href="<?php echo esc_url( SettingsPage::url() ); ?>"><?php echo $check->canApply() ? esc_html__( 'Cancel', 'sceneshifter' ) : esc_html__( 'Back to settings', 'sceneshifter' ); ?></a>
			</p>
		</form>
		<?php
	}

	/**
	 * @param array{status: string, site: Site, before: ?Site, fields: list<string>} $entry
	 */
	private function renderDetails( array $entry ): void {
		$site = $entry['site'];
		if ( RegistryDiff::CHANGED !== $entry['status'] || null === $entry['before'] ) {
			$roles = SettingsPage::roleLabels();
			echo '<code>' . esc_html( $site->url ) . '</code> · ' . esc_html( $roles[ $site->role ] ?? $site->role );
			return;
		}
		echo '<ul class="sceneshifter-diff-fields">';
		foreach ( $entry['fields'] as $field ) {
			printf(
				'<li><strong>%1$s:</strong> <del>%2$s</del> → <ins>%3$s</ins></li>',
				esc_html( self::fieldLabel( $field ) ),
				esc_html( self::fieldValue( $entry['before'], $field ) ),
				esc_html( self::fieldValue( $site, $field ) )
			);
		}
		echo '</ul>';
	}

	public function apply(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to change Sceneshifter settings.', 'sceneshifter' ) );
		}
		check_admin_referer( self::APPLY );

		try {
			$check = $this->checkFromRequest();
		} catch ( InvalidArgumentException $e ) {
			wp_die( esc_html( $e->getMessage() ), esc_html__( 'Sceneshifter', 'sceneshifter' ), array( 'back_link' => true ) );
		}
		if ( ! $check->canApply() ) {
			wp_die( esc_html__( 'This site list can\'t be applied. Go back to see why.', 'sceneshifter' ), esc_html__( 'Sceneshifter', 'sceneshifter' ), array( 'back_link' => true ) );
		}

		$this->plugin->options()->saveRegistry( $check->registryToSave() );
		wp_safe_redirect( SettingsPage::url( array( 'received' => 1 ) ) );
		exit;
	}

	public function export(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to export Sceneshifter settings.', 'sceneshifter' ) );
		}
		check_admin_referer( self::EXPORT );

		$registry = $this->plugin->registry();
		$identity = $this->plugin->identity();
		$from     = $identity->isActive() && null !== $identity->site ? $identity->site->id : '';
		$filename = sprintf( 'sceneshifter-sites-%s-v%d.json', '' !== $from ? $from : 'export', $registry->version );

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		echo ( new RegistryBundle( $registry, $from ) )->toJson(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON download.
		exit;
	}

	/**
	 * Builds the check from a review or apply request. The nonce has
	 * already been checked by the caller.
	 *
	 * @throws InvalidArgumentException When the input isn't a usable site list.
	 */
	private function checkFromRequest(): IncomingRegistry {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified by the caller.
		$source = IncomingRegistry::SOURCE_FILE === ( $_POST['source'] ?? '' ) ? IncomingRegistry::SOURCE_FILE : IncomingRegistry::SOURCE_LINK;
		$input  = isset( $_POST['bundle'] ) ? (string) wp_unslash( $_POST['bundle'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- decoded and validated by RegistryBundle.

		if ( '' === $input && IncomingRegistry::SOURCE_FILE === $source ) {
			$input = self::uploadedFile();
		}
		// phpcs:enable

		return new IncomingRegistry(
			$this->plugin->registry(),
			RegistryBundle::decode( $input ),
			home_url(),
			$source,
			IncomingRegistry::SOURCE_LINK === $source ? $this->verifiedOriginFromRequest() : null
		);
	}

	/**
	 * @throws InvalidArgumentException When no usable file was uploaded.
	 */
	private static function uploadedFile(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput -- verified by the caller; only read as text.
		$file = $_FILES['registry_file'] ?? null;
		if ( ! is_array( $file ) || UPLOAD_ERR_OK !== ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) || ! is_uploaded_file( (string) $file['tmp_name'] ) ) {
			throw new InvalidArgumentException( __( 'Choose the site list file you downloaded from your other site.', 'sceneshifter' ) );
		}
		if ( (int) $file['size'] > RegistryBundle::MAX_BYTES ) {
			throw new InvalidArgumentException( __( 'This file is too large to be a Sceneshifter site list.', 'sceneshifter' ) );
		}
		$contents = file_get_contents( (string) $file['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local upload.
		return false === $contents ? '' : $contents;
	}

	/**
	 * The origin recorded when the link arrived, if its signature is good.
	 */
	private function verifiedOriginFromRequest(): ?string {
		// phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified by the caller; checked against the signature.
		$origin = isset( $_POST['origin'] ) ? (string) wp_unslash( $_POST['origin'] ) : '';
		$sig    = isset( $_POST['origin_sig'] ) ? (string) wp_unslash( $_POST['origin_sig'] ) : '';
		// phpcs:enable
		if ( '' === $origin || ! hash_equals( self::signOrigin( $origin ), $sig ) ) {
			return null;
		}
		return $origin;
	}

	private static function signOrigin( string $origin ): string {
		return wp_hash( 'sceneshifter-origin|' . $origin . '|' . get_current_user_id() );
	}

	/**
	 * Reduces a Referer header to scheme://host[:port], or '' if there is none.
	 */
	private static function originOf( string $referer ): string {
		$parts = wp_parse_url( $referer );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || ! in_array( $parts['scheme'] ?? '', array( 'http', 'https' ), true ) ) {
			return '';
		}
		return $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' );
	}

	private function message( string $code, IncomingRegistry $check ): string {
		$self = home_url();
		return match ( $code ) {
			IncomingRegistry::NOT_INCLUDED      => sprintf(
				/* translators: %s: this site's address. */
				__( 'This list doesn\'t include this site (%s), so applying it would switch Sceneshifter off here. Add this site on the other site, save, and send it again.', 'sceneshifter' ),
				$self
			),
			IncomingRegistry::UNKNOWN_SENDER    => __( 'This wasn\'t sent by a site in this site\'s list, so it has been refused. Send it from a site this one already lists, or use the file download and import instead.', 'sceneshifter' ),
			IncomingRegistry::WRONG_ORIGIN      => __( 'The link was opened from a different site to the one it claims to come from, so it has been refused.', 'sceneshifter' ),
			IncomingRegistry::FIRST_SETUP       => __( 'This site has no list yet. Only apply this if you have just clicked Send on one of your own sites.', 'sceneshifter' ),
			IncomingRegistry::UNVERIFIED_ORIGIN => __( 'Your browser didn\'t say which site the link came from (some sites turn this off), so Sceneshifter can\'t confirm the sender. Check the sites below before applying.', 'sceneshifter' ),
			IncomingRegistry::SENDER_NOT_KNOWN  => __( 'This file came from a site that isn\'t in this site\'s list. Check the sites below carefully before applying.', 'sceneshifter' ),
			IncomingRegistry::OLDER             => sprintf(
				/* translators: 1: incoming version, 2: this site's version. */
				__( 'This list is older than the one here (version %1$d against %2$d). Applying it would undo newer changes.', 'sceneshifter' ),
				$check->incoming()->version,
				$this->plugin->registry()->version
			),
			default                             => $code,
		};
	}

	private static function statusLabel( string $status ): string {
		return match ( $status ) {
			RegistryDiff::ADDED   => __( 'Added', 'sceneshifter' ),
			RegistryDiff::REMOVED => __( 'Removed', 'sceneshifter' ),
			RegistryDiff::CHANGED => __( 'Changed', 'sceneshifter' ),
			default               => __( 'No change', 'sceneshifter' ),
		};
	}

	private static function fieldLabel( string $field ): string {
		return match ( $field ) {
			'label'   => __( 'Name', 'sceneshifter' ),
			'role'    => __( 'Role', 'sceneshifter' ),
			'url'     => __( 'Address', 'sceneshifter' ),
			'aliases' => __( 'Other addresses', 'sceneshifter' ),
			'public'  => __( 'Public', 'sceneshifter' ),
			'colour'  => __( 'Colour', 'sceneshifter' ),
			default   => $field,
		};
	}

	private static function fieldValue( Site $site, string $field ): string {
		return match ( $field ) {
			'label'   => $site->label,
			'role'    => SettingsPage::roleLabels()[ $site->role ] ?? $site->role,
			'url'     => $site->url,
			'aliases' => array() === $site->aliases ? __( 'none', 'sceneshifter' ) : implode( ', ', $site->aliases ),
			'public'  => $site->isPublic ? __( 'yes', 'sceneshifter' ) : __( 'no', 'sceneshifter' ),
			'colour'  => $site->colour(),
			default   => '',
		};
	}
}
