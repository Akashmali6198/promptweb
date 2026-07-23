# PromptWeb

**Maximum AI Creativity** — JSON-first WordPress websites powered by GitHub.

PromptWeb separates two concerns:

| Repository | Purpose |
|------------|---------|
| **Plugin code** `Akashmali6198/promptweb` | WordPress plugin (public). Update via *Settings → Update Plugin from GitHub*. |
| **Design repo** (user-configured) | Website blueprint JSON + `AI_INSTRUCTIONS.md`. Never confused with the plugin repo. |

## For site owners (simple)

1. Connect your **design** GitHub repo in PromptWeb Settings (token + `owner/repo`).
2. Click **Initialize AI-Ready Repository** (creates starter blueprint + AI instructions).
3. Turn on **Auto-sync** (default). The live site pulls new designs automatically.
4. Visit the site. Logged-in editors can:
   - **Manual Edit** → change text/styles → click **Publish** (one step to GitHub).
   - **AI Prompt** → write plain English → **Publish Prompt** (external AI processes later).
5. Share your **live website URL** with any AI agent working in the design repo.

Your design data and settings are never deleted by plugin updates.

## For AI agents (Grok, Claude, ChatGPT, …)

1. **Read `AI_INSTRUCTIONS.md` first** (created by Initialize).
2. Edit **`blueprints/latest.json` only** for website design.
3. Accept **plain-English prompts** — do not ask the human for JSON schemas.
4. Act as an expert designer: modern, trendy, high-quality layouts.
5. Support reference URLs/images; prefer direct image URLs.
6. Process `prompts[]` with `"status": "pending"`, then mark `"done"`.
7. Understand phrases like *update changes*, *publish changes*, *PromptWeb update changes*.
8. When finished, **always return the live published website URL**.

## Architecture

```
Design GitHub repo  ──auto-sync──►  WordPress blueprint option
        ▲                                    │
        │ Publish (editor)                   ▼
   Frontend Editor                    PromptWeb_Renderer
   (Manual / AI Prompt)               + Frontend routes
```

- **JSON-first** — not Gutenberg as the content model.
- **Multisite** — network activation supported; network-active settings are network-scoped.
- **Legacy** — Gutenberg converter remains deprecated and off by default.

## URLs

- Home: front blueprint page (`is_front_page` or first page)
- Namespaced: `/promptweb/{slug}/`
- Shortcode: `[promptweb]` or `[promptweb page="home"]`

## Development

Requires WordPress 5.8+, PHP 7.4+.

Plugin self-update uses the public zipball of this repository and **never** clears options or blueprints.
