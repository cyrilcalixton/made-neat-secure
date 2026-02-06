<?php
if ( ! defined( 'ABSPATH' ) ) exit;

final class MNS_Admin {

	private static ?MNS_Admin $instance = null;

	public static function instance(): MNS_Admin {
		if ( null === self::$instance ) self::$instance = new self();
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
	}

	public function register_admin_menu(): void {
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

	private function get_menu_icon(): string {
		$icon = 'dashicons-shield';

		// Keep your original “theme SVG if exists” logic
		$logo_path = trailingslashit( get_template_directory() ) . 'admin/images/madeneat-logo.svg';
		if ( file_exists( $logo_path ) && is_readable( $logo_path ) ) {
			$svg = file_get_contents( $logo_path );
			if ( is_string( $svg ) && strlen( $svg ) > 10 ) {
				$icon = 'data:image/svg+xml;base64,' . base64_encode( $svg );
			}
		}

		return $icon;
	}

	private function active_main_tab(): string {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'site-health';
		return in_array( $tab, array( 'site-health', 'update-control' ), true ) ? $tab : 'site-health';
	}

	public function render_admin_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed', 403 );
		}

		$tab = $this->active_main_tab();

		echo '<div class="wrap">';
		echo '<h1>Made Neat – Secure</h1>';
		echo '<p style="color:#646970;margin-top:6px;max-width:980px;">A curated security and maintenance layer for WordPress. Keeps sites tidy, reduces update distractions, and enables controlled administrator access without compromising safety.</p>';
		echo '<p style="color:#646970;margin-top:6px;">Installed <code>v' . esc_html( MNS_VERSION ) . '</code></p>';

		echo '<h2 class="nav-tab-wrapper" style="margin-top:14px;">';
		echo '<a class="nav-tab ' . ( 'site-health' === $tab ? 'nav-tab-active' : '' ) . '" href="' . esc_url( admin_url( 'admin.php?page=mns-secure&tab=site-health' ) ) . '">Site Health</a>';
		echo '<a class="nav-tab ' . ( 'update-control' === $tab ? 'nav-tab-active' : '' ) . '" href="' . esc_url( admin_url( 'admin.php?page=mns-secure&tab=update-control' ) ) . '">Update Control</a>';
		echo '</h2>';

		// Notices for update actions
		if ( isset( $_GET['mns_notice'] ) ) {
			$notice = sanitize_key( $_GET['mns_notice'] );
			if ( $notice === 'updates_checked' ) {
				echo '<div class="notice notice-success"><p>Update check completed.</p></div>';
			} elseif ( $notice === 'updated' ) {
				echo '<div class="notice notice-success"><p>Plugin updated successfully.</p></div>';
			} elseif ( $notice === 'update_failed' ) {
				echo '<div class="notice notice-error"><p>Plugin update failed. Check filesystem permissions and try again.</p></div>';
			}
		}

		if ( 'update-control' === $tab ) {
			MNS_Update_Control::instance()->render();
		} else {
			MNS_Site_Health::instance()->render();
		}

		echo '</div>';
	}
}
