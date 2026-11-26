<?php
/**
 * MCP Adapter dependency: one-click install and activate.
 *
 * WP Connection does not use the "Requires Plugins" header, because WordPress then
 * refuses to activate it until the MCP Adapter is active. Instead it activates
 * normally and shows a dialog (and a notice) that installs the MCP Adapter from
 * WordPress.org and activates it. GitHub builds fall back to the adapter's
 * official GitHub release if WordPress.org can't be reached.
 */

defined( 'ABSPATH' ) || exit;

class WPC_Dependency {

	const SLUG = 'mcp-adapter';

	public static function init() {
		add_action( 'admin_post_wpc_install_adapter', array( __CLASS__, 'install' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
		add_action( 'admin_footer', array( __CLASS__, 'dialog' ) );
	}

	public static function active() {
		return class_exists( '\WP\MCP\Core\McpAdapter' );
	}

	/** Plugin file of an installed MCP Adapter (e.g. mcp-adapter/mcp-adapter.php), or ''. */
	public static function installed_file() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		foreach ( get_plugins() as $file => $data ) {
			if ( 0 === strpos( $file, self::SLUG . '/' ) || 'MCP Adapter' === ( $data['Name'] ?? '' ) ) {
				return $file;
			}
		}
		return '';
	}

	private static function can() {
		return current_user_can( 'activate_plugins' ) && ( self::installed_file() || current_user_can( 'install_plugins' ) );
	}

	private static function button( $class = 'button button-primary' ) {
		$label = self::installed_file() ? 'Activate MCP Adapter' : 'Install & activate MCP Adapter';
		ob_start();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wpc-inline" style="display:inline">
			<?php wp_nonce_field( 'wpc_install_adapter' ); ?>
			<input type="hidden" name="action" value="wpc_install_adapter">
			<input type="hidden" name="back" value="<?php echo esc_attr( isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '' ); // phpcs:ignore ?>">
			<button class="<?php echo esc_attr( $class ); ?>" style="background:#3E00C7;border-color:#3E00C7;color:#fff"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
		return ob_get_clean();
	}

	public static function notice() {
		$r = isset( $_GET['wpc_adapter'] ) ? sanitize_key( $_GET['wpc_adapter'] ) : ''; // phpcs:ignore
		if ( 'ok' === $r ) {
			echo '<div class="notice notice-success is-dismissible"><p><strong>MCP Adapter is installed and active.</strong> WP Connection is ready. <a href="' . esc_url( admin_url( 'tools.php?page=wp-connection' ) ) . '">Open WP Connection</a></p></div>';
			return;
		}
		if ( self::active() || ! self::can() || WPC_Guard::is_blocked() ) {
			return;
		}
		$msg = '';
		if ( 'failed' === $r ) {
			$err = get_transient( 'wpc_adapter_error_' . get_current_user_id() );
			$msg = '<br><span style="color:#8a2424">Automatic install didn’t work' . ( $err ? ': ' . esc_html( $err ) : '' ) . '. You can <a href="' . esc_url( admin_url( 'plugin-install.php?s=mcp-adapter&tab=search&type=term' ) ) . '">install it from Plugins → Add New</a>.</span>';
		}
		echo '<div class="notice notice-warning"><p><strong>WP Connection needs the MCP Adapter plugin</strong> to talk to AI apps. It’s free and made by the WordPress team.' . $msg . '</p><p>' . self::button() . '</p></div>'; // phpcs:ignore
	}

	/** Dialog shown once right after activation, and on the WP Connection screen. */
	public static function dialog() {
		if ( self::active() || ! self::can() || WPC_Guard::is_blocked() ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$ours   = $screen && 'tools_page_wp-connection' === $screen->id;
		$fresh  = get_transient( 'wpc_adapter_dialog' );
		if ( ! $ours && ! $fresh ) {
			return;
		}
		delete_transient( 'wpc_adapter_dialog' );
		?>
		<dialog id="wpc-adapter-dlg" aria-labelledby="wpc-adapter-title" style="max-width:480px;width:calc(100% - 32px);border:0;border-radius:8px;padding:0;box-shadow:0 12px 40px rgba(0,0,0,.3)">
			<div style="padding:24px 28px;display:flex;flex-direction:column;gap:12px;font-size:14px;line-height:1.5">
				<div style="font-weight:700;color:#3E00C7;font-size:13px">WP Connection</div>
				<h2 id="wpc-adapter-title" style="margin:0;font-size:20px">One more step: install the MCP Adapter</h2>
				<p style="margin:0">WP Connection uses the free <strong>MCP Adapter</strong> plugin from the WordPress team to talk to AI apps like Claude, ChatGPT and Cursor. Install it now and you’re ready to connect.</p>
				<div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-top:6px">
					<?php echo self::button( 'button button-primary button-hero' ); // phpcs:ignore ?>
					<button type="button" class="button-link" onclick="document.getElementById('wpc-adapter-dlg').close()">Later</button>
				</div>
			</div>
		</dialog>
		<script>( function () { var d = document.getElementById( 'wpc-adapter-dlg' ); if ( d && d.showModal ) { d.showModal(); } } )();</script>
		<?php
	}

	public static function install() {
		check_admin_referer( 'wpc_install_adapter' );
		if ( ! current_user_can( 'activate_plugins' ) ) {
			wp_die( 'Not allowed.' );
		}
		$back = isset( $_POST['back'] ) ? esc_url_raw( wp_unslash( $_POST['back'] ) ) : ''; // phpcs:ignore
		$back = $back ? $back : admin_url( 'tools.php?page=wp-connection' );
		$res  = self::run_install();
		if ( is_wp_error( $res ) ) {
			set_transient( 'wpc_adapter_error_' . get_current_user_id(), $res->get_error_message(), 10 * MINUTE_IN_SECONDS );
			WPC_Log::add( 'install-mcp-adapter', self::SLUG, false, $res->get_error_message(), null, true );
		} else {
			WPC_Log::add( 'install-mcp-adapter', self::SLUG, true, 'MCP Adapter installed/activated from wp-admin', null, true );
		}
		wp_safe_redirect( add_query_arg( 'wpc_adapter', is_wp_error( $res ) ? 'failed' : 'ok', remove_query_arg( array( 'wpc_adapter', 'activate' ), $back ) ) );
		exit;
	}

	private static function run_install() {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$file = self::installed_file();
		if ( ! $file ) {
			if ( ! current_user_can( 'install_plugins' ) ) {
				return new WP_Error( 'wpc_cap', 'Your account can’t install plugins.' );
			}
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
			$pkg  = '';
			$info = plugins_api( 'plugin_information', array( 'slug' => self::SLUG, 'fields' => array( 'sections' => false ) ) );
			if ( ! is_wp_error( $info ) && ! empty( $info->download_link ) ) {
				$pkg = $info->download_link;
			} elseif ( ! WPC_WPORG_BUILD ) {
				$pkg = 'https://github.com/WordPress/mcp-adapter/releases/latest/download/mcp-adapter.zip';
			}
			if ( ! $pkg ) {
				return is_wp_error( $info ) ? $info : new WP_Error( 'wpc_pkg', 'Could not find the MCP Adapter download.' );
			}
			$skin     = new WP_Ajax_Upgrader_Skin();
			$upgrader = new Plugin_Upgrader( $skin );
			$ok       = $upgrader->install( $pkg );
			if ( is_wp_error( $ok ) ) {
				return $ok;
			}
			if ( ! $ok ) {
				$errs = $skin->get_errors();
				return $errs->has_errors() ? $errs : new WP_Error( 'wpc_install', 'Install failed. Your host may need FTP details for plugin installs.' );
			}
			wp_clean_plugins_cache();
			$file = $upgrader->plugin_info() ? $upgrader->plugin_info() : self::installed_file();
		}
		if ( ! $file ) {
			return new WP_Error( 'wpc_install', 'MCP Adapter was downloaded but could not be found.' );
		}
		if ( ! is_plugin_active( $file ) ) {
			$a = activate_plugin( $file );
			if ( is_wp_error( $a ) ) {
				return $a;
			}
		}
		return true;
	}
}
