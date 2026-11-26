<?php
/**
 * Production guard and staging protection.
 */

defined( 'ABSPATH' ) || exit;

class WPC_Guard {

	public static function init() {
		add_action( 'send_headers', array( __CLASS__, 'noindex_header' ) );
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'noindex_rest' ) );
		add_filter( 'robots_txt', array( __CLASS__, 'robots_txt' ), PHP_INT_MAX );
		add_filter( 'pre_option_blog_public', array( __CLASS__, 'force_discourage' ) );
		add_filter( 'pre_wp_mail', array( __CLASS__, 'block_mail_without_test_address' ), 10, 2 );
		add_filter( 'wp_mail', array( __CLASS__, 'redirect_mail' ), PHP_INT_MAX );
	}

	public static function host() {
		return strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	}

	/** True on a blocked (production) host. Checks the site URL and the request host. */
	public static function is_blocked() {
		$blocked = WPC_Settings::lines( 'blocked_hosts' );
		if ( defined( 'WPC_BLOCKED_HOSTS' ) ) {
			$blocked = array_merge( $blocked, array_map( 'trim', explode( ',', strtolower( WPC_BLOCKED_HOSTS ) ) ) );
		}
		$hosts = array( self::host() );
		if ( isset( $_SERVER['HTTP_HOST'] ) ) {
			$hosts[] = strtolower( preg_replace( '/:\d+$/', '', sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) ) );
		}
		return (bool) array_intersect( array_filter( $blocked ), $hosts );
	}

	private static function protect() {
		return ! self::is_blocked();
	}

	public static function noindex_header() {
		if ( self::protect() && WPC_Settings::get( 'noindex_header' ) && ! headers_sent() ) {
			header( 'X-Robots-Tag: noindex, nofollow, noarchive', true );
		}
	}

	public static function noindex_rest( $response ) {
		if ( self::protect() && WPC_Settings::get( 'noindex_header' ) && $response instanceof WP_REST_Response ) {
			$response->header( 'X-Robots-Tag', 'noindex, nofollow, noarchive' );
		}
		return $response;
	}

	public static function robots_txt( $txt ) {
		if ( self::protect() && WPC_Settings::get( 'robots_block' ) ) {
			return "# Staging site: crawling is blocked.\nUser-agent: *\nDisallow: /\n";
		}
		return $txt;
	}

	public static function force_discourage( $pre ) {
		if ( self::protect() && WPC_Settings::get( 'lock_discourage' ) ) {
			return '0';
		}
		return $pre;
	}

	public static function block_mail_without_test_address( $return, $atts ) {
		if ( self::protect() && WPC_Settings::get( 'email_redirect' ) && ! is_email( WPC_Settings::get( 'test_email' ) ) ) {
			return false; // No test address set: do not send staging email to anyone.
		}
		return $return;
	}

	public static function redirect_mail( $atts ) {
		if ( ! self::protect() || ! WPC_Settings::get( 'email_redirect' ) ) {
			return $atts;
		}
		$test = WPC_Settings::get( 'test_email' );
		if ( ! is_email( $test ) ) {
			return $atts;
		}
		$orig            = is_array( $atts['to'] ) ? implode( ', ', $atts['to'] ) : (string) $atts['to'];
		$atts['to']      = $test;
		$atts['subject'] = '[STAGING → ' . $orig . '] ' . $atts['subject'];
		$headers         = is_array( $atts['headers'] ) ? $atts['headers'] : explode( "\n", str_replace( "\r\n", "\n", (string) $atts['headers'] ) );
		$atts['headers'] = array_values(
			array_filter(
				$headers,
				function ( $h ) {
					return ! preg_match( '/^\s*(cc|bcc)\s*:/i', (string) $h );
				}
			)
		);
		return $atts;
	}
}
