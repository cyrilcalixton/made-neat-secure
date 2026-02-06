<?php
if ( ! defined( 'ABSPATH' ) ) exit;

final class MNS_Update_Control {

	private static ?MNS_Update_Control $instance = null;

	public static function instance(): MNS_Update_Control {
		if ( null === self::$instance ) self::$instance = new self();
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_init', array( $this, 'handle_save' ) );

		// Manual actions
		add_action( 'admin_post_mns_check_updates', array( $this, 'handle_check_updates' ) );
		add_action( 'admin_post_mns_update_self', array( $this, 'handle_update_self' ) );
	}

	/* ========= Settings (kept compatible) ========= */

	private function defaults(): array {
		return array(
			'hide_updates'          => true,
			'hide_update_numbers'   => true,
			'hide_editor'           => true,
			'enable_user_switching' => true,
			'excluded_users'        => 'ade, madeneat',
		);
	}

	public function get_settings(): array {
		$saved = get_option( MNS_OPTION_KEY, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), $this->defaults() );
	}

	private function parse_excluded_users( $raw ): array {
		$raw = (string) $raw;
		$raw = str_replace( array( "\r\n", "\r" ), "\n", $raw );
		$raw = str_replace( ",", "\n", $raw );

		$parts = array_filter( array_map( 'trim', explode( "\n", $raw ) ) );
		$parts = array_map( 'strtolower', $parts );

		return array_values( array_unique( $parts ) );
	}

	public function current_user_is_excluded(): bool {
		if ( ! is_user_logged_in() ) return false;

		$settings = $this->get_settings();
		$list     = $this->parse_excluded_users( $settings['excluded_users'] );

		if ( empty( $list ) ) return false;

		$user = wp_get_current_user();

		$user_id   = (string) $user->ID;
		$username  = strtolower( (string) $user->user_login );
		$useremail = strtolower( (string) $user->user_email );

		foreach ( $list as $item ) {
			if ( $item === $user_id || $item === $username || $item === $useremail ) {
				return true;
			}
		}

		return false;
	}

	public function user_switching_enabled(): bool {
		$settings = $this->get_settings();
		return ! empty( $settings['enable_user_switching'] );
	}

	/* ========= Render ========= */

	public function render(): void {
		$settings = $this->get_settings();
		?>
		<p style="color:#646970;max-width:980px;">
			Control WordPress update visibility and editor access. Users listed in “Excluded Users” will not be affected by these restrictions.
		</p>

		<form method="post" action="">
			<?php wp_nonce_field( 'mns_save_settings', 'mns_nonce' ); ?>
			<input type="hidden" name="mns_action" value="save_settings" />

			<table class="form-table" style="max-width:980px;">
				<tbody>

					<tr>
						<th scope="row">Hide Updates</th>
						<td>
							<label>
								<input type="checkbox" name="hide_updates" value="1" <?php checked( ! empty( $settings['hide_updates'] ) ); ?> />
								Hide all WordPress, plugin, and theme update notifications
							</label>
							<p class="description">When enabled, users will not see update notifications in the admin area.</p>
						</td>
					</tr>

					<tr>
						<th scope="row">Hide Update Numbers</th>
						<td>
							<label>
								<input type="checkbox" name="hide_update_numbers" value="1" <?php checked( ! empty( $settings['hide_update_numbers'] ) ); ?> />
								Hide update count numbers (badges)
							</label>
							<p class="description">When enabled, update count badges will be hidden from the admin menu.</p>
						</td>
					</tr>

					<tr>
						<th scope="row">Hide Editor</th>
						<td>
							<label>
								<input type="checkbox" name="hide_editor" value="1" <?php checked( ! empty( $settings['hide_editor'] ) ); ?> />
								Hide theme and plugin file editor
							</label>
							<p class="description">When enabled, the theme editor and plugin editor will be removed from the admin menu.</p>
						</td>
					</tr>

					<tr>
						<th scope="row">Enable User Switching for testing</th>
						<td>
							<label>
								<input type="checkbox" name="enable_user_switching" value="1" <?php checked( ! empty( $settings['enable_user_switching'] ) ); ?> />
								Enable user switching
							</label>
							<p class="description">Allow administrators to switch into another user account to test permissions and visibility.</p>
						</td>
					</tr>

					<tr>
						<th scope="row">Excluded Users</th>
						<td>
							<textarea name="excluded_users" rows="6" style="width:100%;max-width:640px;"><?php echo esc_textarea( $settings['excluded_users'] ); ?></textarea>
							<p class="description" style="max-width:980px;">
								Enter user IDs, usernames, or email addresses (one per line or comma-separated) that should be excluded from these restrictions.
								These users will still see updates and have access to the editor.
							</p>
						</td>
					</tr>

				</tbody>
			</table>

			<p><?php submit_button( 'Save Changes', 'primary', 'submit', false ); ?></p>
		</form>

		<?php $this->render_update_status_sentence(); ?>
		<?php
	}

	private function render_update_status_sentence(): void {
		$installed    = MNS_VERSION;
		$plugin_file  = plugin_basename( MNS_FILE );

		// read update transient
		$updates = get_site_transient( 'update_plugins' );

		$latest     = $installed;
		$has_update = false;

		if ( is_object( $updates ) && ! empty( $updates->response ) && isset( $updates->response[ $plugin_file ] ) ) {
			$has_update = true;
			$latest = $updates->response[ $plugin_file ]->new_version ?? $installed;
		}

		$installed_style = $has_update ? 'color:#d63638;font-weight:700;' : '';
		$latest_style    = $has_update ? 'color:#1e8e3e;font-weight:700;' : '';

		$check_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=mns_check_updates' ),
			'mns_check_updates'
		);

		$update_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=mns_update_self' ),
			'mns_update_self'
		);

		echo '<div style="margin-top:18px;max-width:980px;">';

		// EXACT copy block you provided (format preserved)
		echo '<p style="margin:0 0 8px 0;">';
		echo '<strong>Made Neat – Secure</strong><br>';
		echo 'A curated security and maintenance layer for WordPress. Keeps sites tidy, reduces update distractions, and enables controlled administrator access without compromising safety.<br><br>';

		echo 'Installed <span style="' . esc_attr( $installed_style ) . '">v' . esc_html( $installed ) . '</span> &bull; ';
		echo 'Latest <span style="' . esc_attr( $latest_style ) . '">v' . esc_html( $latest ) . '</span>';

		if ( $has_update ) {
			echo ' <span style="color:#646970;">(update available!)</span>';
		}
		echo '<br>';

		echo '<a class="button" href="' . esc_url( $check_url ) . '">Check for updates</a> ';

		if ( $has_update ) {
			echo '<a class="button button-primary" style="background:#d63638;border-color:#d63638;" href="' . esc_url( $update_url ) . '">Update now!</a>';
		} else {
			echo '<a class="button button-primary" disabled="disabled" style="opacity:.5;pointer-events:none;" href="#">Update now!</a>';
		}

		echo '</p>';
		echo '</div>';
	}

	/* ========= Save settings ========= */

	public function handle_save(): void {
		if ( ! is_admin() ) return;

		if ( empty( $_POST['mns_action'] ) || $_POST['mns_action'] !== 'save_settings' ) return;

		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Not allowed', 403 );

		if ( empty( $_POST['mns_nonce'] ) || ! wp_verify_nonce( $_POST['mns_nonce'], 'mns_save_settings' ) ) {
			wp_die( 'Invalid nonce', 403 );
		}

		$new = array(
			'hide_updates'          => ! empty( $_POST['hide_updates'] ),
			'hide_update_numbers'   => ! empty( $_POST['hide_update_numbers'] ),
			'hide_editor'           => ! empty( $_POST['hide_editor'] ),
			'enable_user_switching' => ! empty( $_POST['enable_user_switching'] ),
			'excluded_users'        => isset( $_POST['excluded_users'] ) ? wp_unslash( $_POST['excluded_users'] ) : '',
		);

		update_option( MNS_OPTION_KEY, $new, false );

		wp_safe_redirect( admin_url( 'admin.php?page=mns-secure&tab=update-control' ) );
		exit;
	}

	/* ========= Manual update actions ========= */

	public function handle_check_updates(): void {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Not allowed', 403 );
		if ( ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'mns_check_updates' ) ) wp_die( 'Invalid nonce', 403 );

		if ( function_exists( 'wp_clean_update_cache' ) ) {
			wp_clean_update_cache();
		}
		if ( function_exists( 'wp_update_plugins' ) ) {
			wp_update_plugins();
		}

		wp_safe_redirect( admin_url( 'admin.php?page=mns-secure&tab=update-control&mns_notice=updates_checked' ) );
		exit;
	}

	public function handle_update_self(): void {
		if ( ! current_user_can( 'update_plugins' ) ) wp_die( 'Not allowed', 403 );
		if ( ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'mns_update_self' ) ) wp_die( 'Invalid nonce', 403 );

		require_once ABSPATH . 'wp-admin/includes/update.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		// Refresh update data first
		wp_update_plugins();

		$plugin_file = plugin_basename( MNS_FILE );

		$skin     = new Automatic_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );

		$result = $upgrader->upgrade( $plugin_file );

		$notice = ( $result === true ) ? 'updated' : 'update_failed';

		wp_safe_redirect( admin_url( 'admin.php?page=mns-secure&tab=update-control&mns_notice=' . $notice ) );
		exit;
	}
}
