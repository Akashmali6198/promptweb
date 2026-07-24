# PromptWeb

**v2 — Full creative freedom** for AI-built WordPress sites: static HTML (Tailwind CDN) + dynamic PHP, MCP tools, GitHub as source of truth.

## Two repositories (never mix them)

| Repository | Purpose |
|------------|---------|
| **Plugin code** `Akashmali6198/promptweb` | WordPress plugin only. *Settings → Update Plugin from GitHub* |
| **Design repo** (user-configured) | Website design pages + `AI_INSTRUCTIONS.md` |

Updating the plugin **never** deletes design data, page files, blueprints, or GitHub connection settings. Local design copies live under `uploads/promptweb/`.

## Design repository structure

```text
pages/
├── manifest.json       # slug, type, status (draft|publish), title
├── static/             # Beautiful static HTML pages
│   └── home.html       # Full HTML + Tailwind CSS (CDN) + JavaScript
└── dynamic/            # Dynamic WordPress pages
    └── blog.php        # PHP + WP loops / queries / hooks
AI_INSTRUCTIONS.md      # Mandatory guide for AI agents
README.md
blueprints/latest.json  # Optional legacy JSON (still supported)
```

## For site owners

1. Connect **design** GitHub repo (token + `owner/repo`) in PromptWeb Settings.
2. **Initialize AI-Ready Repository** → creates `pages/`, `AI_INSTRUCTIONS.md`, `README.md`, and a compatibility blueprint.
3. Leave **Auto-sync** on — the live site pulls new design automatically (throttled).
4. Give any AI agent:
   - This design repo **or** live site MCP endpoint
   - Your **live website URL**
   - `AI_INSTRUCTIONS.md` + `README.md`
5. AI creates pages as **Draft** → improves via **visual analysis** → **Publish** → **commit to GitHub**.

Manual Sync is optional backup only.

### MCP / Abilities tools

When WordPress **Abilities API** (6.9+) is available, PromptWeb registers tools. With the official **mcp-adapter** plugin, they appear as MCP tools. REST mirrors always work:

| Tool | REST |
|------|------|
| `list_pages` | `GET /wp-json/promptweb/v1/mcp/list-pages` |
| `get_page` | `GET /wp-json/promptweb/v1/mcp/get-page?slug=` |
| `create_page` | `POST /wp-json/promptweb/v1/mcp/create-page` |
| `update_page` | `POST /wp-json/promptweb/v1/mcp/update-page` |
| `publish_page` | `POST /wp-json/promptweb/v1/mcp/publish-page` |
| `get_visual_analysis` | `GET|POST /wp-json/promptweb/v1/mcp/get-visual-analysis` |
| `commit_to_github` | `POST /wp-json/promptweb/v1/mcp/commit-to-github` |

All tools require `manage_options` (or `manage_network` on Multisite). Authenticate with Application Passwords.

Optional custom MCP server id: `promptweb-mcp-server`  
Endpoint (with mcp-adapter): `/wp-json/promptweb-mcp/mcp`

## For AI agents

1. **Read `README.md` and `AI_INSTRUCTIONS.md` first.**
2. Prefer **static HTML + Tailwind via CDN** for maximum visual quality.
3. Use **dynamic PHP** only when WordPress data/loops are required.
4. **Always create new pages as Draft.**
5. After create/update → **get_visual_analysis** → improve until score is high → **publish**.
6. **Always commit** to GitHub when finished.
7. Accept simple plain-English prompts only — **do not** ask for technical schema details.
8. Return the **live website URL** when done.

## Architecture

```
Design GitHub  ──auto-sync──►  Local pages (uploads/promptweb) + optional blueprint
      ▲                                    │
      └── commit_to_github                 ▼
                                    Static HTML  |  Dynamic PHP  |  Legacy JSON renderer
                                               Live website
```

- Multisite compatible  
- Hostinger-friendly (uploads storage + HTTP API)  
- Frontend visual editor is temporarily disabled when v2 design pages are active (JSON-element editor conflict); AI + MCP is the primary edit path  
- No paid APIs  

## URLs

- `/` — front design page (or legacy front blueprint page)  
- `/promptweb/{slug}/` — page by slug  
- `[promptweb page="home"]` — shortcode  

## License

GPL-2.0-or-later (WordPress plugin).
