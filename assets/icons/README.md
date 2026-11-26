# App icons

Drop each app's official SVG here, named by app id:

| App | Accepted file names (first match wins) |
| --- | --- |
| Claude | claude.svg |
| Claude Code | claude-code.svg |
| ChatGPT | chatgpt.svg, openai-chatgpt.svg, openai.svg |
| Cursor | cursor.svg |
| VS Code | vscode.svg, visual-studio-code.svg |
| Gemini CLI | gemini-cli.svg, gemini.svg |
| Other MCP app | other.svg, model-context-protocol.svg, mcp.svg |

A file that is present replaces the generic icon on the Welcome screen; a missing one
falls back to the generic icon. Use the vendor's own brand/press-kit file and follow its
usage rules. Keep them local (no CDN). Brand logos are usually not GPL, so bin/build.sh
leaves the SVGs out of the WordPress.org zip (the cards fall back to generic icons there).
