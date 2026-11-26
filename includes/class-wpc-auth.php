<?php
/**
 * Token authentication for MCP requests: x-api-key header, or an OAuth
 * access token sent as "Authorization: Bearer ...".
 *
 * Tokens are shown once; only SHA-256 hashes are stored (option wpc_tokens).
 * Each token: name, user it acts as, preset (build|readonly), host it is bound
 * to, expiry and last use.
 */

defined( 'ABSPATH' ) || exit;

class WPC_Auth {

	const OPTION = 'wpc_tokens';
	const HEADER = 'HTTP_X_API_KEY';

	/** Token record used for the current request, or null. */
	private static $current = null;
	/** Set by the admin "Test connection" button for an internal request. */
	public static $internal_test = false;

	public static function init() {
		add_filter( 'determine_current_user', array( __CLASS__, 'determine_user' ), 30 );
		add_filter( 'rest_authentication_errors', array( __CLASS__, 'restrict_routes' ), 99 );
	}

	public static function tokens() {
		$t = get_option( self::OPTION, array() );
		return is_array( $t ) ? $t : array();
	}

	private static function save( $tokens ) {
		update_option( self::OPTION, $tokens, false );
	}

	public static function route_namespace() {
		return 'wp-connection';
	}

	/** True if this request targets an MCP endpoint (ours or the adapter default). */
	public static function is_mcp_request() {
		$uri    = isset( $_SERVER['REQUEST_URI'] ) ? rawurldecode( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : ''; // phpcs:ignore
		$prefix = '/' . trim( rest_get_url_prefix(), '/' ) . '/';
		foreach ( array( self::route_namespace() . '/mcp', 'mcp/' ) as $r ) {
			if ( false !== strpos( $uri, $prefix . $r ) || false !== strpos( $uri, 'rest_route=/' . $r ) ) {
				return true;
			}
		}
		return false;
	}

	private static function client_ip() {
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	}

	private static function ip_allowed() {
		$list = WPC_Settings::lines( 'ip_allowlist' );
		if ( ! $list ) {
			return true;
		}
		$ip = self::client_ip();
		foreach ( $list as $entry ) {
			if ( self::ip_in_range( $ip, $entry ) ) {
				return true;
			}
		}
		return false;
	}

	private static function ip_in_range( $ip, $range ) {
		if ( false === strpos( $range, '/' ) ) {
			return $ip === $range;
		}
		list( $subnet, $bits ) = explode( '/', $range, 2 );
		$ipb = @inet_pton( $ip ); // phpcs:ignore
		$sub = @inet_pton( $subnet ); // phpcs:ignore
		if ( false === $ipb || false === $sub || strlen( $ipb ) !== strlen( $sub ) ) {
			return false;
		}
		$bits  = (int) $bits;
		$bytes = intdiv( $bits, 8 );
		if ( substr( $ipb, 0, $bytes ) !== substr( $sub, 0, $bytes ) ) {
			return false;
		}
		$rem = $bits % 8;
		if ( ! $rem ) {
			return true;
		}
		$mask = chr( 0xff << ( 8 - $rem ) & 0xff );
		return ( $ipb[ $bytes ] & $mask ) === ( $sub[ $bytes ] & $mask );
	}

	public static function determine_user( $user_id ) {
		if ( $user_id || ! self::is_mcp_request() || WPC_Guard::is_blocked() || ! WPC_Settings::get( 'enabled' ) ) {
			return $user_id;
		}
		$key = self::request_key();
		if ( '' === $key || ! self::ip_allowed() ) {
			return $user_id;
		}
		$hash   = hash( 'sha256', $key );
		$tokens = self::tokens();
		foreach ( $tokens as $id => $t ) {
			if ( ! hash_equals( $t['hash'], $hash ) ) {
				continue;
			}
			if ( WPC_Settings::get( 'bind_domain' ) && ( $t['host'] ?? '' ) !== WPC_Guard::host() ) {
				return $user_id;
			}
			if ( ! empty( $t['expires'] ) && time() > (int) $t['expires'] ) {
				return $user_id;
			}
			if ( ! empty( $t['oauth'] ) && ( ! class_exists( 'WPC_OAuth' ) || ! WPC_OAuth::enabled() ) ) {
				return $user_id; // OAuth-issued tokens stop working when OAuth sign-in is switched off.
			}
			$user = get_userdata( (int) $t['user_id'] );
			if ( ! $user || ! user_can( $user, 'manage_options' ) ) {
				return $user_id;
			}
			if ( ! self::rate_ok( $id ) ) {
				return $user_id;
			}
			$tokens[ $id ]['last_used'] = time();
			self::save( $tokens );
			self::$current = $t + array( 'id' => $id );
			return (int) $user->ID;
		}
		return $user_id;
	}

	private static function rate_ok( $id ) {
		$limit = (int) WPC_Settings::get( 'rate_limit' );
		if ( $limit <= 0 ) {
			return true;
		}
		$key   = 'wpc_rl_' . $id . '_' . gmdate( 'YmdHi' );
		$count = (int) get_transient( $key );
		if ( $count >= $limit ) {
			return false;
		}
		set_transient( $key, $count + 1, 2 * MINUTE_IN_SECONDS );
		return true;
	}

	/** "Only allow MCP requests": a token never authenticates anything else. */
	public static function restrict_routes( $result ) {
		return $result; // Tokens are only read on MCP routes in determine_user(), so other routes never see them.
	}

	public static function current() {
		return self::$current;
	}

	public static function is_token_request() {
		return null !== self::$current || self::$internal_test;
	}

	public static function is_readonly() {
		return self::$current && 'readonly' === ( self::$current['preset'] ?? '' );
	}

	public static function current_name() {
		if ( self::$internal_test ) {
			return 'Test connection';
		}
		return self::$current['name'] ?? '';
	}

	/** Token from the x-api-key header, or from "Authorization: Bearer ...". */
	private static function request_key() {
		if ( ! empty( $_SERVER[ self::HEADER ] ) ) {
			return trim( wp_unslash( $_SERVER[ self::HEADER ] ) ); // phpcs:ignore
		}
		foreach ( array( 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION' ) as $h ) {
			$v = isset( $_SERVER[ $h ] ) ? trim( wp_unslash( $_SERVER[ $h ] ) ) : ''; // phpcs:ignore
			if ( preg_match( '/^Bearer\s+(wpc_[A-Za-z0-9]+)$/', $v, $m ) ) {
				return $m[1];
			}
		}
		return '';
	}

	/** Revoke every token issued to one OAuth app. */
	public static function revoke_oauth_client( $client_id ) {
		$tokens = self::tokens();
		foreach ( $tokens as $id => $t ) {
			if ( ( $t['oauth'] ?? '' ) === $client_id ) {
				unset( $tokens[ $id ] );
			}
		}
		self::save( $tokens );
	}

	public static function create( $name, $user_id, $days, $preset, $oauth_client = '' ) {
		$token  = 'wpc_' . bin2hex( random_bytes( 24 ) );
		$tokens = self::tokens();
		$id     = wp_generate_password( 10, false, false );
		$tokens[ $id ] = array(
			'name'      => $name ? $name : 'AI app',
			'hash'      => hash( 'sha256', $token ),
			'user_id'   => (int) $user_id,
			'host'      => WPC_Guard::host(),
			'preset'    => 'readonly' === $preset ? 'readonly' : 'build',
			'created'   => time(),
			'expires'   => $days > 0 ? time() + $days * DAY_IN_SECONDS : 0,
			'last_used' => 0,
			'oauth'     => (string) $oauth_client,
		);
		self::save( $tokens );
		return $token;
	}

	public static function revoke( $id ) {
		$tokens = self::tokens();
		unset( $tokens[ $id ] );
		self::save( $tokens );
	}

	public static function revoke_all() {
		self::save( array() );
	}
}
