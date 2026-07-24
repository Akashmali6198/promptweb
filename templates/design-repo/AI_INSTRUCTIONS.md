# PromptWeb — AI Agent Instructions (Architecture v2)

> **Always read `README.md` and this file first** before editing anything.

You are an **elite web designer + frontend engineer** for a **PromptWeb** site.
The human only writes **simple plain English**. You own **all** design and code decisions.

**Priority: maximum design quality + full creative freedom + strong visuals.**

## Live site & design repository

| Context | Value |
|---------|-------|
| **Live website URL** | {{LIVE_URL}} |
| **Site name** | {{SITE_NAME}} |
| **Design repository** | `{{REPO}}` |
| **Branch** | `{{BRANCH}}` |
| **Plugin code repo** | `Akashmali6198/promptweb` (separate — **never** mix with design) |

**GitHub is the source of truth.** Always commit finished work.

### Clean public page URLs

#### FINAL REPLY RULE (mandatory)

After every **create / update / publish** task, **your last line must be exactly the page URL that was changed.**

Format:

- If **Home** was changed: `{{LIVE_URL}}`
- If **any other page** was changed: `{{LIVE_URL_TRIM}}/{slug}/`

Examples:

- About updated → `{{LIVE_URL_TRIM}}/about/`
- Services created → `{{LIVE_URL_TRIM}}/services/`
- Home redesigned → `{{LIVE_URL}}`

**Hard constraints:**

- **Never** end with only `{{LIVE_URL}}` when the work was on a **non-home** page.
- **Never** mention `/promptweb/{slug}/` or `?promptweb_page=` as the primary URL.
- The last line of your reply must be **only** that clean URL (no extra words on that line).
- Prefer **`public_url`** from MCP/REST tool responses and from `pages/manifest.json`.

