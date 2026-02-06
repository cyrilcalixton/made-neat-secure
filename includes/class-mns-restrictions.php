<?php
if ( ! defined( 'ABSPATH' ) ) exit;

final class MNS_Restrictions {

	private static ?MNS_Restrictions $instance = null;

	public static function instance(): MNS_Restrictions {
		if ( null === self::$instance ) self::$instance = new self();
		return self::$instance;
	}

	private function __construct() {
		// Apply restrictions early (same as MU)
		add_action( 'init', array( $this, 'apply_restrictions' ), 1 );
	}

	public function apply_restrictions(): void {
		$settings = MNS_Update_Control::instance()->get_settings();

		// Updates controlled for EVERYONE if enabled (no exclusions)
		if ( ! empty( $settings['hide_updates'] ) ) {
			$this->hide_updates_everywhere();
		}

		// Excluded users bypass other restrictions (but NOT update control)
		if ( MNS_Update_Control::instance()->current_user_is_excluded() ) return;

		if ( ! empty( $settings['hide_update_numbers'] ) ) $this->hide_update_badges();
		if ( ! empty( $settings['hide_editor'] ) ) $this->disable_file_editors();
	}

	private function hide_updates_everywhere(): void {

		// Disable ALL automatic updates (but still allow update checks)
		add_filter( 'automatic_updater_disabled', '__return_true' );

		add_filter( 'auto_update_core', '__return_false' );
		add_filter( 'auto_update_plugin', '__return_false' );
		add_filter( 'auto_update_theme', '__return_false' );
		add_filter( 'auto_update_translation', '__return_false' );

		add_filter( 'allow_minor_auto_core_updates', '__return_false' );
		add_filter( 'allow_major_auto_core_updates', '__return_false' );
		add_filter( 'allow_dev_auto_core_updates', '__return_false' );

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
				wp_die( 'Updates are managed centrally for security reasons.', 403 );
			}
		}, 1 );

		// Remove admin bar updates bubble
		add_action( 'admin_bar_menu', function( $wp_admin_bar ) {
			if ( is_object( $wp_admin_bar ) ) $wp_admin_bar->remove_node( 'updates' );
		}, 999 );
	}

	private function hide_update_badges(): void {
		add_action( 'admin_head', function() {
			echo '<style>
				.update-plugins,
				#wp-admin-bar-updates { display:none !important; }
			</style>';
		}, 999 );
	}

	private function disable_file_editors(): void {
		if ( ! defined( 'DISALLOW_FILE_EDIT' ) ) define( 'DISALLOW_FILE_EDIT', true );

		add_action( 'admin_menu', function() {
			remove_submenu_page( 'themes.php', 'theme-editor.php' );
			remove_submenu_page( 'plugins.php', 'plugin-editor.php' );
		}, 999 );

		add_action( 'admin_init', function() {
			if ( empty( $GLOBALS['pagenow'] ) ) return;
			if ( in_array( $GLOBALS['pagenow'], array( 'theme-editor.php', 'plugin-editor.php' ), true ) ) {
				wp_die( 'File editing is disabled for security reasons.', 403 );
			}
		}, 1 );
	}
}
