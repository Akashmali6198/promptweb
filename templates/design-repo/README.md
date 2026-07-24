# PromptWeb Design Repository (Architecture v2)

This repository is the **source of truth** for the website design of **{{SITE_NAME}}**.

| | |
|---|---|
| **Live site** | {{LIVE_URL}} |
| **Design repo** | `{{REPO}}` |
| **Branch** | `{{BRANCH}}` |
| **Plugin code** | `Akashmali6198/promptweb` (separate — never mix) |

---

## Structure

```text
pages/
├── manifest.json       # Catalog: site_url, url_format, pages[] with public_url
├── static/             # Static HTML + Tailwind CDN + JS
│   └── {slug}.html
└── dynamic/            # PHP + WordPress
    └── {slug}.php
AI_INSTRUCTIONS.md      # Full agent rules (Reference + Research modes)
README.md               # This file
blueprints/latest.json  # Legacy JSON (optional)
```

### Page types

| Type | Path | When to use |
|------|------|-------------|
| **Static** | `pages/static/{slug}.html` | High visual quality (Home, About, Services…) |
| **Dynamic** | `pages/dynamic/{slug}.php` | WordPress loops / queries / hooks |

Prefer static HTML + Tailwind via CDN unless dynamic WordPress data is required.

---

## Clean public URLs (one format only)

| Page | URL |
|------|-----|
| **Home** | {{LIVE_URL}} |
| **Other pages** | {{LIVE_URL_TRIM}}/{slug}/ |

MCP/REST tools return **`public_url`** on list/get/create/update/publish.

### FINAL REPLY RULE (mandatory)

After every **create / update / publish**, **your last line must be exactly the page URL that was changed.**

- Home → `{{LIVE_URL}}`
- Other → `{{LIVE_URL_TRIM}}/{slug}/`
- **Never** end with only the homepage URL when work was on another page.
- **Never** use `/promptweb/{slug}/` or `?promptweb_page=` as the primary URL.
- Prefer `public_url` / `final_reply_url` from tool responses.

---

## Modes (see AI_INSTRUCTIONS.md)

| User provides | Mode | Priority |
|---------------|------|----------|
| URL / screenshot / PDF | **Reference Design Mode** | Fidelity first |
| Simple prompt only | **Research Mode** | Creative premium quality first |

Full creative freedom. Draft first. Strong visual quality. Never ask for schema details.

---

## MCP tools

`analyze_reference_url` (call first when reference URL given), `list_pages`, `get_page`, `create_page` (Draft), `update_page`, `publish_page`, `get_visual_analysis`, `commit_to_github`

REST: `/wp-json/promptweb/v1/mcp/*` (`manage_options`)

### Quality loop

1. Read **AI_INSTRUCTIONS.md**.
2. Draft → design with real visuals → visual analysis → improve.
3. Publish → commit to GitHub.
4. Last line = exact changed page `public_url`.

---

## Safety

- Plugin updates from `Akashmali6198/promptweb` **never** delete this design data.
- Local copies live under `uploads/promptweb/`.
- Re-initialize refreshes AI_INSTRUCTIONS.md / README.md without wiping custom design pages.
- Existing `blueprints/latest.json` is never overwritten by Initialize if present.
