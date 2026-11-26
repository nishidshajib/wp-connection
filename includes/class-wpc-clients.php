<?php
/**
 * The AI apps WP Connection can be set up for, with per-app setup steps.
 *
 * Icons: drop an official SVG at assets/icons/<id>.svg (e.g. claude.svg) and it
 * replaces the generic icon. Use each vendor's brand-kit file and follow its
 * usage rules. Icons are loaded from the plugin folder, never from a CDN.
 */

defined( 'ABSPATH' ) || exit;

class WPC_Clients {

	/**
	 * id => name, kind (chat|cli|editor|other), auth (header|oauth|either),
	 * blurb, intro, steps: list of [ text, code|null ]. {url} = MCP server URL.
	 */
	public static function all() {
		$token_step = array( 'On the Access tab, generate a token and copy it.', null );
		return array(
			'claude'      => array(
				'name'  => 'Claude',
				'kind'  => 'chat',
				'auth'  => 'header',
				'blurb' => 'Claude on the web, desktop and mobile apps.',
				'intro' => 'Add WP Connection as a custom connector. It then shows up in every Claude chat.',
				'steps' => array(
					$token_step,
					array( 'In Claude, open Settings → Connectors → Add custom connector.', null ),
					array( 'Paste the MCP server URL:', '{url}' ),
					array( 'Under request headers, add this header with your token as the value:', 'x-api-key: <your token>' ),
					array( 'Save, then click Test connection on the Overview tab.', null ),
				),
			),
			'claude-code' => array(
				'name'  => 'Claude Code',
				'kind'  => 'cli',
				'auth'  => 'header',
				'blurb' => 'Claude in your terminal or editor.',
				'intro' => 'Add the server with one command.',
				'steps' => array(
					$token_step,
					array( 'Run this in your project folder:', "claude mcp add --transport http wp-connection \\\n  {url} \\\n  --header \"x-api-key: <your token>\"" ),
					array( 'Click Test connection on the Overview tab.', null ),
				),
			),
			'chatgpt'     => array(
				'name'  => 'ChatGPT',
				'kind'  => 'chat',
				'auth'  => 'oauth',
				'blurb' => 'Custom connectors with sign-in.',
				'intro' => 'ChatGPT custom connectors sign in with OAuth instead of a header, so switch on OAuth sign-in on the Access tab first.',
				'steps' => array(
					array( 'On the Access tab, switch on OAuth sign-in and add the redirect host ChatGPT shows you.', null ),
					array( 'In ChatGPT, open the connectors settings and create a custom connector (developer mode may need to be on).', null ),
					array( 'Paste the MCP server URL and choose OAuth as the authentication:', '{url}' ),
					array( 'A WordPress approval screen opens. Log in as an administrator, pick the preset and expiry, then approve.', null ),
					array( 'Click Test connection on the Overview tab.', null ),
				),
			),
			'cursor'      => array(
				'name'  => 'Cursor',
				'kind'  => 'editor',
				'auth'  => 'header',
				'blurb' => 'AI code editor.',
				'intro' => 'Add the server to your Cursor MCP config.',
				'steps' => array(
					$token_step,
					array( 'Add this to ~/.cursor/mcp.json (or .cursor/mcp.json in a project):', "{\n  \"mcpServers\": {\n    \"wp-connection\": {\n      \"url\": \"{url}\",\n      \"headers\": { \"x-api-key\": \"<your token>\" }\n    }\n  }\n}" ),
					array( 'Restart Cursor, then click Test connection on the Overview tab.', null ),
				),
			),
			'vscode'      => array(
				'name'  => 'VS Code',
				'kind'  => 'editor',
				'auth'  => 'header',
				'blurb' => 'GitHub Copilot agent mode.',
				'intro' => 'Add the server to your workspace MCP config.',
				'steps' => array(
					$token_step,
					array( 'Add this to .vscode/mcp.json:', "{\n  \"servers\": {\n    \"wp-connection\": {\n      \"type\": \"http\",\n      \"url\": \"{url}\",\n      \"headers\": { \"x-api-key\": \"<your token>\" }\n    }\n  }\n}" ),
					array( 'Start the server from the MCP view, then click Test connection on the Overview tab.', null ),
				),
			),
			'gemini'      => array(
				'name'  => 'Gemini CLI',
				'kind'  => 'cli',
				'auth'  => 'header',
				'blurb' => 'Google’s terminal agent.',
				'intro' => 'Add the server to your Gemini CLI settings.',
				'steps' => array(
					$token_step,
					array( 'Add this to ~/.gemini/settings.json:', "{\n  \"mcpServers\": {\n    \"wp-connection\": {\n      \"httpUrl\": \"{url}\",\n      \"headers\": { \"x-api-key\": \"<your token>\" }\n    }\n  }\n}" ),
					array( 'Restart Gemini CLI, then click Test connection on the Overview tab.', null ),
				),
			),
			'other'       => array(
				'name'  => 'Other MCP app',
				'kind'  => 'other',
				'auth'  => 'either',
				'blurb' => 'Any app that supports remote MCP servers.',
				'intro' => 'Works with any client that supports Streamable HTTP. Use an API key if the app lets you add a header, otherwise OAuth sign-in.',
				'steps' => array(
					array( 'On the Access tab, generate a token (or switch on OAuth sign-in).', null ),
					array( 'In your app, add a remote MCP server with this URL:', '{url}' ),
					array( 'Send the token in a request header (or as "Authorization: Bearer <token>"):', 'x-api-key: <your token>' ),
					array( 'Click Test connection on the Overview tab.', null ),
				),
			),
		);
	}

