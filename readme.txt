=== WP Connections ===
Contributors: nishidshajib
Tags: mcp, ai, staging, connector, abilities
Requires at least: 6.9
Tested up to: 6.9
Requires PHP: 8.0
Requires Plugins: mcp-adapter
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect a staging WordPress site to an AI assistant (Claude, ChatGPT, Cursor, VS Code, Gemini CLI or any MCP client) through the WordPress MCP Adapter.

== Description ==

WP Connections turns a staging site into a safe MCP server for AI assistants.

* Welcome screen: pick the AI app you use and get its exact setup steps.
* Access by API key (x-api-key or Authorization: Bearer) or by OAuth sign-in for apps that only offer a sign-in button. Named tokens, expiry, read-only preset, rate limit, optional IP allowlist, bound to the site domain.
* Per-tool on/off switches (site, plugins & themes, content, files).
* Sandboxed file access: only the folders you allow. PHP is syntax-checked before saving; overwritten files are backed up and can be restored.
* Staging protection: X-Robots-Tag noindex header, robots.txt Disallow, "Discourage search engines" locked on, outgoing email redirected to a test address.
* Production guard: refuses to work on the domains in the block list.
* Activity log with filters, CSV export and one-click restore.

MCP server URL: https://YOUR-STAGING-DOMAIN/wp-json/wp-connections/mcp

Made by [Nishid Shajib](https://nishidshajib.com).

== Installation ==

1. Install and activate the MCP Adapter plugin, then WP Connections.
2. Go to Tools → WP Connections and pick your AI app on the Welcome screen.
3. Follow the steps shown for that app, then click Test connection.

== Frequently Asked Questions ==

= Which AI apps work? =
Any MCP client that supports remote (Streamable HTTP) servers. Apps that let you add a request header use an API key; apps that only offer sign-in use OAuth (switch it on under Access → Sign-in methods).

= Is it safe to leave on a live site? =
No. It is a staging tool. Add your live domains to Safety → Production guard, and delete the plugin before launch.

== Data ==

Tables: {prefix}wpc_activity_log, {prefix}wpc_file_backups
Options: wpc_settings, wpc_tokens, wpc_oauth_clients, wpc_db_version
Transients: wpc_*

Deactivate → choose "Deactivate temporarily" (keeps everything) or "Deactivate and delete this plugin's tables" (removes only the items above). Deleting the plugin also removes only the items above. Nothing belonging to other plugins or WordPress core is touched.

== Changelog ==

= 1.1.0 =
* New Welcome screen to choose your AI app, with per-app setup steps (Claude, Claude Code, ChatGPT, Cursor, VS Code, Gemini CLI, other MCP apps).
* OAuth sign-in for apps that can't send a custom header. Bearer tokens accepted.
* Updates tab. GitHub builds update from GitHub releases; WordPress.org builds update through WordPress.org.
* Neutral wording and generic defaults (no site-specific folders or domains).
* New brand colour.

= 1.0.0 =
* First release.
