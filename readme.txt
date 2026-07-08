=== Altegena Invitation Editor ===
Contributors: kayahan
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.2.0
License: MIT
License URI: https://opensource.org/licenses/MIT

A lightweight, DOM-based invitation card editor for WooCommerce with WhatsApp sharing.

== Description ==

Customers customize invitation card text layers directly on the product page, then
add the design to cart or share the finished invitation via WhatsApp (a public
/davetiye/{token} page with an Open Graph preview image).

The plugin is theme-agnostic: it ships sensible defaults and integrates with any
theme through a settings page (Settings > Altegena Davetiye), `altegena_*` filters,
and CSS custom properties.

Updates are delivered from the plugin's GitHub repository — WordPress shows the
usual "update available" notice when a newer release is tagged.

== Changelog ==

= 1.2.0 =
* Decouple from theme: extension API (filters + admin settings page + CSS variables).
* Personalization visibility is plugin-controlled (default: hidden from customer, shown in admin).
* Theme-agnostic Open Graph de-duplication via the `altegena_seo_suppress_actions` filter.
* GitHub-based automatic updates.

= 1.1.0 =
* WhatsApp sharing: capture the card as a PNG, persist it, and expose a public
  /davetiye/{token} page with an Open Graph preview.

= 1.0.0 =
* Initial release: visual invitation editor + WooCommerce cart/order integration.
