<?php
/**
 * Runs when the plugin is deleted from the Plugins screen.
 * Removes only WP Connection' own tables and settings.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/class-wpc-settings.php';
require_once __DIR__ . '/includes/class-wpc-install.php';

WPC_Install::remove_all_data();
