<?php
/**
 * Sister Sync → Settings: the site registry and enabled content types.
 *
 * The registry is edited as JSON for now. It is the same document on every
 * site, so it can be copied between environments as-is. A form-based editor
 * and signed sharing between paired sites come later.
 *
 * @package RedC\SisterSync
 */

declare(strict_types=1);

namespace RedC\SisterSync\Admin;

use RedC\SisterSync\Check\CheckPage;
use RedC\SisterSync\Plugin;
use RedC\SisterSync\Registry\Identity;
use RedC\SisterSync\Registry\Registry;
use Throwable;

final class SettingsPage {

	public const SLUG   = 'sister-sync-settings';
	private const ACTION = 'sister_sync_save_settings';

	public function __construct( private readonly Plugin $plugin ) {}

	public function register(): void {
		// Priority 20 so the Check page owns the top-level menu and comes first.
		add_action( 'admin_menu', array( $this, 'addMenu' ), 20 );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'save' ) );
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

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to change Sister Sync settings.', 'sister-sync' ) );
		}

		$registry   = $this->plugin->registry();
		$identity   = $this->plugin->identity();
		$draft      = get_transient( self::draftKey() );
		$error      = get_transient( self::errorKey() );
		$json       = is_string( $draft ) ? $draft : ( $registry->isEmpty() ? self::exampleJson() : (string) wp_json_encode( $registry->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		$enabled    = $this->plugin->options()->postTypes();
		$postTypes  = get_post_types( array( 'show_ui' => true ), 'objects' );
		delete_transient( self::draftKey() );
		delete_transient( self::errorKey() );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Sister Sync settings', 'sister-sync' ); ?></h1>

			<?php if ( isset( $_GET['updated'] ) && ! $error ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success"><p><?php esc_html_e( 'Settings saved.', 'sister-sync' ); ?></p></div>
			<?php endif; ?>
			<?php if ( is_string( $error ) && '' !== $error ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'This site', 'sister-sync' ); ?></h2>
			<p><?php echo esc_html( self::identityText( $identity ) ); ?></p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
				<?php wp_nonce_field( self::ACTION ); ?>

				<h2><?php esc_html_e( 'Site registry', 'sister-sync' ); ?></h2>
				<p>
					<?php esc_html_e( 'Every environment of this client site. Paste the same registry into every environment. Roles: dev, staging, uat, live, other. Aliases are other hosts or base URLs that count as that site.', 'sister-sync' ); ?>
					<?php
					/* translators: %d: registry version number. */
					printf( ' ' . esc_html__( 'Current version: %d.', 'sister-sync' ), (int) $registry->version );
					?>
				</p>
				<textarea name="registry" rows="22" class="large-text code" spellcheck="false"><?php echo esc_textarea( $json ); ?></textarea>

				<h2><?php esc_html_e( 'Content types', 'sister-sync' ); ?></h2>
				<p><?php esc_html_e( 'Check (and later Push and Pull) appear on these content types.', 'sister-sync' ); ?></p>
				<fieldset>
					<?php foreach ( $postTypes as $type ) : ?>
						<?php
						if ( 'attachment' === $type->name || str_starts_with( $type->name, 'wp_' ) || 'acf-field-group' === $type->name || 'acf-field' === $type->name ) {
							continue;
						}
						?>
						<label style="display:block;margin:4px 0;">
							<input type="checkbox" name="post_types[]" value="<?php echo esc_attr( $type->name ); ?>" <?php checked( in_array( $type->name, $enabled, true ) ); ?>>
							<?php echo esc_html( $type->labels->name ); ?> <code><?php echo esc_html( $type->name ); ?></code>
						</label>
					<?php endforeach; ?>
				</fieldset>

				<?php submit_button( __( 'Save settings', 'sister-sync' ) ); ?>
			</form>
		</div>
		<?php
	}

	public function save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to change Sister Sync settings.', 'sister-sync' ) );
		}
		check_admin_referer( self::ACTION );

		$json      = isset( $_POST['registry'] ) ? trim( (string) wp_unslash( $_POST['registry'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON is validated and re-encoded below.
		$postTypes = isset( $_POST['post_types'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['post_types'] ) ) : array();

		$this->plugin->options()->savePostTypes( $postTypes );

		$error = null;
		if ( '' === $json ) {
			delete_option( \RedC\SisterSync\Store\Options::REGISTRY );
		} else {
			try {
				$data = json_decode( $json, true, 32, JSON_THROW_ON_ERROR );
				if ( ! is_array( $data ) ) {
					throw new \InvalidArgumentException( __( 'The registry must be a JSON object.', 'sister-sync' ) );
				}
				$registry = Registry::fromArray( $data );
				$previous = $this->plugin->registry();
				if ( $registry->toArray()['sites'] !== $previous->toArray()['sites'] ) {
					$registry = $registry->withVersion( max( $registry->version, $previous->version + 1 ) );
				}
				$this->plugin->options()->saveRegistry( $registry );
			} catch ( Throwable $e ) {
				$error = sprintf(
					/* translators: %s: validation error. */
					__( 'The registry was not saved: %s', 'sister-sync' ),
					$e->getMessage()
				);
				set_transient( self::draftKey(), $json, 10 * MINUTE_IN_SECONDS );
				set_transient( self::errorKey(), $error, 10 * MINUTE_IN_SECONDS );
			}
		}

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG . '&updated=1' ) );
		exit;
	}

	private static function identityText( Identity $identity ): string {
		return match ( $identity->status ) {
			Identity::OK                    => sprintf(
				/* translators: 1: site label, 2: site ID, 3: role. */
				__( 'Identified as %1$s (ID "%2$s", role %3$s). Sister Sync is active.', 'sister-sync' ),
				$identity->site?->label,
				$identity->site?->id,
				$identity->site?->role
			),
			Identity::UNCONFIGURED          => __( 'No sites set up yet. Add every environment below, including this one, then save.', 'sister-sync' ),
			Identity::HOME_NOT_IN_REGISTRY  => sprintf(
				/* translators: %s: home URL. */
				__( 'Not identified: this site\'s home URL (%s) does not match any site or alias in the registry. Sister Sync is inactive.', 'sister-sync' ),
				$identity->detail
			),
			Identity::REQUEST_HOST_MISMATCH => sprintf(
				/* translators: 1: request host. */
				__( 'Not identified: this request came in on %1$s, which does not match the site the home URL points to. Sister Sync is inactive.', 'sister-sync' ),
				$identity->detail
			),
			default                         => __( 'Sister Sync is inactive.', 'sister-sync' ),
		};
	}

	private static function exampleJson(): string {
		return (string) wp_json_encode(
			array(
				'version' => 1,
				'sites'   => array(
					array(
						'id'      => 'live',
						'label'   => 'Live',
						'role'    => 'live',
						'url'     => 'https://www.example.co.uk',
						'aliases' => array( 'example.co.uk' ),
						'public'  => true,
					),
					array(
						'id'      => 'staging',
						'label'   => 'Staging',
						'role'    => 'staging',
						'url'     => 'https://staging.example.co.uk',
						'aliases' => array(),
						'public'  => true,
					),
					array(
						'id'      => 'dev',
						'label'   => 'Dev',
						'role'    => 'dev',
						'url'     => 'https://example.test',
						'aliases' => array(),
						'public'  => false,
					),
				),
			),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		);
	}

	private static function draftKey(): string {
		return 'sister_sync_registry_draft_' . get_current_user_id();
	}

	private static function errorKey(): string {
		return 'sister_sync_registry_error_' . get_current_user_id();
	}
}
