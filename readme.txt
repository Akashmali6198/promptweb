=== PromptWeb ===
Contributors: promptweb
Tags: ai, design, github, multisite, mcp, tailwind
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 2.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

PromptWeb — Full creative freedom for AI design: static HTML (Tailwind) + dynamic PHP, MCP tools, GitHub sync. Multisite-ready.

== Description ==

PromptWeb lets AI agents build high-quality websites on WordPress:

* Static pages: full HTML + Tailwind CSS (CDN) + JavaScript
* Dynamic pages: PHP + WordPress loops, queries, and hooks
* Draft-first publishing and visual design analysis for self-improvement
* GitHub as the design source of truth (sync / auto-sync / commit)
* MCP tools via WordPress Abilities API + optional mcp-adapter
* Multisite compatible; plugin updates never delete design data

== Installation ==

1. Upload the `promptweb` folder to the `/wp-content/plugins/` directory.
2. **Single site:** Activate the plugin through the 'Plugins' menu in WordPress.
3. **Multisite:** Network Activate via Network Admin → Plugins for network-wide use, or activate per site as needed.
4. Connect a design GitHub repository and run **Initialize AI-Ready Repository**.

== Frequently Asked Questions ==

= Is this plugin Multisite compatible? =

Yes. The plugin is network-activatable and uses Multisite-aware storage for settings and design data.

= Does updating the plugin delete my design? =

No. Design files are stored under uploads/promptweb/ and in your design GitHub repo. Plugin Update from GitHub only replaces plugin code.

= How do AI agents connect? =

Use the design GitHub repository with AI_INSTRUCTIONS.md, and/or connect MCP clients to the site's Abilities/MCP endpoints (Application Passwords). Tools require manage_options.

== Changelog ==

= 2.0.0 =
* Major architecture: static HTML + dynamic PHP design pages
* pages/static, pages/dynamic, pages/manifest.json structure
* MCP / Abilities tools: list, get, create, update, publish, visual analysis, commit
* Draft-first page creation; strengthened AI_INSTRUCTIONS.md
* Frontend rendering for static and dynamic pages; visual editor temporarily limited on v2 pages
* Legacy JSON blueprints still supported; GitHub init/sync/update preserved

= 1.0.0 =
* Initial foundation: JSON blueprints, GitHub sync, renderer, Multisite support

== Upgrade Notice ==

= 2.0.0 =
Major design architecture upgrade. Existing GitHub settings, blueprints, and sync continue to work. Re-run Initialize to add the new pages/ structure for maximum AI design quality.
