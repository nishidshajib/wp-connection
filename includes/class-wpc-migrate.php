<?php
/**
 * Replacing the older "WP Connections" plugin (folder wp-connections).
 *
 * The old and new plugins share class names, so only one may load at a time.
 * - While the old one is active, this copy loads only this class (dormant mode):
 *   it shows a "Replace" notice, and activating this copy switches over by itself.
 * - Once switched, it offers to remove the old files WITHOUT running the old
 *   uninstaller, because that would erase the settings, tokens and logs that this
 *   version keeps using (same option and table names).
 */

defined( 'ABSPATH' ) || exit;

class WPC_Migrate {

	const OLD = 'wp-connections/wp-connections.php';

	private static $file;
	private static $dormant = false;

	public static function init( $file, $dormant ) {
		self::$file    = $file;
		self::$dormant = $dormant;
		add_action( 'activated_plugin', array( __CLASS__, 'on_activate' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
		add_action( 'admin_post_wpc_replace_old', array( __CLASS__, 'replace' ) );
		add_filter( 'plugin_action_links_' . self::OLD, array( __CLASS__, 'old_row_links' ) );
	}

	public static function old_installed() {
		return file_exists( WP_PLUGIN_DIR . '/' . self::OLD );
	}

	public static function old_active() {
		return in_array( self::OLD, (array) get_option( 'active_plugins', array() ), true );
	}

	/** Activating WP Connection while the old copy is active switches over at once. */
	public static function on_activate( $plugin ) {
		if ( plugin_basename( self::$file ) === $plugin && self::old_active() ) {
			deactivate_plugins( self::OLD, true ); // Silent: keeps all data.
			set_transient( 'wpc_replaced_notice', 1, HOUR_IN_SECONDS );
		}
	}

	private static function replace_url() {
		return wp_nonce_url( admin_url( 'admin-post.php?action=wpc_replace_old' ), 'wpc_replace_old' );
	}

	public static function notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		if ( isset( $_GET['wpc_replaced'] ) && ! self::$dormant ) { // phpcs:ignore
			echo '1' === $_GET['wpc_replaced'] // phpcs:ignore
				? '<div class="notice notice-success is-dismissible"><p><strong>Done.</strong> WP Connection replaced the older WP Connections plugin and kept your settings, tokens and activity log. Update any AI app you connected before to the new MCP URL ending in <code>/wp-json/wp-connection/mcp</code>.</p></div>'
				: '<div class="notice notice-warning is-dismissible"><p>WP Connection is now active, but the old <code>wp-connections</code> folder could not be removed automatically. Delete that folder by FTP or your host’s file manager (not with Delete on the Plugins page).</p></div>';
			return;
		}
		if ( ! self::old_installed() ) {
			return;
		}
		if ( self::$dormant && self::old_active() ) {
			echo '<div class="notice notice-warning"><p><strong>WP Connection is ready to replace the older WP Connections plugin.</strong> It will take over your settings, tokens and activity log. Until you replace it, the old version keeps running and WP Connection stays switched off.</p><p><a class="button button-primary" href="' . esc_url( self::replace_url() ) . '">Replace old version</a></p></div>';
			return;
		}
		if ( ! self::$dormant && ! self::old_active() ) {
			$just = get_transient( 'wpc_replaced_notice' );
			echo '<div class="notice notice-' . ( $just ? 'success' : 'info' ) . '"><p>' . ( $just ? '<strong>WP Connection replaced the older WP Connections plugin.</strong> Your settings, tokens and activity log were kept. ' : '<strong>The older WP Connections plugin is still installed</strong> (switched off). ' ) . 'Remove its files here. Don’t use Delete on the Plugins page: its uninstaller would erase the settings WP Connection uses. AI apps you connected before need the new MCP URL ending in <code>/wp-json/wp-connection/mcp</code>.</p><p><a class="button button-primary" href="' . esc_url( self::replace_url() ) . '">Remove old files</a></p></div>';
		}
	}

	/** Swap the old row's Delete link (which runs its uninstaller) for a safe one. */
	public static function old_row_links( $links ) {
		unset( $links['delete'] );
		if ( ! self::old_active() && ! self::$dormant ) {
			$links['wpc_remove'] = '<a href="' . esc_url( self::replace_url() ) . '">Remove (keeps settings)</a>';
		}
		return $links;
	}

	/** Deactivate the old copy (silently), activate this one, delete the old files. */
	public static function replace() {
		if ( ! current_user_can( 'activate_plugins' ) || ! current_user_can( 'delete_plugins' ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'wpc_replace_old' );
		if ( self::old_active() ) {
			deactivate_plugins( self::OLD, true );
		}
		$self = plugin_basename( self::$file );
		if ( ! is_plugin_active( $self ) ) {
			activate_plugin( $self, '', false, true );
		}
		$removed = self::delete_old_files();
		delete_transient( 'wpc_replaced_notice' );
		wp_safe_redirect( add_query_arg( 'wpc_replaced', $removed ? '1' : '0', admin_url( 'tools.php?page=wp-connection' ) ) );
		exit;
	}

	/** Delete the old folder directly; its uninstall.php is never run. */
	private static function delete_old_files() {
		if ( ! self::old_installed() ) {
			return true;
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		if ( ! WP_Filesystem() ) {
			return false;
		}
		global $wp_filesystem;
		$dir = trailingslashit( WP_PLUGIN_DIR ) . dirname( self::OLD );
		$ok  = $wp_filesystem->delete( $dir, true );
		wp_clean_plugins_cache();
		return (bool) $ok;
	}
}
