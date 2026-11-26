<?php
/**
 * Plugin Name:       WP Connection
 * Description:       Connects a staging WordPress site to AI assistants (Claude, ChatGPT, Cursor, VS Code and any MCP client) through the WordPress MCP Adapter, with API-key or OAuth access, per-tool permissions, sandboxed file access, staging protection and an activity log. Refuses to run on blocked (production) domains.
 * Version:           1.1.0
 * Requires at least: 6.9
 * Requires PHP:      8.0
 * Requires Plugins:  mcp-adapter
 * Author:            Nishid Shajib
 * Plugin URI:        https://wp-connection.com
 * Author URI:        https://nishidshajib.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-connection
 */

defined( 'ABSPATH' ) || exit;

/*
 * Replacing the older "WP Connections" plugin: both share class names, so while the
 * old copy is active this one stays dormant and only offers to replace it.
 */
require_once __DIR__ . '/includes/class-wpc-migrate.php';
if ( class_exists( 'WPC_Settings', false ) || WPC_Migrate::old_active() ) {
	WPC_Migrate::init( __FILE__, true );
	return;
}
WPC_Migrate::init( __FILE__, false );

define( 'WPC_VERSION', '1.1.0' );
define( 'WPC_DB_VERSION', '1' );
define( 'WPC_FILE', __FILE__ );
define( 'WPC_DIR', __DIR__ );
define( 'WPC_URL', plugin_dir_url( __FILE__ ) );
// Default GitHub repository for updates (GitHub build only; editable on the Updates tab).
define( 'WPC_GITHUB_REPO', 'nishidshajib/wp-connection' );
// The WordPress.org build ships without the GitHub updater (see bin/build.sh).
define( 'WPC_WPORG_BUILD', ! file_exists( __DIR__ . '/includes/class-wpc-updater.php' ) );

require_once WPC_DIR . '/includes/class-wpc-settings.php';
require_once WPC_DIR . '/includes/class-wpc-install.php';
require_once WPC_DIR . '/includes/class-wpc-guard.php';
require_once WPC_DIR . '/includes/class-wpc-auth.php';
require_once WPC_DIR . '/includes/class-wpc-log.php';
require_once WPC_DIR . '/includes/class-wpc-files.php';
require_once WPC_DIR . '/includes/class-wpc-abilities.php';
require_once WPC_DIR . '/includes/class-wpc-clients.php';
require_once WPC_DIR . '/includes/class-wpc-oauth.php';
if ( ! WPC_WPORG_BUILD ) {
	require_once WPC_DIR . '/includes/class-wpc-updater.php';
}
require_once WPC_DIR . '/includes/class-wpc-admin.php';
require_once WPC_DIR . '/includes/class-wpc-deactivate.php';

register_activation_hook( __FILE__, array( 'WPC_Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'WPC_Install', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'WPC_Install', 'maybe_upgrade' ) );
WPC_Guard::init();
WPC_Auth::init();
WPC_Log::init();
WPC_Abilities::init();
WPC_OAuth::init();
if ( ! WPC_WPORG_BUILD ) {
	WPC_Updater::init();
}
WPC_Admin::init();
WPC_Deactivate::init();
