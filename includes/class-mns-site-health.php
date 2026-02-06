<?php
if ( ! defined( 'ABSPATH' ) ) exit;

final class MNS_Site_Health {

	private static ?MNS_Site_Health $instance = null;

	public static function instance(): MNS_Site_Health {
		if ( null === self::$instance ) self::$instance = new self();
		return self::$instance;
	}

	private function __construct() {}

	private function active_health_subtab(): string {
		$sub = isset( $_GET['subtab'] ) ? sanitize_key( $_GET['subtab'] ) : 'plugins';
		return in_array( $sub, array( 'plugins', 'themes', 'core' ), true ) ? $sub : 'plugins';
	}

	public function render(): void {
		$sub  = $this->active_health_subtab();
		$base = admin_url( 'admin.php?page=mns-secure&tab=site-health' );

		echo '<h2 class="nav-tab-wrapper" style="margin-top:10px;">';
		echo '<a class="nav-tab ' . ( 'plugins' === $sub ? 'nav-tab-active' : '' ) . '" href="' . esc_url( $base . '&subtab=plugins' ) . '">Plugins</a>';
		echo '<a class="nav-tab ' . ( 'themes' === $sub ? 'nav-tab-active' : '' ) . '" href="' . esc_url( $base . '&subtab=themes' ) . '">Themes</a>';
		echo '<a class="nav-tab ' . ( 'core' === $sub ? 'nav-tab-active' : '' ) . '" href="' . esc_url( $base . '&subtab=core' ) . '">Core &amp; Translations</a>';
		echo '</h2>';

		if ( 'themes' === $sub ) {
			$this->render_themes();
		} elseif ( 'core' === $sub ) {
			$this->render_core();
		} else {
			$this->render_plugins();
		}
	}

	private function render_plugins(): void {
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

	private function render_themes(): void {
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

	private function render_core(): void {
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
		echo '<table class="widefat striped"><tbody>';

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
}
