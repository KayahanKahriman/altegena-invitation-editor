=== Altegena Invitation Editor ===
Contributors: kayahan
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.4.0
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

= 1.4.0 =
* Print-ready PDFs: after payment, every invitation order item gets two PDFs at the product's physical size — "konturlu" (text as vector outlines) and "metinli" (selectable text with embedded fonts).
* Pure-PHP print engine (no Chromium/Ghostscript/Imagick, works on shared hosting): browser-identical layout, OpenType shaping (Turkish locl, ligatures, kerning), synthetic bold/italic, rotation, letter spacing, print background with DPI checks. No fallback fonts: a missing font or glyph stops that item with a clear reason.
* Order screen box "Davetiye baskı PDF'leri": download, regenerate, and correct layer texts before printing (the customer's original text is kept); "Baskı PDF" column in the orders list.
* Generation runs in the background (Action Scheduler) with retries; PDFs are stored in a protected folder and downloaded through wp-admin only.
* WP-CLI: `wp altegena print-proof <product_id>` renders proof PDFs for a template.

= 1.3.0 =
* Print groundwork: every invitation order item now stores an immutable design snapshot (template layout plus the text the customer saw) for print-ready PDFs.
* Product editor: print size in mm (ratio lock), optional high-resolution print background, and DPI/aspect-ratio checks.
* New Settings > Altegena Davetiye > Font denetimi tab. It lists template layers whose font has no file, applies safe spelling-only fixes, and reports missing Turkish characters per font.
* The admin font list only offers bundled fonts (no system fonts, since print never falls back to another font).
* The default layer line-height is now a setting (1.55) pinned on the canvas, so the editor, share page and admin preview render identically.
* Cart text is sanitized without trimming (visible spaces and blank lines are kept). WooCommerce HPOS compatibility is declared.

= 1.2.1 =
* Fix: text typed directly on the invitation canvas now reaches the cart and share data, with line breaks preserved.
* Fix: the editor's WhatsApp share dialog uses the share message from Settings instead of a hardcoded one.
* Fix: in the admin visual editor, the Delete key and "Sil" button remove all selected layers (confirmation shows the count).

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

Specially made for www.davetiyemakinesi.com