	public static function get( $id ) {
		$all = self::all();
		return isset( $all[ $id ] ) ? $all[ $id ] + array( 'id' => $id ) : null;
	}

	/** The app chosen on the Welcome screen, or null. */
	public static function chosen() {
		return self::get( (string) WPC_Settings::get( 'client' ) );
	}

	/** Display name of the chosen app, with a neutral fallback. */
	public static function chosen_name() {
		$c = self::chosen();
		return $c ? $c['name'] : 'your AI app';
	}

	public static function auth_label( $auth ) {
		return array( 'header' => 'API key header', 'oauth' => 'OAuth sign-in', 'either' => 'API key or OAuth' )[ $auth ] ?? '';
	}

	/** File names accepted for each app's icon in assets/icons/ (first match wins). */
	const ICON_FILES = array(
		'claude'      => array( 'claude' ),
		'claude-code' => array( 'claude-code' ),
		'chatgpt'     => array( 'chatgpt', 'openai-chatgpt', 'openai' ),
		'cursor'      => array( 'cursor' ),
		'vscode'      => array( 'vscode', 'visual-studio-code' ),
		'gemini'      => array( 'gemini-cli', 'gemini' ),
		'other'       => array( 'other', 'model-context-protocol', 'mcp' ),
	);

	/** Official icon from assets/icons/ if present, else a generic outline icon. */
	public static function icon( $id, $kind ) {
		foreach ( self::ICON_FILES[ $id ] ?? array( $id ) as $name ) {
			$file = WPC_DIR . '/assets/icons/' . sanitize_file_name( $name ) . '.svg';
			if ( ! is_readable( $file ) ) {
				continue;
			}
			// A white-only mark (e.g. fill="#ffffff") would vanish on the light tile: show it dark.
			$head  = (string) file_get_contents( $file, false, null, 0, 400 ); // phpcs:ignore
			$white = (bool) preg_match( '/<svg[^>]*\sfill="#(?:fff|ffffff)"/i', $head );
			return '<img class="wpc-brand' . ( $white ? ' wpc-brand-invert' : '' ) . '" src="' . esc_url( WPC_URL . 'assets/icons/' . sanitize_file_name( $name ) . '.svg' ) . '?v=' . (int) filemtime( $file ) . '" alt="" width="24" height="24">';
		}
		$paths = array(
			'chat'   => '<path d="M21 12a8 8 0 0 1-11.6 7.1L4 20l1-4.6A8 8 0 1 1 21 12z"/>',
			'cli'    => '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M7 9l3 3-3 3M13 15h4"/>',
			'editor' => '<path d="M8 7l-5 5 5 5M16 7l5 5-5 5M14 4l-4 16"/>',
			'other'  => '<path d="M9 2v6M15 2v6M6 8h12v4a6 6 0 0 1-12 0zM12 18v4"/>',
		);
		return '<svg aria-hidden="true" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' . ( $paths[ $kind ] ?? $paths['other'] ) . '</svg>';
	}
}
