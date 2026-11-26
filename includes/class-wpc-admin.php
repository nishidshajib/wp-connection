<?php
/**
 * Tools → WP Connection admin screen.
 */

defined( 'ABSPATH' ) || exit;

class WPC_Admin {

	const SLUG = 'wp-connection';

	/** Boolean settings per tab (unchecked boxes are not posted). */
	const BOOLS = array(
		'access' => array( 'oauth_on' ),
		'files'  => array( 'theme_on', 'calc_on', 'mu_on', 'read_content', 'php_check', 'backups_on', 'keep_paths', 'skip_dupes' ),
		'safety' => array( 'bind_domain', 'noindex_header', 'robots_block', 'lock_discourage', 'email_redirect' ),
		'log'    => array(),
		'updates' => array(),
	);
	const TEXTS = array(
		'access' => array( 'rate_limit' => 'int', 'ip_allowlist' => 'lines', 'oauth_redirect_hosts' => 'hosts' ),
		'files'  => array( 'theme_slug' => 'slug', 'calc_slug' => 'slug', 'mu_prefix' => 'slug', 'extra_roots' => 'lines', 'backups_keep' => 'int', 'max_mb' => 'int', 'fetch_hosts' => 'hosts' ),
		'safety' => array( 'blocked_hosts' => 'hosts', 'test_email' => 'email' ),
		'log'     => array( 'log_keep' => 'int' ),
		'updates' => array( 'github_repo' => 'repo', 'update_channel' => 'key', 'github_token' => 'secret' ),
	);

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_wpc_action', array( __CLASS__, 'handle' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( WPC_FILE ), array( __CLASS__, 'links' ) );
	}

	public static function url( $tab = 'overview', $extra = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::SLUG, 'tab' => $tab ), $extra ), admin_url( 'tools.php' ) );
	}

	public static function links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">Settings</a>' );
		return $links;
	}

	public static function menu() {
		add_management_page( 'WP Connection', 'WP Connection', 'manage_options', self::SLUG, array( __CLASS__, 'page' ) );
	}

	public static function assets( $hook ) {
		if ( 'tools_page_' . self::SLUG === $hook ) {
			wp_enqueue_style( 'wpc-admin', WPC_URL . 'assets/admin.css', array(), WPC_VERSION );
		}
	}

	public static function notices() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( WPC_Guard::is_blocked() ) {
			echo '<div class="notice notice-error"><p><strong>WP Connection is disabled on this domain</strong> (it is in the blocked list). Deactivate and delete the plugin here.</p></div>';
		} elseif ( ! function_exists( 'wp_register_ability' ) ) {
			echo '<div class="notice notice-error"><p><strong>WP Connection</strong> needs WordPress 6.9 or newer.</p></div>';
		} elseif ( ! class_exists( '\WP\MCP\Core\McpAdapter' ) ) {
			echo '<div class="notice notice-warning"><p><strong>WP Connection</strong> needs the <strong>MCP Adapter</strong> plugin to be active.</p></div>';
		}
	}

	/* ------------------------------------------------------------ actions */

	public static function handle() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'wpc_action' );
		$do  = isset( $_POST['do'] ) ? sanitize_key( $_POST['do'] ) : '';
		$tab = isset( $_POST['tab'] ) ? sanitize_key( $_POST['tab'] ) : 'overview';
		$msg = '';
		switch ( $do ) {
			case 'save':
				self::save_tab( $tab );
				$msg = 'saved';
				break;
			case 'toggle_enabled':
				WPC_Settings::save( array( 'enabled' => ! WPC_Settings::get( 'enabled' ) ) );
				$msg = 'saved';
				break;
			case 'preset':
				$preset = isset( $_POST['preset'] ) ? sanitize_key( $_POST['preset'] ) : '';
				$tools  = array();
				foreach ( WPC_Settings::tools() as $k => $t ) {
					$tools[ $k ] = 'build' === $preset ? $t[4] : ( 'readonly' === $preset ? 'read' === $t[2] : false );
				}
				WPC_Settings::save( array( 'tools' => $tools ) );
				$msg = 'saved';
				break;
			case 'create_token':
				if ( WPC_Guard::is_blocked() ) {
					break;
				}
				$uid = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
				if ( ! user_can( $uid, 'manage_options' ) ) {
					wp_die( 'That user is not an administrator.' );
				}
				$name  = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : 'AI app';
				$days  = isset( $_POST['days'] ) ? absint( $_POST['days'] ) : 30;
				$pre   = isset( $_POST['preset'] ) ? sanitize_key( $_POST['preset'] ) : 'build';
				$token = WPC_Auth::create( $name, $uid, $days, $pre );
				set_transient( 'wpc_new_token_' . get_current_user_id(), $token, 5 * MINUTE_IN_SECONDS );
				WPC_Log::add( 'token-created', $name, true, 'Token created in wp-admin', null, true );
				break;
			case 'revoke':
				$id = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';
				WPC_Auth::revoke( $id );
				WPC_Log::add( 'token-revoked', $id, true, 'Token revoked in wp-admin', null, true );
				break;
			case 'revoke_all':
				WPC_Auth::revoke_all();
				WPC_Log::add( 'token-revoked', 'all', true, 'All tokens revoked in wp-admin', null, true );
				break;
			case 'clear_log':
				WPC_Log::clear();
				break;
			case 'restore':
				$r = WPC_Files::restore( isset( $_POST['backup_id'] ) ? absint( $_POST['backup_id'] ) : 0 );
				WPC_Log::add( 'restore-file', is_array( $r ) ? $r['root'] . '/' . $r['path'] : '', ! is_wp_error( $r ), is_wp_error( $r ) ? $r->get_error_message() : 'Restored in wp-admin', null, true );
				$msg = is_wp_error( $r ) ? 'restore_failed' : 'restored';
				break;
			case 'choose_client':
				$c = isset( $_POST['client'] ) ? sanitize_key( $_POST['client'] ) : '';
				if ( WPC_Clients::get( $c ) ) {
					WPC_Settings::save( array( 'client' => $c ) );
					$tab = 'setup';
				} else {
					$tab = 'welcome';
				}
				break;
			case 'skip_welcome':
				if ( ! WPC_Settings::get( 'client' ) ) {
					WPC_Settings::save( array( 'client' => 'other' ) );
				}
				$tab = 'overview';
				break;
			case 'remove_oauth_client':
				$id = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';
				WPC_OAuth::remove_client( $id );
				WPC_Auth::revoke_oauth_client( $id );
				WPC_Log::add( 'oauth-removed', $id, true, 'OAuth app removed and its tokens revoked in wp-admin', null, true );
				$msg = 'saved';
				break;
			case 'check_updates':
				if ( class_exists( 'WPC_Updater' ) ) {
					WPC_Updater::releases( true );
					delete_site_transient( 'update_plugins' );
					wp_update_plugins();
				}
				$msg = 'checked';
				break;
			case 'test':
				$msg = self::test_connection() ? 'test_ok' : 'test_failed';
				break;
			case 'export':
				self::export_csv();
				exit;
			case 'remove_data':
				WPC_Install::remove_all_data();
				WPC_Install::create_tables();
				add_option( WPC_Settings::OPTION, WPC_Settings::defaults(), '', false );
				$msg = 'reset';
				break;
		}
		wp_safe_redirect( self::url( $tab, $msg ? array( 'wpc_msg' => $msg ) : array() ) );
		exit;
	}

	private static function save_tab( $tab ) {
		$vals = array();
		if ( 'tools' === $tab ) {
			$on = isset( $_POST['tools'] ) && is_array( $_POST['tools'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['tools'] ) ) : array(); // phpcs:ignore
			$t  = array();
			foreach ( array_keys( WPC_Settings::tools() ) as $k ) {
				$t[ $k ] = in_array( $k, $on, true );
			}
			$vals['tools'] = $t;
		}
		foreach ( self::BOOLS[ $tab ] ?? array() as $k ) {
			$vals[ $k ] = ! empty( $_POST[ $k ] );
		}
		foreach ( self::TEXTS[ $tab ] ?? array() as $k => $type ) {
			if ( ! isset( $_POST[ $k ] ) ) {
				continue;
			}
			$v = wp_unslash( $_POST[ $k ] ); // phpcs:ignore
			switch ( $type ) {
				case 'int':
					$v = max( 0, (int) $v );
					break;
				case 'slug':
					$v = preg_replace( '/[^A-Za-z0-9._-]/', '', (string) $v );
					break;
				case 'email':
					$v = sanitize_email( $v );
					break;
				case 'repo':
					$v = trim( preg_replace( '#^https?://github\.com/#i', '', trim( (string) $v ) ), '/' );
					$v = preg_match( '#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $v ) ? $v : '';
					break;
				case 'key':
					$v = sanitize_key( $v );
					break;
				case 'secret':
					$v = trim( (string) $v );
					if ( '' === $v ) {
						continue 2; // Empty field keeps the saved token.
					}
					if ( '-' === $v ) {
						$v = ''; // "-" clears it.
					}
					$v = preg_replace( '/[^A-Za-z0-9_]/', '', $v );
					break;
				case 'hosts':
					$v = implode( "\n", array_filter( array_map( function ( $h ) { return preg_replace( '/[^a-z0-9.-]/', '', strtolower( trim( $h ) ) ); }, preg_split( '/[\r\n,]+/', (string) $v ) ) ) );
					break;
				default:
					$v = sanitize_textarea_field( $v );
			}
			$vals[ $k ] = $v;
		}
		if ( 'updates' === $tab ) {
			self::set_auto_update( ! empty( $_POST['auto_update'] ) );
			if ( class_exists( 'WPC_Updater' ) ) {
				WPC_Updater::flush();
			}
		}
		WPC_Settings::save( $vals );
	}

	public static function auto_update_on() {
		return in_array( plugin_basename( WPC_FILE ), (array) get_site_option( 'auto_update_plugins', array() ), true );
	}

	/** Uses WordPress's own per-plugin auto-update setting (same as the Plugins page switch). */
	private static function set_auto_update( $on ) {
		$list = (array) get_site_option( 'auto_update_plugins', array() );
		$base = plugin_basename( WPC_FILE );
		$list = array_values( array_diff( $list, array( $base ) ) );
		if ( $on ) {
			$list[] = $base;
		}
		update_site_option( 'auto_update_plugins', $list );
	}

	/** Internal request to our own MCP route (no network, no token needed). */
	private static function test_connection() {
		if ( ! class_exists( '\WP\MCP\Core\McpAdapter' ) ) {
			return false;
		}
		WPC_Auth::$internal_test = true;
		$req = new WP_REST_Request( 'POST', '/' . WPC_Auth::route_namespace() . '/mcp' );
		$req->set_header( 'content-type', 'application/json' );
		$req->set_header( 'accept', 'application/json, text/event-stream' );
		$req->set_body( wp_json_encode( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => array( 'protocolVersion' => '2025-06-18', 'capabilities' => new stdClass(), 'clientInfo' => array( 'name' => 'wpc-test', 'version' => '1' ) ) ) ) );
		$res = rest_do_request( $req );
		WPC_Auth::$internal_test = false;
		$ok = ! $res->is_error() && 200 === $res->get_status();
		WPC_Log::add( 'test-connection', 'initialize', $ok, $ok ? 'MCP server answered' : 'Status ' . $res->get_status() );
		return $ok;
	}

	private static function export_csv() {
		$rows = WPC_Log::query( array( 'limit' => 5000 ) );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="wp-connection-log-' . gmdate( 'Ymd-His' ) . '.csv"' );
		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'id', 'created_utc', 'token', 'tool', 'target', 'ok', 'write', 'message' ) );
		foreach ( $rows as $r ) {
			fputcsv( $out, array( $r['id'], $r['created'], $r['token'], $r['tool'], $r['target'], $r['ok'], $r['is_write'], $r['message'] ) );
		}
		fclose( $out ); // phpcs:ignore
	}

	/* --------------------------------------------------------------- view */

	private static function form_open( $tab, $do, $class = '' ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="' . esc_attr( $class ) . '">';
		wp_nonce_field( 'wpc_action' );
		echo '<input type="hidden" name="action" value="wpc_action"><input type="hidden" name="tab" value="' . esc_attr( $tab ) . '"><input type="hidden" name="do" value="' . esc_attr( $do ) . '">';
	}

	private static function sw( $name, $on, $label, $value = '1' ) {
		printf( '<label class="wpc-sw"><input type="checkbox" name="%s" value="%s" %s aria-label="%s"><span class="screen-reader-text">%s</span></label>', esc_attr( $name ), esc_attr( $value ), checked( $on, true, false ), esc_attr( $label ), esc_html( $label ) );
	}

	private static function row( $title, $desc, $control_html ) {
		echo '<div class="wpc-row"><div class="wpc-lbl"><strong>' . esc_html( $title ) . '</strong>' . ( $desc ? '<span class="wpc-muted">' . esc_html( $desc ) . '</span>' : '' ) . '</div><div class="wpc-ctl">' . $control_html . '</div></div>'; // phpcs:ignore
	}

	private static function tag( $text, $kind ) {
		return '<span class="wpc-tag wpc-' . esc_attr( $kind ) . '">' . esc_html( $text ) . '</span>';
	}

	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$tabs = array( 'overview' => 'Overview', 'access' => 'Access & tokens', 'tools' => 'Tools & permissions', 'files' => 'Files & network', 'safety' => 'Safety', 'log' => 'Activity log', 'updates' => 'Updates', 'setup' => 'Setup guide' );
		$req  = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : ''; // phpcs:ignore
		$tab  = isset( $tabs[ $req ] ) ? $req : 'overview';
		if ( 'welcome' === $req || ( '' === $req && ! WPC_Settings::get( 'client' ) && ! WPC_Guard::is_blocked() ) ) {
			self::welcome();
			return;
		}
		$s    = WPC_Settings::all();
		$msgs = array(
			'saved'          => array( 'success', 'Settings saved.' ),
			'restored'       => array( 'success', 'File restored.' ),
			'restore_failed' => array( 'error', 'Restore failed. See the activity log.' ),
			'test_ok'        => array( 'success', 'Connection test passed: the MCP server answered.' ),
			'test_failed'    => array( 'error', 'Connection test failed. Check that the MCP Adapter is active.' ),
			'reset'          => array( 'success', 'All WP Connection data was removed and default settings restored.' ),
			'checked'        => array( 'success', 'Checked for updates.' ),
		);
		$m = isset( $_GET['wpc_msg'] ) ? sanitize_key( $_GET['wpc_msg'] ) : ''; // phpcs:ignore
		?>
		<div class="wrap wpc">
			<div class="wpc-head">
				<div>
					<h1>WP Connection</h1>
					<span class="wpc-muted">Version <?php echo esc_html( WPC_VERSION ); ?><?php $up = self::update_available(); if ( $up ) { echo ' · <a href="' . esc_url( self::url( 'updates' ) ) . '">Version ' . esc_html( $up ) . ' available</a>'; } ?> · Connects this staging site to your AI assistant through the WordPress MCP Adapter</span>
				</div>
				<div class="wpc-head-right">
					<?php
					if ( WPC_Guard::is_blocked() ) {
						echo self::tag( 'Blocked domain', 'bad' ); // phpcs:ignore
					} else {
						$last = WPC_Log::stats_24h()['last'];
						echo self::tag( $s['enabled'] ? ( $last ? 'Connected' : 'Waiting for ' . WPC_Clients::chosen_name() ) : 'Off', $s['enabled'] ? ( $last ? 'ok' : 'warn' ) : 'bad' ); // phpcs:ignore
						echo self::tag( 'Staging: ' . WPC_Guard::host(), 'info' ); // phpcs:ignore
						echo '<span class="wpc-chip wpc-appchip">App: ' . esc_html( WPC_Clients::chosen_name() ) . ' · <a href="' . esc_url( self::url( 'welcome' ) ) . '">Change</a></span>';
						self::form_open( $tab, 'toggle_enabled', 'wpc-inline' );
						echo '<button class="button ' . ( $s['enabled'] ? '' : 'button-primary' ) . '">' . ( $s['enabled'] ? 'Turn connections off' : 'Turn connections on' ) . '</button></form>';
					}
					?>
				</div>
			</div>
			<?php if ( isset( $msgs[ $m ] ) ) : ?>
				<div class="notice notice-<?php echo esc_attr( $msgs[ $m ][0] ); ?> is-dismissible"><p><?php echo esc_html( $msgs[ $m ][1] ); ?></p></div>
			<?php endif; ?>
			<?php if ( WPC_Guard::is_blocked() ) : ?>
				<div class="notice notice-error inline"><p>This domain is in the production block list, so every tool is disabled and tokens do not work. Deactivate and delete WP Connection on this site.</p></div>
				</div>
				<?php
				return;
			endif;
			?>
			<nav class="nav-tab-wrapper wpc-tabs" aria-label="WP Connection sections">
				<?php foreach ( $tabs as $k => $label ) : ?>
					<a href="<?php echo esc_url( self::url( $k ) ); ?>" class="nav-tab <?php echo $tab === $k ? 'nav-tab-active' : ''; ?>" <?php echo $tab === $k ? 'aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>
			<?php call_user_func( array( __CLASS__, 'tab_' . $tab ), $s ); ?>
		</div>
		<?php
	}

	private static function tab_overview( $s ) {
		$url     = rest_url( WPC_Auth::route_namespace() . '/mcp' );
		$routes  = rest_get_server()->get_routes();
		$active  = isset( $routes[ '/' . WPC_Auth::route_namespace() . '/mcp' ] );
		$stats   = WPC_Log::stats_24h();
		global $wp_version;
		$https   = 0 === strpos( home_url(), 'https://' );
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$fs      = get_filesystem_method();
		$httpauth = ! empty( $_SERVER['PHP_AUTH_USER'] ) || ! empty( $_SERVER['REMOTE_USER'] ) || ! empty( $_SERVER['HTTP_AUTHORIZATION'] );
		?>
		<div class="wpc-grid">
			<section class="wpc-card">
				<h2>Connection</h2>
				<div class="wpc-stack"><span class="wpc-muted">MCP server URL (add it in <?php echo esc_html( WPC_Clients::chosen_name() ); ?>, see the <a href="<?php echo esc_url( self::url( 'setup' ) ); ?>">Setup guide</a>)</span>
					<div class="wpc-copy"><code class="wpc-code" id="wpc-url"><?php echo esc_html( $url ); ?></code><button type="button" class="button" onclick="navigator.clipboard.writeText(document.getElementById('wpc-url').textContent);this.textContent='Copied'">Copy</button></div>
				</div>
				<div class="wpc-stack"><span class="wpc-muted">Request header</span><div><code class="wpc-code">x-api-key</code> <span class="wpc-muted">= a token from the Access tab<?php echo WPC_Settings::get( 'oauth_on' ) ? ', or OAuth sign-in (on)' : ''; ?></span></div></div>
				<div class="wpc-btns">
					<?php self::form_open( 'overview', 'test', 'wpc-inline' ); ?><button class="button button-primary">Test connection</button></form>
					<a class="button" href="<?php echo esc_url( self::url( 'setup' ) ); ?>">Setup guide</a>
				</div>
			</section>
			<section class="wpc-card">
				<h2>Health checks</h2>
				<table class="wpc-table"><tbody>
					<tr><td>WordPress 6.9+ (Abilities API)</td><td><?php echo function_exists( 'wp_register_ability' ) ? self::tag( $wp_version, 'ok' ) : self::tag( $wp_version . ' — too old', 'bad' ); // phpcs:ignore ?></td></tr>
					<tr><td>MCP Adapter plugin</td><td><?php echo class_exists( '\WP\MCP\Core\McpAdapter' ) ? self::tag( 'Active' . ( defined( 'WP_MCP_VERSION' ) ? ' · ' . WP_MCP_VERSION : '' ), 'ok' ) : self::tag( 'Not active', 'bad' ); // phpcs:ignore ?></td></tr>
					<tr><td>MCP route registered</td><td><?php echo $active ? self::tag( 'Yes', 'ok' ) : self::tag( 'No', 'bad' ); // phpcs:ignore ?></td></tr>
					<tr><td>HTTPS</td><td><?php echo $https ? self::tag( 'Yes', 'ok' ) : self::tag( 'No — AI apps need https', 'bad' ); // phpcs:ignore ?></td></tr>
					<tr><td>Production guard</td><td><?php echo self::tag( 'Not a blocked host', 'ok' ); // phpcs:ignore ?></td></tr>
					<tr><td>Search engines discouraged</td><td><?php echo '0' === (string) get_option( 'blog_public' ) ? self::tag( 'On', 'ok' ) : self::tag( 'Off', 'warn' ); // phpcs:ignore ?></td></tr>
					<tr><td>Filesystem method</td><td><?php echo 'direct' === $fs ? self::tag( 'direct', 'ok' ) : self::tag( (string) $fs . ' — plugin installs need direct', 'warn' ); // phpcs:ignore ?></td></tr>
					<tr><td>Server password (HTTP auth)</td><td><?php echo $httpauth ? self::tag( 'Detected · use x-api-key', 'warn' ) : self::tag( 'Not detected', 'info' ); // phpcs:ignore ?></td></tr>
				</tbody></table>
			</section>
			<section class="wpc-card">
				<h2>Last 24 hours</h2>
				<div class="wpc-stats">
					<div><span class="wpc-big"><?php echo (int) $stats['calls']; ?></span><span class="wpc-muted">Tool calls</span></div>
					<div><span class="wpc-big"><?php echo (int) $stats['writes']; ?></span><span class="wpc-muted">Writes</span></div>
					<div><span class="wpc-big"><?php echo (int) $stats['errors']; ?></span><span class="wpc-muted">Errors</span></div>
				</div>
				<span class="wpc-muted">Last request: <?php echo $stats['last'] ? esc_html( get_date_from_gmt( $stats['last'], 'Y-m-d H:i' ) ) : 'none yet'; ?></span>
				<a href="<?php echo esc_url( self::url( 'log' ) ); ?>">Open activity log</a>
			</section>
		</div>
		<?php
	}

	private static function tab_access( $s ) {
		$new = get_transient( 'wpc_new_token_' . get_current_user_id() );
		if ( $new ) {
			delete_transient( 'wpc_new_token_' . get_current_user_id() );
		}
		$admins = get_users( array( 'role' => 'administrator', 'fields' => array( 'ID', 'user_login', 'display_name' ) ) );
		?>
		<section class="wpc-card">
			<h2>Create a token</h2>
			<span class="wpc-muted">The token is shown once. Only a hash is stored<?php echo $s['bind_domain'] ? ', and it works only on ' . esc_html( WPC_Guard::host() ) : ''; ?>.</span>
			<?php self::form_open( 'access', 'create_token' ); ?>
				<div class="wpc-fields">
					<label><strong>Name</strong><input class="regular-text" type="text" name="name" value="<?php echo esc_attr( ( WPC_Clients::chosen() ? WPC_Clients::chosen()['name'] : 'AI app' ) . ' – staging build' ); ?>" required></label>
					<label><strong>Act as administrator</strong><select name="user_id"><?php foreach ( $admins as $a ) { printf( '<option value="%d">%s</option>', (int) $a->ID, esc_html( $a->display_name . ' (' . $a->user_login . ')' ) ); } ?></select></label>
					<label><strong>Expires</strong><select name="days"><option value="30">After 30 days</option><option value="7">After 7 days</option><option value="90">After 90 days</option><option value="0">Never</option></select></label>
					<label><strong>Permission preset</strong><select name="preset"><option value="build">Build (uses Tools tab switches)</option><option value="readonly">Read-only</option></select></label>
				</div>
				<p><button class="button button-primary">Generate token</button></p>
			</form>
			<?php if ( $new ) : ?>
				<div class="wpc-newtoken"><strong>Copy this token now. It will not be shown again.</strong>
					<div class="wpc-copy"><input type="text" readonly id="wpc-token" value="<?php echo esc_attr( $new ); ?>" onclick="this.select()"><button type="button" class="button" onclick="navigator.clipboard.writeText(document.getElementById('wpc-token').value);this.textContent='Copied'">Copy</button></div>
				</div>
			<?php endif; ?>
		</section>
		<section class="wpc-card">
			<h2>Active tokens</h2>
			<div class="wpc-scroll"><table class="wpc-table widefat">
				<thead><tr><th>Name</th><th>Acts as</th><th>Preset</th><th>Domain</th><th>Created</th><th>Last used</th><th>Expires</th><th></th></tr></thead>
				<tbody>
				<?php
				$tokens = WPC_Auth::tokens();
				if ( ! $tokens ) {
					echo '<tr><td colspan="8" class="wpc-muted">No tokens yet.</td></tr>';
				}
				foreach ( $tokens as $id => $t ) {
					$u       = get_userdata( (int) $t['user_id'] );
					$expired = ! empty( $t['expires'] ) && time() > $t['expires'];
					echo '<tr><td>' . esc_html( $t['name'] ) . '</td><td>' . esc_html( $u ? $u->user_login : '#' . $t['user_id'] ) . '</td><td>' . esc_html( ucfirst( $t['preset'] ) ) . '</td><td>' . esc_html( $t['host'] ) . '</td><td>' . esc_html( wp_date( 'Y-m-d', $t['created'] ) ) . '</td><td>' . esc_html( $t['last_used'] ? wp_date( 'Y-m-d H:i', $t['last_used'] ) : 'never' ) . '</td><td>' . ( $t['expires'] ? ( $expired ? self::tag( 'Expired', 'bad' ) : esc_html( wp_date( 'Y-m-d', $t['expires'] ) ) ) : 'never' ) . '</td><td>'; // phpcs:ignore
					self::form_open( 'access', 'revoke', 'wpc-inline' );
					echo '<input type="hidden" name="id" value="' . esc_attr( $id ) . '"><button class="button wpc-danger">Revoke</button></form></td></tr>';
				}
				?>
				</tbody>
			</table></div>
		</section>
		<?php self::form_open( 'access', 'save' ); ?>
		<section class="wpc-card">
			<h2>Sign-in methods</h2>
			<span class="wpc-muted">Apps that let you add a request header use an API key. Apps that only offer a sign-in button for custom connectors (such as ChatGPT) need OAuth.</span>
			<?php
			self::row( 'API key header', 'The app sends x-api-key (or Authorization: Bearer) with a token from above. Works with Claude, Claude Code, Cursor, VS Code and Gemini CLI.', self::tag( 'Always on', 'ok' ) );
			ob_start(); self::sw( 'oauth_on', $s['oauth_on'], 'OAuth sign-in' );
			self::row( 'OAuth sign-in', 'The app opens a WordPress approval screen. An administrator picks the preset and expiry there, and a token is created. Needs pretty permalinks and WordPress at the root of the domain.', ob_get_clean() );
			self::row( 'Allowed redirect hosts', 'OAuth only returns to these app domains, one per line. Add the domain your app shows when you create the connector. Local addresses (localhost) are always allowed.', '<textarea name="oauth_redirect_hosts" rows="3" cols="30" aria-label="Allowed redirect hosts" placeholder="app.example.com">' . esc_textarea( $s['oauth_redirect_hosts'] ) . '</textarea>' );
			$oc = WPC_OAuth::clients();
			if ( $oc ) {
				echo '<div class="wpc-stack"><strong>Apps registered for sign-in</strong><div class="wpc-scroll"><table class="wpc-table widefat"><thead><tr><th>Name (as the app calls itself)</th><th>Returns to</th><th>Registered</th></tr></thead><tbody>';
				foreach ( $oc as $cid => $c ) {
					$hosts = array_unique( array_map( function ( $u ) { return (string) wp_parse_url( $u, PHP_URL_HOST ); }, $c['redirect_uris'] ) );
					echo '<tr><td>' . esc_html( $c['name'] ) . '</td><td>' . esc_html( implode( ', ', $hosts ) ) . '</td><td>' . esc_html( wp_date( 'Y-m-d', $c['created'] ) ) . '</td></tr>';
				}
				echo '</tbody></table></div></div>';
			}
			?>
		</section>
		<section class="wpc-card">
			<h2>Extra restrictions</h2>
				<?php
				self::row( 'Only allow MCP requests', 'Tokens are only accepted on the WP Connection MCP route, never on other REST routes or wp-admin.', self::tag( 'Always on', 'ok' ) );
				self::row( 'Rate limit', 'Maximum tool calls per minute per token (0 = no limit).', '<input type="number" min="0" name="rate_limit" value="' . (int) $s['rate_limit'] . '" class="small-text" aria-label="Calls per minute">' );
				self::row( 'IP allowlist (optional)', 'One IP or CIDR range per line. Leave empty for cloud-hosted AI apps, whose addresses can change. Useful for desktop tools on a fixed office IP.', '<textarea name="ip_allowlist" rows="3" cols="30" aria-label="IP allowlist" placeholder="203.0.113.0/24">' . esc_textarea( $s['ip_allowlist'] ) . '</textarea>' );
				?>
		</section>
		<p><button class="button button-primary">Save changes</button></p>
		</form>
		<?php
		if ( $oc ) {
			echo '<section class="wpc-card"><h2>Remove a sign-in app</h2><span class="wpc-muted">Removing an app also revokes every token it was given.</span><div class="wpc-btns">';
			foreach ( $oc as $cid => $c ) {
				self::form_open( 'access', 'remove_oauth_client', 'wpc-inline' );
				echo '<input type="hidden" name="id" value="' . esc_attr( $cid ) . '"><button class="button wpc-danger" onclick="return confirm(\'Remove this app and revoke its tokens?\')">Remove ' . esc_html( $c['name'] ) . '</button></form>';
			}
			echo '</div></section>';
		}
	}

	private static function tab_tools( $s ) {
		$kinds = array( 'read' => array( 'Read', 'ok' ), 'write' => array( 'Write', 'info' ), 'destructive' => array( 'Destructive', 'bad' ) );
		?>
		<div class="wpc-btns" style="margin:16px 0">
			<span class="wpc-muted">Preset:</span>
			<?php foreach ( array( 'readonly' => 'Read-only', 'build' => 'Build (recommended)', 'off' => 'Turn everything off' ) as $p => $label ) : ?>
				<?php self::form_open( 'tools', 'preset', 'wpc-inline' ); ?><input type="hidden" name="preset" value="<?php echo esc_attr( $p ); ?>"><button class="button"><?php echo esc_html( $label ); ?></button></form>
			<?php endforeach; ?>
		</div>
		<?php self::form_open( 'tools', 'save' ); ?>
			<?php foreach ( WPC_Settings::groups() as $g => $gd ) : ?>
				<section class="wpc-card">
					<h2><?php echo esc_html( $gd[0] ); ?></h2>
					<span class="wpc-muted"><?php echo esc_html( $gd[1] ); ?></span>
					<div>
					<?php
					foreach ( WPC_Settings::tools() as $k => $t ) {
						if ( $t[3] !== $g ) {
							continue;
						}
						ob_start();
						self::sw( 'tools[]', ! empty( $s['tools'][ $k ] ), $t[0], $k );
						$ctl = ob_get_clean();
						echo '<div class="wpc-row"><div class="wpc-lbl"><div><strong>' . esc_html( $t[0] ) . '</strong> ' . self::tag( $kinds[ $t[2] ][0], $kinds[ $t[2] ][1] ) . '</div><span class="wpc-muted">' . esc_html( $t[1] ) . '</span></div><div class="wpc-ctl">' . $ctl . '</div></div>'; // phpcs:ignore
					}
					?>
					</div>
				</section>
			<?php endforeach; ?>
			<p><button class="button button-primary">Save changes</button></p>
		</form>
		<?php
	}

	private static function tab_files( $s ) {
		self::form_open( 'files', 'save' );
		?>
		<section class="wpc-card">
			<h2>Writable folders</h2>
			<span class="wpc-muted">The AI can create, edit and delete files only inside these folders. Leave a folder name empty to turn it off.</span>
			<div class="wpc-scroll"><table class="wpc-table widefat">
				<thead><tr><th>Folder</th><th>Path</th><th>Rule</th><th>Allowed</th></tr></thead>
				<tbody>
					<tr><td>Theme</td><td><code>wp-content/themes/</code><input type="text" name="theme_slug" value="<?php echo esc_attr( $s['theme_slug'] ); ?>" aria-label="Theme folder" placeholder="my-theme"></td><td class="wpc-muted">Any file</td><td><?php self::sw( 'theme_on', $s['theme_on'], 'Allow theme folder' ); ?></td></tr>
					<tr><td>Plugin</td><td><code>wp-content/plugins/</code><input type="text" name="calc_slug" value="<?php echo esc_attr( $s['calc_slug'] ); ?>" aria-label="Plugin folder" placeholder="my-plugin"></td><td class="wpc-muted">Any file</td><td><?php self::sw( 'calc_on', $s['calc_on'], 'Allow plugin folder' ); ?></td></tr>
					<tr><td>Must-use plugins</td><td><code>wp-content/mu-plugins/</code></td><td class="wpc-muted">Top-level files starting <input type="text" name="mu_prefix" value="<?php echo esc_attr( $s['mu_prefix'] ); ?>" class="small-text" aria-label="Prefix"> and ending .php</td><td><?php self::sw( 'mu_on', $s['mu_on'], 'Allow mu-plugins' ); ?></td></tr>
				</tbody>
			</table></div>
			<label class="wpc-stack"><strong>More folders (optional)</strong><span class="wpc-muted">One per line, relative to wp-content, e.g. <code>plugins/my-plugin</code>. Uploads cannot be added.</span><textarea name="extra_roots" rows="2" cols="50"><?php echo esc_textarea( $s['extra_roots'] ); ?></textarea></label>
		</section>
		<section class="wpc-card">
			<h2>Read access and limits</h2>
			<?php
			ob_start(); self::sw( 'read_content', $s['read_content'], 'Read wp-content' ); self::row( 'Read anywhere in wp-content', 'For inspecting installed plugins and themes. Uploads are excluded (use the media tools).', ob_get_clean() );
			self::row( 'Always blocked', 'These can never be read or written.', '<span class="wpc-chip">wp-config.php</span><span class="wpc-chip">.htaccess</span><span class="wpc-chip">.user.ini</span><span class="wpc-chip">php.ini</span><span class="wpc-chip">.env</span><span class="wpc-chip">wp-admin/</span><span class="wpc-chip">wp-includes/</span>' );
			ob_start(); self::sw( 'php_check', $s['php_check'], 'PHP syntax check' ); self::row( 'Check PHP syntax before saving', 'A file with a syntax error is refused, so a typo can’t take the site down.', ob_get_clean() );
			ob_start(); echo '<input type="number" min="1" name="backups_keep" value="' . (int) $s['backups_keep'] . '" class="small-text" aria-label="Versions to keep"> <span class="wpc-muted">versions</span> '; self::sw( 'backups_on', $s['backups_on'], 'Keep backups' ); self::row( 'Keep a backup of overwritten files', 'Stores previous versions in the plugin’s own table for one-click restore.', ob_get_clean() );
			self::row( 'Maximum file size', '', '<input type="number" min="1" name="max_mb" value="' . (int) $s['max_mb'] . '" class="small-text" aria-label="Max size in MB"> <span class="wpc-muted">MB</span>' );
			?>
		</section>
		<section class="wpc-card">
			<h2>Network and media</h2>
			<?php
			self::row( 'Hosts the AI may fetch from (read-only)', 'One per line, e.g. the live site. For copying content and media and comparing pages. This site is always allowed.', '<textarea name="fetch_hosts" rows="3" cols="36" aria-label="Fetch hosts" placeholder="example.com">' . esc_textarea( $s['fetch_hosts'] ) . '</textarea>' );
			ob_start(); self::sw( 'keep_paths', $s['keep_paths'], 'Keep upload paths' ); self::row( 'Keep original upload paths', 'Imported images keep /uploads/YYYY/MM/filename so image URLs match the live site.', ob_get_clean() );
			ob_start(); self::sw( 'skip_dupes', $s['skip_dupes'], 'Skip duplicates' ); self::row( 'Skip files already imported', 'Matches on the source URL.', ob_get_clean() );
			?>
		</section>
		<p><button class="button button-primary">Save changes</button></p>
		</form>
		<?php
	}

	private static function tab_safety( $s ) {
		self::form_open( 'safety', 'save' );
		?>
		<section class="wpc-card">
			<h2>Production guard</h2>
			<span class="wpc-muted">On these domains every tool is disabled and tokens stop working, even if the plugin is copied there by mistake. One per line.</span>
			<textarea name="blocked_hosts" rows="3" cols="40" aria-label="Blocked domains" placeholder="example.com&#10;www.example.com"><?php echo esc_textarea( $s['blocked_hosts'] ); ?></textarea>
			<?php ob_start(); self::sw( 'bind_domain', $s['bind_domain'], 'Bind tokens to domain' ); self::row( 'Disable tokens if the site domain changes', 'Each token only works on the domain it was created on (' . WPC_Guard::host() . ').', ob_get_clean() ); ?>
		</section>
		<section class="wpc-card">
			<h2>Staging protection</h2>
			<span class="wpc-muted">Extra safeguards so search engines never index this copy of the site.</span>
			<?php
			ob_start(); self::sw( 'noindex_header', $s['noindex_header'], 'X-Robots-Tag header' ); self::row( 'Send “X-Robots-Tag: noindex, nofollow” header', 'On every page and API response.', ob_get_clean() );
			ob_start(); self::sw( 'robots_block', $s['robots_block'], 'robots.txt disallow' ); self::row( 'robots.txt blocks all crawlers', 'Serves “User-agent: * / Disallow: /” (only when WordPress serves robots.txt — delete any physical robots.txt file).', ob_get_clean() );
			ob_start(); self::sw( 'lock_discourage', $s['lock_discourage'], 'Lock discourage search engines' ); self::row( 'Keep “Discourage search engines” switched on', 'Forces Settings → Reading → Search engine visibility to “discourage”.', ob_get_clean() );
			ob_start(); echo '<input type="email" name="test_email" value="' . esc_attr( $s['test_email'] ) . '" placeholder="test@yourdomain.com" aria-label="Test email address"> '; self::sw( 'email_redirect', $s['email_redirect'], 'Redirect outgoing email' ); self::row( 'Send all outgoing email only to a test address', 'Form and calculator emails go only to this address (Cc/Bcc removed). With no address set, staging sends no email at all.', ob_get_clean() );
			?>
		</section>
		<p><button class="button button-primary">Save changes</button></p>
		</form>
		<section class="wpc-card">
			<h2>Protected settings</h2>
			<span class="wpc-muted">The AI can read these but never change them.</span>
			<div><?php foreach ( array( 'Site address (URL)', 'WordPress address', 'Admin email', 'User registration', 'Default role', 'User roles', 'Active plugins list', 'Security keys', 'WP Connection settings' ) as $c ) { echo '<span class="wpc-chip">' . esc_html( $c ) . '</span>'; } ?></div>
			<?php self::row( 'Self-protection', 'No AI app can deactivate WP Connection or the MCP Adapter, or manage users.', self::tag( 'Always on', 'ok' ) ); ?>
		</section>
		<section class="wpc-card wpc-dangerzone">
			<h2>Danger zone</h2>
			<div class="wpc-btns">
				<?php self::form_open( 'safety', 'revoke_all', 'wpc-inline' ); ?><button class="button wpc-danger" onclick="return confirm('Revoke all tokens? Every connected AI app will be disconnected.')">Revoke all tokens</button></form>
				<?php self::form_open( 'safety', 'clear_log', 'wpc-inline' ); ?><button class="button wpc-danger" onclick="return confirm('Clear the activity log?')">Clear activity log</button></form>
				<?php self::form_open( 'safety', 'remove_data', 'wpc-inline' ); ?><button class="button wpc-danger" onclick="return confirm('Delete WP Connection tables, tokens and settings, then restore defaults? Other plugins are not touched.')">Reset WP Connection data</button></form>
			</div>
			<span class="wpc-muted">Before launch: deactivate and delete WP Connection and the MCP Adapter so they never reach the production site.</span>
		</section>
		<?php
	}

	private static function tab_log( $s ) {
		$tool   = isset( $_GET['tool'] ) ? sanitize_text_field( wp_unslash( $_GET['tool'] ) ) : ''; // phpcs:ignore
		$result = isset( $_GET['result'] ) ? sanitize_key( $_GET['result'] ) : ''; // phpcs:ignore
		$rows   = WPC_Log::query( array( 'tool' => $tool, 'result' => $result, 'limit' => 200 ) );
		?>
		<section class="wpc-card">
			<div class="wpc-head">
				<h2>Activity log</h2>
				<div class="wpc-btns">
					<form method="get" class="wpc-inline">
						<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>"><input type="hidden" name="tab" value="log">
						<select name="tool" aria-label="Filter by tool"><option value="">All tools</option><?php foreach ( WPC_Log::tools_used() as $t ) { printf( '<option %s>%s</option>', selected( $tool, $t, false ), esc_html( $t ) ); } ?></select>
						<select name="result" aria-label="Filter by result"><option value="">All results</option><option value="errors" <?php selected( $result, 'errors' ); ?>>Errors only</option><option value="writes" <?php selected( $result, 'writes' ); ?>>Writes only</option></select>
						<button class="button">Filter</button>
					</form>
					<?php self::form_open( 'log', 'export', 'wpc-inline' ); ?><button class="button">Export CSV</button></form>
				</div>
			</div>
			<div class="wpc-scroll"><table class="wpc-table widefat striped">
				<thead><tr><th>Time</th><th>Token</th><th>Tool</th><th>Target</th><th>Result</th><th></th></tr></thead>
				<tbody>
				<?php
				if ( ! $rows ) {
					echo '<tr><td colspan="6" class="wpc-muted">No activity yet.</td></tr>';
				}
				foreach ( $rows as $r ) {
					echo '<tr><td>' . esc_html( get_date_from_gmt( $r['created'], 'Y-m-d H:i:s' ) ) . '</td><td>' . esc_html( $r['token'] ) . '</td><td><code>' . esc_html( $r['tool'] ) . '</code></td><td>' . esc_html( $r['target'] ) . '</td><td>' . ( $r['ok'] ? self::tag( $r['is_write'] ? 'Saved' : 'OK', 'ok' ) : self::tag( 'Refused: ' . wp_trim_words( $r['message'], 12 ), 'bad' ) ) . '</td><td>'; // phpcs:ignore
					if ( $r['backup_id'] ) {
						self::form_open( 'log', 'restore', 'wpc-inline' );
						echo '<input type="hidden" name="backup_id" value="' . (int) $r['backup_id'] . '"><button class="button button-small" onclick="return confirm(\'Restore the version from before this change?\')">Restore previous</button></form>';
					}
					echo '</td></tr>';
				}
				?>
				</tbody>
			</table></div>
			<?php self::form_open( 'log', 'save' ); ?>
				<?php self::row( 'Keep the last', 'Older entries are removed daily.', '<input type="number" min="100" name="log_keep" value="' . (int) $s['log_keep'] . '" class="small-text" aria-label="Entries to keep"> <span class="wpc-muted">entries</span> <button class="button">Save</button>' ); ?>
			</form>
		</section>
		<?php
	}

	private static function steps_html( $c ) {
		$url = rest_url( WPC_Auth::route_namespace() . '/mcp' );
		$out = '<ol class="wpc-steps">';
		foreach ( $c['steps'] as $st ) {
			$out .= '<li><span>' . esc_html( $st[0] ) . '</span>';
			if ( $st[1] ) {
				$out .= '<pre class="wpc-code wpc-pre">' . esc_html( str_replace( '{url}', $url, $st[1] ) ) . '</pre>';
			}
			$out .= '</li>';
		}
		return $out . '</ol>';
	}

	private static function tab_setup( $s ) {
		$view = isset( $_GET['app'] ) ? sanitize_key( $_GET['app'] ) : (string) $s['client']; // phpcs:ignore
		$c    = WPC_Clients::get( $view ) ?: WPC_Clients::get( 'other' );
		?>
		<section class="wpc-card">
			<h2>Which app are you connecting?</h2>
			<div class="wpc-btns" role="list">
				<?php foreach ( WPC_Clients::all() as $id => $x ) : ?>
					<a role="listitem" class="button <?php echo $id === $c['id'] ? 'button-primary' : ''; ?>" href="<?php echo esc_url( self::url( 'setup', array( 'app' => $id ) ) ); ?>" <?php echo $id === $c['id'] ? 'aria-current="true"' : ''; ?>><?php echo esc_html( $x['name'] ); ?></a>
				<?php endforeach; ?>
			</div>
		</section>
		<section class="wpc-card">
			<div class="wpc-btns"><h2>Connect <?php echo esc_html( $c['name'] ); ?></h2><?php echo self::tag( WPC_Clients::auth_label( $c['auth'] ), 'oauth' === $c['auth'] ? 'info' : 'ok' ); // phpcs:ignore ?></div>
			<span class="wpc-muted"><?php echo esc_html( $c['intro'] ); ?></span>
			<?php if ( 'oauth' === $c['auth'] && ! $s['oauth_on'] ) : ?>
				<div class="notice notice-warning inline"><p>OAuth sign-in is off. Switch it on in <a href="<?php echo esc_url( self::url( 'access' ) ); ?>">Access → Sign-in methods</a> first.</p></div>
			<?php endif; ?>
			<?php echo self::steps_html( $c ); // phpcs:ignore ?>
			<div class="wpc-btns">
				<?php self::form_open( 'overview', 'test', 'wpc-inline' ); ?><button class="button button-primary">Test connection</button></form>
				<?php if ( $c['id'] !== $s['client'] ) : ?>
					<?php self::form_open( 'setup', 'choose_client', 'wpc-inline' ); ?><input type="hidden" name="client" value="<?php echo esc_attr( $c['id'] ); ?>"><button class="button">Make <?php echo esc_html( $c['name'] ); ?> my app</button></form>
				<?php endif; ?>
			</div>
			<span class="wpc-muted">Before you start, create a dedicated administrator user such as <code>ai-dev</code> (Users → Add New) and generate the token for it. If the site is behind a server password (HTTP basic auth), use the x-api-key header, not Authorization. Menu names in each app can change between versions. To disconnect any app, revoke its token.</span>
		</section>
		<?php
	}

	/** Newer version number if the GitHub updater found one, else ''. */
	private static function update_available() {
		if ( ! class_exists( 'WPC_Updater' ) ) {
			$t = get_site_transient( 'update_plugins' );
			$r = $t->response[ plugin_basename( WPC_FILE ) ] ?? null;
			return $r && version_compare( $r->new_version, WPC_VERSION, '>' ) ? (string) $r->new_version : '';
		}
		$l = WPC_Updater::latest();
		return $l && version_compare( $l['version'], WPC_VERSION, '>' ) ? $l['version'] : '';
	}

	private static function tab_updates( $s ) {
		$base = plugin_basename( WPC_FILE );
		$new  = self::update_available();
		if ( $new ) {
			$update_url = wp_nonce_url( self_admin_url( 'update.php?action=upgrade-plugin&plugin=' . rawurlencode( $base ) ), 'upgrade-plugin_' . $base );
		}
		$gh = class_exists( 'WPC_Updater' );
		$latest = $gh ? WPC_Updater::latest() : null;
		?>
		<section class="wpc-card <?php echo $new ? 'wpc-update' : ''; ?>">
			<?php if ( $new ) : ?>
				<div class="wpc-head">
					<div class="wpc-stack"><h2>Version <?php echo esc_html( $new ); ?> is available</h2><span class="wpc-muted">You have <?php echo esc_html( WPC_VERSION ); ?><?php echo $latest && $latest['date'] ? ' · Released ' . esc_html( wp_date( 'Y-m-d', strtotime( $latest['date'] ) ) ) : ''; ?></span></div>
					<div class="wpc-btns"><a class="button button-primary" href="<?php echo esc_url( $update_url ); ?>">Update now</a><?php if ( $latest && $latest['url'] ) : ?><a class="button" href="<?php echo esc_url( $latest['url'] ); ?>" target="_blank" rel="noopener">View release on GitHub</a><?php endif; ?></div>
				</div>
				<?php if ( $latest && $latest['notes'] ) : ?><div class="wpc-notes"><strong>What’s new</strong><?php echo wpautop( esc_html( $latest['notes'] ) ); // phpcs:ignore ?></div><?php endif; ?>
				<span class="wpc-muted">Settings, tokens, file backups and the activity log are kept when you update.</span>
			<?php else : ?>
				<h2>You’re up to date</h2>
				<span class="wpc-muted">Version <?php echo esc_html( WPC_VERSION ); ?> is installed.</span>
			<?php endif; ?>
		</section>
		<?php self::form_open( 'updates', 'save' ); ?>
		<section class="wpc-card">
			<h2>Where updates come from</h2>
			<?php if ( $gh ) : ?>
				<span class="wpc-muted">This copy was installed from GitHub, so it checks the repository’s releases twice a day. Each release needs a <code>wp-connection.zip</code> file attached. When GitHub publishes a SHA-256 checksum for it, the download is checked before installing.</span>
				<div class="wpc-fields">
					<label><strong>GitHub repository</strong><input type="text" name="github_repo" value="<?php echo esc_attr( $s['github_repo'] ); ?>" placeholder="owner/wp-connection"></label>
					<label><strong>Release channel</strong><select name="update_channel"><option value="stable" <?php selected( $s['update_channel'], 'stable' ); ?>>Stable releases only</option><option value="beta" <?php selected( $s['update_channel'], 'beta' ); ?>>Include pre-releases (beta)</option></select></label>
					<label><strong>Access token (private repos only)</strong><input type="password" name="github_token" value="" autocomplete="off" placeholder="<?php echo $s['github_token'] ? 'Saved · type - to clear' : 'Leave empty for a public repo'; ?>"></label>
				</div>
				<?php $st = WPC_Updater::status(); ?>
				<?php if ( ! empty( $st['error'] ) ) : ?><div class="notice notice-error inline"><p><?php echo esc_html( $st['error'] ); ?></p></div><?php endif; ?>
			<?php else : ?>
				<span class="wpc-muted">This copy updates through WordPress.org, like any other plugin: Dashboard → Updates and the Plugins page.</span>
			<?php endif; ?>
			<?php ob_start(); self::sw( 'auto_update', self::auto_update_on(), 'Auto-update' ); self::row( 'Install updates automatically', 'Uses WordPress’s built-in auto-updates (the same switch as on the Plugins page).', ob_get_clean() ); ?>
			<p><button class="button button-primary">Save changes</button></p>
		</section>
		</form>
		<?php if ( $gh ) : ?>
		<section class="wpc-card">
			<div class="wpc-head"><h2>Version history</h2><?php self::form_open( 'updates', 'check_updates', 'wpc-inline' ); ?><button class="button">Check for updates</button></form></div>
			<?php $st = WPC_Updater::status(); ?>
			<span class="wpc-muted">Last checked: <?php echo ! empty( $st['checked'] ) ? esc_html( wp_date( 'Y-m-d H:i', $st['checked'] ) ) : 'not yet'; ?></span>
			<div class="wpc-scroll"><table class="wpc-table widefat">
				<thead><tr><th>Version</th><th>Released</th><th>Type</th><th></th></tr></thead>
				<tbody>
				<?php
				$rel = WPC_Updater::releases();
				if ( ! $rel ) {
					echo '<tr><td colspan="4" class="wpc-muted">No releases found yet.</td></tr>';
				}
				foreach ( $rel as $r ) {
					$installed = version_compare( $r['version'], WPC_VERSION, '=' );
					echo '<tr><td><strong>' . esc_html( $r['version'] ) . '</strong> ' . ( $installed ? self::tag( 'Installed', 'ok' ) : '' ) . '</td><td>' . esc_html( $r['date'] ? wp_date( 'Y-m-d', strtotime( $r['date'] ) ) : '' ) . '</td><td>' . ( $r['prerelease'] ? self::tag( 'Beta', 'warn' ) : 'Stable' ) . ( $r['package'] ? '' : ' ' . self::tag( 'No zip attached', 'bad' ) ) . '</td><td>' . ( $r['url'] ? '<a href="' . esc_url( $r['url'] ) . '" target="_blank" rel="noopener">Release notes</a>' : '' ) . '</td></tr>'; // phpcs:ignore
				}
				?>
				</tbody>
			</table></div>
		</section>
		<?php endif; ?>
		<?php
	}

	/** First-run screen: pick the AI app. */
	private static function welcome() {
		$cur = (string) WPC_Settings::get( 'client' );
		$mcp = class_exists( '\WP\MCP\Core\McpAdapter' );
		$https = 0 === strpos( home_url(), 'https://' );
		?>
		<div class="wrap wpc wpc-welcome">
			<ol class="wpc-progress" aria-label="Setup progress">
				<li class="on"><span>1</span>Choose your AI app</li>
				<li><span>2</span>Create access</li>
				<li><span>3</span>Test connection</li>
			</ol>
			<h1>Welcome to WP Connection</h1>
			<p class="wpc-muted wpc-lead">Let an AI assistant build and edit this staging site through the WordPress MCP Adapter. Pick the app you’ll use. You can connect more apps later.</p>
			<?php self::form_open( 'welcome', 'choose_client' ); ?>
				<fieldset class="wpc-picks">
					<legend class="screen-reader-text">AI app</legend>
					<?php foreach ( WPC_Clients::all() as $id => $c ) : ?>
						<label class="wpc-pick">
							<input type="radio" name="client" value="<?php echo esc_attr( $id ); ?>" <?php checked( $cur, $id ); ?> required>
							<span class="wpc-ico"><?php echo WPC_Clients::icon( $id, $c['kind'] ); // phpcs:ignore ?></span>
							<strong><?php echo esc_html( $c['name'] ); ?></strong>
							<span class="wpc-muted"><?php echo esc_html( $c['blurb'] ); ?></span>
							<?php echo self::tag( WPC_Clients::auth_label( $c['auth'] ), 'oauth' === $c['auth'] ? 'info' : 'ok' ); // phpcs:ignore ?>
						</label>
					<?php endforeach; ?>
				</fieldset>
				<section class="wpc-card wpc-next" aria-live="polite">
					<?php foreach ( WPC_Clients::all() as $id => $c ) : ?>
						<div class="wpc-next-app" data-app="<?php echo esc_attr( $id ); ?>" <?php echo $cur === $id ? '' : 'hidden'; ?>>
							<div class="wpc-btns"><h2>What happens next for <?php echo esc_html( $c['name'] ); ?></h2><?php echo self::tag( WPC_Clients::auth_label( $c['auth'] ), 'oauth' === $c['auth'] ? 'info' : 'ok' ); // phpcs:ignore ?></div>
							<span class="wpc-muted"><?php echo esc_html( $c['intro'] ); ?></span>
							<ol class="wpc-steps"><?php foreach ( $c['steps'] as $st ) { echo '<li>' . esc_html( $st[0] ) . '</li>'; } ?></ol>
						</div>
					<?php endforeach; ?>
					<div class="wpc-next-none" <?php echo $cur ? 'hidden' : ''; ?>><h2>What happens next</h2><span class="wpc-muted">Pick an app above to see the exact steps for it.</span></div>
				</section>
				<div class="wpc-checks">
					<span class="wpc-muted">Before you start:</span>
					<span><?php echo $mcp ? self::tag( 'Ready', 'ok' ) : self::tag( 'Missing', 'bad' ); // phpcs:ignore ?> MCP Adapter active</span>
					<span><?php echo $https ? self::tag( 'Ready', 'ok' ) : self::tag( 'Missing', 'bad' ); // phpcs:ignore ?> HTTPS</span>
					<span><?php echo self::tag( 'Ready', 'ok' ); // phpcs:ignore ?> Not a production domain</span>
				</div>
				<div class="wpc-btns">
					<button class="button button-primary button-hero" id="wpc-continue" <?php echo $cur ? '' : 'disabled'; ?>><?php echo $cur ? 'Continue with ' . esc_html( WPC_Clients::get( $cur )['name'] ) : 'Pick an app to continue'; ?></button>
				</div>
			</form>
			<?php self::form_open( 'overview', 'skip_welcome', 'wpc-inline' ); ?><button class="button-link">Skip, go to settings</button></form>
		</div>
		<script>
		( function () {
			var names = <?php echo wp_json_encode( array_map( function ( $c ) { return $c['name']; }, WPC_Clients::all() ) ); ?>;
			var btn = document.getElementById( 'wpc-continue' );
			document.querySelectorAll( '.wpc-pick input' ).forEach( function ( r ) {
				r.addEventListener( 'change', function () {
					document.querySelectorAll( '.wpc-next-app' ).forEach( function ( d ) { d.hidden = d.getAttribute( 'data-app' ) !== r.value; } );
					document.querySelector( '.wpc-next-none' ).hidden = true;
					btn.disabled = false;
					btn.textContent = 'Continue with ' + names[ r.value ];
				} );
			} );
		} )();
		</script>
		<?php
	}
}
