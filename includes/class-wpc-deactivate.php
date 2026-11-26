<?php
/**
 * Plugins screen: "Deactivate" opens a dialog with two choices:
 *  - Deactivate temporarily (keep everything)
 *  - Deactivate and delete this plugin's tables and settings
 */

defined( 'ABSPATH' ) || exit;

class WPC_Deactivate {

	public static function init() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_footer', array( __CLASS__, 'dialog' ) );
		add_action( 'admin_post_wpc_full_deactivate', array( __CLASS__, 'handle' ) );
	}

	public static function assets( $hook ) {
		if ( 'plugins.php' !== $hook ) {
			return;
		}
		wp_enqueue_style( 'wpc-deactivate', WPC_URL . 'assets/deactivate.css', array(), WPC_VERSION . '.' . filemtime( WPC_DIR . '/assets/deactivate.css' ) );
		wp_enqueue_script( 'wpc-deactivate', WPC_URL . 'assets/deactivate.js', array(), WPC_VERSION . '.' . filemtime( WPC_DIR . '/assets/deactivate.js' ), true );
		wp_localize_script( 'wpc-deactivate', 'wpcDeactivate', array( 'basename' => plugin_basename( WPC_FILE ) ) );
	}

	public static function dialog() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'plugins' !== $screen->id || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		$t = WPC_Install::tables();
		?>
		<div id="wpc-dlg" class="wpc-dlg-backdrop" hidden>
			<div class="wpc-dlg" role="dialog" aria-modal="true" aria-labelledby="wpc-dlg-title">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="wpc-dlg-form">
					<?php wp_nonce_field( 'wpc_full_deactivate' ); ?>
					<input type="hidden" name="action" value="wpc_full_deactivate">
					<div class="wpc-dlg-head">
						<h2 id="wpc-dlg-title">Deactivate WP Connection</h2>
						<button type="button" class="wpc-dlg-x" data-wpc-close aria-label="Close"><span aria-hidden="true">×</span></button>
					</div>
					<div class="wpc-dlg-body">
						<p>What would you like to do?</p>
						<label class="wpc-opt"><input type="radio" name="mode" value="temp" checked>
							<span><strong>Deactivate temporarily</strong><span class="wpc-muted">Every connected AI app is disconnected straight away. Settings, tokens, file backups and the activity log are kept, so you can switch it back on later.</span></span>
						</label>
						<label class="wpc-opt"><input type="radio" name="mode" value="full">
							<span><strong>Deactivate and delete this plugin’s tables</strong><span class="wpc-muted">Removes only the database tables and settings that WP Connection itself created, then deactivates it. Other plugins’ tables and data are never touched.</span></span>
						</label>
						<div class="wpc-dlg-warn" id="wpc-dlg-warn" hidden>
							<strong>Only these WP Connection items will be permanently deleted:</strong>
							<ul>
								<li>Database tables <code><?php echo esc_html( $t['log'] ); ?></code> and <code><?php echo esc_html( $t['backups'] ); ?></code></li>
								<li>Its settings: <code>wpc_settings</code>, <code>wpc_tokens</code>, <code>wpc_db_version</code></li>
								<li>Its temporary data (<code>wpc_</code> transients) and its daily cleanup task</li>
							</ul>
							<strong>Not touched:</strong>
							<span class="wpc-muted">Every other plugin’s tables and settings (page builders, SEO plugins, MCP Adapter and the rest), WordPress core tables, your pages, posts, media and theme files, and your users.</span>
							<label class="wpc-confirm"><input type="checkbox" id="wpc-dlg-confirm" name="confirm" value="1"> I understand this cannot be undone</label>
						</div>
					</div>
					<div class="wpc-dlg-foot">
						<button type="button" class="button" data-wpc-close>Cancel</button>
						<a href="#" class="button button-primary" id="wpc-dlg-temp">Deactivate</a>
						<button type="submit" class="button wpc-btn-danger" id="wpc-dlg-full" hidden disabled>Delete WP Connection tables and deactivate</button>
					</div>
				</form>
			</div>
		</div>
		<?php
	}

	public static function handle() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'wpc_full_deactivate' );
		if ( empty( $_POST['confirm'] ) || 'full' !== ( $_POST['mode'] ?? '' ) ) {
			wp_safe_redirect( admin_url( 'plugins.php' ) );
			exit;
		}
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		WPC_Install::remove_all_data();
		deactivate_plugins( plugin_basename( WPC_FILE ) );
		// Deactivation hook may re-check options; make sure nothing of ours was recreated.
		delete_option( WPC_Settings::OPTION );
		wp_safe_redirect( admin_url( 'plugins.php?deactivate=true' ) );
		exit;
	}
}
