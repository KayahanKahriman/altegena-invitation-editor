# Altegena Invitation Editor - Plugin Documentation

## Overview

A lightweight, DOM-based invitation card editor for WooCommerce. Customers customize text layers on invitation card templates directly on the product page, then add to cart or share the finished card via WhatsApp. The customized text flows through the WooCommerce cart and order system.

When an order is paid, the plugin renders two PDFs per invitation item for the print shop and shows them on the admin order screen:
- **Konturlu:** text converted to vector outlines.
- **Metinli:** selectable text with embedded fonts.

The print shop downloads them and handles sizing and bleed itself.

**Author:** Kayahan
**Version:** 1.4.1
**Text Domain:** `altegena-invitation-editor`
**Constants prefix:** `ALTEGENA_`
**UI Language:** Turkish (button labels, error messages)

## Architecture

### Design Philosophy
- **No canvas editing** - Uses pure DOM elements (divs with `contenteditable`) positioned absolutely over a background image via CSS. A PNG is rendered client-side (html2canvas) solely for the WhatsApp share feature.
- **JSON-driven configuration** - Each invitation product stores its entire design layout as a JSON blob in post meta. The JSON defines the canvas size, background image and text layers with their positions/styles.
- **Singleton pattern** - Hook-owning PHP classes use `get_instance()` singletons; print engine classes are plain classes.
- **No build step** - Plain jQuery-based JS, no bundler/transpiler. No composer: the print engine is dependency-free PHP (runs on shared hosting without Chromium/Ghostscript/Imagick).
- **No fallback fonts for print** - Every layer is printed with its own bundled font file. A missing family, file or glyph is an error that blocks that item's PDF, never a silent substitution.
- **Print = what the customer saw** - The print layout reproduces the browser's CSS layout and HarfBuzz shaping (verified against Chrome and HarfBuzz, see Development Notes).
- **No print size / bleed** - The PDF page is the canvas at CSS size (1 px = 0.75 pt) with the site background stretched to it. Output is vector, so the print shop scales it freely.

### Data Flow
```
Admin creates JSON config --> Saved as `_invitation_json_config` post meta
                                        |
Customer visits product page --> JS parses JSON --> Renders editor UI
                                        |
Customer edits text layers --> Data stored in hidden input `#altegena-custom-data`
                                        |
Add to cart (AJAX) --> `altegena_custom_data` POST param --> sanitized --> `altegena_design_data` in cart item
                                        |
Checkout --> `_altegena_design_data` order item meta + individual layer labels as separate meta
         --> `_altegena_print_snapshot` order item meta (template layout + the text each layer showed)
                                        |
Payment (payment_complete / processing / completed) --> Action Scheduler job `altegena_print_generate_item`
         --> Altegena_Print_Generator::render() --> 2 PDFs in protected storage --> `_altegena_print_state`
                                        |
Admin order screen --> download / regenerate / correct texts (`_altegena_print_text_overrides`)

