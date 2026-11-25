<?php
/**
 * Sister Sync → Settings: the sites of this client, enabled content types,
 * and sharing the list of sites with the other environments.
 *
 * @package RedC\SisterSync
 */

declare(strict_types=1);

namespace RedC\SisterSync\Admin;

use RedC\SisterSync\Check\CheckPage;
use RedC\SisterSync\Plugin;
use RedC\SisterSync\Registry\Identity;
use RedC\SisterSync\Registry\RegistryForm;
use RedC\SisterSync\Registry\Site;
use RedC\SisterSync\Store\Options;

final class SettingsPage {

	public const SLUG    = 'sister-sync-settings';
	private const ACTION = 'sister_sync_save_settings';

	public function __construct( private readonly Plugin $plugin ) {}

	/**
	 * @param array<string, string|int> $args Extra query arguments.
	 */
	public static function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	public function register(): void {
		// Priority 20 so the Check page owns the top-level menu and comes first.
		add_action( 'admin_menu', array( $this, 'addMenu' ), 20 );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'save' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		( new CheckSettingsTab( $this->plugin ) )->register();
	}

	public function addMenu(): void {
		// The top-level menu belongs to the Check page when the plugin is active.
		if ( ! $this->plugin->identity()->isActive() ) {
			add_menu_page(
				__( 'Sister Sync', 'sister-sync' ),
				__( 'Sister Sync', 'sister-sync' ),
				'manage_options',
				self::SLUG,
				array( $this, 'render' ),
				'dashicons-randomize',
				81
			);
		}
		add_submenu_page(
			$this->plugin->identity()->isActive() ? CheckPage::SLUG : self::SLUG,
			__( 'Sister Sync settings', 'sister-sync' ),
			__( 'Settings', 'sister-sync' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	public function enqueue( string $hook ): void {
		if ( ! str_ends_with( $hook, '_page_' . self::SLUG ) ) {
			return;
		}
		wp_enqueue_style( 'sister-sync-settings', SISTER_SYNC_URL . 'assets/settings.css', array(), Plugin::assetVersion( 'assets/settings.css' ) );
		wp_enqueue_script( 'sister-sync-settings', SISTER_SYNC_URL . 'assets/settings.js', array(), Plugin::assetVersion( 'assets/settings.js' ), true );
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to change Sister Sync settings.', 'sister-sync' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- choosing a view only.
		if ( 'receive' === ( $_GET['view'] ?? '' ) ) {
			( new RegistryTransfer( $this->plugin ) )->renderReceive();
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- choosing a tab only.
		$tab = 'check' === ( $_GET['tab'] ?? '' ) ? 'check' : 'sites';
		if ( 'check' === $tab ) {
			?>
			<div class="wrap sister-sync-settings">
				<h1><?php esc_html_e( 'Sister Sync settings', 'sister-sync' ); ?></h1>
				<?php self::tabs( $tab ); ?>
				<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- notice after redirect. ?>
				<?php if ( isset( $_GET['updated'] ) ) : ?>
					<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'sister-sync' ); ?></p></div>
				<?php endif; ?>
				<?php ( new CheckSettingsTab( $this->plugin ) )->render(); ?>
			</div>
			<?php
			return;
		}

		$registry  = $this->plugin->registry();
		$identity  = $this->plugin->identity();
		$draft     = get_transient( self::draftKey() );
		$draft     = is_array( $draft ) ? $draft : null;
		$rows      = $draft['rows'] ?? RegistryForm::rowsFrom( $registry );
		$rowErrors = $draft['rowErrors'] ?? array();
		$error     = $draft['error'] ?? null;
		delete_transient( self::draftKey() );

		$selfId = $identity->isActive() ? $identity->site?->id : null;
		if ( array() === $rows ) {
			// Start a new registry with this site filled in.
			$rows[] = array(
				'id'      => '',
				'label'   => '',
				'role'    => 'live',
				'url'     => untrailingslashit( home_url() ),
				'aliases' => '',
				'public'  => true,
				'colour'  => Site::DEFAULT_COLOURS['live'],
			);
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- notices after redirects.
		$saved    = isset( $_GET['updated'] ) && null === $draft;
		$received = isset( $_GET['received'] );
		// phpcs:enable
		?>
		<div class="wrap sister-sync-settings">
			<h1><?php esc_html_e( 'Sister Sync settings', 'sister-sync' ); ?></h1>
			<?php self::tabs( $tab ); ?>

			<?php if ( $saved ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'sister-sync' ); ?></p></div>
			<?php endif; ?>
			<?php if ( $received ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'The site list from your other site has been applied.', 'sister-sync' ); ?></p></div>
			<?php endif; ?>
			<?php if ( null !== $draft ) : ?>
				<div class="notice notice-error">
					<p>
						<?php esc_html_e( 'The sites were not saved. Fix the problems marked below and save again.', 'sister-sync' ); ?>
						<?php
						if ( is_string( $error ) && '' !== $error ) {
							echo ' ' . esc_html( $error );
						}
						?>
					</p>
				</div>
			<?php endif; ?>

			<div class="sister-sync-identity is-<?php echo esc_attr( $identity->isActive() ? 'active' : 'inactive' ); ?>">
				<h2><?php esc_html_e( 'This site', 'sister-sync' ); ?></h2>
				<p><?php echo esc_html( self::identityText( $identity ) ); ?></p>
				<?php if ( ! $identity->isActive() && ! $registry->isEmpty() ) : ?>
					<p>
						<button type="button" class="button" data-sister-sync-add-site="<?php echo esc_attr( untrailingslashit( home_url() ) ); ?>">
							<?php esc_html_e( 'Add this site to the list', 'sister-sync' ); ?>
						</button>
					</p>
				<?php endif; ?>
			</div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="sister-sync-settings-form">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
				<?php wp_nonce_field( self::ACTION ); ?>

				<h2><?php esc_html_e( 'Sites', 'sister-sync' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Every environment of this client site, including this one. All of them need the same list: after saving, send it to the others from "Share with your other sites" below.', 'sister-sync' ); ?>
				</p>

				<table class="widefat sister-sync-sites" data-default-colours="<?php echo esc_attr( (string) wp_json_encode( Site::DEFAULT_COLOURS ) ); ?>">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Name', 'sister-sync' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Role', 'sister-sync' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Address', 'sister-sync' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Other addresses', 'sister-sync' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Public', 'sister-sync' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Colour', 'sister-sync' ); ?></th>
							<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Remove', 'sister-sync' ); ?></span></th>
						</tr>
					</thead>
					<tbody id="sister-sync-site-rows">
						<?php foreach ( array_values( $rows ) as $i => $row ) : ?>
							<?php self::renderRow( (string) $i, $row, $rowErrors[ $i ] ?? null, null !== $selfId && $row['id'] === $selfId ); ?>
						<?php endforeach; ?>
					</tbody>
				</table>
				<template id="sister-sync-site-template">
					<?php
					self::renderRow(
						'__INDEX__',
						array(
							'id'      => '',
							'label'   => '',
							'role'    => 'staging',
							'url'     => '',
							'aliases' => '',
							'public'  => true,
							'colour'  => Site::DEFAULT_COLOURS['staging'],
						),
						null,
						false
					);
					?>
				</template>
				<p>
					<button type="button" class="button" id="sister-sync-add-row"><?php esc_html_e( 'Add a site', 'sister-sync' ); ?></button>
				</p>
				<details class="sister-sync-help">
					<summary><?php esc_html_e( 'What the fields mean', 'sister-sync' ); ?></summary>
					<dl>
						<dt><?php esc_html_e( 'Role', 'sister-sync' ); ?></dt>
						<dd><?php esc_html_e( 'On live, links to any other role are errors; elsewhere they are warnings.', 'sister-sync' ); ?></dd>
						<dt><?php esc_html_e( 'Address', 'sister-sync' ); ?></dt>
						<dd><?php esc_html_e( 'The site\'s home address, as set in Settings → General on that site. A site in a subfolder includes the folder, for example https://agency.example.com/clients/acme.', 'sister-sync' ); ?></dd>
						<dt><?php esc_html_e( 'Other addresses', 'sister-sync' ); ?></dt>
						<dd><?php esc_html_e( 'Any other host names that serve the same site, such as the version without www or a CDN for its images. One per line.', 'sister-sync' ); ?></dd>
						<dt><?php esc_html_e( 'Public', 'sister-sync' ); ?></dt>
						<dd><?php esc_html_e( 'Untick for sites the other sites can\'t reach over the internet, such as local dev sites. Pushing and pulling (coming later) is then always started from that site.', 'sister-sync' ); ?></dd>
						<dt><?php esc_html_e( 'Colour', 'sister-sync' ); ?></dt>
						<dd><?php esc_html_e( 'The colour of the environment badge in the admin bar.', 'sister-sync' ); ?></dd>
					</dl>
				</details>

				<?php submit_button( __( 'Save sites', 'sister-sync' ) ); ?>
			</form>

			<?php ( new RegistryTransfer( $this->plugin ) )->renderShare(); ?>
		</div>
		<?php
	}

	public function save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to change Sister Sync settings.', 'sister-sync' ) );
		}
		check_admin_referer( self::ACTION );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each field is validated by RegistryForm and Site.
		$posted = isset( $_POST['sites'] ) && is_array( $_POST['sites'] ) ? wp_unslash( $_POST['sites'] ) : array();

		$result = RegistryForm::parse( $posted, $this->plugin->registry() );
		if ( null === $result['registry'] ) {
			set_transient(
				self::draftKey(),
				array(
					'rows'      => $result['rows'],
					'rowErrors' => $result['rowErrors'],
					'error'     => $result['error'],
				),
				10 * MINUTE_IN_SECONDS
			);
		} elseif ( $result['registry']->isEmpty() ) {
			delete_option( Options::REGISTRY );
		} else {
			$this->plugin->options()->saveRegistry( $result['registry'] );
		}

		wp_safe_redirect( self::url( array( 'updated' => 1 ) ) );
		exit;
	}

	private static function tabs( string $current ): void {
		$tabs = array(
			'sites' => __( 'Sites', 'sister-sync' ),
			'check' => __( 'Check', 'sister-sync' ),
		);
		echo '<nav class="nav-tab-wrapper" aria-label="' . esc_attr__( 'Settings sections', 'sister-sync' ) . '">';
		foreach ( $tabs as $tab => $label ) {
			printf(
				'<a href="%1$s" class="nav-tab%2$s"%3$s>%4$s</a>',
				esc_url( self::url( 'sites' === $tab ? array() : array( 'tab' => $tab ) ) ),
				$tab === $current ? ' nav-tab-active' : '',
				$tab === $current ? ' aria-current="page"' : '',
				esc_html( $label )
			);
		}
		echo '</nav>';
	}

	/**
	 * @param array{id: string, label: string, role: string, url: string, aliases: string, public: bool, colour: string} $row
	 */
	private static function renderRow( string $index, array $row, ?string $error, bool $isThisSite ): void {
		$name = static fn( string $field ): string => "sites[{$index}][{$field}]";
		$id   = static fn( string $field ): string => "sister-sync-site-{$index}-{$field}";
		?>
		<tr class="sister-sync-site<?php echo null !== $error ? ' has-error' : ''; ?>">
			<td data-label="<?php esc_attr_e( 'Name', 'sister-sync' ); ?>">
				<input type="hidden" name="<?php echo esc_attr( $name( 'id' ) ); ?>" value="<?php echo esc_attr( $row['id'] ); ?>">
				<label class="screen-reader-text" for="<?php echo esc_attr( $id( 'label' ) ); ?>"><?php esc_html_e( 'Name', 'sister-sync' ); ?></label>
				<input type="text" class="regular-text" id="<?php echo esc_attr( $id( 'label' ) ); ?>" name="<?php echo esc_attr( $name( 'label' ) ); ?>" value="<?php echo esc_attr( $row['label'] ); ?>" placeholder="<?php esc_attr_e( 'e.g. Staging', 'sister-sync' ); ?>">
				<?php if ( $isThisSite ) : ?>
					<span class="sister-sync-this-site"><?php esc_html_e( 'This site', 'sister-sync' ); ?></span>
				<?php endif; ?>
				<?php if ( null !== $error ) : ?>
					<p class="sister-sync-row-error"><?php echo esc_html( $error ); ?></p>
				<?php endif; ?>
			</td>
			<td data-label="<?php esc_attr_e( 'Role', 'sister-sync' ); ?>">
				<label class="screen-reader-text" for="<?php echo esc_attr( $id( 'role' ) ); ?>"><?php esc_html_e( 'Role', 'sister-sync' ); ?></label>
				<select id="<?php echo esc_attr( $id( 'role' ) ); ?>" name="<?php echo esc_attr( $name( 'role' ) ); ?>" data-sister-sync-role>
					<?php foreach ( self::roleLabels() as $role => $label ) : ?>
						<option value="<?php echo esc_attr( $role ); ?>" <?php selected( $row['role'], $role ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
			<td data-label="<?php esc_attr_e( 'Address', 'sister-sync' ); ?>">
				<label class="screen-reader-text" for="<?php echo esc_attr( $id( 'url' ) ); ?>"><?php esc_html_e( 'Address', 'sister-sync' ); ?></label>
				<input type="text" inputmode="url" class="regular-text code" id="<?php echo esc_attr( $id( 'url' ) ); ?>" name="<?php echo esc_attr( $name( 'url' ) ); ?>" value="<?php echo esc_attr( $row['url'] ); ?>" placeholder="https://staging.example.co.uk" spellcheck="false" autocomplete="off">
			</td>
			<td data-label="<?php esc_attr_e( 'Other addresses', 'sister-sync' ); ?>">
				<label class="screen-reader-text" for="<?php echo esc_attr( $id( 'aliases' ) ); ?>"><?php esc_html_e( 'Other addresses, one per line', 'sister-sync' ); ?></label>
				<textarea class="code" rows="2" id="<?php echo esc_attr( $id( 'aliases' ) ); ?>" name="<?php echo esc_attr( $name( 'aliases' ) ); ?>" placeholder="example.co.uk" spellcheck="false"><?php echo esc_textarea( $row['aliases'] ); ?></textarea>
			</td>
			<td data-label="<?php esc_attr_e( 'Public', 'sister-sync' ); ?>">
				<label>
					<input type="checkbox" name="<?php echo esc_attr( $name( 'public' ) ); ?>" value="1" <?php checked( $row['public'] ); ?>>
					<span class="screen-reader-text"><?php esc_html_e( 'Reachable from the internet', 'sister-sync' ); ?></span>
				</label>
			</td>
			<td data-label="<?php esc_attr_e( 'Colour', 'sister-sync' ); ?>">
				<label class="screen-reader-text" for="<?php echo esc_attr( $id( 'colour' ) ); ?>"><?php esc_html_e( 'Badge colour', 'sister-sync' ); ?></label>
				<input type="color" id="<?php echo esc_attr( $id( 'colour' ) ); ?>" name="<?php echo esc_attr( $name( 'colour' ) ); ?>" value="<?php echo esc_attr( self::sixDigitColour( $row['colour'], $row['role'] ) ); ?>" data-sister-sync-colour>
			</td>
			<td>
				<button type="button" class="button-link button-link-delete" data-sister-sync-remove><?php esc_html_e( 'Remove', 'sister-sync' ); ?></button>
			</td>
		</tr>
		<?php
	}

	/**
	 * @return array<string, string>
	 */
	public static function roleLabels(): array {
		return array(
			'live'    => __( 'Live', 'sister-sync' ),
			'staging' => __( 'Staging', 'sister-sync' ),
			'uat'     => __( 'UAT', 'sister-sync' ),
			'dev'     => __( 'Dev', 'sister-sync' ),
			'other'   => __( 'Other', 'sister-sync' ),
		);
	}

	/**
	 * Colour inputs only accept #rrggbb.
	 */
	private static function sixDigitColour( string $colour, string $role ): string {
		if ( preg_match( '/^#([0-9a-f])([0-9a-f])([0-9a-f])$/i', $colour, $m ) ) {
			return strtolower( "#{$m[1]}{$m[1]}{$m[2]}{$m[2]}{$m[3]}{$m[3]}" );
		}
		if ( preg_match( '/^#[0-9a-f]{6}$/i', $colour ) ) {
			return strtolower( $colour );
		}
		return Site::DEFAULT_COLOURS[ $role ] ?? Site::DEFAULT_COLOURS['other'];
	}

	private static function identityText( Identity $identity ): string {
		return match ( $identity->status ) {
			Identity::OK                    => sprintf(
				/* translators: 1: site name, 2: role. */
				__( 'This is %1$s (%2$s). Sister Sync is active.', 'sister-sync' ),
				$identity->site?->label,
				self::roleLabels()[ $identity->site->role ?? 'other' ] ?? ''
			),
			Identity::UNCONFIGURED          => __( 'No sites set up yet. List every environment below, including this one, and save. If another environment is already set up, open its Sister Sync settings and send the list here instead.', 'sister-sync' ),
			Identity::HOME_NOT_IN_REGISTRY  => sprintf(
				/* translators: %s: home URL. */
				__( 'This site\'s address (%s) isn\'t in the list below, so Sister Sync is inactive here.', 'sister-sync' ),
				$identity->detail
			),
			Identity::REQUEST_HOST_MISMATCH => sprintf(
				/* translators: 1: request host. */
				__( 'This page was opened on %1$s, which doesn\'t match the site\'s home address. Sister Sync is inactive until the two match (common straight after copying a database, before search and replace).', 'sister-sync' ),
				$identity->detail
			),
			default                         => __( 'Sister Sync is inactive.', 'sister-sync' ),
		};
	}

	private static function draftKey(): string {
		return 'sister_sync_registry_draft_' . get_current_user_id();
	}
}
