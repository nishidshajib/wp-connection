# WP Connection

**Connect a staging WordPress site to your AI assistant — safely.**

WP Connection turns a staging copy of a WordPress site into an [MCP](https://modelcontextprotocol.io) server, using the official [WordPress MCP Adapter](https://github.com/WordPress/mcp-adapter). Your AI app can then read and build the site for you (pages, posts, themes, plugins, media and files), while guard rails keep it away from your live site, your users and anything you haven't allowed.

Website: [wp-connection.com](https://wp-connection.com) · Made by [Nishid Shajib](https://nishidshajib.com).

---

## Works with

| App | How it signs in |
| --- | --- |
| Claude (web, desktop, mobile) | API key header |
| Claude Code | API key header |
| ChatGPT | OAuth sign-in |
| Cursor | API key header |
| VS Code (GitHub Copilot agent mode) | API key header |
| Gemini CLI | API key header |
| Any other MCP app (Streamable HTTP) | API key or OAuth |

Pick your app on the Welcome screen and WP Connection shows the exact steps and copy-paste config for it.

## Features

- **Welcome screen and per-app setup guide.** Choose your AI app; every screen then uses it.
- **Two ways in.** API key (`x-api-key` or `Authorization: Bearer`) or OAuth 2.1 sign-in with PKCE for apps that only offer a sign-in button. Named tokens, expiry, read-only preset, rate limit, optional IP allowlist, tokens bound to the site's domain. Only a hash of each token is stored.
- **Per-tool switches.** Turn each tool on or off: site info, options, maintenance, plugins and themes, posts and pages, terms, media import, files, URL fetch and logs. Destructive tools are off by default.
- **Sandboxed files.** The AI can only write inside folders you allow. PHP is syntax-checked before saving, and every overwritten file is backed up for one-click restore. `wp-config.php`, `.htaccess`, `wp-admin/` and `wp-includes/` are always blocked.
- **Staging protection.** `X-Robots-Tag: noindex`, a blocking `robots.txt`, "Discourage search engines" locked on, and outgoing email redirected to a test address.
- **Production guard.** List your live domains and the plugin refuses to work there, even if it's copied over by mistake.
- **Self-protection.** The AI can't deactivate WP Connection or the MCP Adapter, manage users, or change protected settings (site URL, admin email, roles, security keys and more).
- **SEO tools** for Yoast SEO, Rank Math and All in One SEO: one set of fields (title, description, focus keyword, canonical, noindex, nofollow, social title/description/image) for posts, pages, categories and tags.
- **Activity log** with filters, CSV export and restore.
- **Updates.** GitHub builds update from this repository's releases (with a checksum check when GitHub provides one). WordPress.org builds update through WordPress.org.
- **Clean removal.** Deactivate temporarily, or remove only this plugin's own tables and settings. Nothing else is touched.

## Requirements

- WordPress 6.9 or newer (Abilities API)
- PHP 8.0 or newer
- [MCP Adapter](https://github.com/WordPress/mcp-adapter) plugin, active
- HTTPS
- A **staging** site. This is not a tool for live sites.

## Install

1. Download `wp-connection.zip` from the [latest release](../../releases/latest).
2. In WordPress: Plugins → Add New → Upload Plugin, then activate. If the MCP Adapter isn't active yet, a dialog offers to install and activate it in one click.
3. Go to **Tools → WP Connection**, pick your AI app and follow the steps.
4. Click **Test connection**.

MCP server URL: `https://YOUR-STAGING-DOMAIN/wp-json/wp-connection/mcp`

### Quick config examples

Claude Code:

```bash
claude mcp add --transport http wp-connection \
  https://YOUR-STAGING-DOMAIN/wp-json/wp-connection/mcp \
  --header "x-api-key: <your token>"
```

Cursor (`~/.cursor/mcp.json`):

```json
{
  "mcpServers": {
    "wp-connection": {
      "url": "https://YOUR-STAGING-DOMAIN/wp-json/wp-connection/mcp",
      "headers": { "x-api-key": "<your token>" }
    }
  }
}
```

VS Code (`.vscode/mcp.json`):

```json
{
  "servers": {
    "wp-connection": {
      "type": "http",
      "url": "https://YOUR-STAGING-DOMAIN/wp-json/wp-connection/mcp",
      "headers": { "x-api-key": "<your token>" }
    }
  }
}
```

### OAuth sign-in (ChatGPT and other sign-in-only apps)

1. Access → Sign-in methods: switch on **OAuth sign-in** and add the app's redirect host (for example the domain the app shows when you create a connector).
2. In the app, add the MCP server URL and choose OAuth.
3. Approve on the WordPress screen that opens. You choose the permission preset and expiry there.

Needs pretty permalinks and WordPress installed at the root of the domain (the `/.well-known/` discovery files are served by WordPress). Endpoints:

| | |
| --- | --- |
| Protected resource metadata | `/.well-known/oauth-protected-resource` |
| Authorization server metadata | `/.well-known/oauth-authorization-server` |
| Registration | `POST /wp-json/wpc-oauth/v1/register` |
| Authorization | `/wpc-oauth/authorize` |
| Token | `POST /wp-json/wpc-oauth/v1/token` |

Public clients only, PKCE (S256) required, single-use codes that expire after 10 minutes. Tokens issued this way appear on the Access tab and can be revoked there.

## Security notes

- Use a dedicated administrator user (for example `ai-dev`) for tokens, so the activity log and post authors show what the AI did.
- Keep destructive tools off unless you need them.
- Add every live domain to **Safety → Production guard**.
- **Delete WP Connection and the MCP Adapter before launch.**

Found a security problem? Please contact me through [nishidshajib.com](https://nishidshajib.com) instead of opening a public issue.

## Development

```
wp-connection.php          Plugin header, constants, bootstrap
includes/
  class-wpc-abilities.php   MCP tools (WordPress Abilities)
  class-wpc-admin.php       Tools → WP Connection screens
  class-wpc-auth.php        API key / Bearer token auth
  class-wpc-clients.php     Supported AI apps and their setup steps
  class-wpc-oauth.php       OAuth 2.1 sign-in
  class-wpc-updater.php     GitHub release updates (GitHub build only)
  class-wpc-files.php       Sandboxed file access and backups
  class-wpc-guard.php       Production guard and staging protection
  class-wpc-log.php         Activity log
  class-wpc-settings.php    Settings and defaults
assets/icons/               Optional official app icons (see README there)
bin/build.sh                Builds the release zips
```

### Releasing a new version

1. Bump the version in three places: the `Version:` header and `WPC_VERSION` in `wp-connection.php`, and `Stable tag:` in `readme.txt`. Add a changelog entry.
2. Run `bash bin/build.sh`. It refuses to build if the three versions don't match, and creates:
   - `dist/wp-connection.zip` — GitHub build (includes the updater)
   - `dist/wp-connection-wordpressorg.zip` — WordPress.org build (no GitHub updater, no "install from zip URL" tool, as the directory guidelines require)
3. Create a GitHub release tagged `v1.2.0` and attach `dist/wp-connection.zip`. Mark betas as pre-releases.

Sites running the GitHub build see the update within 12 hours (or right away with **Updates → Check for updates**).

## Roadmap

- Refresh tokens for OAuth
- WordPress.org listing

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
