# Changelog

## 1.2.1
- Refused connections now say why (no key sent, unknown token, expired, wrong domain, IP not allowed…) in the app's error, the activity log and on the Overview tab.
- Unauthenticated MCP requests get a standard 401 with a `WWW-Authenticate` header, so AI apps can tell sign-in is needed instead of reporting the server as unreachable.

## 1.2.0
- New SEO tools (`get-seo`, `update-seo`) for Yoast SEO, Rank Math and All in One SEO with one set of fields: title, description, focus keyword, canonical, noindex, nofollow and social title/description/image. Posts, pages, categories and tags (terms: Yoast and Rank Math).
- Yoast SEO fields written through `upsert-post` now show on the site straight away.
- Admin pages are centred on wide screens; style changes no longer stick in the browser cache.

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
