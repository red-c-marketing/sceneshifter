<?php
/**
 * Settings → Check: content types, severity per environment, publishing,
 * the scheduled scan and extra options to scan. These belong to this site
 * only and are not shared with the other environments.
 *
 * @package RedC\SisterSync
 */

declare(strict_types=1);

namespace RedC\SisterSync\Admin;

use RedC\SisterSync\Plugin;
use RedC\SisterSync\Registry\Site;
use RedC\SisterSync\Scanner\Finding;
use RedC\SisterSync\SiteScan\ScheduledScan;
use RedC\SisterSync\SiteScan\SiteScanner;
use RedC\SisterSync\Store\CheckSettings;

final class CheckSettingsTab {

	public const ACTION = 'sister_sync_save_check';

	public function __construct( private readonly Plugin $plugin ) {}

	public function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'save' ) );
	}

	public function render(): void {
		$settings  = $this->plugin->options()->checkSettings();
		$enabled   = $this->plugin->options()->postTypes();
		$postTypes = get_post_types( array( 'show_ui' => true ), 'objects' );
		$identity  = $this->plugin->identity();
		$role      = $identity->isActive() && null !== $identity->site ? $identity->site->role : 'other';
		$roles     = SettingsPage::roleLabels();
		$next      = wp_next_scheduled( ScheduledScan::HOOK );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="sister-sync-check-form">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
			<?php wp_nonce_field( self::ACTION ); ?>

			<p class="description"><?php esc_html_e( 'These settings apply to this site only. Set them separately on each environment: live usually blocks publishing, staging usually doesn\'t.', 'sister-sync' ); ?></p>

			<h2><?php esc_html_e( 'Content types', 'sister-sync' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Check (and later Push and Pull) appear on these content types, and the site scan covers them.', 'sister-sync' ); ?></p>
			<fieldset class="sister-sync-post-types">
				<legend class="screen-reader-text"><?php esc_html_e( 'Content types', 'sister-sync' ); ?></legend>
				<?php foreach ( $postTypes as $type ) : ?>
					<?php
					if ( 'attachment' === $type->name || str_starts_with( $type->name, 'wp_' ) || in_array( $type->name, array( 'acf-field-group', 'acf-field', 'acf-post-type', 'acf-taxonomy', 'acf-ui-options-page' ), true ) ) {
						continue;
					}
					?>
					<label class="sister-sync-post-type">
						<input type="checkbox" name="post_types[]" value="<?php echo esc_attr( $type->name ); ?>" <?php checked( in_array( $type->name, $enabled, true ) ); ?>>
						<?php echo esc_html( $type->labels->name ); ?> <code><?php echo esc_html( $type->name ); ?></code>
					</label>
				<?php endforeach; ?>
			</fieldset>

			<h2><?php esc_html_e( 'How serious is each link', 'sister-sync' ); ?></h2>
			<p class="description">
				<?php
				printf(
					/* translators: %s: this site's role. */
					esc_html__( 'What a link from this site (%s) to each kind of environment counts as. Errors can block publishing; warnings never do.', 'sister-sync' ),
					esc_html( $roles[ $role ] ?? $role )
				);
				?>
			</p>
			<table class="form-table sister-sync-severity" role="presentation">
				<tbody>
					<?php foreach ( $roles as $sisterRole => $label ) : ?>
						<?php
						$value   = $settings->severity[ $sisterRole ] ?? CheckSettings::defaultSeverity( $role, $sisterRole );
						$default = CheckSettings::defaultSeverity( $role, $sisterRole );
						?>
						<tr>
							<th scope="row">
								<label for="sister-sync-severity-<?php echo esc_attr( $sisterRole ); ?>">
									<?php
									/* translators: %s: environment role, e.g. Staging. */
									echo esc_html( sprintf( __( 'Links to %s sites', 'sister-sync' ), $label ) );
									?>
								</label>
							</th>
							<td>
								<select id="sister-sync-severity-<?php echo esc_attr( $sisterRole ); ?>" name="severity[<?php echo esc_attr( $sisterRole ); ?>]">
									<?php foreach ( self::severityLabels() as $severity => $severityLabel ) : ?>
										<option value="<?php echo esc_attr( $severity ); ?>" <?php selected( $value, $severity ); ?>>
											<?php
											echo esc_html( $severity === $default ? sprintf( /* translators: %s: severity. */ __( '%s (default)', 'sister-sync' ), $severityLabel ) : $severityLabel );
											?>
										</option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Publishing', 'sister-sync' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Both editors check before publishing and list what they find. Pressing Publish or Update with findings asks for confirmation.', 'sister-sync' ); ?></p>
			<label>
				<input type="checkbox" name="block_publish" value="1" <?php checked( $settings->blockPublish ); ?>>
				<?php esc_html_e( 'Block publishing and updating while content has errors', 'sister-sync' ); ?>
			</label>
			<p class="description"><?php esc_html_e( 'Recommended on live. Applies to everyone, including administrators. Content that isn\'t published yet is kept as a draft; published content can\'t be updated until the links are fixed.', 'sister-sync' ); ?></p>

			<h2><?php esc_html_e( 'Scheduled scan', 'sister-sync' ); ?></h2>
			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><label for="sister-sync-schedule"><?php esc_html_e( 'Scan the whole site', 'sister-sync' ); ?></label></th>
						<td>
							<select id="sister-sync-schedule" name="schedule">
								<option value="<?php echo esc_attr( CheckSettings::SCHEDULE_OFF ); ?>" <?php selected( $settings->schedule, CheckSettings::SCHEDULE_OFF ); ?>><?php esc_html_e( 'Never (only when started by hand)', 'sister-sync' ); ?></option>
								<option value="<?php echo esc_attr( CheckSettings::SCHEDULE_DAILY ); ?>" <?php selected( $settings->schedule, CheckSettings::SCHEDULE_DAILY ); ?>><?php esc_html_e( 'Every night', 'sister-sync' ); ?></option>
								<option value="<?php echo esc_attr( CheckSettings::SCHEDULE_WEEKLY ); ?>" <?php selected( $settings->schedule, CheckSettings::SCHEDULE_WEEKLY ); ?>><?php esc_html_e( 'Once a week', 'sister-sync' ); ?></option>
							</select>
							<p class="description">
								<?php esc_html_e( 'Runs at about 3am site time, using WP-Cron.', 'sister-sync' ); ?>
								<?php
								if ( false !== $next ) {
									printf(
										/* translators: %s: date and time. */
										esc_html__( 'Next scan: %s.', 'sister-sync' ),
										esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $next ) )
									);
								}
								if ( ! SiteScanner::queueAvailable() ) {
									echo ' ' . esc_html__( 'Action Scheduler isn\'t loaded, so batches run through WP-Cron, which needs visitors or a server cron job to keep going.', 'sister-sync' );
								}
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="sister-sync-recipients"><?php esc_html_e( 'Email the results to', 'sister-sync' ); ?></label></th>
						<td>
							<textarea id="sister-sync-recipients" name="recipients" rows="2" class="regular-text" placeholder="<?php echo esc_attr( (string) get_option( 'admin_email' ) ); ?>"><?php echo esc_textarea( implode( "\n", $settings->recipients ) ); ?></textarea>
							<p class="description"><?php esc_html_e( 'One email address per line. Leave empty to send no email.', 'sister-sync' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Send the email', 'sister-sync' ); ?></th>
						<td>
							<fieldset>
								<legend class="screen-reader-text"><?php esc_html_e( 'Send the email', 'sister-sync' ); ?></legend>
								<label><input type="radio" name="notify" value="<?php echo esc_attr( CheckSettings::NOTIFY_NEW ); ?>" <?php checked( $settings->notify, CheckSettings::NOTIFY_NEW ); ?>> <?php esc_html_e( 'Only when a scan finds something new', 'sister-sync' ); ?></label><br>
								<label><input type="radio" name="notify" value="<?php echo esc_attr( CheckSettings::NOTIFY_ALWAYS ); ?>" <?php checked( $settings->notify, CheckSettings::NOTIFY_ALWAYS ); ?>> <?php esc_html_e( 'After every scheduled scan', 'sister-sync' ); ?></label>
							</fieldset>
						</td>
					</tr>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Other options to scan', 'sister-sync' ); ?></h2>
			<p class="description"><?php esc_html_e( 'The site scan already covers ACF options pages, widgets, menus and Customizer settings. Some themes and plugins keep their own settings elsewhere, such as a theme options panel. List those option names here, one per line; * matches anything, for example mytheme_* or footer_settings.', 'sister-sync' ); ?></p>
			<textarea name="extra_options" rows="4" class="large-text code" spellcheck="false" placeholder="mytheme_options&#10;footer_*"><?php echo esc_textarea( implode( "\n", $settings->extraOptions ) ); ?></textarea>

			<?php submit_button( __( 'Save Check settings', 'sister-sync' ) ); ?>
		</form>
		<?php
	}

	public function save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to change Sister Sync settings.', 'sister-sync' ) );
		}
		check_admin_referer( self::ACTION );

		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- every value is validated by CheckSettings::fromArray().
		$postTypes = isset( $_POST['post_types'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['post_types'] ) ) : array();
		$settings  = CheckSettings::fromArray(
			array(
				'severity'     => isset( $_POST['severity'] ) ? (array) wp_unslash( $_POST['severity'] ) : array(),
				'blockPublish' => ! empty( $_POST['block_publish'] ),
				'schedule'     => isset( $_POST['schedule'] ) ? (string) wp_unslash( $_POST['schedule'] ) : CheckSettings::SCHEDULE_OFF,
				'recipients'   => isset( $_POST['recipients'] ) ? (string) wp_unslash( $_POST['recipients'] ) : '',
				'notify'       => isset( $_POST['notify'] ) ? (string) wp_unslash( $_POST['notify'] ) : CheckSettings::NOTIFY_NEW,
				'extraOptions' => isset( $_POST['extra_options'] ) ? (string) wp_unslash( $_POST['extra_options'] ) : '',
			)
		);
		// phpcs:enable

		$this->plugin->options()->savePostTypes( $postTypes );
		$this->plugin->options()->saveCheckSettings( self::withoutDefaults( $settings, $this->currentRole() ) );
		ScheduledScan::reschedule( $settings->schedule );

		wp_safe_redirect( SettingsPage::url( array( 'tab' => 'check', 'updated' => 1 ) ) );
		exit;
	}

	/**
	 * @return array<string, string>
	 */
	private static function severityLabels(): array {
		return array(
			Finding::ERROR        => __( 'Error', 'sister-sync' ),
			Finding::WARNING      => __( 'Warning', 'sister-sync' ),
			CheckSettings::IGNORE => __( 'Don\'t report', 'sister-sync' ),
		);
	}

	private function currentRole(): string {
		$identity = $this->plugin->identity();
		return $identity->isActive() && null !== $identity->site ? $identity->site->role : 'other';
	}

	/**
	 * Stores only the severities that differ from the default, so changing
	 * this site's role later still gives sensible defaults.
	 */
	private static function withoutDefaults( CheckSettings $settings, string $role ): CheckSettings {
		$severity = array_filter(
			$settings->severity,
			static fn( string $value, string $sisterRole ): bool => in_array( $sisterRole, Site::ROLES, true ) && CheckSettings::defaultSeverity( $role, $sisterRole ) !== $value,
			ARRAY_FILTER_USE_BOTH
		);
		return new CheckSettings( $severity, $settings->blockPublish, $settings->schedule, $settings->recipients, $settings->notify, $settings->extraOptions );
	}
}
