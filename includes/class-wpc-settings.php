<?php
/**
 * Settings: one option (wpc_settings) with defaults.
 */

defined( 'ABSPATH' ) || exit;

class WPC_Settings {

	const OPTION = 'wpc_settings';

	/** Tool switches: key => [label, description, kind (read|write|destructive), group, default]. */
	public static function tools() {
		$tools = array(
			'site_info'      => array( 'Site info', 'Versions, server limits, theme, plugins, staging status.', 'read', 'site', true ),
			'read_options'   => array( 'Read options', 'Read settings by name. Secret values are hidden.', 'read', 'site', true ),
			'update_options' => array( 'Update options', 'Change settings, e.g. SEO or page builder options. Protected settings are blocked.', 'write', 'site', true ),
			'maintenance'    => array( 'Maintenance', 'Flush permalinks and caches, regenerate Elementor CSS.', 'write', 'site', true ),
			'list_plugins'   => array( 'List plugins', 'Installed plugins with versions and status.', 'read', 'plugins', true ),
			'install_wporg'  => array( 'Install from wordpress.org', 'Install or update a plugin by its wordpress.org slug.', 'write', 'plugins', true ),
			'install_zip'    => array( 'Install from zip URL', 'For premium plugins you have a download link for (https only).', 'write', 'plugins', true ),
			'plugin_state'   => array( 'Activate / deactivate plugins', 'Change plugin status.', 'write', 'plugins', true ),
			'delete_plugins' => array( 'Delete plugins', 'Remove an inactive plugin\'s files.', 'destructive', 'plugins', false ),
			'switch_theme'   => array( 'Switch theme', 'Activate an installed theme.', 'write', 'plugins', true ),
			'read_posts'     => array( 'Read posts and pages', 'Including raw content and meta.', 'read', 'content', true ),
			'write_posts'    => array( 'Create and edit posts and pages', 'Title, slug, status, parent, template, content, meta, categories.', 'write', 'content', true ),
			'trash_posts'    => array( 'Move to trash', 'Recoverable from Trash.', 'write', 'content', true ),
			'delete_posts'   => array( 'Delete permanently', 'Cannot be undone.', 'destructive', 'content', false ),
			'terms'          => array( 'Categories and tags', 'Create and edit terms.', 'write', 'content', true ),
			'import_media'   => array( 'Import media from URL', 'Copy images from allowed hosts into the media library.', 'write', 'content', true ),
			'read_files'     => array( 'List and read files', 'Inspect theme and plugin code.', 'read', 'files', true ),
			'write_files'    => array( 'Write files', 'Create, overwrite or restore files in writable folders.', 'write', 'files', true ),
			'delete_files'   => array( 'Delete files', 'Remove files in writable folders.', 'destructive', 'files', true ),
			'fetch_url'      => array( 'Fetch URL', 'Read pages from this site or allowed hosts, as a visitor.', 'read', 'files', true ),
			'read_logs'      => array( 'Read logs', 'This plugin\'s activity log and debug.log.', 'read', 'files', true ),
		);
		if ( WPC_WPORG_BUILD ) {
			unset( $tools['install_zip'] ); // WordPress.org builds may only install from WordPress.org (guideline 8).
		}
		return $tools;
	}

	public static function groups() {
		return array(
			'site'    => array( 'Site', 'Information about this WordPress install.' ),
			'plugins' => array( 'Plugins & themes', 'Only approved plugins should be installed. Every install is logged.' ),
			'content' => array( 'Content', 'Pages, posts, page builder data, SEO fields, categories and media.' ),
			'files'   => array( 'Files', 'Limited to the folders on the Files & network tab.' ),
		);
	}

	public static function defaults() {
		$tools = array();
		foreach ( self::tools() as $k => $t ) {
			$tools[ $k ] = $t[4];
		}
		return array(
			'enabled'         => true,
			'client'          => '',
			'oauth_on'        => false,
			'oauth_redirect_hosts' => '',
			'github_repo'     => WPC_GITHUB_REPO,
			'update_channel'  => 'stable',
			'github_token'    => '',
			'tools'           => $tools,
			'mcp_only'        => true,
			'rate_limit'      => 120,
			'ip_allowlist'    => '',
			'theme_on'        => true,
			'theme_slug'      => '',
			'calc_on'         => true,
			'calc_slug'       => '',
			'mu_on'           => true,
			'mu_prefix'       => 'ai-',
			'extra_roots'     => '',
			'read_content'    => true,
			'php_check'       => true,
			'backups_on'      => true,
			'backups_keep'    => 10,
			'max_mb'          => 4,
			'fetch_hosts'     => '',
			'keep_paths'      => true,
			'skip_dupes'      => true,
			'blocked_hosts'   => '',
			'bind_domain'     => true,
			'noindex_header'  => true,
			'robots_block'    => true,
			'lock_discourage' => true,
			'email_redirect'  => true,
			'test_email'      => '',
			'log_keep'        => 1000,
		);
	}

	private static $cache = null;

	public static function all() {
		if ( null === self::$cache ) {
			$saved = get_option( self::OPTION, array() );
			$def   = self::defaults();
			$s     = is_array( $saved ) ? array_merge( $def, $saved ) : $def;
			$s['tools'] = array_merge( $def['tools'], is_array( $s['tools'] ) ? $s['tools'] : array() );
			self::$cache = $s;
		}
		return self::$cache;
	}

	public static function get( $key ) {
		$s = self::all();
		return $s[ $key ] ?? null;
	}

	public static function tool_on( $key ) {
		$s = self::all();
		return ! empty( $s['tools'][ $key ] );
	}

	public static function save( array $values ) {
		$s = array_merge( self::all(), $values );
		update_option( self::OPTION, $s, false );
		self::$cache = null;
	}

	/** Lines of a textarea setting as a clean lower-case list. */
	public static function lines( $key ) {
		$v = (string) self::get( $key );
		return array_values( array_filter( array_map( 'trim', preg_split( '/[\r\n,]+/', strtolower( $v ) ) ) ) );
	}
}
