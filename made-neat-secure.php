<?php
/**
 * Plugin Name: Made Neat – Secure
 * Plugin URI:  https://madeneat.com.au/
 * Description: A curated security and maintenance layer for WordPress. Keeps sites tidy, reduces update distractions, and enables controlled administrator access without compromising safety.
 * Version:     1.0.0
 * Author:      Made Neat
 * Author URI:  https://madeneat.com.au/
 * License:     GPLv2 or later
 * Text Domain: made-neat-secure
 */

// --- GitHub Updates (Plugin Update Checker) ---
$puc_path = __DIR__ . '/vendor/plugin-update-checker/plugin-update-checker.php';

if ( file_exists( $puc_path ) ) {
	require_once $puc_path;
}

add_action('plugins_loaded', function () {

	if ( ! class_exists('\YahnisElsts\PluginUpdateChecker\v5\PucFactory') ) {
		return;
	}

	$updateChecker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
		'https://github.com/cyrilcalixton/made-neat-secure/',
		__FILE__,
		'made-neat-secure'
	);

	$updateChecker->setBranch('main');
	$updateChecker->getVcsApi()->enableReleaseAssets();

});

// NOW your class starts
final class Made_Neat_Secure {

}

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MNS_VERSION', '1.0.0' );
define( 'MNS_FILE', __FILE__ );

define( 'MNS_OPTION_KEY', 'mns_settings_v1' );

define( 'MNS_META_SWITCHED_FROM', 'mns_switched_from_admin_id' );
define( 'MNS_META_SWITCHED_AT', 'mns_switched_at' );

define( 'MNS_DB_VERSION', '1.0.0' );
define( 'MNS_LOG_TABLE', 'mns_secure_logs' );
define( 'MNS_LOG_RETENTION_DAYS', 30 );

final class Made_Neat_Secure {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Activation hook entry point.
	 */
	public static function activate() {
		$self = self::instance();
		$self->install_logs_table();
		$self->schedule_log_cleanup();
	}

	// --- GitHub Updates (Plugin Update Checker) ---
	$puc_path = __DIR__ . '/vendor/plugin-update-checker/plugin-update-checker.php';

	if ( file_exists( $puc_path ) ) {
		require_once $puc_path;
	}


	use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

	$updateChecker = PucFactory::buildUpdateChecker(
    'https://github.com/cyrilcalixton/made-neat-secure/',
    __FILE__,
    'made-neat-secure'
	);

	// Tell PUC to use GitHub Releases assets.
	$updateChecker->getVcsApi()->enableReleaseAssets();


