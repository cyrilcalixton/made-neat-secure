<?php
if ( ! defined( 'ABSPATH' ) ) exit;

final class MNS_User_Switch {

	private static ?MNS_User_Switch $instance = null;

	public static function instance(): MNS_User_Switch {
		if ( null === self::$instance ) self::$instance = new self();
		return self::$instance;
	}

	private function __construct() {
		// User switching: row action + endpoints + UI
		add_filter( 'user_row_actions', array( $this, 'add_switch_to_user_row_action' ), 10, 2 );

		add_action( 'admin_post_mns_switch_to_user', array( $this, 'handle_switch_to_user' ) );
		add_action( 'admin_post_mns_switch_back', array( $this, 'handle_switch_back' ) );

		add_action( 'admin_bar_menu', array( $this, 'admin_bar_switch_back' ), 999 );

		// “Every page” switch-back visibility: admin notice + frontend footer link
		add_action( 'admin_notices', array( $this, 'admin_notice_switch_back' ) );
		add_action( 'wp_footer', array( $this, 'frontend_switch_back_link' ), 999 );
		add_action( 'admin_footer', array( $this, 'admin_footer_switch_back_link' ), 999 );
	}

	private function is_switched_session(): bool {
		if ( ! is_user_logged_in() ) return false;
		$user_id = get_current_user_id();
		$from_id = (int) get_user_meta( $user_id, MNS_META_SWITCHED_FROM, true );
		return $from_id > 0;
	}

	private function switched_from_admin_id(): int {
		if ( ! $this->is_switched_session() ) return 0;
		return (int) get_user_meta( get_current_user_id(), MNS_META_SWITCHED_FROM, true );
	}

	public function add_switch_to_user_row_action( $actions, $user_object ) {
		if ( ! current_user_can( 'manage_options' ) ) return $actions;
		if ( ! MNS_Update_Control::instance()->user_switching_enabled() ) return $actions;
		if ( $this->is_switched_session() ) return $actions;

		$target_id = (int) $user_object->ID;
		if ( $target_id === get_current_user_id() ) return $actions;

		$url = wp_nonce_url(
			admin_url( 'admin-post.php?action=mns_switch_to_user&user_id=' . $target_id ),
			'mns_switch_to_user_' . $target_id
		);

		$actions['mns_switch_to'] = '<a href="' . esc_url( $url ) . '">Switch To</a>';
		return $actions;
	}

	public function handle_switch_to_user(): void {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Not allowed', 403 );
		if ( ! MNS_Update_Control::instance()->user_switching_enabled() ) wp_die( 'Not allowed', 403 );

		$target_id = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0;
		if ( ! $target_id ) wp_die( 'Missing user', 400 );

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

		wp_set_current_user( $target_id );
		wp_set_auth_cookie( $target_id, true );

		wp_safe_redirect( admin_url() );
		exit;
	}

	public function handle_switch_back(): void {
		if ( ! is_user_logged_in() ) wp_die( 'Not allowed', 403 );

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

		wp_set_current_user( $admin_id );
		wp_set_auth_cookie( $admin_id, true );

		wp_safe_redirect( admin_url() );
		exit;
	}

	private function switch_back_url(): string {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=mns_switch_back' ),
			'mns_switch_back'
		);
	}

	public function admin_bar_switch_back( $wp_admin_bar ): void {
		if ( ! is_object( $wp_admin_bar ) ) return;
		if ( ! $this->is_switched_session() ) return;

		$admin_id = $this->switched_from_admin_id();
		$admin    = $admin_id ? get_user_by( 'id', $admin_id ) : null;
		$name     = $admin ? $admin->user_login : 'admin';

		$wp_admin_bar->add_node( array(
			'id'    => 'mns_switch_back',
			'title' => 'Switch back to ' . esc_html( $name ),
			'href'  => $this->switch_back_url(),
		) );
	}

	public function admin_notice_switch_back(): void {
		if ( ! $this->is_switched_session() ) return;

		$admin_id = $this->switched_from_admin_id();
		$admin    = $admin_id ? get_user_by( 'id', $admin_id ) : null;
		$name     = $admin ? $admin->user_login : 'admin';

		echo '<div class="notice notice-warning" style="border-left-color:#d63638;">';
		echo '<p><strong>You are currently switched into another user.</strong> <a href="' . esc_url( $this->switch_back_url() ) . '">Switch back to ' . esc_html( $name ) . '</a></p>';
		echo '</div>';
	}

	private function output_fixed_switch_back_link(): void {
		if ( ! $this->is_switched_session() ) return;

		$admin_id = $this->switched_from_admin_id();
		$admin    = $admin_id ? get_user_by( 'id', $admin_id ) : null;
		$name     = $admin ? $admin->user_login : 'admin';

		$url = $this->switch_back_url();

		echo '<div id="mns-switchback" style="
			position:fixed;left:14px;bottom:14px;z-index:999999;
			background:rgba(29,35,39,.95);color:#fff;
			padding:10px 12px;border-radius:10px;
			font:600 13px/1.2 -apple-system,BlinkMacSystemFont,Segoe UI,Roboto,Helvetica,Arial,sans-serif;
			box-shadow:0 10px 22px rgba(0,0,0,.25);
		">';
		echo '<a href="' . esc_url( $url ) . '" style="color:#fff;text-decoration:none;">Switch back to ' . esc_html( $name ) . '</a>';
		echo '</div>';
	}

	public function frontend_switch_back_link(): void {
		$this->output_fixed_switch_back_link();
	}

	public function admin_footer_switch_back_link(): void {
		$this->output_fixed_switch_back_link();
	}
}
