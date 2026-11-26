<?php
/**
 * Updates from GitHub releases.
 *
 * GITHUB BUILD ONLY. bin/build.sh leaves this file out of the WordPress.org zip,
 * because WordPress.org plugins must update through WordPress.org (guideline 8).
 *
 * How a release reaches sites:
 * 1. Bump "Version" in wp-connection.php and WPC_VERSION, and "Stable tag" in readme.txt.
 * 2. Run bin/build.sh, then create a GitHub release tagged v1.2.0 (or 1.2.0) and attach
 *    dist/wp-connection.zip (the GitHub build). Mark betas as pre-releases.
 * 3. Sites check twice a day; the update shows in Dashboard → Updates, on the Plugins
 *    page and on Tools → WP Connection → Updates, and can auto-update.
 */

defined( 'ABSPATH' ) || exit;

class WPC_Updater {

	const CACHE = 'wpc_gh_releases';

	public static function init() {
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'inject' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'info' ), 20, 3 );
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'fix_folder' ), 10, 4 );
		add_filter( 'upgrader_pre_download', array( __CLASS__, 'download' ), 10, 3 );
		add_filter( 'http_request_args', array( __CLASS__, 'auth_header' ), 10, 2 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'flush' ), 10, 0 );
	}

	public static function basename() {
		return plugin_basename( WPC_FILE );
	}

	public static function repo() {
		$r = trim( (string) WPC_Settings::get( 'github_repo' ) );
		return preg_match( '#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $r ) ? $r : '';
	}

	public static function flush() {
		delete_site_transient( self::CACHE );
	}

	/** Releases from the GitHub API, newest first, cached 12 hours. */
	public static function releases( $force = false ) {
		$repo = self::repo();
		if ( ! $repo ) {
			return array();
		}
		$cached = $force ? false : get_site_transient( self::CACHE );
		if ( is_array( $cached ) && ( $cached['repo'] ?? '' ) === $repo ) {
			return $cached['list'];
		}
		$res  = wp_remote_get( 'https://api.github.com/repos/' . $repo . '/releases?per_page=10', array( 'timeout' => 10, 'headers' => array( 'Accept' => 'application/vnd.github+json' ) ) );
		$code = wp_remote_retrieve_response_code( $res );
		$list = array();
		if ( ! is_wp_error( $res ) && 200 === $code ) {
			foreach ( (array) json_decode( wp_remote_retrieve_body( $res ), true ) as $r ) {
				if ( ! is_array( $r ) || ! empty( $r['draft'] ) ) {
					continue;
				}
				$asset = null;
				foreach ( (array) ( $r['assets'] ?? array() ) as $a ) {
					if ( 'wp-connection.zip' === ( $a['name'] ?? '' ) ) {
						$asset = $a;
						break;
					}
				}
				$list[] = array(
					'version'    => ltrim( (string) $r['tag_name'], 'vV' ),
					'prerelease' => ! empty( $r['prerelease'] ),
					'date'       => (string) ( $r['published_at'] ?? '' ),
					'notes'      => (string) ( $r['body'] ?? '' ),
					'url'        => (string) ( $r['html_url'] ?? '' ),
					// Private repos download through the API asset URL with the token.
					'package'    => $asset ? ( WPC_Settings::get( 'github_token' ) ? $asset['url'] : $asset['browser_download_url'] ) : '',
					'digest'     => $asset && ! empty( $asset['digest'] ) && 0 === strpos( $asset['digest'], 'sha256:' ) ? substr( $asset['digest'], 7 ) : '',
				);
			}
		}
		$error = is_wp_error( $res ) ? $res->get_error_message() : ( 200 === $code ? '' : 'GitHub answered ' . $code . ( 404 === $code ? ' (repository not found, or private without a token)' : '' ) );
		set_site_transient( self::CACHE, array( 'repo' => $repo, 'list' => $list, 'error' => $error, 'checked' => time() ), $error ? HOUR_IN_SECONDS : 12 * HOUR_IN_SECONDS );
		return $list;
	}

	public static function status() {
		$c = get_site_transient( self::CACHE );
		return is_array( $c ) ? $c : array( 'error' => '', 'checked' => 0 );
	}

	/** Newest release for the chosen channel that has a plugin zip attached. */
	public static function latest() {
		$beta = 'beta' === WPC_Settings::get( 'update_channel' );
		foreach ( self::releases() as $r ) {
			if ( $r['package'] && ( $beta || ! $r['prerelease'] ) ) {
				return $r;
			}
		}
		return null;
	}

	public static function inject( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}
		$r = self::latest();
		if ( ! $r ) {
			return $transient;
		}
		$item = (object) array(
			'id'          => 'github.com/' . self::repo(),
			'slug'        => 'wp-connection',
			'plugin'      => self::basename(),
			'new_version' => $r['version'],
			'url'         => $r['url'],
			'package'     => $r['package'],
			'requires'    => '6.9',
			'requires_php' => '8.0',
		);
		if ( version_compare( $r['version'], WPC_VERSION, '>' ) ) {
			$transient->response[ self::basename() ] = $item;
		} else {
			$transient->no_update[ self::basename() ] = $item; // Lets WordPress show the auto-update switch.
		}
		return $transient;
	}

	/** "View version x details" popup on the Plugins page. */
	public static function info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || 'wp-connection' !== ( $args->slug ?? '' ) ) {
			return $result;
		}
		$r = self::latest();
		if ( ! $r ) {
			return $result;
		}
		$log = '';
		foreach ( array_slice( self::releases(), 0, 5 ) as $x ) {
			$log .= '<h4>' . esc_html( $x['version'] ) . ( $x['prerelease'] ? ' (beta)' : '' ) . '</h4>' . wpautop( esc_html( $x['notes'] ) );
		}
		return (object) array(
			'name'          => 'WP Connection',
			'slug'          => 'wp-connection',
			'version'       => $r['version'],
			'author'        => '<a href="https://nishidshajib.com">Nishid Shajib</a>',
			'homepage'      => 'https://wp-connection.com',
			'requires'      => '6.9',
			'requires_php'  => '8.0',
			'last_updated'  => $r['date'],
			'download_link' => $r['package'],
			'sections'      => array(
				'description' => 'Connects a staging WordPress site to AI assistants through the WordPress MCP Adapter.',
				'changelog'   => $log,
			),
		);
	}

	private static function is_ours( $url ) {
		foreach ( self::releases() as $r ) {
			if ( $r['package'] && $r['package'] === $url ) {
				return $r;
			}
		}
		return null;
	}

	/** Download our zip ourselves so the SHA-256 digest GitHub publishes can be checked. */
	public static function download( $reply, $package, $upgrader ) {
		$r = self::is_ours( $package );
		if ( ! $r || false !== $reply ) {
			return $reply;
		}
		if ( 0 !== strpos( $package, 'https://github.com/' ) && 0 !== strpos( $package, 'https://api.github.com/' ) ) {
			return new WP_Error( 'wpc_update', 'Update package is not on GitHub.' );
		}
		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$tmp = download_url( $package, 120 );
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}
		if ( $r['digest'] && ! hash_equals( strtolower( $r['digest'] ), hash_file( 'sha256', $tmp ) ) ) {
			wp_delete_file( $tmp );
			return new WP_Error( 'wpc_update', 'The downloaded update does not match the checksum on GitHub, so it was not installed.' );
		}
		return $tmp;
	}

	/** Token for private repos, and the octet-stream Accept header for API asset URLs. */
	public static function auth_header( $args, $url ) {
		$repo  = self::repo();
		$token = (string) WPC_Settings::get( 'github_token' );
		if ( ! $repo || 0 !== strpos( $url, 'https://api.github.com/repos/' . $repo . '/' ) ) {
			return $args;
		}
		$args['headers'] = (array) ( $args['headers'] ?? array() );
		$args['headers']['User-Agent'] = 'WP-Connections/' . WPC_VERSION;
		if ( $token ) {
			$args['headers']['Authorization'] = 'Bearer ' . $token;
		}
		if ( false !== strpos( $url, '/releases/assets/' ) ) {
			$args['headers']['Accept'] = 'application/octet-stream';
		}
		return $args;
	}

	/** Make sure the unzipped folder is called wp-connection. */
	public static function fix_folder( $source, $remote_source, $upgrader, $extra = array() ) {
		if ( ( $extra['plugin'] ?? '' ) !== self::basename() ) {
			return $source;
		}
		$want = trailingslashit( $remote_source ) . dirname( self::basename() ) . '/';
		if ( untrailingslashit( $source ) === untrailingslashit( $want ) ) {
			return $source;
		}
		global $wp_filesystem;
		return $wp_filesystem && $wp_filesystem->move( $source, $want, true ) ? $want : $source;
	}

	/** WordPress's own auto-update switch for this plugin. */
	public static function auto_update_on() {
		return in_array( self::basename(), (array) get_site_option( 'auto_update_plugins', array() ), true );
	}
}
