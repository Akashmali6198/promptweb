=== PromptWeb ===
Contributors: promptweb
Tags: multisite, prompts
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

PromptWeb — Multisite-compatible WordPress plugin foundation.

== Description ==

PromptWeb is a Multisite-compatible WordPress plugin. This release provides a clean structural foundation only: main bootstrap, core class, admin scaffolding, assets directories, and translation support.

Network activation is supported (`Network: true` in the plugin header).

== Installation ==

1. Upload the `promptweb` folder to the `/wp-content/plugins/` directory.
2. **Single site:** Activate the plugin through the 'Plugins' menu in WordPress.
3. **Multisite:** Network Activate via Network Admin → Plugins for network-wide use, or activate per site as needed.

== Frequently Asked Questions ==

= Is this plugin Multisite compatible? =

Yes. The plugin is network-activatable and uses Multisite-aware activation hooks and options where appropriate.

= Does this version include features? =

No. Version 1.0.0 is a structural foundation only. Feature modules will be added in later releases.

== Changelog ==

= 1.0.0 =
* Initial foundation: plugin bootstrap, Multisite support, admin class stub, assets and languages folders.

== Upgrade Notice ==

= 1.0.0 =
Initial release — foundation only.