	/**
	 * Deactivation hook entry point.
	 */
	public static function deactivate() {
		$timestamp = wp_next_scheduled( 'mns_cleanup_logs_daily' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'mns_cleanup_logs_daily' );
		}
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'handle_save' ) );

		// Apply restrictions early
		add_action( 'init', array( $this, 'apply_restrictions' ), 1 );

		// User switching: row action + endpoints + UI
		add_filter( 'user_row_actions', array( $this, 'add_switch_to_user_row_action' ), 10, 2 );

		add_action( 'admin_post_mns_switch_to_user', array( $this, 'handle_switch_to_user' ) );
		add_action( 'admin_post_mns_switch_back', array( $this, 'handle_switch_back' ) );

		add_action( 'admin_bar_menu', array( $this, 'admin_bar_switch_back' ), 999 );

		// “Every page” switch-back visibility: admin notice + frontend footer link
		add_action( 'admin_notices', array( $this, 'admin_notice_switch_back' ) );
		// Update Control actions (manual)
		add_action( 'admin_post_mns_check_updates', array( $this, 'handle_check_updates' ) );
		add_action( 'admin_post_mns_update_self', array( $this, 'handle_update_self' ) );

		// Logs cleanup cron
		add_action( 'mns_cleanup_logs_daily', array( $this, 'cleanup_logs_daily' ) );

		/**
		 * IMPORTANT SAFETY CHECK:
		 * If someone updates the plugin without deactivating/reactivating,
		 * the activation hook will NOT run.
		 *
		 * So we ensure the DB exists and schema is current here.
		 */
		add_action( 'admin_init', array( $this, 'maybe_install_or_upgrade_logs_table' ), 1 );
	}

	/* ============================================================
	 * LOGS (DB + HELPERS)
	 * ============================================================ */

	private function logs_table_name() {
		global $wpdb;
		return $wpdb->prefix . MNS_LOG_TABLE;
	}

	private function logs_table_exists() {
		global $wpdb;

		$table = $this->logs_table_name();

		$exists = $wpdb->get_var(
			$wpdb->prepare(
				"SHOW TABLES LIKE %s",
				$table
			)
		);

		return ( $exists === $table );
	}

	public function maybe_install_or_upgrade_logs_table() {
		// If table missing OR schema version changed, install/upgrade.
		$db_version = get_option( 'mns_db_version', '' );

		if ( $db_version !== MNS_DB_VERSION || ! $this->logs_table_exists() ) {
			$this->install_logs_table();
			$this->schedule_log_cleanup();
		}
	}

	private function install_logs_table() {
		global $wpdb;

		$table = $this->logs_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$sql = "CREATE TABLE {$table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			created_at DATETIME NOT NULL,
			severity VARCHAR(20) NOT NULL,
			event VARCHAR(80) NOT NULL,
			message TEXT NOT NULL,
			context LONGTEXT NULL,
			user_id BIGINT(20) UNSIGNED NULL,
			site_id BIGINT(20) UNSIGNED NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY severity (severity),
			KEY event (event),
			KEY user_id (user_id),
			KEY site_id (site_id)
		) {$charset_collate};";

		dbDelta( $sql );

		update_option( 'mns_db_version', MNS_DB_VERSION, false );
	}

	private function schedule_log_cleanup() {
		if ( ! wp_next_scheduled( 'mns_cleanup_logs_daily' ) ) {
			wp_schedule_event( time() + 3600, 'daily', 'mns_cleanup_logs_daily' );
		}
	}

	public function cleanup_logs_daily() {
		global $wpdb;

		$table = $this->logs_table_name();
		$days  = (int) MNS_LOG_RETENTION_DAYS;

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table}
				 WHERE created_at < (UTC_TIMESTAMP() - INTERVAL %d DAY)",
				$days
			)
		);
	}

	private function log( $event, $message, $context = array(), $severity = 'info' ) {
		global $wpdb;

		// If table is missing for any reason, do not fatal.
		if ( ! $this->logs_table_exists() ) {
			return;
		}

		$table = $this->logs_table_name();

		$severity = strtolower( trim( (string) $severity ) );
		if ( ! in_array( $severity, array( 'info', 'warning', 'error' ), true ) ) {
			$severity = 'info';
		}

		$event   = sanitize_key( (string) $event );
		$message = wp_strip_all_tags( (string) $message );

		$user_id = get_current_user_id();
		$site_id = function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : null;

		$context_json = null;
		if ( ! empty( $context ) && is_array( $context ) ) {
			$context_json = wp_json_encode( $context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		}

		$wpdb->insert(
			$table,
			array(
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
				'severity'   => $severity,
				'event'      => $event,
				'message'    => $message,
				'context'    => $context_json,
				'user_id'    => $user_id ?: null,
				'site_id'    => $site_id ?: null,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%d' )
		);
	}

	/* ============================================================
	 * SETTINGS
	 * ============================================================ */

	private function defaults() {
		return array(
			'hide_updates'          => true,
			'hide_update_numbers'   => true,
			'hide_editor'           => true,
			'enable_user_switching' => true,
			'excluded_users'        => "ade, madeneat",
		);
	}

	private function get_settings() {
		$saved = get_option( MNS_OPTION_KEY, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), $this->defaults() );
	}

	private function parse_excluded_users( $raw ) {
		$raw = (string) $raw;
		$raw = str_replace( array( "\r\n", "\r" ), "\n", $raw );
		$raw = str_replace( ",", "\n", $raw );

		$parts = array_filter( array_map( 'trim', explode( "\n", $raw ) ) );
		$parts = array_map( 'strtolower', $parts );

		return array_values( array_unique( $parts ) );
	}

	private function current_user_is_excluded() {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		$settings = $this->get_settings();
		$list     = $this->parse_excluded_users( $settings['excluded_users'] );

		if ( empty( $list ) ) {
			return false;
		}

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

	private function user_switching_enabled() {
		$settings = $this->get_settings();
		return ! empty( $settings['enable_user_switching'] );
	}

	/* ============================================================
	 * ADMIN MENU + ICON
	 * ============================================================ */

	public function register_admin_menu() {
		$icon = $this->get_menu_icon();

		add_menu_page(
			'Made Neat – Secure',
			'Made Neat – Secure',
			'manage_options',
			'mns-secure',
			array( $this, 'render_admin_page' ),
			$icon,
			80
		);
	}

	private function get_menu_icon() {
		$icon = 'dashicons-shield';

		$logo_path = trailingslashit( get_template_directory() ) . 'admin/images/madeneat-logo.svg';
		if ( file_exists( $logo_path ) && is_readable( $logo_path ) ) {
			$svg = file_get_contents( $logo_path );
			if ( is_string( $svg ) && strlen( $svg ) > 10 ) {
				$icon = 'data:image/svg+xml;base64,' . base64_encode( $svg );
			}
		}

		return $icon;
	}

	/* ============================================================
	 * ADMIN UI (TABS)
	 * ============================================================ */

	private function active_main_tab() {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'site-health';
		return in_array( $tab, array( 'site-health', 'update-control', 'logs' ), true ) ? $tab : 'site-health';
	}

	private function active_health_subtab() {
		$sub = isset( $_GET['subtab'] ) ? sanitize_key( $_GET['subtab'] ) : 'plugins';
		return in_array( $sub, array( 'plugins', 'themes', 'core' ), true ) ? $sub : 'plugins';
	}

	public function render_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed', 403 );
		}

		$tab = $this->active_main_tab();

		echo '<div class="wrap">';
		echo '<h1>Made Neat – Secure</h1>';

		echo '<p style="color:#646970;margin-top:6px;max-width:980px;">A curated security and maintenance layer for WordPress. Keeps sites tidy, reduces update distractions, and enables controlled administrator access without compromising safety.</p>';

		// Top status line (no redundancy)
		$this->render_update_status_line_top();

		echo '<h2 class="nav-tab-wrapper" style="margin-top:14px;">';
		echo '<a class="nav-tab ' . ( 'site-health' === $tab ? 'nav-tab-active' : '' ) . '" href="' . esc_url( admin_url( 'admin.php?page=mns-secure&tab=site-health' ) ) . '">Site Health</a>';
		echo '<a class="nav-tab ' . ( 'update-control' === $tab ? 'nav-tab-active' : '' ) . '" href="' . esc_url( admin_url( 'admin.php?page=mns-secure&tab=update-control' ) ) . '">Update Control</a>';
		echo '<a class="nav-tab ' . ( 'logs' === $tab ? 'nav-tab-active' : '' ) . '" href="' . esc_url( admin_url( 'admin.php?page=mns-secure&tab=logs' ) ) . '">Logs</a>';
		echo '</h2>';

		if ( 'update-control' === $tab ) {
			$this->render_update_control();
		} elseif ( 'logs' === $tab ) {
			$this->render_logs();
		} else {
			$this->render_site_health();
		}

		echo '</div>';
	}

	/* ============================================================
	 * LOGS TAB UI
	 * ============================================================ */

	private function render_logs() {
		if ( ! current_user_can( 'manage_options' ) ) {
			echo '<p>Not allowed.</p>';
			return;
		}

		global $wpdb;
		$table = $this->logs_table_name();

		// If missing, show a real error instead of lying with an empty table.
		if ( ! $this->logs_table_exists() ) {
			echo '<div class="notice notice-error"><p><strong>Logs table is missing.</strong> Please deactivate + reactivate the plugin, or reload wp-admin once.</p></div>';
			return;
		}

		// Handle clear logs.
		if (
			isset( $_POST['mns_clear_logs'] )
			&& check_admin_referer( 'mns_clear_logs_action', 'mns_clear_logs_nonce' )
		) {
			$wpdb->query( "TRUNCATE TABLE {$table}" );

			// Log AFTER truncation.
			$this->log( 'logs_cleared', 'Logs were cleared.', array(), 'warning' );

			echo '<div class="notice notice-success is-dismissible"><p>Logs cleared.</p></div>';
		}

		$severity = isset( $_GET['severity'] ) ? sanitize_text_field( $_GET['severity'] ) : '';
		$event    = isset( $_GET['event'] ) ? sanitize_text_field( $_GET['event'] ) : '';

		$paged    = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$per_page = 20;
		$offset   = ( $paged - 1 ) * $per_page;

		$where = "WHERE 1=1";
		$args  = array();

		if ( in_array( $severity, array( 'info', 'warning', 'error' ), true ) ) {
			$where .= " AND severity = %s";
			$args[] = $severity;
		}

		if ( ! empty( $event ) ) {
			$where .= " AND event = %s";
			$args[] = sanitize_key( $event );
		}

		$count_sql = "SELECT COUNT(*) FROM {$table} {$where}";
		$total = (int) $wpdb->get_var( $args ? $wpdb->prepare( $count_sql, $args ) : $count_sql );

		$data_sql = "SELECT * FROM {$table} {$where} ORDER BY id DESC LIMIT %d OFFSET %d";
		$data_args = array_merge( $args, array( $per_page, $offset ) );

		$rows = $wpdb->get_results( $wpdb->prepare( $data_sql, $data_args ) );

		$events = $wpdb->get_col( "SELECT DISTINCT event FROM {$table} ORDER BY event ASC LIMIT 200" );

		$total_pages = max( 1, (int) ceil( $total / $per_page ) );
		?>
		<p style="color:#646970;max-width:980px;">
			Records key Made Neat – Secure actions (settings changes, update control events).
			Login/logout events are intentionally not recorded.
		</p>

		<form method="get" style="margin: 12px 0;">
			<input type="hidden" name="page" value="<?php echo esc_attr( $_GET['page'] ?? '' ); ?>">
			<input type="hidden" name="tab" value="logs">

			<label style="margin-right: 12px;">
				Severity:
				<select name="severity">
					<option value="">All</option>
					<option value="info" <?php selected( $severity, 'info' ); ?>>Info</option>
					<option value="warning" <?php selected( $severity, 'warning' ); ?>>Warning</option>
					<option value="error" <?php selected( $severity, 'error' ); ?>>Error</option>
				</select>
			</label>

			<label style="margin-right: 12px;">
				Event:
				<select name="event">
					<option value="">All</option>
					<?php foreach ( $events as $ev ) : ?>
						<option value="<?php echo esc_attr( $ev ); ?>" <?php selected( $event, $ev ); ?>>
							<?php echo esc_html( $ev ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</label>

			<button class="button button-primary">Filter</button>
		</form>

		<table class="widefat striped" style="max-width:1200px;">
			<thead>
				<tr>
					<th style="width:170px;">Date</th>
					<th style="width:90px;">Severity</th>
					<th style="width:180px;">Event</th>
					<th style="width:160px;">User</th>
					<th>Message</th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr><td colspan="5">No logs found.</td></tr>
				<?php else : ?>
					<?php foreach ( $rows as $row ) : ?>
						<?php
						$user_label = 'System';
						if ( ! empty( $row->user_id ) ) {
							$u = get_user_by( 'id', (int) $row->user_id );
							$user_label = $u ? $u->user_login : 'User #' . (int) $row->user_id;
						}
						?>
						<tr>
							<td><?php echo esc_html( get_date_from_gmt( $row->created_at, 'Y-m-d H:i' ) ); ?></td>
							<td>
								<span class="mns-badge mns-<?php echo esc_attr( $row->severity ); ?>">
									<?php echo esc_html( ucfirst( $row->severity ) ); ?>
								</span>
							</td>
							<td><?php echo esc_html( $row->event ); ?></td>
							<td><?php echo esc_html( $user_label ); ?></td>
							<td>
								<?php echo esc_html( $row->message ); ?>

								<?php if ( ! empty( $row->context ) ) : ?>
									<details style="margin-top:6px;">
										<summary>Details</summary>
										<pre style="white-space: pre-wrap; margin: 8px 0; background: #f6f7f7; padding: 10px; border-radius: 6px;"><?php
											echo esc_html( $row->context );
										?></pre>
									</details>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>

		<?php if ( $total_pages > 1 ) : ?>
			<div style="margin: 12px 0; display: flex; gap: 10px; align-items: center;">
				<?php $base_url = remove_query_arg( 'paged' ); ?>

				<?php if ( $paged > 1 ) : ?>
					<a class="button" href="<?php echo esc_url( add_query_arg( 'paged', $paged - 1, $base_url ) ); ?>">Previous</a>
				<?php endif; ?>

				<span>Page <?php echo (int) $paged; ?> of <?php echo (int) $total_pages; ?></span>

				<?php if ( $paged < $total_pages ) : ?>
					<a class="button" href="<?php echo esc_url( add_query_arg( 'paged', $paged + 1, $base_url ) ); ?>">Next</a>
				<?php endif; ?>
			</div>
		<?php endif; ?>

		<form method="post" style="margin-top: 16px;">
			<?php wp_nonce_field( 'mns_clear_logs_action', 'mns_clear_logs_nonce' ); ?>
			<button class="button button-secondary" name="mns_clear_logs" value="1"
				onclick="return confirm('Clear all logs? This cannot be undone.');">
				Clear Logs
			</button>
		</form>

		<style>
			.mns-badge {
				display:inline-block;
				padding:3px 10px;
				border-radius:999px;
				font-size:12px;
				font-weight:600;
				line-height:1.4;
			}
			.mns-info { background:#dbeafe; color:#1e40af; }
			.mns-warning { background:#fef3c7; color:#92400e; }
			.mns-error { background:#fee2e2; color:#991b1b; }
		</style>
		<?php
	}

	/* ============================================================
	 * UPDATE CONTROL TAB
	 * ============================================================ */

	private function render_update_control() {
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

			<p>
				<?php submit_button( 'Save Changes', 'primary', 'submit', false ); ?>
			</p>
		</form>
		<?php
	}

	private function render_update_status_line_top() {
		$installed = MNS_VERSION;

		$updates = get_site_transient( 'update_plugins' );
		$plugin_file = plugin_basename( MNS_FILE );

		$latest     = '';
		$has_update = false;

		if ( is_object( $updates ) && ! empty( $updates->response ) && isset( $updates->response[ $plugin_file ] ) ) {
			$has_update = true;
			$latest = $updates->response[ $plugin_file ]->new_version ?? '';
		}

		$check_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=mns_check_updates' ),
			'mns_check_updates'
		);

		$update_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=mns_update_self' ),
			'mns_update_self'
		);

		echo '<div style="margin:10px 0 16px 0;max-width:980px;">';

		echo '<p style="margin:0 0 6px 0;color:#646970;">';
		echo 'Installed <code>v' . esc_html( $installed ) . '</code>';

		if ( $has_update && $latest ) {
			echo ' &bull; Latest <strong style="color:#1e8e3e;">v' . esc_html( $latest ) . '</strong>';
			echo ' <span style="color:#646970;">(update available!)</span>';
		}

		echo '</p>';

		echo '<p style="margin:0;">';
		echo '<a href="' . esc_url( $check_url ) . '" style="text-decoration:underline;">Check for updates</a>';

		if ( $has_update ) {
			echo ' &nbsp;•&nbsp; ';
			echo '<a href="' . esc_url( $update_url ) . '" style="text-decoration:underline;color:#d63638;font-weight:700;">Update now!</a>';
		}

		echo '</p>';

		echo '</div>';
	}

	

	/* ============================================================
	 * SITE HEALTH TAB
	 * ============================================================ */

	private function render_site_health() {
		$sub = $this->active_health_subtab();

		$base = admin_url( 'admin.php?page=mns-secure&tab=site-health' );

		echo '<h2 class="nav-tab-wrapper" style="margin-top:10px;">';
		echo '<a class="nav-tab ' . ( 'plugins' === $sub ? 'nav-tab-active' : '' ) . '" href="' . esc_url( $base . '&subtab=plugins' ) . '">Plugins</a>';
		echo '<a class="nav-tab ' . ( 'themes' === $sub ? 'nav-tab-active' : '' ) . '" href="' . esc_url( $base . '&subtab=themes' ) . '">Themes</a>';
		echo '<a class="nav-tab ' . ( 'core' === $sub ? 'nav-tab-active' : '' ) . '" href="' . esc_url( $base . '&subtab=core' ) . '">Core &amp; Translations</a>';
		echo '</h2>';

		if ( 'themes' === $sub ) {
			$this->render_health_themes();
		} elseif ( 'core' === $sub ) {
			$this->render_health_core();
		} else {
			$this->render_health_plugins();
		}
	}

	private function render_health_plugins() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all_plugins = get_plugins();
		$active      = (array) get_option( 'active_plugins', array() );

		$updates     = get_site_transient( 'update_plugins' );
		$update_resp = ( is_object( $updates ) && ! empty( $updates->response ) ) ? (array) $updates->response : array();

		$rows = array();

		foreach ( $all_plugins as $file => $data ) {
			$is_active = in_array( $file, $active, true );
			$has_upd   = isset( $update_resp[ $file ] );

			$rows[] = array(
				'name'   => $data['Name'] ?? $file,
				'ver'    => $data['Version'] ?? '',
				'status' => $is_active ? 'Active' : 'Inactive',
				'upd'    => $has_upd ? 'Update Available' : '',
			);
		}

		$counts = array(
			'installed' => count( $rows ),
			'active'    => count( array_filter( $rows, fn($r) => $r['status'] === 'Active' ) ),
			'inactive'  => count( array_filter( $rows, fn($r) => $r['status'] === 'Inactive' ) ),
			'updates'   => count( array_filter( $rows, fn($r) => $r['upd'] === 'Update Available' ) ),
		);

		echo '<p style="color:#646970;margin-top:12px;">Installed: <strong>' . esc_html( $counts['installed'] ) . '</strong> · Active: <strong>' . esc_html( $counts['active'] ) . '</strong> · Inactive: <strong>' . esc_html( $counts['inactive'] ) . '</strong> · Update Available: <strong>' . esc_html( $counts['updates'] ) . '</strong></p>';

		echo '<table class="widefat striped" style="max-width:1200px;">';
		echo '<thead><tr><th>Plugin</th><th>Version</th><th>Status</th><th>Update Available</th></tr></thead><tbody>';

		foreach ( $rows as $r ) {
			$is_update = ( $r['upd'] === 'Update Available' );

			$version_style = $is_update
				? 'color:#d63638;font-weight:700;'
				: 'color:#1e8e3e;font-weight:700;';

			echo '<tr>';
			echo '<td><strong>' . esc_html( $r['name'] ) . '</strong></td>';
			echo '<td style="' . esc_attr( $version_style ) . '">' . esc_html( $r['ver'] ) . '</td>';
			echo '<td>' . esc_html( $r['status'] ) . '</td>';
			echo '<td>' . ( $is_update ? '<strong style="color:#d63638;">Update Available</strong>' : '' ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	private function render_health_themes() {
		$themes  = wp_get_themes();
		$active  = wp_get_theme();
		$updates = get_site_transient( 'update_themes' );
		$resp    = ( is_object( $updates ) && ! empty( $updates->response ) ) ? (array) $updates->response : array();

		$rows = array();

		foreach ( $themes as $slug => $theme_obj ) {
			$is_active = ( $active->get_stylesheet() === $slug );
			$has_upd   = isset( $resp[ $slug ] );

			$rows[] = array(
				'name'   => $theme_obj->get( 'Name' ),
				'ver'    => $theme_obj->get( 'Version' ),
				'status' => $is_active ? 'Active / In-use' : 'Inactive',
				'upd'    => $has_upd ? 'Update Available' : '',
			);
		}

		$counts = array(
			'installed' => count( $rows ),
			'active'    => count( array_filter( $rows, fn($r) => $r['status'] === 'Active / In-use' ) ),
			'inactive'  => count( array_filter( $rows, fn($r) => $r['status'] === 'Inactive' ) ),
			'updates'   => count( array_filter( $rows, fn($r) => $r['upd'] === 'Update Available' ) ),
		);

		echo '<p style="color:#646970;margin-top:12px;">Installed: <strong>' . esc_html( $counts['installed'] ) . '</strong> · Active / In-use: <strong>' . esc_html( $counts['active'] ) . '</strong> · Inactive: <strong>' . esc_html( $counts['inactive'] ) . '</strong> · Update Available: <strong>' . esc_html( $counts['updates'] ) . '</strong></p>';

		echo '<table class="widefat striped" style="max-width:1200px;">';
		echo '<thead><tr><th>Theme</th><th>Version</th><th>Status</th><th>Update Available</th></tr></thead><tbody>';

		foreach ( $rows as $r ) {
			$is_update = ( $r['upd'] === 'Update Available' );

			$version_style = $is_update
				? 'color:#d63638;font-weight:700;'
				: 'color:#1e8e3e;font-weight:700;';

			echo '<tr>';
			echo '<td><strong>' . esc_html( $r['name'] ) . '</strong></td>';
			echo '<td style="' . esc_attr( $version_style ) . '">' . esc_html( $r['ver'] ) . '</td>';
			echo '<td>' . esc_html( $r['status'] ) . '</td>';
			echo '<td>' . ( $is_update ? '<strong style="color:#d63638;">Update Available</strong>' : '' ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	private function render_health_core() {
		$current = get_bloginfo( 'version' );

		$core = get_site_transient( 'update_core' );
		$core_updates = array();

		if ( is_object( $core ) && ! empty( $core->updates ) && is_array( $core->updates ) ) {
			foreach ( $core->updates as $u ) {
				if ( is_object( $u ) && isset( $u->current ) && isset( $u->response ) && $u->response === 'upgrade' ) {
					$core_updates[] = $u->current;
				}
			}
		}

		$trans = get_site_transient( 'update_translations' );
		$translation_updates = 0;
		if ( is_object( $trans ) && ! empty( $trans->translations ) && is_array( $trans->translations ) ) {
			$translation_updates = count( $trans->translations );
		}

		echo '<div style="max-width:980px;margin-top:12px;">';
		echo '<table class="widefat striped">';
		echo '<tbody>';

		$core_has_update = ! empty( $core_updates );

		$core_style = $core_has_update
			? 'color:#d63638;font-weight:700;'
			: 'color:#1e8e3e;font-weight:700;';

		echo '<tr><th style="width:240px;">WordPress Core</th><td>';
		echo 'Current version: <strong style="' . esc_attr( $core_style ) . '">' . esc_html( $current ) . '</strong>';

		if ( $core_has_update ) {
			echo '<br>Update Available: <strong style="color:#d63638;">' . esc_html( implode( ', ', array_unique( $core_updates ) ) ) . '</strong>';
		} else {
			echo '<br>Update Available: <strong style="color:#1e8e3e;">None</strong>';
		}
		echo '</td></tr>';

		$trans_style = ( $translation_updates > 0 )
			? 'color:#d63638;font-weight:700;'
			: 'color:#1e8e3e;font-weight:700;';

		echo '<tr><th>Translations</th><td>';
		echo 'Update Available: <strong style="' . esc_attr( $trans_style ) . '">' . esc_html( $translation_updates > 0 ? (string) $translation_updates : 'None' ) . '</strong>';
		echo '</td></tr>';

		echo '</tbody></table>';
		echo '</div>';
	}


	/* ============================================================
	 * SAVE SETTINGS
	 * ============================================================ */

	public function handle_save() {
		if ( ! is_admin() ) {
			return;
		}

		if ( empty( $_POST['mns_action'] ) || $_POST['mns_action'] !== 'save_settings' ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed', 403 );
		}

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

		$this->log(
			'settings_changed',
			'Update Control settings updated.',
			array(
				'hide_updates'          => (bool) $new['hide_updates'],
				'hide_update_numbers'   => (bool) $new['hide_update_numbers'],
				'hide_editor'           => (bool) $new['hide_editor'],
				'enable_user_switching' => (bool) $new['enable_user_switching'],
			),
			'info'
		);

		wp_safe_redirect( admin_url( 'admin.php?page=mns-secure&tab=update-control' ) );
		exit;
	}

	/* ============================================================
	 * RESTRICTIONS (GLOBAL)
	 * ============================================================ */

	public function apply_restrictions() {
		$settings = $this->get_settings();

		// Updates must be controlled for EVERYONE if enabled (no exclusions, no roles, no switching)
		if ( ! empty( $settings['hide_updates'] ) ) {
			$this->hide_updates_everywhere();
		}

		// Excluded users bypass other restrictions (but NOT update control)
		if ( $this->current_user_is_excluded() ) {
			return;
		}

		if ( ! empty( $settings['hide_update_numbers'] ) ) {
			$this->hide_update_badges();
		}
		if ( ! empty( $settings['hide_editor'] ) ) {
			$this->disable_file_editors();
		}
	}

	private function hide_updates_everywhere() {

		/**
		 * Disable ALL automatic updates (but still allow update checks)
		 */
		add_filter( 'automatic_updater_disabled', '__return_true' );

		add_filter( 'auto_update_core', '__return_false' );
		add_filter( 'auto_update_plugin', '__return_false' );
		add_filter( 'auto_update_theme', '__return_false' );
		add_filter( 'auto_update_translation', '__return_false' );

		add_filter( 'allow_minor_auto_core_updates', '__return_false' );
		add_filter( 'allow_major_auto_core_updates', '__return_false' );
		add_filter( 'allow_dev_auto_core_updates', '__return_false' );

		/**
		 * IMPORTANT:
		 * We intentionally do NOT disable update checks or null update transients,
		 * because Site Health relies on them to show update availability details.
		 */

		// Hide update nags
		add_action( 'admin_init', function() {
			remove_action( 'admin_notices', 'update_nag', 3 );
			remove_action( 'network_admin_notices', 'update_nag', 3 );
			remove_action( 'user_admin_notices', 'update_nag', 3 );
		}, 1 );

		// Hide Updates menu + Update Core
		add_action( 'admin_menu', function() {
			remove_submenu_page( 'index.php', 'update-core.php' );
			remove_menu_page( 'update-core.php' );
		}, 999 );

		// Block direct access to update-core.php
		add_action( 'admin_init', function() {
			if ( ! empty( $GLOBALS['pagenow'] ) && $GLOBALS['pagenow'] === 'update-core.php' ) {
				Made_Neat_Secure::instance()->log(
					'update_core_blocked',
					'Direct access to update-core.php was blocked.',
					array(),
					'warning'
				);

				wp_die( 'Updates are managed centrally for security reasons.', 403 );
			}
		}, 1 );

		// Remove admin bar updates bubble
		add_action( 'admin_bar_menu', function( $wp_admin_bar ) {
			if ( is_object( $wp_admin_bar ) ) {
				$wp_admin_bar->remove_node( 'updates' );
			}
		}, 999 );

		// Hide update banners inside plugin + theme screens
		$this->hide_update_banners_on_plugins_and_themes();
	}

	private function hide_update_banners_on_plugins_and_themes() {

		add_action( 'admin_head', function() {

			if ( empty( $GLOBALS['pagenow'] ) ) {
				return;
			}

			// Plugins list + Themes list only
			if ( ! in_array( $GLOBALS['pagenow'], array( 'plugins.php', 'themes.php' ), true ) ) {
				return;
			}

			echo '<style>
				/* PLUGINS: inline update notice box */
				.plugins .notice.inline,
				.plugins .notice-warning.inline,
				.plugins .notice-info.inline,
				.plugins .notice-error.inline,
				.plugins .update-message,
				.plugins .plugin-update,
				.plugins .plugin-update .notice,
				.plugins .plugin-update .notice-warning,
				.plugins .plugin-update .notice-info {
					display:none !important;
				}

				/* PLUGINS: the extra update row itself */
				.plugins tr.plugin-update-tr,
				.plugins tr.plugin-update-tr td,
				.plugins tr.plugin-update-tr + tr,
				.plugins tr.plugin-update-tr + tr td {
					display:none !important;
				}

				/* THEMES: update notice blocks */
				.themes .notice.inline,
				.themes .notice-warning.inline,
				.themes .notice-info.inline,
				.themes .update-message,
				.theme .notice,
				.theme .update-message,
				.theme .theme-update-message {
					display:none !important;
				}
			</style>';
		}, 999 );
	}

	private function hide_update_badges() {
		add_action( 'admin_head', function() {
			echo '<style>
				.update-plugins,
				#wp-admin-bar-updates { display:none !important; }
			</style>';
		}, 999 );
	}

	private function disable_file_editors() {
		if ( ! defined( 'DISALLOW_FILE_EDIT' ) ) {
			define( 'DISALLOW_FILE_EDIT', true );
		}

		add_action( 'admin_menu', function() {
			remove_submenu_page( 'themes.php', 'theme-editor.php' );
			remove_submenu_page( 'plugins.php', 'plugin-editor.php' );
		}, 999 );

		add_action( 'admin_init', function() {
			if ( empty( $GLOBALS['pagenow'] ) ) {
				return;
			}
			if ( in_array( $GLOBALS['pagenow'], array( 'theme-editor.php', 'plugin-editor.php' ), true ) ) {
				wp_die( 'File editing is disabled for security reasons.', 403 );
			}
		}, 1 );
	}

	/* ============================================================
	 * UPDATE CONTROL ACTIONS
	 * ============================================================ */

	public function handle_check_updates() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed', 403 );
		}

		if ( ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'mns_check_updates' ) ) {
			wp_die( 'Invalid nonce', 403 );
		}

		if ( function_exists( 'wp_clean_update_cache' ) ) {
			wp_clean_update_cache();
		}

		if ( function_exists( 'wp_update_plugins' ) ) {
			wp_update_plugins();
		}

		$this->log( 'update_check', 'Manual update check triggered.', array(), 'info' );

		wp_safe_redirect( admin_url( 'admin.php?page=mns-secure&tab=update-control' ) );
		exit;
	}

	public function handle_update_self() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( 'Not allowed', 403 );
		}

		if ( ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'mns_update_self' ) ) {
			wp_die( 'Invalid nonce', 403 );
		}

		if ( ! function_exists( 'wp_update_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}
		if ( ! class_exists( 'Plugin_Upgrader' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		}

		$this->log( 'self_update', 'Plugin self-update triggered.', array(), 'warning' );

		// Refresh update data first
		wp_update_plugins();

		$plugin_file = plugin_basename( MNS_FILE );

		$skin = new Automatic_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );

		$upgrader->upgrade( $plugin_file );

		$this->log( 'self_update_complete', 'Plugin self-update finished.', array(), 'info' );

		wp_safe_redirect( admin_url( 'admin.php?page=mns-secure&tab=update-control' ) );
		exit;
	}

	/* ============================================================
	 * USER SWITCHING (ADMIN-ONLY, TOGGLE CONTROLLED)
	 * ============================================================ */

	private function is_switched_session() {
		if ( ! is_user_logged_in() ) {
			return false;
		}
		$user_id = get_current_user_id();
		$from_id = (int) get_user_meta( $user_id, MNS_META_SWITCHED_FROM, true );
		return $from_id > 0;
	}

	private function switched_from_admin_id() {
		if ( ! $this->is_switched_session() ) {
			return 0;
		}
		return (int) get_user_meta( get_current_user_id(), MNS_META_SWITCHED_FROM, true );
	}

	public function add_switch_to_user_row_action( $actions, $user_object ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return $actions;
		}
		if ( ! $this->user_switching_enabled() ) {
			return $actions;
		}
		if ( $this->is_switched_session() ) {
			return $actions;
		}

		$target_id = (int) $user_object->ID;
		if ( $target_id === get_current_user_id() ) {
			return $actions;
		}

		$url = wp_nonce_url(
			admin_url( 'admin-post.php?action=mns_switch_to_user&user_id=' . $target_id ),
			'mns_switch_to_user_' . $target_id
		);

		$actions['mns_switch_to'] = '<a href="' . esc_url( $url ) . '">Switch To</a>';
		return $actions;
	}

	public function handle_switch_to_user() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed', 403 );
		}
		if ( ! $this->user_switching_enabled() ) {
			wp_die( 'Not allowed', 403 );
		}

		$target_id = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0;
		if ( ! $target_id ) {
			wp_die( 'Missing user', 400 );
		}

		if ( ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'mns_switch_to_user_' . $target_id ) ) {
			wp_die( 'Invalid nonce', 403 );
		}

		$admin_id = get_current_user_id();
		if ( $target_id === $admin_id ) {
			wp_safe_redirect( admin_url( 'users.php' ) );
			exit;
		}

		if ( (int) get_user_meta( $target_id, MNS_META_SWITCHED_FROM, true ) > 0 ) {
			wp_die( 'That user is already in a switched session.', 409 );
		}

		update_user_meta( $target_id, MNS_META_SWITCHED_FROM, $admin_id );
		update_user_meta( $target_id, MNS_META_SWITCHED_AT, time() );

		$this->log(
			'user_switched',
			'Administrator switched into another user.',
			array(
				'from_admin_id' => $admin_id,
				'to_user_id'    => $target_id,
			),
			'warning'
		);

		wp_set_current_user( $target_id );
		wp_set_auth_cookie( $target_id, true );

		wp_safe_redirect( admin_url() );
		exit;
	}

	public function handle_switch_back() {
		if ( ! is_user_logged_in() ) {
			wp_die( 'Not allowed', 403 );
		}

		if ( ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'mns_switch_back' ) ) {
			wp_die( 'Invalid nonce', 403 );
		}

		if ( ! $this->is_switched_session() ) {
			wp_safe_redirect( admin_url() );
			exit;
		}

		$current_user_id = get_current_user_id();
		$admin_id        = $this->switched_from_admin_id();

		delete_user_meta( $current_user_id, MNS_META_SWITCHED_FROM );
		delete_user_meta( $current_user_id, MNS_META_SWITCHED_AT );

		if ( $admin_id <= 0 || ! get_user_by( 'id', $admin_id ) ) {
			wp_safe_redirect( admin_url() );
			exit;
		}

		$this->log(
			'user_switched_back',
			'Administrator switched back to original admin.',
			array(
				'from_user_id'  => $current_user_id,
				'to_admin_id'   => $admin_id,
			),
			'info'
		);

		wp_set_current_user( $admin_id );
		wp_set_auth_cookie( $admin_id, true );

		wp_safe_redirect( admin_url() );
		exit;
	}

	private function switch_back_url() {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=mns_switch_back' ),
			'mns_switch_back'
		);
	}


	public function admin_bar_switch_back( $wp_admin_bar ) {
		if ( ! is_object( $wp_admin_bar ) ) {
			return;
		}
		if ( ! $this->is_switched_session() ) {
			return;
		}

		$admin_id = $this->switched_from_admin_id();
		$admin    = $admin_id ? get_user_by( 'id', $admin_id ) : null;
		$name     = $admin ? $admin->user_login : 'admin';

		$wp_admin_bar->add_node( array(
			'id'    => 'mns_switch_back',
			'title' => '↩ Switch back to ' . esc_html( $name ),
			'href'  => $this->switch_back_url(),
			'meta'  => array(
				'class' => 'mns-switchback-top',
			),
		) );

		add_action( 'admin_head', function() {
			echo '<style>
				#wpadminbar .mns-switchback-top > a {
					background: #2563eb !important;
					color: #ffffff !important;
					border-radius: 999px !important;
					padding: 0 12px !important;
					margin-top: 3px !important;
					font-weight: 700 !important;
				}
				#wpadminbar .mns-switchback-top > a:hover {
					background: #1d4ed8 !important;
					color: #ffffff !important;
				}
			</style>';
		}, 999 );
	}



	public function admin_notice_switch_back() {
		if ( ! $this->is_switched_session() ) {
			return;
		}

		$admin_id = $this->switched_from_admin_id();
		$admin    = $admin_id ? get_user_by( 'id', $admin_id ) : null;
		$name     = $admin ? $admin->user_login : 'admin';

		echo '<div class="notice" style="
			border-left: 4px solid #2563eb;
			background: #eff6ff;
			padding: 12px 14px;
			border-radius: 10px;
			box-shadow: 0 1px 2px rgba(0,0,0,.04);
			max-width: 980px;
		">';

		echo '<p style="margin:0; font-size:14px; color:#0f172a;">';
		echo '<strong style="color:#0f172a;">Switched session active:</strong> You are currently acting as another user.';
		echo ' <a href="' . esc_url( $this->switch_back_url() ) . '" style="
			color:#2563eb;
			font-weight:700;
			text-decoration:none;
			margin-left:6px;
		">↩ Switch back to ' . esc_html( $name ) . '</a>';
		echo '</p>';

		echo '</div>';
	}




}

// Hooks that WordPress requires outside the class.
register_activation_hook( __FILE__, array( 'Made_Neat_Secure', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Made_Neat_Secure', 'deactivate' ) );

add_action( 'plugins_loaded', function() {
	Made_Neat_Secure::instance();
}, 0 );
