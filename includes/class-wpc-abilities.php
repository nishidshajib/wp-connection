<?php
/**
 * Abilities exposed to AI apps as MCP tools at /wp-json/wp-connection/mcp.
 */

defined( 'ABSPATH' ) || exit;

class WPC_Abilities {

	const NS       = 'wp-connection';
	const CATEGORY = 'wp-connection';

	/** Options the AI can never change. */
	const PROTECTED_OPTIONS = array( 'siteurl', 'home', 'admin_email', 'new_admin_email', 'users_can_register', 'default_role', 'active_plugins', 'recovery_keys', 'cron', 'auth_key', 'secure_auth_key', 'logged_in_key', 'nonce_key', 'auth_salt', 'secure_auth_salt', 'logged_in_salt', 'nonce_salt' );

	public static function init() {
		add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register' ) );
		add_action( 'mcp_adapter_init', array( __CLASS__, 'register_server' ) );
	}

	public static function register_category() {
		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => 'WP Connection',
				'description' => 'Staging tools for building and migrating this site with an AI assistant.',
			)
		);
	}

	/**
	 * name => [label, description, properties, required, method, readonly, destructive, toggles (any one on = available)].
	 */
	private static function definitions() {
		$str  = array( 'type' => 'string' );
		$int  = array( 'type' => 'integer' );
		$bool = array( 'type' => 'boolean' );
		$obj  = array( 'type' => 'object' );
		$any  = array( 'type' => array( 'string', 'number', 'integer', 'boolean', 'array', 'object', 'null' ) );
		$root = array( 'type' => 'string', 'description' => 'Folder key. Call site-info to see available folders (e.g. theme, calculator, mu-plugins, content = wp-content read-only).' );

		return array(
			'site-info'        => array( 'Site info', 'WordPress, PHP and server details, active theme, plugins, writable folders, enabled tools and staging-protection status.', array(), array(), 'site_info', true, false, array( 'site_info' ) ),
			'list-plugins'     => array( 'List plugins', 'All installed plugins with version and active status.', array(), array(), 'list_plugins', true, false, array( 'list_plugins' ) ),
			'install-plugin'   => array( 'Install plugin', 'Install (or update with overwrite=true) a plugin from wordpress.org by slug, or from an https zip URL. Optionally activate it.', array( 'slug' => $str, 'zip_url' => $str, 'activate' => $bool, 'overwrite' => $bool ), array(), 'install_plugin', false, false, array( 'install_wporg', 'install_zip' ) ),
			'set-plugin-state' => array( 'Activate, deactivate or delete a plugin', 'plugin = plugin file from list-plugins, e.g. "seo-by-rank-math/rank-math.php". Delete requires it to be inactive.', array( 'plugin' => $str, 'action' => array( 'type' => 'string', 'enum' => array( 'activate', 'deactivate', 'delete' ) ) ), array( 'plugin', 'action' ), 'set_plugin_state', false, true, array( 'plugin_state' ) ),
			'activate-theme'   => array( 'Activate theme', 'Switch the active theme by folder name.', array( 'stylesheet' => $str ), array( 'stylesheet' ), 'activate_theme', false, false, array( 'switch_theme' ) ),
			'get-option'       => array( 'Get options', 'Read one or more options by name. Secret values are hidden.', array( 'names' => array( 'type' => 'array', 'items' => $str ) ), array( 'names' ), 'get_option', true, false, array( 'read_options' ) ),
			'update-option'    => array( 'Update option', 'Create or update an option (any JSON value). Core URL, user and security options are protected.', array( 'name' => $str, 'value' => $any, 'autoload' => $bool ), array( 'name', 'value' ), 'update_option', false, false, array( 'update_options' ) ),
			'list-posts'       => array( 'List posts', 'List posts, pages or any post type with filters.', array( 'post_type' => $str, 'status' => $str, 'search' => $str, 'parent' => $int, 'per_page' => $int, 'page' => $int, 'orderby' => $str, 'order' => $str ), array(), 'list_posts', true, false, array( 'read_posts' ) ),
			'get-post'         => array( 'Get post', 'Get one post by id or path with raw content, terms and optionally meta (meta_keys limits which keys).', array( 'id' => $int, 'path' => $str, 'post_type' => $str, 'include_meta' => $bool, 'meta_keys' => array( 'type' => 'array', 'items' => $str ) ), array(), 'get_post', true, false, array( 'read_posts' ) ),
			'upsert-post'      => array( 'Create or update post', 'Create (no id) or update (id) a post or page: title, slug, status, parent, menu_order, template, date, raw content, excerpt, meta object (null deletes a key) and terms (taxonomy => slugs).', array( 'id' => $int, 'post_type' => $str, 'title' => $str, 'slug' => $str, 'status' => $str, 'content' => $str, 'excerpt' => $str, 'parent' => $int, 'menu_order' => $int, 'template' => $str, 'date' => $str, 'author' => $int, 'comment_status' => $str, 'meta' => $obj, 'terms' => $obj ), array(), 'upsert_post', false, false, array( 'write_posts' ) ),
			'trash-post'       => array( 'Trash post', 'Move a post to the trash. force=true deletes permanently (only if that tool is switched on).', array( 'id' => $int, 'force' => $bool ), array( 'id' ), 'trash_post', false, true, array( 'trash_posts', 'delete_posts' ) ),
			'upsert-term'      => array( 'Create or update term', 'Create or update a category, tag or other term by slug.', array( 'taxonomy' => $str, 'name' => $str, 'slug' => $str, 'description' => $str, 'parent' => $int, 'meta' => $obj ), array( 'taxonomy', 'slug' ), 'upsert_term', false, false, array( 'terms' ) ),
			'import-media'     => array( 'Import media from URL', 'Copy a file from an allowed host into the media library, keeping its file name and /uploads/YYYY/MM/ folder. Skips files already imported from the same URL.', array( 'url' => $str, 'year_month' => $str, 'title' => $str, 'alt' => $str, 'caption' => $str, 'description' => $str, 'parent' => $int, 'date' => $str ), array( 'url' ), 'import_media', false, false, array( 'import_media' ) ),
			'list-files'       => array( 'List files', 'List files in an allowed folder.', array( 'root' => $root, 'path' => $str, 'recursive' => $bool ), array( 'root' ), 'list_files', true, false, array( 'read_files' ) ),
			'read-file'        => array( 'Read file', 'Read a file in an allowed folder (text as utf8, binary as base64).', array( 'root' => $root, 'path' => $str ), array( 'root', 'path' ), 'read_file', true, false, array( 'read_files' ) ),
			'write-file'       => array( 'Write file', 'Create or overwrite a file in a writable folder. PHP is syntax-checked first; the previous version is backed up. encoding: utf8 (default) or base64 for binary files.', array( 'root' => $root, 'path' => $str, 'content' => $str, 'encoding' => array( 'type' => 'string', 'enum' => array( 'utf8', 'base64' ) ) ), array( 'root', 'path', 'content' ), 'write_file', false, false, array( 'write_files' ) ),
			'list-backups'     => array( 'List file backups', 'Previous versions stored for a file.', array( 'root' => $root, 'path' => $str ), array( 'root', 'path' ), 'list_backups', true, false, array( 'read_files' ) ),
			'restore-file'     => array( 'Restore file backup', 'Put back a previous version of a file by backup id.', array( 'backup_id' => $int ), array( 'backup_id' ), 'restore_file', false, false, array( 'write_files' ) ),
			'delete-file'      => array( 'Delete file', 'Delete a file (backed up first) or an empty folder in a writable folder.', array( 'root' => $root, 'path' => $str ), array( 'root', 'path' ), 'delete_file', false, true, array( 'delete_files' ) ),
			'fetch-url'        => array( 'Fetch URL', 'GET a URL from the server as an anonymous visitor: status, headers and body. Allowed: this site and the hosts in settings.', array( 'url' => $str, 'max_bytes' => $int ), array( 'url' ), 'fetch_url', true, false, array( 'fetch_url' ) ),
			'maintenance'      => array( 'Maintenance', 'flush_rewrite, flush_cache, elementor_css (regenerate) or all.', array( 'task' => array( 'type' => 'string', 'enum' => array( 'flush_rewrite', 'flush_cache', 'elementor_css', 'all' ) ) ), array( 'task' ), 'maintenance', false, false, array( 'maintenance' ) ),
			'read-log'         => array( 'Read activity log', 'Latest WP Connection activity entries and the tail of wp-content/debug.log.', array( 'limit' => $int ), array(), 'read_log', true, false, array( 'read_logs' ) ),
		);
	}

	public static function available( $name ) {
		$d = self::definitions()[ $name ] ?? null;
		if ( ! $d ) {
			return false;
		}
		foreach ( $d[7] as $toggle ) {
			if ( WPC_Settings::tool_on( $toggle ) ) {
				return true;
			}
		}
		return false;
	}

	/** Ability names that are switched on. */
	public static function enabled_names() {
		$out = array();
		foreach ( array_keys( self::definitions() ) as $n ) {
			if ( self::available( $n ) ) {
				$out[] = self::NS . '/' . $n;
			}
		}
		return $out;
	}

	public static function register() {
		foreach ( self::definitions() as $name => $d ) {
			list( $label, $desc, $props, $required, $method, $readonly, $destructive ) = $d;
			$schema = array( 'type' => 'object', 'properties' => $props, 'additionalProperties' => false, 'default' => new stdClass() );
			if ( $required ) {
				$schema['required'] = $required;
			}
			wp_register_ability(
				self::NS . '/' . $name,
				array(
					'label'               => $label,
					'description'         => $desc,
					'category'            => self::CATEGORY,
					'input_schema'        => $schema,
					'execute_callback'    => function ( $input = null ) use ( $name, $method, $readonly ) {
						return WPC_Abilities::run( $name, $method, is_array( $input ) ? $input : array(), $readonly );
					},
					'permission_callback' => function () use ( $name, $readonly ) {
						return WPC_Abilities::permission( $name, $readonly );
					},
					'meta'                => array(
						'show_in_rest' => false,
						'annotations'  => array( 'readonly' => $readonly, 'destructive' => $destructive, 'idempotent' => $readonly ),
						'mcp'          => array( 'public' => true, 'type' => 'tool' ),
					),
				)
			);
		}
	}

	public static function register_server( $adapter ) {
		if ( WPC_Guard::is_blocked() || ! WPC_Settings::get( 'enabled' ) || ! class_exists( '\WP\MCP\Transport\HttpTransport' ) ) {
			return;
		}
		try {
			$adapter->create_server(
				'wp-connection',
				self::NS,
				'mcp',
				'WP Connection',
				'Staging tools for building and migrating this site with an AI assistant.',
				WPC_VERSION,
				array( \WP\MCP\Transport\HttpTransport::class ),
				\WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler::class,
				null,
				self::enabled_names(),
				array(),
				array(),
				array( __CLASS__, 'transport_permission' )
			);
		} catch ( \Throwable $e ) {
			error_log( 'WP Connection: could not create MCP server: ' . $e->getMessage() ); // phpcs:ignore
		}
	}

	public static function transport_permission() {
		return WPC_Settings::get( 'enabled' ) && ! WPC_Guard::is_blocked() && WPC_Auth::is_token_request() && current_user_can( 'manage_options' );
	}

	public static function permission( $name, $readonly ) {
		if ( WPC_Guard::is_blocked() || ! WPC_Settings::get( 'enabled' ) || ! current_user_can( 'manage_options' ) ) {
			return false;
		}
		if ( ! self::available( $name ) ) {
			return new WP_Error( 'wpc_tool_off', 'This tool is switched off in WP Connection → Tools & permissions.' );
		}
		if ( ! $readonly && WPC_Auth::is_readonly() ) {
			return new WP_Error( 'wpc_readonly_token', 'This token is read-only.' );
		}
		return true;
	}

	/** Wrapper: guard, run, log. */
	public static function run( $name, $method, $input, $readonly ) {
		if ( WPC_Guard::is_blocked() ) {
			return new WP_Error( 'wpc_blocked', 'WP Connection is disabled on this domain.' );
		}
		try {
			$result = call_user_func( array( __CLASS__, 'do_' . $method ), $input );
		} catch ( \Throwable $e ) {
			$result = new WP_Error( 'wpc_exception', $e->getMessage() );
		}
		$target = '';
		foreach ( array( 'path', 'url', 'slug', 'plugin', 'name', 'stylesheet', 'task' ) as $k ) {
			if ( ! empty( $input[ $k ] ) && is_string( $input[ $k ] ) ) {
				$target = ( isset( $input['root'] ) ? $input['root'] . '/' : '' ) . $input[ $k ];
				break;
			}
		}
		if ( ! $target && ! empty( $input['id'] ) ) {
			$target = '#' . (int) $input['id'];
		}
		if ( ! $target && is_array( $result ) && ! empty( $result['id'] ) ) {
			$target = '#' . (int) $result['id'];
		}
		$backup = is_array( $result ) && ! empty( $result['backup_id'] ) ? (int) $result['backup_id'] : null;
		WPC_Log::add( $name, $target, ! is_wp_error( $result ), is_wp_error( $result ) ? $result->get_error_message() : '', $input, ! $readonly, $backup );
		return $result;
	}

	private static function admin_includes() {
		foreach ( array( 'file', 'misc', 'plugin', 'plugin-install', 'class-wp-upgrader', 'theme', 'media', 'image' ) as $f ) {
			require_once ABSPATH . 'wp-admin/includes/' . $f . '.php';
		}
	}

	/* ---------------------------------------------------------------- site */

	public static function do_site_info( $in ) {
		global $wp_version, $wpdb;
		self::admin_includes();
		$theme = wp_get_theme();
		return array(
			'site'        => array(
				'home'       => home_url(),
				'siteurl'    => site_url(),
				'name'       => get_bloginfo( 'name' ),
				'language'   => get_locale(),
				'timezone'   => wp_timezone_string(),
				'permalinks' => get_option( 'permalink_structure' ),
				'front_page' => array( 'show_on_front' => get_option( 'show_on_front' ), 'page_on_front' => (int) get_option( 'page_on_front' ), 'page_for_posts' => (int) get_option( 'page_for_posts' ) ),
			),
			'staging'     => array(
				'search_engines_discouraged' => '0' === (string) get_option( 'blog_public' ),
				'x_robots_tag_header'        => (bool) WPC_Settings::get( 'noindex_header' ),
				'robots_txt_blocks_all'      => (bool) WPC_Settings::get( 'robots_block' ),
				'email_redirected_to'        => WPC_Settings::get( 'email_redirect' ) ? ( WPC_Settings::get( 'test_email' ) ? WPC_Settings::get( 'test_email' ) : 'blocked (no test address)' ) : 'off',
				'https'                      => is_ssl() || 0 === strpos( home_url(), 'https://' ),
			),
			'versions'    => array(
				'wordpress' => $wp_version,
				'php'       => PHP_VERSION,
				'mysql'     => $wpdb->db_server_info(),
				'server'    => isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '',
				'wp_connection' => WPC_VERSION,
				'mcp_adapter'    => defined( 'WP_MCP_VERSION' ) ? WP_MCP_VERSION : null,
			),
			'limits'      => array(
				'memory_limit'        => ini_get( 'memory_limit' ),
				'wp_memory_limit'     => WP_MEMORY_LIMIT,
				'max_execution_time'  => ini_get( 'max_execution_time' ),
				'upload_max_filesize' => ini_get( 'upload_max_filesize' ),
				'post_max_size'       => ini_get( 'post_max_size' ),
			),
			'environment' => array(
				'wp_environment_type' => wp_get_environment_type(),
				'wp_debug'            => WP_DEBUG,
				'filesystem_method'   => get_filesystem_method(),
				'object_cache'        => wp_using_ext_object_cache(),
				'extensions'          => array_values( array_intersect( array( 'imagick', 'gd', 'zip', 'curl', 'mbstring', 'intl', 'opcache', 'exif', 'redis', 'memcached' ), array_map( 'strtolower', get_loaded_extensions() ) ) ),
				'webp_support'        => wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) ),
				'avif_support'        => wp_image_editor_supports( array( 'mime_type' => 'image/avif' ) ),
			),
			'theme'       => array( 'name' => $theme->get( 'Name' ), 'stylesheet' => $theme->get_stylesheet(), 'template' => $theme->get_template(), 'version' => $theme->get( 'Version' ) ),
			'plugins'     => self::do_list_plugins( array() ),
			'file_roots'  => array_map( function ( $r ) { return array( 'folder' => $r[2], 'writable' => $r[1] ); }, WPC_Files::roots() ),
			'enabled_tools' => array_keys( array_filter( WPC_Settings::get( 'tools' ) ) ),
			'counts'      => array(
				'posts'       => (int) wp_count_posts( 'post' )->publish,
				'pages'       => (int) wp_count_posts( 'page' )->publish,
				'attachments' => (int) wp_count_posts( 'attachment' )->inherit,
			),
		);
	}

	public static function do_list_plugins( $in ) {
		self::admin_includes();
		$out = array();
		foreach ( get_plugins() as $file => $p ) {
			$out[] = array( 'file' => $file, 'name' => $p['Name'], 'version' => $p['Version'], 'active' => is_plugin_active( $file ) );
		}
		foreach ( get_mu_plugins() as $file => $p ) {
			$out[] = array( 'file' => 'mu-plugins/' . $file, 'name' => $p['Name'], 'version' => $p['Version'], 'active' => true );
		}
		return $out;
	}

	public static function do_install_plugin( $in ) {
		self::admin_includes();
		if ( 'direct' !== get_filesystem_method() ) {
			return new WP_Error( 'wpc_fs', 'Filesystem method is not "direct"; plugin installs need direct file access on this host.' );
		}
		WP_Filesystem();
		if ( ! empty( $in['zip_url'] ) ) {
			if ( ! WPC_Settings::tool_on( 'install_zip' ) ) {
				return new WP_Error( 'wpc_tool_off', 'Installing from a zip URL is switched off in WP Connection.' );
			}
			$pkg = esc_url_raw( $in['zip_url'] );
			if ( 0 !== strpos( $pkg, 'https://' ) ) {
				return new WP_Error( 'wpc_url', 'zip_url must be https.' );
			}
		} elseif ( ! empty( $in['slug'] ) ) {
			if ( ! WPC_Settings::tool_on( 'install_wporg' ) ) {
				return new WP_Error( 'wpc_tool_off', 'Installing from wordpress.org is switched off in WP Connection.' );
			}
			$api = plugins_api( 'plugin_information', array( 'slug' => sanitize_key( $in['slug'] ), 'fields' => array( 'sections' => false ) ) );
			if ( is_wp_error( $api ) ) {
				return $api;
			}
			$pkg = $api->download_link;
		} else {
			return new WP_Error( 'wpc_input', 'Give slug or zip_url.' );
		}
		$skin     = new WP_Ajax_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );
		$res      = $upgrader->install( $pkg, array( 'overwrite_package' => ! empty( $in['overwrite'] ) ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( ! $res ) {
			$errs = $skin->get_errors();
			return is_wp_error( $errs ) && $errs->has_errors() ? $errs : new WP_Error( 'wpc_install', 'Install failed: ' . implode( ' | ', (array) $skin->get_upgrade_messages() ) );
		}
		$file = $upgrader->plugin_info();
		$out  = array( 'installed' => $file );
		if ( ! empty( $in['activate'] ) && $file ) {
			$act = activate_plugin( $file );
			if ( is_wp_error( $act ) ) {
				return $act;
			}
			$out['active'] = true;
		}
		return $out;
	}

	public static function do_set_plugin_state( $in ) {
		self::admin_includes();
		$file = $in['plugin'];
		if ( plugin_basename( WPC_FILE ) === $file && 'activate' !== $in['action'] ) {
			return new WP_Error( 'wpc_self', 'WP Connection cannot deactivate itself; do that in wp-admin.' );
		}
		if ( 'delete' === $in['action'] && ! WPC_Settings::tool_on( 'delete_plugins' ) ) {
			return new WP_Error( 'wpc_tool_off', 'Deleting plugins is switched off in WP Connection.' );
		}
		if ( 0 === strpos( $file, 'mcp-adapter/' ) && 'activate' !== $in['action'] ) {
			return new WP_Error( 'wpc_self', 'The MCP Adapter is needed for this connection; change it in wp-admin.' );
		}
		if ( ! array_key_exists( $file, get_plugins() ) ) {
			return new WP_Error( 'wpc_not_found', 'Plugin not found. Use list-plugins for exact file names.' );
		}
		switch ( $in['action'] ) {
			case 'activate':
				$r = activate_plugin( $file );
				break;
			case 'deactivate':
				deactivate_plugins( $file );
				$r = null;
				break;
			case 'delete':
				if ( is_plugin_active( $file ) ) {
					return new WP_Error( 'wpc_active', 'Deactivate it first.' );
				}
				WP_Filesystem();
				$r = delete_plugins( array( $file ) );
				break;
		}
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		return array( 'plugin' => $file, 'action' => $in['action'], 'ok' => true );
	}

	public static function do_activate_theme( $in ) {
		$theme = wp_get_theme( $in['stylesheet'] );
		if ( ! $theme->exists() ) {
			return new WP_Error( 'wpc_not_found', 'Theme folder not found.' );
		}
		if ( $theme->errors() ) {
			return $theme->errors();
		}
		switch_theme( $theme->get_stylesheet() );
		return array( 'active' => get_stylesheet() );
	}

	/* ------------------------------------------------------------- options */

	public static function do_get_option( $in ) {
		$out = array();
		foreach ( (array) $in['names'] as $n ) {
			if ( 0 === strpos( strtolower( $n ), 'wpc_' ) || preg_match( '/(_key|_salt|password|secret|token)$/i', $n ) ) {
				$out[ $n ] = '[hidden]';
				continue;
			}
			$out[ $n ] = get_option( $n, null );
		}
		return $out;
	}

	public static function do_update_option( $in ) {
		$n = (string) $in['name'];
		global $wpdb;
		if ( in_array( strtolower( $n ), self::PROTECTED_OPTIONS, true ) || 0 === strpos( strtolower( $n ), 'wpc_' ) || $wpdb->prefix . 'user_roles' === $n ) {
			return new WP_Error( 'wpc_protected', "Option '$n' is protected." );
		}
		$autoload = isset( $in['autoload'] ) ? (bool) $in['autoload'] : null;
		update_option( $n, $in['value'], $autoload );
		return array( 'name' => $n, 'value' => get_option( $n ) );
	}

	/* --------------------------------------------------------------- posts */

	public static function do_list_posts( $in ) {
		$q = new WP_Query(
			array(
				'post_type'           => $in['post_type'] ?? 'any',
				'post_status'         => $in['status'] ?? 'any',
				's'                   => $in['search'] ?? '',
				'post_parent'         => isset( $in['parent'] ) ? (int) $in['parent'] : '',
				'posts_per_page'      => min( 200, max( 1, (int) ( $in['per_page'] ?? 50 ) ) ),
				'paged'               => max( 1, (int) ( $in['page'] ?? 1 ) ),
				'orderby'             => $in['orderby'] ?? 'ID',
				'order'               => $in['order'] ?? 'ASC',
				'no_found_rows'       => false,
				'ignore_sticky_posts' => true,
				'suppress_filters'    => true,
			)
		);
		$items = array();
		foreach ( $q->posts as $p ) {
			$items[] = array(
				'id'       => $p->ID,
				'type'     => $p->post_type,
				'status'   => $p->post_status,
				'title'    => $p->post_title,
				'slug'     => $p->post_name,
				'parent'   => $p->post_parent,
				'link'     => get_permalink( $p ),
				'template' => get_page_template_slug( $p ),
				'modified' => $p->post_modified_gmt,
			);
		}
		return array( 'total' => (int) $q->found_posts, 'pages' => (int) $q->max_num_pages, 'items' => $items );
	}

	public static function do_get_post( $in ) {
		$p = null;
		if ( ! empty( $in['id'] ) ) {
			$p = get_post( (int) $in['id'] );
		} elseif ( ! empty( $in['path'] ) ) {
			$p = get_page_by_path( trim( $in['path'], '/' ), OBJECT, $in['post_type'] ?? array( 'page', 'post' ) );
		}
		if ( ! $p ) {
			return new WP_Error( 'wpc_not_found', 'Post not found.' );
		}
		$out = array(
			'id'             => $p->ID,
			'type'           => $p->post_type,
			'status'         => $p->post_status,
			'title'          => $p->post_title,
			'slug'           => $p->post_name,
			'parent'         => $p->post_parent,
			'menu_order'     => $p->menu_order,
			'author'         => (int) $p->post_author,
			'date'           => $p->post_date,
			'modified'       => $p->post_modified,
			'link'           => get_permalink( $p ),
			'template'       => get_page_template_slug( $p ),
			'excerpt'        => $p->post_excerpt,
			'content'        => $p->post_content,
			'comment_status' => $p->comment_status,
			'terms'          => array(),
		);
		foreach ( get_object_taxonomies( $p->post_type ) as $tax ) {
			$terms = wp_get_object_terms( $p->ID, $tax, array( 'fields' => 'slugs' ) );
			if ( ! is_wp_error( $terms ) && $terms ) {
				$out['terms'][ $tax ] = $terms;
			}
		}
		if ( ! empty( $in['include_meta'] ) || ! empty( $in['meta_keys'] ) ) {
			$meta = array();
			foreach ( get_post_meta( $p->ID ) as $k => $vals ) {
				if ( ! empty( $in['meta_keys'] ) && ! in_array( $k, $in['meta_keys'], true ) ) {
					continue;
				}
				if ( in_array( $k, array( '_edit_lock', '_edit_last' ), true ) ) {
					continue;
				}
				$v          = array_map( 'maybe_unserialize', $vals );
				$meta[ $k ] = 1 === count( $v ) ? $v[0] : $v;
			}
			$out['meta'] = $meta;
		}
		return $out;
	}

	public static function do_upsert_post( $in ) {
		$map = array(
			'post_type'      => 'post_type',
			'title'          => 'post_title',
			'slug'           => 'post_name',
			'status'         => 'post_status',
			'content'        => 'post_content',
			'excerpt'        => 'post_excerpt',
			'parent'         => 'post_parent',
			'menu_order'     => 'menu_order',
			'date'           => 'post_date',
			'author'         => 'post_author',
			'comment_status' => 'comment_status',
		);
		$arr = array();
		foreach ( $map as $k => $wpk ) {
			if ( array_key_exists( $k, $in ) ) {
				$arr[ $wpk ] = $in[ $k ];
			}
		}
		if ( ! empty( $in['id'] ) ) {
			if ( ! get_post( (int) $in['id'] ) ) {
				return new WP_Error( 'wpc_not_found', 'Post not found.' );
			}
			$arr['ID'] = (int) $in['id'];
			$id        = wp_update_post( wp_slash( $arr ), true );
		} else {
			$arr += array( 'post_type' => 'page', 'post_status' => 'draft' );
			if ( empty( $arr['post_author'] ) ) {
				$arr['post_author'] = get_current_user_id();
			}
			$id = wp_insert_post( wp_slash( $arr ), true );
		}
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		if ( isset( $in['template'] ) ) {
			update_post_meta( $id, '_wp_page_template', $in['template'] ? $in['template'] : 'default' );
		}
		if ( ! empty( $in['meta'] ) && is_array( $in['meta'] ) ) {
			foreach ( $in['meta'] as $k => $v ) {
				if ( null === $v ) {
					delete_post_meta( $id, $k );
				} else {
					update_post_meta( $id, $k, wp_slash( $v ) );
				}
			}
		}
		if ( ! empty( $in['terms'] ) && is_array( $in['terms'] ) ) {
			foreach ( $in['terms'] as $tax => $terms ) {
				$r = wp_set_object_terms( $id, (array) $terms, $tax );
				if ( is_wp_error( $r ) ) {
					return $r;
				}
			}
		}
		clean_post_cache( $id );
		$p = get_post( $id );
		return array( 'id' => $id, 'status' => $p->post_status, 'slug' => $p->post_name, 'link' => get_permalink( $id ) );
	}

	public static function do_trash_post( $in ) {
		$id = (int) $in['id'];
		if ( ! get_post( $id ) ) {
			return new WP_Error( 'wpc_not_found', 'Post not found.' );
		}
		if ( ! empty( $in['force'] ) && ! WPC_Settings::tool_on( 'delete_posts' ) ) {
			return new WP_Error( 'wpc_tool_off', 'Permanent delete is switched off in WP Connection; the post can only be trashed.' );
		}
		$r = ! empty( $in['force'] ) ? wp_delete_post( $id, true ) : wp_trash_post( $id );
		return $r ? array( 'id' => $id, 'deleted' => ! empty( $in['force'] ), 'trashed' => empty( $in['force'] ) ) : new WP_Error( 'wpc_trash', 'Could not trash post.' );
	}

	public static function do_upsert_term( $in ) {
		if ( ! taxonomy_exists( $in['taxonomy'] ) ) {
			return new WP_Error( 'wpc_tax', 'Unknown taxonomy.' );
		}
		$args     = array_filter(
			array(
				'slug'        => $in['slug'],
				'description' => $in['description'] ?? null,
				'parent'      => $in['parent'] ?? null,
			),
			function ( $v ) {
				return null !== $v;
			}
		);
		$existing = get_term_by( 'slug', $in['slug'], $in['taxonomy'] );
		if ( $existing ) {
			if ( isset( $in['name'] ) ) {
				$args['name'] = $in['name'];
			}
			$r = wp_update_term( $existing->term_id, $in['taxonomy'], wp_slash( $args ) );
		} else {
			$r = wp_insert_term( wp_slash( $in['name'] ?? $in['slug'] ), $in['taxonomy'], wp_slash( $args ) );
		}
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		if ( ! empty( $in['meta'] ) ) {
			foreach ( $in['meta'] as $k => $v ) {
				update_term_meta( $r['term_id'], $k, wp_slash( $v ) );
			}
		}
		return array( 'term_id' => $r['term_id'], 'link' => get_term_link( (int) $r['term_id'] ) );
	}

	/* --------------------------------------------------------------- media */

	private static function url_allowed( $url ) {
		$host    = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$allowed   = WPC_Settings::lines( 'fetch_hosts' );
		$allowed[] = WPC_Guard::host();
		return in_array( $host, $allowed, true ) && in_array( wp_parse_url( $url, PHP_URL_SCHEME ), array( 'https', 'http' ), true );
	}

	public static function do_import_media( $in ) {
		self::admin_includes();
		$url = esc_url_raw( $in['url'] );
		if ( ! self::url_allowed( $url ) ) {
			return new WP_Error( 'wpc_url', 'URL host not allowed. Allowed: ' . implode( ', ', array_merge( WPC_Settings::lines( 'fetch_hosts' ), array( WPC_Guard::host() ) ) ) );
		}
		$existing = ! WPC_Settings::get( 'skip_dupes' ) ? array() : get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'any',
				'meta_key'       => '_wpc_source_url', // phpcs:ignore
				'meta_value'     => $url, // phpcs:ignore
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);
		if ( $existing ) {
			return array( 'id' => $existing[0], 'url' => wp_get_attachment_url( $existing[0] ), 'skipped' => 'already imported' );
		}
		$ym = $in['year_month'] ?? '';
		if ( ! $ym && WPC_Settings::get( 'keep_paths' ) && preg_match( '#/uploads/(\d{4})/(\d{2})/#', $url, $m ) ) {
			$ym = $m[1] . '/' . $m[2];
		}
		$time = null;
		if ( preg_match( '#^(\d{4})/(\d{2})$#', $ym, $m ) ) {
			$time = $m[1] . '/' . $m[2]; // wp_handle_sideload accepts 'yyyy/mm'.
		}
		$tmp = download_url( $url, 120 );
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}
		$name = sanitize_file_name( rawurldecode( wp_basename( wp_parse_url( $url, PHP_URL_PATH ) ) ) );
		$file = array( 'name' => $name, 'tmp_name' => $tmp );
		$res  = wp_handle_sideload( $file, array( 'test_form' => false ), $time );
		if ( isset( $res['error'] ) ) {
			@unlink( $tmp ); // phpcs:ignore
			return new WP_Error( 'wpc_sideload', $res['error'] );
		}
		$post = array(
			'post_mime_type' => $res['type'],
			'post_title'     => $in['title'] ?? preg_replace( '/\.[^.]+$/', '', $name ),
			'post_excerpt'   => $in['caption'] ?? '',
			'post_content'   => $in['description'] ?? '',
			'post_status'    => 'inherit',
		);
		if ( ! empty( $in['date'] ) ) {
			$post['post_date'] = $in['date'];
		}
		$id = wp_insert_attachment( wp_slash( $post ), $res['file'], (int) ( $in['parent'] ?? 0 ), true );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $res['file'] ) );
		update_post_meta( $id, '_wpc_source_url', $url );
		if ( isset( $in['alt'] ) ) {
			update_post_meta( $id, '_wp_attachment_image_alt', wp_slash( $in['alt'] ) );
		}
		return array( 'id' => $id, 'url' => wp_get_attachment_url( $id ), 'same_path_as_source' => wp_parse_url( wp_get_attachment_url( $id ), PHP_URL_PATH ) === wp_parse_url( $url, PHP_URL_PATH ) );
	}

	/* --------------------------------------------------------------- files */

	public static function do_list_files( $in ) {
		return WPC_Files::list_dir( $in['root'], $in['path'] ?? '', ! empty( $in['recursive'] ) );
	}

	public static function do_read_file( $in ) {
		return WPC_Files::read( $in['root'], $in['path'] );
	}

	public static function do_write_file( $in ) {
		return WPC_Files::write( $in['root'], $in['path'], $in['content'], $in['encoding'] ?? 'utf8' );
	}

	public static function do_restore_file( $in ) {
		return WPC_Files::restore( (int) $in['backup_id'] );
	}

	public static function do_list_backups( $in ) {
		return WPC_Files::backups_for( $in['root'], ltrim( (string) $in['path'], '/' ) );
	}

	public static function do_delete_file( $in ) {
		return WPC_Files::delete( $in['root'], $in['path'] );
	}

	/* ------------------------------------------------------------- network */

	public static function do_fetch_url( $in ) {
		$url = esc_url_raw( $in['url'] );
		if ( ! self::url_allowed( $url ) ) {
			return new WP_Error( 'wpc_url', 'URL host not allowed. Allowed: ' . implode( ', ', array_merge( WPC_Settings::lines( 'fetch_hosts' ), array( WPC_Guard::host() ) ) ) );
		}
		$max = min( 2097152, max( 1024, (int) ( $in['max_bytes'] ?? 262144 ) ) );
		$t0  = microtime( true );
		$r   = wp_remote_get(
			$url,
			array(
				'timeout'     => 30,
				'redirection' => 0,
				'user-agent'  => 'WP-Connections/' . WPC_VERSION,
				'headers'     => array( 'Cache-Control' => 'no-cache' ),
			)
		);
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$body = wp_remote_retrieve_body( $r );
		$hdrs = wp_remote_retrieve_headers( $r );
		return array(
			'status'    => wp_remote_retrieve_response_code( $r ),
			'location'  => $hdrs['location'] ?? null,
			'headers'   => is_object( $hdrs ) ? $hdrs->getAll() : (array) $hdrs,
			'ms'        => (int) round( ( microtime( true ) - $t0 ) * 1000 ),
			'bytes'     => strlen( $body ),
			'truncated' => strlen( $body ) > $max,
			'body'      => mb_strcut( $body, 0, $max, 'UTF-8' ),
		);
	}

	/* --------------------------------------------------------- maintenance */

	public static function do_maintenance( $in ) {
		$done = array();
		$task = $in['task'];
		if ( in_array( $task, array( 'flush_rewrite', 'all' ), true ) ) {
			flush_rewrite_rules( false );
			$done[] = 'flush_rewrite';
		}
		if ( in_array( $task, array( 'flush_cache', 'all' ), true ) ) {
			wp_cache_flush();
			$done[] = 'flush_cache';
		}
		if ( in_array( $task, array( 'elementor_css', 'all' ), true ) && class_exists( '\Elementor\Plugin' ) ) {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
			$done[] = 'elementor_css';
		}
		return array( 'done' => $done );
	}

	public static function do_read_log( $in ) {
		$limit = min( 300, max( 1, (int) ( $in['limit'] ?? 50 ) ) );
		$out   = array( 'tool_log' => WPC_Log::query( array( 'limit' => $limit ) ) );
		$debug = WP_CONTENT_DIR . '/debug.log';
		if ( is_readable( $debug ) ) {
			$size              = filesize( $debug );
			$out['debug_log']  = file_get_contents( $debug, false, null, max( 0, $size - 20000 ) ); // phpcs:ignore
			$out['debug_size'] = $size;
		}
		return $out;
	}
}