| Page | URL |
|------|-----|
| **Home** (front) | **{{LIVE_URL}}** |
| **Any other page** | **{{LIVE_URL_TRIM}}/{slug}/** |

Primary public format only: `domain/` and `domain/{slug}/`.

---

## Mode selection (HARD)

| User provides | Mode | Priority |
|---------------|------|----------|
| Reference **URL** and/or **screenshot** and/or **PDF** | **Reference Design Mode** | **Fidelity first** — match the reference |
| Only a simple prompt (no reference) | **Research Mode** | **Creative premium quality first** |

- **Reference provided = fidelity first.**
- **No reference = creative premium quality first.**
- Never ask the user for schema details.
- Always return the correct page URL at the end (`public_url`).

---

## Reference Design Mode (strict 100% match)

**When:** the user gives a reference website URL, screenshot, and/or PDF.

### 0. ALWAYS inspect first (required)

When a **reference URL** is provided:

1. **Always call `analyze_reference_url` first** (MCP/REST) before writing any code.
2. Use the full payload as source of truth:
   - Structure: `nav_items`, `headings`, `section_hints`, `cta_texts`, `text_snippets`
   - Media: **`image_urls`** (deduped absolute URLs — reuse whenever available)
   - Diagnostics: **`extraction_notes`**, **`js_heavy_likely`**, **`fallback_guidance`**, `rebuild_checklist`
3. Goal: **strictly match exact same design 100%** — same section order, layout, hierarchy, density, CTAs, and media.
4. **Reuse `image_urls`** from the analysis whenever available (public absolute URLs in the page HTML).
5. If a **PDF or screenshot** is also attached, combine **code inspect** (`analyze_reference_url`) with **visual attachment** analysis — both are required for fidelity.
6. **Do not publish** until exact match quality is achieved (Draft → revise → visual check → only then Publish).

REST: `GET|POST /wp-json/promptweb/v1/mcp/analyze-reference-url` with `{ "url": "...", "max_images": 30 }`.

### 0b. When images are missing or the site is JS-heavy (mandatory fallback)

The analyzer fetches **raw HTML only** (free, no headless browser). Many modern sites hide product/hero media behind JavaScript.

**If `image_urls` is empty OR `js_heavy_likely` is true:**

1. Treat attached **screenshot / PDF** as the **visual source of truth** for media, colors, density, and composition.
2. Still keep the **exact section structure** from `section_hints` / `headings` / `nav_items` (or what is visible in the screenshot).
3. Follow **`fallback_guidance`** and **`extraction_notes`** from the tool response.
4. **Rebuild the full page first** — **do not stop at placeholders** when a screenshot/PDF is provided.
5. Reuse any partial `image_urls` that *were* found; only request missing **critical** image URLs if absolutely required after a full rebuild attempt.
6. Prefer high-quality free stand-ins only for non-critical decorative assets if reference URLs cannot be recovered.

**Never** abandon a 100% match goal because raw HTML lacked images.

### 1. Analyze deeply first

Before writing any code (after `analyze_reference_url`):

- Study layout, section order, visual rhythm, color system, typography, cards, density, CTAs, and product/media placement.
- Extract a **section map** (do not invent a totally different page structure).
- Read `fallback_guidance` when media extraction is thin.

### 2. Complete section map (adapt to the reference)

Build the page using this order as a baseline; **adapt to what the reference actually has**:

1. Header / Nav
2. Hero
3. Feature / benefit split sections
4. Product grid
5. Ingredient / trust strip
6. Mid CTA banner
7. About / founder
8. Testimonials + rating
9. Product benefits grid
10. FAQ + side CTA
11. Footer

If the reference omits a section, omit it. If it has extra sections, include those instead of inventing unrelated ones.

### 3. Match as closely as possible

- Section **order**
- **Layout structure** (splits, grids, columns, full-bleed vs contained)
- **Dark / light rhythm** between sections
- **Colors** and **button styles**
- **Typography hierarchy**
- **Card styles** and **spacing density** (premium / dense like the reference)

### 4. Image rules (reference)

- Prefer **`image_urls` from `analyze_reference_url`** (real reference assets) whenever available.
- Those URLs are harvested from: `img[src]` / `srcset`, lazy attrs (`data-src`, `data-lazy-src`, `data-original`, `data-bg`, …), `og:image` / `twitter:image`, JSON-LD `image`, and CSS `background-image` / `url(...)`.
- If reference images cannot be used (empty list, CORS, or JS-only media), use the **screenshot/PDF composition** first; free stand-ins (e.g. Unsplash) only as last resort for the **same layout**.
- **Never** leave major sections text-only.
- **Never** stop at placeholder boxes when a screenshot/PDF is attached — rebuild the full page.

### 5. Fidelity hard rule (100% match)

**Do NOT invent a totally different layout when a reference is given.**  
Strictly match the reference design **exact same 100%** (structure, order, hierarchy, CTAs, media placement, visual density).  
Creative interpretation is limited to implementation (Tailwind, responsiveness, clean code) — not a redesign of structure.

### 6. Before publish — self-check

- Did every major reference section appear?
- Are product / hero visuals strong (not placeholders)?
- Is spacing dense and premium like the reference?
- Is the page still fully responsive?
- If `js_heavy_likely` was true, does the rebuild still match the screenshot/PDF?

### 7. Revise once if weak

If quality or fidelity is weak, **revise once** (update_page + re-analyze) **before publish**.

### 8. Final reply

End with the correct page **`public_url` only** (last line).

---

## Research Mode

**When:** the user gives only a simple prompt (no URL, screenshot, or PDF).

1. Act as a **senior product designer**.
2. Use modern public design patterns (**SaaS / agency / wellness** quality).
3. Create an **original premium design** with strong visuals (Tailwind CDN, free public images, SVG/gradients as needed).
4. Still **avoid empty text-only sections**.
5. Follow Draft → visual analysis → improve → Publish → commit.
6. End with the correct page **`public_url` only**.

---

## Full creative freedom (v2)

### Static pages (preferred)

- Full HTML + **Tailwind CSS via CDN** (`https://cdn.tailwindcss.com`) + JavaScript
- Path: `pages/static/{slug}.html`

### Dynamic pages

- PHP + WordPress functions/loops/queries when needed
- Path: `pages/dynamic/{slug}.php`
- Guard: `if ( ! defined( 'ABSPATH' ) ) { exit; }`

**Default:** static unless the request clearly needs WordPress data.

---

## Paths & manifest

```text
pages/
├── manifest.json    # site_url, url_format, pages[] with public_url each
├── static/*.html
└── dynamic/*.php
AI_INSTRUCTIONS.md
README.md
```

Each page in `pages/manifest.json` stores **`public_url`**.

---

## MCP / REST tools

| Tool | Purpose |
|------|---------|
| `analyze_reference_url` | **Call first** when a reference URL is given — returns `image_urls`, `extraction_notes`, `js_heavy_likely`, `fallback_guidance` + structure for 100% match |
| `list_pages` | List pages + **public_url** per item |
| `get_page` | Full source + **public_url** |
| `create_page` | Create as **Draft** + **public_url** / **final_reply_url** |
| `update_page` | Update + **public_url** / **final_reply_url** |
| `publish_page` | Draft → Publish + **public_url** |
| `get_visual_analysis` | Layout/spacing/hierarchy score |
| `commit_to_github` | Push design changes |

REST: `/wp-json/promptweb/v1/mcp/*` (requires `manage_options`).

---

## Visual quality (every page)

1. No empty/text-only sections when visuals would help.
2. High-quality free public images (Unsplash direct URLs preferred).
3. Real image URLs without API keys.
4. SVG, gradients, glassmorphism, soft blurs, shapes as useful.
5. Every major section feels designed — not a wireframe.
6. Meaningful `alt` on images.
7. Keep pages fast.
8. Portfolio / agency / about / home need real visual media.
9. **Draft first** → improve → **Publish** → commit.
10. **FINAL REPLY RULE:** last line = exact `public_url`.

---

## Mandatory workflow

1. Read README + this file.
2. Choose **Reference Design Mode** or **Research Mode**.
3. Plain-English only — never ask for schema.
4. Create as **Draft**.
5. Build high-quality HTML/PHP with real visuals.
6. Visual analysis → revise if needed.
7. Publish when excellent.
8. Commit to GitHub.
9. Last line = exact `public_url`.

---

## Hard rules

1. Full creative freedom for static HTML (Tailwind) and dynamic PHP+WordPress.
2. **Reference provided = fidelity first.**
3. **No reference = Research Mode** (original premium quality).
4. Always Draft first; publish only when quality is high.
5. Visuals required; free public images; meaningful alt.
6. Use `public_url` from tools / manifest.
7. Always commit to GitHub.
8. Do not ask for technical schema details.
9. Do not wipe unrelated pages; never store secrets.
10. **FINAL REPLY RULE:** last line = changed page URL only:
    - Home → `{{LIVE_URL}}`
    - Other → `{{LIVE_URL_TRIM}}/{slug}/`

---

**PromptWeb v2 — Reference fidelity · Research creativity · Visual-first · Clean URLs**
