# PromptWeb

**Maximum AI Creativity** — free, JSON-first WordPress websites with **Design Tokens**, GitHub as source of truth, and one-click publish.

## Two repositories (never mix them)

| Repository | Purpose |
|------------|---------|
| **Plugin code** `Akashmali6198/promptweb` | WordPress plugin only. *Settings → Update Plugin from GitHub* |
| **Design repo** (user-configured) | Website blueprint + `AI_INSTRUCTIONS.md` |

Updating the plugin **never** deletes design data, blueprints, or GitHub connection settings.

## For site owners

1. Connect **design** GitHub repo (token + `owner/repo`) in PromptWeb Settings.
2. **Initialize AI-Ready Repository** → creates:
   - `blueprints/latest.json` (with default **design tokens**)
   - `AI_INSTRUCTIONS.md` (for any AI agent)
3. Leave **Auto-sync** on — the live site pulls new design automatically (throttled).
4. Edit on the live site (logged-in):
   - **Manual Edit** → change content/styles → **Publish** (one step to GitHub)
   - **AI Prompt** → plain English → **Publish Prompt** (external AI processes later)
5. Give any AI agent:
   - This design repo
   - Your **live website URL**
   - `AI_INSTRUCTIONS.md` + `README.md`

Manual Sync is optional backup only.

## For AI agents (Grok, Claude, ChatGPT, …)

1. **Read `README.md` and `AI_INSTRUCTIONS.md` first.**
2. Edit **only** `blueprints/latest.json` for website design.
3. **Always use / create `design` tokens** for colors, font, radius, shadow, container width.
4. Act as expert designer + frontend developer; modern/trending quality.
5. Accept **simple plain-English prompts only**  
   Example: *“design a 5 section homepage for web developer portfolio”*
6. Support reference URLs/images; prefer direct image URLs.
7. Keep structure: **pages → sections → elements** (+ `design`, `prompts`).
8. Process `prompts[]` with `"status": "pending"`, then mark `"done"`.
9. Understand: *update changes*, *publish changes*, *PromptWeb update changes*.
10. **Do not** ask the human for JSON schema details.
11. When finished, **always return the live published website URL**.

### Design tokens (free, required for consistency)

```json
{
  "design": {
    "colors": {
      "primary": "#4F46E5",
      "primary_dark": "#3730A3",
      "ink": "#0F172A",
      "muted": "#64748B",
      "surface": "#FFFFFF",
      "surface_alt": "#F8FAFC",
      "bg": "#F1F5F9",
      "border": "#E2E8F0"
    },
    "font_family": "Inter, system-ui, sans-serif",
    "radius": "12px",
    "shadow": "0 10px 30px rgba(15, 23, 42, 0.08)",
    "container_width": "1120px"
  }
}
```

WordPress maps these to CSS variables automatically. Missing tokens fall back to clean defaults.

### Section patterns

Set `settings.variant` when possible: `hero`, `features`, `about`, `stats`, `testimonials`, `cta`.  
Use `settings.layout: "grid"` + `settings.columns` for card grids.  
Button `settings.variant`: `primary` | `secondary` | `outline` | `ghost`.

## Architecture

```
Design GitHub  ──auto-sync──►  WP blueprint option  ──►  Renderer (+ design tokens)
      ▲                                                      │
      └── Publish (editor)                                   ▼
                                                    Live website HTML
```

- Multisite compatible  
- Hostinger-friendly file updates  
- No paid APIs  

## URLs

- `/` — front blueprint page  
- `/promptweb/{slug}/` — page by slug  
- `[promptweb page="home"]` — shortcode  

## License

GPL-2.0-or-later (WordPress plugin).
