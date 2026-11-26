# Changelog

## 1.1.0
- One-click "Install & activate MCP Adapter" dialog. WP Connection no longer refuses to activate when the MCP Adapter is missing.
- Replaces the older WP Connections plugin in one click and keeps its settings, tokens and activity log.
- Renamed to WP Connection (slug `wp-connection`, site wp-connection.com). The MCP URL is now `/wp-json/wp-connection/mcp`.
- Welcome screen to choose your AI app, with per-app setup steps (Claude, Claude Code, ChatGPT, Cursor, VS Code, Gemini CLI, other MCP apps).
- OAuth 2.1 sign-in (PKCE) for apps that can't send a custom header. `Authorization: Bearer` tokens accepted.
- Updates tab. GitHub builds update from GitHub releases; WordPress.org builds update through WordPress.org.
- Neutral wording and generic defaults (no site-specific folders or domains).
- New brand colour (#3E00C7).

## 1.0.0
- First release.
