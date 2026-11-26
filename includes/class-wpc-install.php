<?php
/**
 * Install, upgrade, deactivate and full data removal.
 *
 * Everything this plugin stores in the database is listed here, so removal
 * deletes exactly that and nothing else:
 *   tables:     {prefix}wpc_activity_log, {prefix}wpc_file_backups
 *   options:    wpc_settings, wpc_tokens, wpc_db_version, wpc_oauth_clients
 *   site transients: wpc_gh_releases (GitHub build)
 *   transients: wpc_*
 *   user meta:  wpc_*
 *   cron:       wpc_daily_cleanup
 */

defined( 'ABSPATH' ) || exit;

class WPC_Install {

	const OPTIONS = array( 'wpc_settings', 'wpc_tokens', 'wpc_db_version', 'wpc_oauth_clients' );
	const CRON    = 'wpc_daily_cleanup';

	/** The plugin's own tables (full names). */
	public static function tables() {
		global $wpdb;
		return array(
			'log'     => $wpdb->prefix . 'wpc_activity_log',
			'backups' => $wpdb->prefix . 'wpc_file_backups',
		);
	}

	public static function activate() {
		self::create_tables();
		if ( false === get_option( WPC_Settings::OPTION ) ) {
			add_option( WPC_Settings::OPTION, WPC_Settings::defaults(), '', false );
		}
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON );
		}
		if ( ! class_exists( '\WP\MCP\Core\McpAdapter' ) ) {
			set_transient( 'wpc_adapter_dialog', 1, HOUR_IN_SECONDS ); // Offer the one-click MCP Adapter install.
		}
	}

	/** Temporary deactivation: keep all data; just stop the scheduled task. */
	public static function deactivate() {
		delete_site_transient( 'wpc_gh_releases' );
		wp_clear_scheduled_hook( self::CRON );
	}

	public static function maybe_upgrade() {
		if ( get_option( 'wpc_db_version' ) !== WPC_DB_VERSION ) {
			self::create_tables();
		}
	}

	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$c = $wpdb->get_charset_collate();
		$t = self::tables();
		dbDelta(
			"CREATE TABLE {$t['log']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				created datetime NOT NULL,
				token varchar(100) NOT NULL DEFAULT '',
				tool varchar(64) NOT NULL DEFAULT '',
				target varchar(255) NOT NULL DEFAULT '',
				ok tinyint(1) NOT NULL DEFAULT 1,
				is_write tinyint(1) NOT NULL DEFAULT 0,
				message text NULL,
				input longtext NULL,
				backup_id bigint(20) unsigned NULL,
				PRIMARY KEY  (id),
				KEY created (created),
				KEY tool (tool)
			) $c;"
		);
		dbDelta(
			"CREATE TABLE {$t['backups']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				created datetime NOT NULL,
				root varchar(32) NOT NULL DEFAULT '',
				path varchar(255) NOT NULL DEFAULT '',
				bytes int(10) unsigned NOT NULL DEFAULT 0,
				md5 char(32) NOT NULL DEFAULT '',
				content longblob NULL,
				PRIMARY KEY  (id),
				KEY file (root, path(191))
			) $c;"
		);
		update_option( 'wpc_db_version', WPC_DB_VERSION, false );
	}

	/**
	 * Remove every piece of data this plugin created. Touches nothing else.
	 */
	public static function remove_all_data() {
		global $wpdb;
		foreach ( self::tables() as $table ) {
			// Table names are built from $wpdb->prefix and fixed strings only.
			$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore
		}
		foreach ( self::OPTIONS as $o ) {
			delete_option( $o );
		}
		$like = $wpdb->esc_like( '_transient_wpc_' ) . '%';
		$like2 = $wpdb->esc_like( '_transient_timeout_wpc_' ) . '%';
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $like, $like2 ) ); // phpcs:ignore
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", $wpdb->esc_like( 'wpc_' ) . '%' ) ); // phpcs:ignore
		delete_site_transient( 'wpc_gh_releases' );
		wp_clear_scheduled_hook( self::CRON );
		wp_cache_flush();
	}
}