Share (optional) --> PNG + text map to admin-ajax `altegena_save_share` --> `altegena_invitation` post at /davetiye/{token}
```

## File Structure

```
altegena-invitation-editor/
├── altegena-invitation-editor.php      # Main plugin file, bootstrap class, HPOS declaration, update checker
├── includes/
│   ├── class-settings.php            # Settings page (General + Font denetimi tabs) + static API
│   ├── class-design-config.php       # Template access, share merge, print snapshot
│   ├── class-product-meta.php        # WordPress metabox: visual editor + JSON config for product editor
│   ├── class-cart-handler.php        # Cart/order integration + editor modal HTML + trigger button
│   ├── class-product-page-handler.php # Hides default add-to-cart & quantity for invitation products
│   ├── class-share-handler.php       # WhatsApp share: CPT, AJAX save, public page, OG tags, migration
│   ├── class-print-order-handler.php # Snapshot at checkout, payment triggers, jobs, state, text overrides
│   ├── class-print-storage.php       # Protected PDF storage (random folders, .htaccess, access probe)
│   ├── class-print-admin.php         # Order meta box, AJAX regenerate/save texts, download, list column
│   ├── class-print-font-audit.php    # Font audit: fonts without files, safe fixes, glyph coverage
│   ├── print/                        # Print engine (pure PHP)
│   │   ├── class-print-text.php      # Print text sanitizer (no trimming) + code point helpers
│   │   ├── class-print-font.php      # sfnt reader: cmap, metrics, hmtx, glyf outlines, kern, GDEF, name
│   │   ├── class-print-font-registry.php # fonts.css @font-face parsing + CSS font matching (no fallback)
│   │   ├── class-print-shaper.php    # OpenType GSUB/GPOS subset (HarfBuzz-compatible for these fonts)
│   │   ├── class-print-layout.php    # Browser-identical layout → positioned glyphs (canvas px)
│   │   ├── class-print-pdf-writer.php # Minimal PDF 1.7 writer (Flate, JPEG pass-through, GD fallback)
│   │   ├── class-print-outline-emitter.php # Glyphs as vector paths (konturlu PDF)
│   │   ├── class-print-text-emitter.php # CID TrueType fonts + ToUnicode/ActualText (metinli PDF)
│   │   ├── class-print-generator.php # render(): one layout → both PDFs; background resolution
│   │   └── class-print-cli.php       # `wp altegena print-proof` (development tool, WP-CLI only)
│   └── plugin-update-checker/        # Vendored YahnisElsts/plugin-update-checker v5.7 (do not edit)
├── templates/
│   └── single-invitation.php         # Theme-independent public share page (/davetiye/{token})
├── assets/
│   ├── js/
│   │   ├── editor.js                 # Frontend editor logic (Altegena_Editor object)
│   │   ├── admin-editor.js           # Admin visual editor logic (Altegena_AdminEditor object)
│   │   ├── admin-print.js            # Order screen print box (regenerate, save texts, revert)
│   │   ├── public-invitation.js      # Read-only renderer for the public share page
│   │   └── vendor/html2canvas.min.js # Bundled html2canvas 1.4.1 (same-origin, untainted capture)
│   ├── css/
│   │   ├── editor.css                # Full-screen modal layout, sidebar, canvas, layers, share dialog
│   │   ├── admin-editor.css          # Admin visual editor styles (three-panel layout, properties)
│   │   ├── admin-print.css           # Order print box + orders list column
│   │   ├── public-invitation.css     # Public share page styles
│   │   └── fonts.css                 # @font-face declarations (48 faces, 45 families) — also the print font registry
│   ├── fonts/                        # 45 .ttf + 3 .otf font files + .htaccess
│   │   └── print/                    # Offline TrueType conversions of the 3 CFF .otf fonts (+ README with SHA-1s)
│   └── backgrounds/                  # Invitation background images (referenced by JSON config)
├── readme.txt                        # Update-modal metadata + changelog (plugin-update-checker)
└── CLAUDE.md                         # This file
```

## PHP Classes

### `Altegena_Invitation_Editor` (altegena-invitation-editor.php)
- **Role:**
  - plugin bootstrap: defines constants, includes files, initializes the handler classes;
  - declares WooCommerce HPOS compatibility (`before_woocommerce_init` → `FeaturesUtil::declare_compatibility('custom_order_tables', …)`);
  - registers the GitHub update checker;
  - loads `class-print-cli.php` only under WP-CLI.
- **Constants:** `ALTEGENA_PLUGIN_DIR`, `ALTEGENA_PLUGIN_URL`, `ALTEGENA_PLUGIN_FILE`, `ALTEGENA_VERSION`, `ALTEGENA_SHARE_MAX_CONFIG_BYTES`, `ALTEGENA_SHARE_MAX_IMAGE_BYTES`. Optional (wp-config.php): `ALTEGENA_PRINT_DIR`.
- **Enqueue logic:** Only loads assets on single product pages (`is_product()`) that have `_invitation_json_config` meta set.
- **Script dependencies:** `jquery`, `wc-add-to-cart`, `altegena-html2canvas`
- **Localized data (`altegena_config`):** `raw_config`, `is_admin_mode` (`manage_options` + `?mode=admin`), `ajax_url`, `share_nonce`, `share_action`, `product_id`, `share_message`.

### `Altegena_Settings` (class-settings.php)
- **Role:** Settings page (**Settings → Altegena Davetiye**, option `altegena_settings`).
  - **Genel** tab: labels, colors, visibility, default line-height.
  - **Font denetimi** tab: rendered by `Altegena_Print_Font_Audit`.
  - Static read API used by every other class.
- **`line_height()`** - Unitless default line-height for layers without their own `lineHeight`.
  - Source: setting `print_default_line_height`, default 1.55, filter `altegena_default_line_height`.
  - Output as `--altegena-line-height` by `css_vars()`; the print layout uses the same value.
- **Adding a setting:** update `defaults()`, `text_defaults()` (for labels), the key list in `sanitize()`, and `render_page()`. If JS needs it, add it to the relevant `wp_localize_script` call.

### `Altegena_Design_Config` (class-design-config.php)
- **Role:** Single place that reads the trusted template (`_invitation_json_config`). Styles, positions, fonts and canvas always come from the template, never from the client.
- **`get_template($product_id)`** → `{raw, config}` or `WP_Error`.
- **`merge($product_id, $text_map, $sanitizer)`** - Template + client text (share handler, with `sanitize_share_text`).
- **`template_text($layer)`** - `default_text` with literal `\n` as real newlines (as editor.js renders it).
- **`build_snapshot($product_id, $variation_id, $text_map, $backfilled)`** - Immutable print snapshot:
  ```
  {schema, captured_at, product_id, variation_id, template_sha1, backfilled, canvas,
   layers[{id, label, hidden_on_frontend, style, text}],
   fonts{"Family|weight|style": {file, sha1, synthetic_bold, synthetic_italic} | {error}}}
  ```
  Hidden (fixed) layers always use template text.

### `Altegena_Product_Meta` (class-product-meta.php)
- **Role:** Registers the "Invitation Editor" metabox on the product edit screen with the visual editor.
- **Meta key:** `_invitation_json_config` (hidden input synced from the visual editor or JSON textarea).
- **Admin asset enqueue:** on `post.php`/`post-new.php` for `product`:
  - `wp.media`, `wp-color-picker`, `jquery-ui-sortable`;
  - fonts CSS;
  - `admin-editor.css` (+ inline `Altegena_Settings::css_vars()`);
  - `admin-editor.js`.
- **Localized data (`altegena_admin_config`):** `fonts` (45 bundled family names), `plugin_url`.
- **Save validation:** `save_product_data_tab()` requires `canvas` and `layers` and `wp_slash()`es before `update_post_meta()`. Empty values delete the meta.

### `Altegena_Cart_Handler` (class-cart-handler.php)
- **Editor trigger button + modal HTML + inline JS:** variation-aware; the modal is moved to `document.body`, z-index max.
- **Cart item data:** decodes `altegena_custom_data` (≤256 KB, ≤200 layers). Per layer it keeps only:
  - `label` (sanitize_text_field);
  - `text` (`Altegena_Print_Text::sanitize`, no trimming);
  - `fontFamily`.

  The result is stored as `altegena_design_data`.
- **Cart/checkout display:** gated by personalization visibility; hidden on the cart page.
- **Order meta:** `_altegena_design_data` blob plus one visible meta row per layer; `filter_order_item_meta()` hides those rows per visibility.
- **Hook:** `woocommerce_single_variation` (priority 15) for FSE/block theme compatibility.

### `Altegena_Product_Page_Handler` (class-product-page-handler.php)
- Injects CSS via `wp_head` to hide the default add-to-cart button and quantity input for invitation products (the variation form stays functional).

### `Altegena_Share_Handler` (class-share-handler.php)
- **CPT:** `altegena_invitation`, rewrite slug `davetiye` (public URL `/davetiye/{token}`). Rewrite rules are flushed once per `ALTEGENA_VERSION`.
- **AJAX:** `wp_ajax(_nopriv)_altegena_save_share` checks:
  - nonce;
  - per-IP rate limit (10 per 10 min);
  - payload caps;
  - PNG/JPEG magic-byte + dimension validation.

  It then sideloads the image into the media library.
- **Trusted merge:** `build_merged_config()` → `Altegena_Design_Config::merge()`.
- **Public page:** `templates/single-invitation.php`, `public-invitation.js/.css`, OG/Twitter tags at `wp_head` priority 1 with SEO de-duplication.
- **Migration:** `maybe_migrate()` renames old `sie_*` identifiers.

### `Altegena_Print_Order_Handler` (class-print-order-handler.php)
- **Snapshot:** `woocommerce_checkout_create_order_line_item` priority 20 (classic checkout and Checkout block / Store API) → `_altegena_print_snapshot` (array, stored `wp_slash`ed).
- **Triggers:**
  - `woocommerce_payment_complete`, `woocommerce_order_status_processing` and `woocommerce_order_status_completed` → `enqueue_order()`.
  - It queues one unique `as_enqueue_async_action('altegena_print_generate_item', {order_id, item_id}, 'altegena-print', true)` per invitation item not already queued/running/done.
  - Without Action Scheduler it generates synchronously.
- **Job:** `run_job()` → `generate_item()`.
  - Retries with backoff (up to 3 attempts) unless the error is permanent (`PERMANENT_ERRORS`: missing font/glyph/background, bad canvas, …).
  - A `locked` item is retried in 60 s.
- **`generate_item($order, $item, $force)`:**
  - **Lock:** option `altegena_print_lock_{item}`, 300 s TTL.
  - **Snapshot:** `ensure_snapshot()` backfills from the current template for pre-snapshot orders (with a warning).
  - **Skip check:** the `input_hash` covers snapshot, overrides, engine version, default line-height, current font file SHA-1s and background file size/mtime. Work is skipped when the hash is unchanged and the files exist, unless `$force`.
  - **Render:** `Altegena_Print_Generator::render()`, writing `siparis-{no}-kalem-{item}-r{rev}-{rand}-konturlu|metinli.pdf`.
  - **After success:** old revision files are deleted.
  - **Notes and logging:** order notes on success and on new failures; logs to `wc_get_logger()` source `altegena-print`.
- **Stored data:**
  - **Order item JSON strings** (written via delete + add meta, so WooCommerce's string slashing round-trips them):
    - `_altegena_print_state` = `{status queued|running|done|failed, attempts, rev, input_hash, generated_at, duration_ms, page_px, files{outline,text}{file,bytes}, warnings[], error{code,message,at}}`.
    - `_altegena_print_text_overrides` = `{rev, layers{id:{text,by,at}}, history[]}`.
  - **Order meta:** `_altegena_print_summary` (`done|warning|queued|failed`). `update_summary()` reads item states from the DB, because `WC_Order::get_item()` returns fresh instances.
- **`save_texts($order, $item, $texts, $user_id)`:**
  - validates layer ids against the snapshot and sanitizes;
  - stores only differences from the customer's text (reverting to the original removes the override);
  - increments `rev`, keeps 50 history entries, adds an order note.

  The snapshot, customer-facing order rows and emails are never changed.
- **Cleanup:** `woocommerce_before_delete_order` / `before_delete_post` (legacy `shop_order`) remove the order's PDF folder.

### `Altegena_Print_Admin` (class-print-admin.php)
- **Meta box** "Davetiye baskı PDF'leri": screen `wc_get_page_screen_id('shop-order')` (HPOS) or `shop_order`, only for orders with invitation items. Per item it shows:
  - status badge, page px, time, rev;
  - "Konturlu PDF indir" / "Metinli PDF indir";
  - "Yeniden oluştur";
  - errors and warnings;
  - a `<details>` "Metinleri düzenle" table: editable layers, then "Sabit metinler". Each row has a textarea (no `name`, so WooCommerce's order save ignores it), a "Müşteri: …" line when overridden, "Orijinale dön", and "Kaydet ve PDF'leri yeniden oluştur".

  A storage exposure warning appears when `Altegena_Print_Storage::is_publicly_accessible()`.
- **AJAX:**
  - endpoints: `wp_ajax_altegena_print_regenerate`, `wp_ajax_altegena_print_save_texts`;
  - checks: nonce `altegena_print_{order_id}`, capability `edit_shop_orders`, the item must belong to the order;
  - both regenerate synchronously and return the re-rendered panel HTML.
- **Download:** `admin-post.php?action=altegena_print_download&order_id&item_id&kind=outline|text&_wpnonce`.
  - Checks: nonce `altegena_print_download_{order_id}` + `edit_shop_orders`.
  - The file is taken only from the stored state (via `Altegena_Print_Storage::path()`).
  - Response: `litespeed_control_set_nocache`, `nocache_headers`, `application/pdf` attachment `siparis-{no}-kalem-{item}-konturlu|metinli.pdf`.
- **Orders list column** "Baskı PDF":
  - HPOS: `manage_woocommerce_page_wc-orders_columns` / `_custom_column`;
  - legacy: `manage_edit-shop_order_columns` / `manage_shop_order_posts_custom_column`;
  - reads `_altegena_print_summary`.

### `Altegena_Print_Storage` (class-print-storage.php)
- **Base folder:** `ALTEGENA_PRINT_DIR`, else `uploads/altegena-print-{16-char secret}` (option `altegena_print_storage_secret`); filter `altegena_print_storage_dir`.
- **Folder contents:** `.htaccess` (`Require all denied` + `Deny from all`), `index.php`, one `{order_id}-{16 random}` folder per order (order meta `_altegena_print_dir`).
- **Methods:** `write()` (temp file + rename), `path()` (filename pattern + `realpath` containment), `delete_file()`, `delete_order()`.
- **`is_publicly_accessible()`:** loopback probe of `altegena-probe.pdf`, cached 12 h in transient `altegena_print_storage_public`. nginx ignores `.htaccess`, so locally it reports accessible; on LiteSpeed/Apache it should not.

### `Altegena_Print_Font_Audit` (class-print-font-audit.php)
- **Font denetimi tab:**
  - lists template layers whose font family/file doesn't exist;
  - **safe fixes** (`admin_post_altegena_font_safe_fix`): spelling-only, exact weight/style file must exist, backup in `_invitation_json_config_font_fix_backup`;
  - per-font coverage of Turkish letters and template characters;
  - flags restricted embedding (fsType) and CFF;
  - lists layers that get synthetic bold/italic.

### Print engine (includes/print/)
- **`Altegena_Print_Text`**
  - `sanitize()`: invalid UTF-8 handled; CR/CRLF/U+2028/U+2029 → LF; tab → space; controls (except LF) and bidi overrides stripped; NFC; 2000 chars. Never trims.
  - Helpers: `codepoints()` / `chr_utf8()`.
- **`Altegena_Print_Font`** - `load($path)` (cached). Provides:
  - cmap: fmt 12 > fmt 4 > symbol 3,0;
  - `vertical_metrics()`: FreeType/Skia rule (typo metrics if USE_TYPO_METRICS, else hhea, with zero fallbacks);
  - `advance_width()`, `glyph_contours()` (simple + composite glyf);
  - `legacy_kern()`, GDEF `glyph_class()` / `mark_attach_class()`, `read_coverage()` / `read_class_def()`;
  - PDF descriptor data: `bbox()`, `italic_angle()`, `cap_height()`, `postscript_name()`;
  - `fs_type()` / `embedding_allowed()`, `is_cff()`, `bytes()`.
- **`Altegena_Print_Font_Registry`**
  - Parses `assets/css/fonts.css`.
  - `match($family, $weight, $style)`: CSS weight/style matching within the family → `{face, synthetic_bold (≥600 requested, face <600), synthetic_italic}` or `WP_Error('font_missing')`.
  - `print_file($face)`: uses `assets/fonts/print/{name}.ttf` when present (CFF conversions).
- **`Altegena_Print_Shaper`** - `for_font($font)->shape($codepoints, {language, disable})` → glyphs `{gid, start, end, advance, dx, dy}` in font units.
  - Script `latn` → `DFLT`; language `TRK ` → default LangSys.
  - Features: required + `ccmp locl rlig calt clig liga`.
  - GSUB lookup types 1, 2, 4, 5, 6, 7, with lookup flags, mark attach classes and mark filtering sets.
  - GPOS `kern` via types 1, 2, 9; the legacy `kern` table only when GPOS has no kern feature.
  - `LETTER_SPACING_DISABLED` (`liga clig calt dlig hlig`) mirrors Blink when letter-spacing ≠ 0.
- **`Altegena_Print_Layout`** - `layout($snapshot, $texts)` → scene in canvas px (y down):
  - text: `white-space: pre` lines (one trailing LF ignored); letter-spacing added after every cluster;
  - box: width = widest line (ceil to 1/64 px), height = lines × line-height;
  - line-height: number × font-size / px / % / normal, else `Settings::line_height()` (1/64 px precision);
  - vertical placement: ascent/descent rounded to px; baseline_i = i·LH + floor((LH − (A+D))/2) + A;
  - horizontal placement: textAlign offset; X0 = (left + width/2)% when both are set (else left); top %;
  - layer matrix = T(−W/2, −H/2) · R(θ) · T(X0, top + H/2);
  - page = canvas × `PX_TO_PT` (0.75 pt/px);
  - errors: `bad_canvas`, `font_missing`, `font_unsupported`, `missing_glyph` (all layers' missing chars listed).
- **`Altegena_Print_Pdf_Writer`**
  - Objects/xref, Flate streams.
  - `add_image()`: JPEG pass-through (DCT, Gray/RGB/CMYK); other formats via GD → JPEG 95 with a memory check.
  - `add_page()` (MediaBox = TrimBox), `output()`, `num()` (locale-independent), `text_string()` (UTF-16BE).
- **`Altegena_Print_Outline_Emitter`** - TrueType quadratics → cubic paths (implied on-curve points), cached per glyph, one `cm` per glyph, nonzero fill.
  - Synthetic bold: `B` with width upm/24 (= font-size/24 px), round joins.
  - Synthetic italic: skew 0.25.
- **`Altegena_Print_Text_Emitter`**
  - Fonts: Type0/Identity-H + CIDFontType2 (CID = glyph id), full TrueType as FontFile2, `/W` widths, ToUnicode CMap (ligature → cluster text).
  - Content: one `BT` per layer; per line `/Span << /ActualText >> BDC`, `Tm` (y flip, italic skew), `TJ` adjustments to the layout's positions.
  - Synthetic bold: `2 Tr` + width font-size/24.
  - Fonts whose fsType forbids embedding (Lovely Home, Riesling) are drawn as outlines (not selectable), with a warning.
- **`Altegena_Print_Generator`**
  - `render($snapshot, $texts)` → `{outline, text, scene, background, warnings}` (one layout, two writers).
  - `resolve_background()`: `canvas.bg_image` mapped to a local uploads/plugin file by URL path (host-independent, remote never fetched), stretched to the page.
  - `layout_debug()` for DOM comparison.
- **`Altegena_Print_CLI`** - `wp altegena print-proof <product_id> [--texts=<json>] [--out=<dir>] [--layout]` writes both proof PDFs (default `uploads/altegena-print-proof/`) and optionally the layout JSON.

## JavaScript: Altegena_Editor (editor.js)

Single IIFE-wrapped object, jQuery-based.
- **Init:**
  - parses config, builds sidebar (360px) + preview;
  - renders layers (hidden layers have no input and aren't editable);
  - loads the localStorage autosave and binds events.
- **Canvas scaling:** `ResizeObserver` + CSS `transform: scale()`; the canvas is rendered at config px size.
- **Layer positioning:**
  - `left + width/2` % center point with `translateX(-50%)` (+ `rotate(Ndeg)`);
  - `white-space: pre`, auto width;
  - line-height from `style.lineHeight`, else the canvas' pinned `line-height: var(--altegena-line-height, 1.55)` (pinned in editor.css, public-invitation.css and admin-editor.css).
- **Two-way binding:** sidebar textarea → layer; contenteditable layer → textarea via `innerText` (line breaks kept); cart data comes from the textareas.
- **Admin mode (`?mode=admin`):** draggable layers + "Copy Updated JSON".
- **Add to cart:** serializes `form.cart`, AJAX POST, redirects to the cart.
- **WhatsApp share:** waits for fonts, html2canvas capture of an unscaled clone, `altegena_save_share`, share dialog (message from `altegena_config.share_message`).
- **Autosave:** `localStorage` key `altegena_autosave_{productId}`.

## JavaScript: Altegena_AdminEditor (admin-editor.js)

Visual design editor in the product metabox.
- **Layout:**
  - left: canvas settings (width/height px, background image) and layer list with groups;
  - center: live canvas (fullscreen toggle);
  - right: layer properties and alignment.
- **Layer management:**
  - add / duplicate / delete (all selected layers; the confirmation shows the count);
  - Shift+click multi-select; mousedown deselect; `hidden_on_frontend` toggle;
  - flat `jquery-ui-sortable` list with group headers (collapse, rename, duplicate).
- **Drag & keyboard:** multi-layer drag compensated by `scaleFactor`; arrows 1px (Shift 10px); Escape deselects; Delete removes the selection.
- **Undo/redo:** snapshot history (50 entries) pushed from the `syncConfigToHiddenField()` chokepoint; `_isRestoring` guard.
- **Alignment:** align to canvas (1+ selected layers); align/distribute the selection (2+/3+) on the stored % values.
- **Properties:**
  - ID, label, group, default text, hidden;
  - font: 45 bundled fonts only (a template font without a file shows as "(dosyası yok)");
  - size, color, weight, style, textAlign, letterSpacing, lineHeight;
  - left/top/width %, rotate.
- **Form submission:** applies the JSON tab if it is active, then `syncConfigToHiddenField()`.

## JavaScript: admin-print.js

- `.altegena-print-regenerate` → `altegena_print_regenerate`.
- `.altegena-print-save` collects `textarea[data-layer]` values → `altegena_print_save_texts` (`texts` JSON).
- `.altegena-print-revert` restores `data-original` into the textarea (saved on the next "Kaydet").
- The item panel is replaced with the returned HTML (the `<details>` stays open). Errors are shown with `alert`.

## JSON Configuration Format

Stored in `_invitation_json_config` post meta:

```json
{
  "canvas": { "width": 1200, "height": 1800, "bg_image": "https://example.com/wp-content/uploads/invitation-bg.jpg" },
  "layers": [
    {
      "id": "names", "type": "text", "label": "Isimler", "group": "Ön Yüz",
      "default_text": "Ad & Soyad", "hidden_on_frontend": false,
      "style": { "left": "10%", "top": "40%", "width": "80%", "rotate": "0",
        "fontFamily": "Darleston", "fontSize": "48px", "color": "#333333", "textAlign": "center" }
    }
  ]
}
```

- **canvas.width/height** - Base px size (aspect ratio, scaling). The PDF page is width × height × 0.75 pt.
- **canvas.bg_image** - Background URL. The print PDF uses it too, so it must resolve to a local uploads/plugin file.
- **layers[].id / type ("text") / label / group / default_text (`\n` newlines) / hidden_on_frontend**.
- **layers[].style** - CSS applied to the layer div:
  - `left`, `top`, `width` in %;
  - `rotate` in degrees (transform);
  - `fontFamily` must match `fonts.css` (no print fallback);
  - `fontSize` in px;
  - `lineHeight` as number/px/%/normal;
  - `letterSpacing` in px;
  - `color`, `textAlign`, `fontWeight`, `fontStyle`.

## Custom Fonts

- **Files:** 48 files (45 `.ttf` + 3 `.otf`) in `assets/fonts/`, declared by 48 `@font-face` rules / 45 families in `fonts.css` (`font-display: swap`).
- **Admin dropdown:** the family list is `Altegena_Product_Meta::get_available_fonts()`. Add fonts there and in `fonts.css` together.
- **Print:** reads the same `fonts.css`.
  - The CFF fonts Champignon, Christmas Wish Calligraphy and Marquette are printed from `assets/fonts/print/*.ttf`.
  - Those files come from fontTools otf2ttf; glyph ids, advances, cmap and GSUB/GPOS/GDEF were verified identical.
  - **When a source `.otf` changes, re-convert and update that README.**
- **Updating a font file** (e.g. adding missing Turkish glyphs) changes its SHA-1, so "Yeniden oluştur" re-renders the affected items. Check Settings → Altegena Davetiye → Font denetimi for gaps.
- **Embedding restrictions:** Lovely Home (fsType 0x0102) and Riesling (0x0002) forbid embedding, so they appear as outlines only in the metinli PDF.

## WooCommerce Integration Points

| Hook | Class | Purpose |
|------|-------|---------|
| `add_meta_boxes` | Product_Meta, Print_Admin | Product "Invitation Editor" metabox / order "Davetiye baskı PDF'leri" box |
| `woocommerce_process_product_meta` | Product_Meta | Save JSON config (validation, wp_slash) |
| `admin_enqueue_scripts` | Product_Meta, Settings, Print_Admin | Admin editor assets / settings color picker / order print box assets |
| `admin_menu`, `admin_init` | Settings | Settings page and option |
| `admin_post_altegena_font_safe_fix` | Print_Font_Audit | Spelling-only template font fixes |
| `before_woocommerce_init` | Main file | Declare HPOS compatibility |
| `woocommerce_single_variation` | Cart_Handler | Trigger button + modal |
| `woocommerce_add_cart_item_data` | Cart_Handler | Sanitized design data into cart |
| `woocommerce_get_item_data` | Cart_Handler | Checkout display |
| `woocommerce_checkout_create_order_line_item` | Cart_Handler (10), Print_Order_Handler (20) | Design data / print snapshot on the order item |
| `woocommerce_order_item_get_formatted_meta_data` | Cart_Handler | Hide per-layer rows per visibility |
| `woocommerce_payment_complete`, `woocommerce_order_status_processing`, `woocommerce_order_status_completed` | Print_Order_Handler | Queue print jobs |
| `altegena_print_generate_item` (Action Scheduler, group `altegena-print`) | Print_Order_Handler | Render both PDFs for one item |
| `woocommerce_before_delete_order`, `before_delete_post` | Print_Order_Handler | Delete the order's PDF folder |
| `wp_ajax_altegena_print_regenerate`, `wp_ajax_altegena_print_save_texts` | Print_Admin | Regenerate / save text corrections |
| `admin_post_altegena_print_download` | Print_Admin | Capability-checked PDF download |
| `manage_woocommerce_page_wc-orders_columns` (+ legacy `manage_edit-shop_order_columns`) | Print_Admin | "Baskı PDF" orders list column |
| `wp_enqueue_scripts` | Main class, Share_Handler | Product page / share page assets |
| `wp_head` | Product_Page_Handler, Share_Handler | Hide default buttons / OG tags |
| `init` | Share_Handler | Migration, CPT, rewrite flush |
| `wp_ajax(_nopriv)_altegena_save_share` | Share_Handler | Save a shared invitation |
| `template_include` | Share_Handler | Share page template |

## Admin Usage

**Designing a product:** Products → Edit Product → **Invitation Editor**. Set the canvas and background, add and style layers, then click **Update**.

**Print PDFs:**
- **Generation:** they are generated automatically after payment. Action Scheduler relies on WP-Cron, so set up a real cron on low-traffic sites.
- **On the order screen:** the **Davetiye baskı PDF'leri** box lets you:
  - download "Konturlu" (outlines) or "Metinli" (selectable);
  - regenerate;
  - fix texts under "Metinleri düzenle" → "Kaydet ve PDF'leri yeniden oluştur".
- **Failures:** items that fail (missing glyph/font/background) show the reason. Fix the font or template, then click "Yeniden oluştur".

**Proofs without orders (WP-CLI):** `wp altegena print-proof <product_id> --layout`.

**Frontend admin mode (legacy):** `?mode=admin` on the product page lets you drag layers and "Copy Updated JSON" into the metabox.

## Key Implementation Details

- **Block theme (FSE) compatibility:** the editor button hooks `woocommerce_single_variation`.
- **Modal placement:** the modal is moved to `document.body` to escape stacking contexts; the admin bar offset is handled in CSS.
- **Variation awareness:** the customize button is disabled until a variation is selected.
- **Cart page suppression:** design data is hidden on the cart page and shown at checkout (per visibility).
- **Print vs share:** the share PNG is a raster screen preview. Print PDFs are vector at canvas size; the print shop handles final size and bleed.

## Development Notes

- **Dependencies and PHP compatibility:**
  - no npm/composer dependencies (vendored: html2canvas, plugin-update-checker);
  - PHP 7.4–8.4 compatible: no `match`, nullsafe, named arguments, union types or `str_contains`.
- **Settings page:** Settings → Altegena Davetiye (Genel, Font denetimi). Per-product design is JSON post meta.
- **Strings:** JS strings are mostly hardcoded Turkish; PHP labels/messages are configurable via settings + `altegena_*` filters. Full `__()` i18n is not done.
- **wp_slash gotcha (post meta):** `update_post_meta()` unslashes internally, so wrap unslashed JSON with `wp_slash()` or `\n` escapes are lost.
- **wp_slash gotcha (WC meta):**
  - `WC_Data_Store_WP::add_meta()` only slashes strings, but `add_metadata()` / `update_metadata_by_mid()` unslash arrays too.
  - Store arrays as `wp_slash($array)` (e.g. `_altegena_print_snapshot`), or store JSON strings through delete + add meta (state/overrides).
  - `WC_Order::get_item($id)` loads a fresh instance, distinct from `$order->get_items()`.
- **Print verification** (how the engine was validated; repeat after engine changes):
  - **Shaping:** compare `Altegena_Print_Shaper` against uharfbuzz (HarfBuzz 14) for every font using absolute glyph positions. All template fonts matched within 1 font unit; HarfBuzz splits legacy kern across two glyphs, but positions are the same.
  - **Layout:** measure an untransformed clone of the editor canvas in Chrome (`Range.getClientRects`, set `transition: none` before removing transforms) against `print-proof --layout`. Tested with rotation, letter-spacing, synthetic bold, line-height 0 and left/right alignment; everything was within ≤0.36 px.
  - **PDFs:** PyMuPDF checks: no fonts in konturlu; embedded Type0 fonts and extractable Turkish text in metinli; both rasterize nearly identically.
- **Known print limits:**
  - Not supported: GPOS mark attachment (4/5/6), contextual positioning (7/8), GSUB 8, and Apple-style kern format 2 (only Anthem Of The Angels, unused).
  - Characters missing as precomposed glyphs are an error (HarfBuzz would compose base + mark from the same font).
  - Vertical metrics follow FreeType/Skia (Chrome on Linux/Android/ChromeOS), so Windows/macOS browsers may place some script fonts' baselines differently.
- **Storage security:** LiteSpeed/Apache honor the storage `.htaccess`; nginx does not (random folder names still apply). The order box warns when the probe finds the folder publicly reachable; set `ALTEGENA_PRINT_DIR` outside the web root then.
- **Updates / releases:** plugin-update-checker watches GitHub (`setBranch('main')`: releases → tags → branch). Bump `Version:`, `ALTEGENA_VERSION` and readme `Stable tag` together, then tag a release (tag = version, no `v`).

## Theme Integration / Extension API

The plugin is theme-agnostic: sensible defaults, settings (**Settings → Altegena Davetiye**), documented filters and CSS custom properties. Themes never depend on plugin internals.

`Altegena_Settings` is the hub: `::text($key)`, `::visibility()`, `::css_vars()`, `::line_height()`, `::get()`.

### Filters
- `altegena_label_customize`, `altegena_label_add_to_cart`, `altegena_label_share`, `altegena_modal_title` — UI text
- `altegena_share_message`, `altegena_og_title`, `altegena_og_description` — share message / OG text
- `altegena_personalization_visibility` — `admin_only` (default) | `customer_and_admin` | `hidden`
- `altegena_colors` — CSS variable → value
- `altegena_default_line_height` — default layer line-height (editor, share page, print)
- `altegena_print_storage_dir` — print PDF storage folder
- `altegena_seo_suppress_actions` — `wp_head` callbacks to remove on share pages

### CSS custom properties
- **Fed from settings on `:root`:** `--altegena-accent`, `--altegena-accent-dark`, `--altegena-share`, `--altegena-share-dark`, `--altegena-line-height`.
- **Fallback-only:** `--altegena-danger`, `--altegena-stage-bg`.

### Personalization visibility
- `get_item_data()` only adds checkout rows for `customer_and_admin`.
- `filter_order_item_meta()` hides order rows on the frontend for `admin_only` and everywhere for `hidden`.
- Themes should not read the plugin's cart/order keys.

### dm-omni theme note
The active `dm-omni` theme integrates via `altegena_seo_suppress_actions` and decorates the modal via stable `altegena-*` classes.
