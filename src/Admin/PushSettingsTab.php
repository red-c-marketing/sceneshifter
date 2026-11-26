<?php
/**
 * Settings → Push: how content pushed to this site arrives. These belong
 * to this site only, since the receiving site decides.
 *
 * @package RedC\Sceneshifter
 */

declare(strict_types=1);

namespace RedC\Sceneshifter\Admin;

use RedC\Sceneshifter\Plugin;
use RedC\Sceneshifter\Store\PushSettings;

final class PushSettingsTab {

	public const ACTION = 'sceneshifter_save_push';

	public function __construct( private readonly Plugin $plugin ) {}

	public function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'save' ) );
	}

	public function render(): void {
		$settings = $this->plugin->options()->pushSettings();
		$authors  = get_users(
			array(
				'capability' => array( 'edit_posts' ),
				'orderby'    => 'display_name',
				'fields'     => array( 'ID', 'display_name', 'user_login' ),
				'number'     => 500,
			)
		);
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="sceneshifter-push-settings-form">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
			<?php wp_nonce_field( self::ACTION ); ?>

			<p class="description"><?php esc_html_e( 'These settings decide how content pushed to this site from other environments arrives. They apply to this site only, so set them separately on each environment.', 'sceneshifter' ); ?></p>

			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Updates to published content', 'sceneshifter' ); ?></th>
						<td>
							<fieldset>
								<legend class="screen-reader-text"><?php esc_html_e( 'Updates to published content', 'sceneshifter' ); ?></legend>
								<label><input type="radio" name="published_updates" value="<?php echo esc_attr( PushSettings::UPDATES_LIVE ); ?>" <?php checked( $settings->publishedUpdates, PushSettings::UPDATES_LIVE ); ?>> <?php esc_html_e( 'Show to visitors straight away', 'sceneshifter' ); ?></label><br>
								<label><input type="radio" name="published_updates" value="<?php echo esc_attr( PushSettings::UPDATES_REVIEW ); ?>" <?php checked( $settings->publishedUpdates, PushSettings::UPDATES_REVIEW ); ?>> <?php esc_html_e( 'Hold them for review', 'sceneshifter' ); ?></label>
							</fieldset>
							<p class="description"><?php esc_html_e( 'When held, a pushed update to a published or scheduled post arrives as a separate copy waiting for review, and the published post doesn\'t change. Anyone who can push can then apply or discard it from the post\'s edit screen or from Sceneshifter → Log. Changes made to the copy itself aren\'t kept when it is applied.', 'sceneshifter' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="sceneshifter-default-author"><?php esc_html_e( 'Default author', 'sceneshifter' ); ?></label></th>
						<td>
							<select id="sceneshifter-default-author" name="default_author">
								<option value="0"><?php esc_html_e( 'The first administrator', 'sceneshifter' ); ?></option>
								<?php foreach ( $authors as $user ) : ?>
									<?php if ( is_object( $user ) && isset( $user->ID, $user->display_name, $user->user_login ) ) : ?>
										<option value="<?php echo esc_attr( (string) $user->ID ); ?>" <?php selected( $settings->defaultAuthor, (int) $user->ID ); ?>><?php echo esc_html( $user->display_name . ' (' . $user->user_login . ')' ); ?></option>
									<?php endif; ?>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'New posts keep their author when a user here has the same email address. Otherwise they get this author. Updates never change the author.', 'sceneshifter' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'A different file with the same name', 'sceneshifter' ); ?></th>
						<td>
							<fieldset>
								<legend class="screen-reader-text"><?php esc_html_e( 'A different file with the same name', 'sceneshifter' ); ?></legend>
								<label><input type="radio" name="media_clash" value="<?php echo esc_attr( PushSettings::CLASH_STOP ); ?>" <?php checked( $settings->mediaClash, PushSettings::CLASH_STOP ); ?>> <?php esc_html_e( 'Stop the push and say why', 'sceneshifter' ); ?></label><br>
								<label><input type="radio" name="media_clash" value="<?php echo esc_attr( PushSettings::CLASH_RENAME ); ?>" <?php checked( $settings->mediaClash, PushSettings::CLASH_RENAME ); ?>> <?php esc_html_e( 'Save the new file with a number added, such as photo-1.jpg', 'sceneshifter' ); ?></label>
							</fieldset>
							<p class="description"><?php esc_html_e( 'What to do when a pushed file has the same name and folder as a different file already in this site\'s uploads. Links to a renamed file are updated in the pushed content.', 'sceneshifter' ); ?></p>
						</td>
					</tr>
				</tbody>
			</table>

			<?php submit_button( __( 'Save Push settings', 'sceneshifter' ) ); ?>
		</form>
		<?php
	}

	public function save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to change Sceneshifter settings.', 'sceneshifter' ) );
		}
		check_admin_referer( self::ACTION );

		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- every value is validated by PushSettings::fromArray().
		$author   = isset( $_POST['default_author'] ) ? absint( $_POST['default_author'] ) : 0;
		$settings = PushSettings::fromArray(
			array(
				'publishedUpdates' => isset( $_POST['published_updates'] ) ? sanitize_key( wp_unslash( $_POST['published_updates'] ) ) : '',
				'defaultAuthor'    => $author > 0 && user_can( $author, 'edit_posts' ) ? $author : 0,
				'mediaClash'       => isset( $_POST['media_clash'] ) ? sanitize_key( wp_unslash( $_POST['media_clash'] ) ) : '',
			)
		);
		// phpcs:enable

		$this->plugin->options()->savePushSettings( $settings );
		wp_safe_redirect( SettingsPage::url( array( 'tab' => 'push', 'updated' => 1 ) ) );
		exit;
	}
}
