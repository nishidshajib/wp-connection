<?php
/**
 * OAuth 2.1 sign-in for AI apps that can't send a custom header.
 *
 * Off by default (Access tab → Sign-in methods). Implements what MCP clients use:
 * - Protected resource metadata   /.well-known/oauth-protected-resource
 * - Authorization server metadata /.well-known/oauth-authorization-server
 * - Dynamic client registration   POST /wp-json/wpc-oauth/v1/register
 * - Authorization (consent page)  /wpc-oauth/authorize  (or /?wpc_oauth=authorize)
 * - Token endpoint (PKCE S256)    POST /wp-json/wpc-oauth/v1/token
 *
 * Public clients only (no client secret), PKCE required, codes are single-use and
 * expire after 10 minutes. Redirect URIs must be on an allowed host or loopback.
 * The access token is a normal WP Connections token, so it shows on the Access
 * tab, can be revoked there and follows the same domain, expiry and rate rules.
 */

defined( 'ABSPATH' ) || exit;

class WPC_OAuth {

	const CLIENTS = 'wpc_oauth_clients';
	const NS      = 'wpc-oauth/v1';

	public static function init() {
		add_action( 'parse_request', array( __CLASS__, 'front_routes' ), 0 );
		add_action( 'rest_api_init', array( __CLASS__, 'rest_routes' ) );
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'challenge' ), 20, 3 );
	}

	public static function enabled() {
		return WPC_Settings::get( 'oauth_on' ) && WPC_Settings::get( 'enabled' ) && ! WPC_Guard::is_blocked();
	}

	/* -------------------------------------------------------------- URLs */

	public static function issuer() {
		return untrailingslashit( home_url() );
	}

	public static function resource() {
		return rest_url( WPC_Auth::route_namespace() . '/mcp' );
	}

	public static function authorize_url() {
		return get_option( 'permalink_structure' ) ? home_url( '/wpc-oauth/authorize' ) : add_query_arg( 'wpc_oauth', 'authorize', home_url( '/' ) );
	}

	public static function resource_metadata_url() {
		return home_url( '/.well-known/oauth-protected-resource' );
	}

	/* ------------------------------------------------------------ clients */

	public static function clients() {
		$c = get_option( self::CLIENTS, array() );
		return is_array( $c ) ? $c : array();
	}

	public static function remove_client( $id ) {
		$c = self::clients();
		unset( $c[ $id ] );
		update_option( self::CLIENTS, $c, false );
	}

	/** Loopback (native/desktop apps) or https on an allowed host. */
	public static function redirect_ok( $uri ) {
		$p = wp_parse_url( $uri );
		if ( ! $p || empty( $p['scheme'] ) || empty( $p['host'] ) || isset( $p['fragment'] ) ) {
			return false;
		}
		$host = strtolower( trim( $p['host'], '[]' ) );
		if ( in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true ) ) {
			return in_array( $p['scheme'], array( 'http', 'https' ), true );
		}
		return 'https' === $p['scheme'] && in_array( $host, WPC_Settings::lines( 'oauth_redirect_hosts' ), true );
	}

	/* ----------------------------------------------------- front endpoints */

	private static function rel_path() {
		$path = (string) wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/', PHP_URL_PATH ); // phpcs:ignore
		$home = untrailingslashit( (string) wp_parse_url( home_url(), PHP_URL_PATH ) );
		if ( $home && 0 === strpos( $path, $home ) ) {
			$path = substr( $path, strlen( $home ) );
		}
		return '/' . ltrim( $path, '/' );
	}

	public static function front_routes() {
		$rel = self::rel_path();
		if ( 0 === strpos( $rel, '/.well-known/oauth-protected-resource' ) ) {
			self::json( self::enabled() ? self::resource_metadata() : array( 'error' => 'not_found' ), self::enabled() ? 200 : 404 );
		}
		if ( 0 === strpos( $rel, '/.well-known/oauth-authorization-server' ) || 0 === strpos( $rel, '/.well-known/openid-configuration' ) ) {
			self::json( self::enabled() ? self::server_metadata() : array( 'error' => 'not_found' ), self::enabled() ? 200 : 404 );
		}
		$q = isset( $_GET['wpc_oauth'] ) ? sanitize_key( $_GET['wpc_oauth'] ) : ''; // phpcs:ignore
		if ( '/wpc-oauth/authorize' === untrailingslashit( $rel ) || 'authorize' === $q ) {
			self::authorize();
		}
	}

	public static function resource_metadata() {
		return array(
			'resource'                 => self::resource(),
			'authorization_servers'    => array( self::issuer() ),
			'bearer_methods_supported' => array( 'header' ),
			'resource_name'            => get_bloginfo( 'name' ) . ' (WP Connections)',
		);
	}

	public static function server_metadata() {
		return array(
			'issuer'                                => self::issuer(),
			'authorization_endpoint'                => self::authorize_url(),
			'token_endpoint'                        => rest_url( self::NS . '/token' ),
			'registration_endpoint'                 => rest_url( self::NS . '/register' ),
			'response_types_supported'              => array( 'code' ),
			'grant_types_supported'                 => array( 'authorization_code' ),
			'code_challenge_methods_supported'      => array( 'S256' ),
			'token_endpoint_auth_methods_supported' => array( 'none' ),
			'scopes_supported'                      => array( 'mcp' ),
		);
	}

	private static function json( $data, $status = 200 ) {
		status_header( $status );
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Access-Control-Allow-Origin: *' );
		echo wp_json_encode( $data ); // phpcs:ignore
		exit;
	}

	/* ---------------------------------------------------- consent screen */

	private static function authorize() {
		$p = array();
		foreach ( array( 'response_type', 'client_id', 'redirect_uri', 'code_challenge', 'code_challenge_method', 'state', 'scope', 'resource' ) as $k ) {
			$p[ $k ] = isset( $_REQUEST[ $k ] ) ? sanitize_text_field( wp_unslash( $_REQUEST[ $k ] ) ) : ''; // phpcs:ignore
		}
		// The redirect URI is used raw (it was validated at registration and must match exactly).
		$p['redirect_uri'] = isset( $_REQUEST['redirect_uri'] ) ? trim( wp_unslash( $_REQUEST['redirect_uri'] ) ) : ''; // phpcs:ignore

		if ( ! self::enabled() ) {
			self::page( 'Sign-in is switched off', '<p>OAuth sign-in is not enabled for WP Connections on this site.</p>' );
		}
		$clients = self::clients();
		$client  = $clients[ $p['client_id'] ] ?? null;
		if ( ! $client || ! in_array( $p['redirect_uri'], $client['redirect_uris'], true ) || ! self::redirect_ok( $p['redirect_uri'] ) ) {
			self::page( 'Unknown app', '<p>This app is not registered here, or its return address does not match. Start the connection again from the app.</p>' );
		}
		// From here on, errors go back to the app.
		if ( 'code' !== $p['response_type'] ) {
			self::back( $p, array( 'error' => 'unsupported_response_type' ) );
		}
		if ( '' === $p['code_challenge'] || 'S256' !== $p['code_challenge_method'] ) {
			self::back( $p, array( 'error' => 'invalid_request', 'error_description' => 'PKCE with S256 is required.' ) );
		}
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( self::current_url( $p ) ) );
			exit;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			self::page( 'Administrator needed', '<p>Only an administrator can connect an AI app. Log in with an administrator account.</p>' );
		}

		$nonce_action = 'wpc_oauth_consent_' . $p['client_id'];
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { // phpcs:ignore
			check_admin_referer( $nonce_action );
			if ( empty( $_POST['approve'] ) ) {
				WPC_Log::add( 'oauth-denied', $client['name'], false, 'Sign-in denied in the browser', null, true );
				self::back( $p, array( 'error' => 'access_denied' ) );
			}
			$preset = isset( $_POST['preset'] ) && 'readonly' === $_POST['preset'] ? 'readonly' : 'build';
			$days   = isset( $_POST['days'] ) ? absint( $_POST['days'] ) : 30;
			$days   = in_array( $days, array( 0, 7, 30, 90 ), true ) ? $days : 30;
			$code   = bin2hex( random_bytes( 32 ) );
			set_transient(
				'wpc_oac_' . hash( 'sha256', $code ),
				array(
					'client_id' => $p['client_id'],
					'redirect'  => $p['redirect_uri'],
					'challenge' => $p['code_challenge'],
					'user_id'   => get_current_user_id(),
					'preset'    => $preset,
					'days'      => $days,
				),
				10 * MINUTE_IN_SECONDS
			);
			WPC_Log::add( 'oauth-approved', $client['name'], true, 'Sign-in approved in the browser', null, true );
			self::back( $p, array( 'code' => $code ) );
		}

		$host = wp_parse_url( $p['redirect_uri'], PHP_URL_HOST );
		ob_start();
		?>
		<p><strong><?php echo esc_html( $client['name'] ); ?></strong> wants to connect to <strong><?php echo esc_html( get_bloginfo( 'name' ) ); ?></strong> through WP Connections and act as <strong><?php echo esc_html( wp_get_current_user()->user_login ); ?></strong>.</p>
		<p class="muted">The app named itself. After approval you return to <strong><?php echo esc_html( $host ); ?></strong>. Only approve if you started this connection.</p>
		<form method="post">
			<?php wp_nonce_field( $nonce_action ); ?>
			<label>Permissions<select name="preset"><option value="build">Build (uses the Tools tab switches)</option><option value="readonly">Read-only</option></select></label>
			<label>Access expires<select name="days"><option value="30">After 30 days</option><option value="7">After 7 days</option><option value="90">After 90 days</option><option value="0">Never</option></select></label>
			<div class="btns"><button class="primary" name="approve" value="1">Approve</button><button name="deny" value="1">Deny</button></div>
		</form>
		<p class="muted">You can revoke this access at any time on Tools → WP Connections → Access.</p>
		<?php
		self::page( 'Connect an AI app', ob_get_clean() );
	}

	private static function current_url( $p ) {
		return add_query_arg( array_map( 'rawurlencode', array_filter( $p ) ), self::authorize_url() );
	}

	/** Redirect back to the app's validated redirect URI. */
	private static function back( $p, $args ) {
		if ( '' !== $p['state'] ) {
			$args['state'] = $p['state'];
		}
		$args['iss'] = self::issuer();
		$sep = false === strpos( $p['redirect_uri'], '?' ) ? '?' : '&';
		wp_redirect( $p['redirect_uri'] . $sep . http_build_query( $args, '', '&', PHP_QUERY_RFC3986 ) ); // phpcs:ignore -- external app URL, validated against the registered list.
		exit;
	}

	private static function page( $title, $body ) {
		nocache_headers();
		header( 'X-Frame-Options: DENY' );
		header( "Content-Security-Policy: frame-ancestors 'none'" );
		header( 'Content-Type: text/html; charset=utf-8' );
		?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title><?php echo esc_html( $title ); ?> · WP Connections</title>
<style>
body{margin:0;background:#f0f0f1;font:15px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;color:#1d2327}
main{max-width:460px;margin:8vh auto;background:#fff;border:1px solid #c3c4c7;border-radius:8px;padding:28px 32px;box-sizing:border-box}
h1{margin:0 0 12px;font-size:20px}.muted{color:#50575e;font-size:13px}
form{display:flex;flex-direction:column;gap:14px;margin:18px 0}
label{display:flex;flex-direction:column;gap:4px;font-weight:600;font-size:13px}
select{font:inherit;min-height:40px;padding:0 8px;border:1px solid #8c8f94;border-radius:4px}
.btns{display:flex;gap:10px}
button{font:inherit;min-height:44px;padding:0 20px;border-radius:4px;border:1px solid #3E00C7;background:#fff;color:#3E00C7;cursor:pointer}
button.primary{background:#3E00C7;color:#fff}
button:focus-visible,select:focus-visible{outline:2px solid #3E00C7;outline-offset:2px}
.brand{font-weight:700;color:#3E00C7;font-size:13px;letter-spacing:.02em;margin-bottom:8px}
</style></head><body><main><div class="brand">WP Connections</div><h1><?php echo esc_html( $title ); ?></h1><?php echo $body; // phpcs:ignore -- built from escaped parts above. ?></main></body></html>
		<?php
		exit;
	}

	/* ----------------------------------------------------- REST endpoints */

	public static function rest_routes() {
		register_rest_route( self::NS, '/register', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'register' ), 'permission_callback' => '__return_true' ) );
		register_rest_route( self::NS, '/token', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'token' ), 'permission_callback' => '__return_true' ) );
	}

	private static function oauth_error( $code, $desc, $status = 400 ) {
		$r = new WP_REST_Response( array( 'error' => $code, 'error_description' => $desc ), $status );
		$r->header( 'Cache-Control', 'no-store' );
		return $r;
	}

	/** RFC 7591 dynamic client registration (public clients). */
	public static function register( WP_REST_Request $req ) {
		if ( ! self::enabled() ) {
			return self::oauth_error( 'access_denied', 'OAuth sign-in is switched off.', 403 );
		}
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$key = 'wpc_oareg_' . md5( $ip . gmdate( 'YmdH' ) );
		$n   = (int) get_transient( $key );
		if ( $n >= 20 ) {
			return self::oauth_error( 'slow_down', 'Too many registrations. Try again later.', 429 );
		}
		set_transient( $key, $n + 1, HOUR_IN_SECONDS );

		$body = $req->get_json_params();
		$uris = is_array( $body['redirect_uris'] ?? null ) ? array_values( array_filter( array_map( 'strval', $body['redirect_uris'] ) ) ) : array();
		if ( ! $uris || count( $uris ) > 10 ) {
			return self::oauth_error( 'invalid_redirect_uri', 'Give 1 to 10 redirect_uris.' );
		}
		foreach ( $uris as $u ) {
			if ( strlen( $u ) > 500 || ! self::redirect_ok( $u ) ) {
				$h = wp_parse_url( $u, PHP_URL_HOST );
				WPC_Log::add( 'oauth-register', (string) $h, false, 'Redirect host not allowed' );
				return self::oauth_error( 'invalid_redirect_uri', 'Redirect host "' . $h . '" is not allowed. An administrator can add it on Tools → WP Connections → Access → Allowed redirect hosts.' );
			}
		}
		$clients = self::clients();
		if ( count( $clients ) >= 50 ) {
			return self::oauth_error( 'invalid_client_metadata', 'Too many registered apps. Remove unused ones on the Access tab.' );
		}
		$name = sanitize_text_field( (string) ( $body['client_name'] ?? '' ) );
		$name = $name ? mb_substr( $name, 0, 80 ) : (string) wp_parse_url( $uris[0], PHP_URL_HOST );
		$id   = 'wpc_' . bin2hex( random_bytes( 12 ) );
		$clients[ $id ] = array( 'name' => $name, 'redirect_uris' => $uris, 'created' => time() );
		update_option( self::CLIENTS, $clients, false );
		WPC_Log::add( 'oauth-register', $name, true, 'App registered for OAuth sign-in' );

		$r = new WP_REST_Response(
			array(
				'client_id'                  => $id,
				'client_id_issued_at'        => time(),
				'client_name'                => $name,
				'redirect_uris'              => $uris,
				'grant_types'                => array( 'authorization_code' ),
				'response_types'             => array( 'code' ),
				'token_endpoint_auth_method' => 'none',
			),
			201
		);
		$r->header( 'Cache-Control', 'no-store' );
		return $r;
	}

	/** Authorization code + PKCE → WP Connections access token. */
	public static function token( WP_REST_Request $req ) {
		if ( ! self::enabled() ) {
			return self::oauth_error( 'access_denied', 'OAuth sign-in is switched off.', 403 );
		}
		if ( 'authorization_code' !== (string) $req->get_param( 'grant_type' ) ) {
			return self::oauth_error( 'unsupported_grant_type', 'Only authorization_code is supported.' );
		}
		$code = (string) $req->get_param( 'code' );
		$key  = 'wpc_oac_' . hash( 'sha256', $code );
		$data = '' !== $code ? get_transient( $key ) : false;
		delete_transient( $key ); // Single use, even when the checks below fail.
		if ( ! is_array( $data ) ) {
			return self::oauth_error( 'invalid_grant', 'The code is invalid, used or expired.' );
		}
		$verifier = (string) $req->get_param( 'code_verifier' );
		$expected = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
		if ( (string) $req->get_param( 'client_id' ) !== $data['client_id']
			|| (string) $req->get_param( 'redirect_uri' ) !== $data['redirect']
			|| strlen( $verifier ) < 43 || ! hash_equals( $data['challenge'], $expected ) ) {
			return self::oauth_error( 'invalid_grant', 'client_id, redirect_uri or code_verifier does not match.' );
		}
		$clients = self::clients();
		if ( ! isset( $clients[ $data['client_id'] ] ) || ! user_can( (int) $data['user_id'], 'manage_options' ) ) {
			return self::oauth_error( 'invalid_grant', 'The app or the approving user is no longer allowed.' );
		}
		$name  = $clients[ $data['client_id'] ]['name'] . ' (OAuth)';
		$token = WPC_Auth::create( $name, (int) $data['user_id'], (int) $data['days'], $data['preset'], $data['client_id'] );
		WPC_Log::add( 'token-created', $name, true, 'Token issued through OAuth sign-in', null, true );
		$out = array( 'access_token' => $token, 'token_type' => 'Bearer', 'scope' => 'mcp' );
		if ( $data['days'] > 0 ) {
			$out['expires_in'] = (int) $data['days'] * DAY_IN_SECONDS;
		}
		$r = new WP_REST_Response( $out, 200 );
		$r->header( 'Cache-Control', 'no-store' );
		$r->header( 'Pragma', 'no-cache' );
		return $r;
	}

	/** Tell MCP clients where to sign in when they call without a valid token. */
	public static function challenge( $response, $server, $request ) {
		if ( ! self::enabled() || ! WPC_Auth::is_mcp_request() || WPC_Auth::is_token_request() || ! $response instanceof WP_HTTP_Response ) {
			return $response;
		}
		if ( in_array( (int) $response->get_status(), array( 401, 403 ), true ) ) {
			$response->set_status( 401 );
			$response->header( 'WWW-Authenticate', 'Bearer resource_metadata="' . self::resource_metadata_url() . '"' );
		}
		return $response;
	}
}
