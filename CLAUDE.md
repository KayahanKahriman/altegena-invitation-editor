# Altegena Invitation Editor - Plugin Documentation

## Overview

A lightweight, DOM-based invitation card editor for WooCommerce. Customers can customize text layers on invitation card templates directly on the product page before adding to cart, or share the finished card via WhatsApp. The customized text data flows through the WooCommerce cart and order system, and every order item keeps an immutable design snapshot for print-ready PDF generation (PDF engine in progress).

**Author:** Kayahan
**Version:** 1.3.0
**Text Domain:** `altegena-invitation-editor`
**Constants prefix:** `ALTEGENA_`
**UI Language:** Turkish (button labels, error messages)

## Architecture

### Design Philosophy
- **No canvas editing** - Uses pure DOM elements (divs with `contenteditable`) positioned absolutely over a background image via CSS. Orders store text data plus a layout snapshot. A PNG is rendered client-side (html2canvas) solely for the WhatsApp share feature.
- **JSON-driven configuration** - Each invitation product stores its entire design layout as a JSON blob in post meta. The JSON defines the canvas size, background image, print size and text layers with their positions/styles.
- **Singleton pattern** - Hook-owning PHP classes use `get_instance()` singletons.
- **No build step** - Plain jQuery-based JS, no bundler/transpiler.
- **No fallback fonts for print** - Every layer is printed with its own bundled font file. A missing family, file or glyph is an error, never a silent substitution.

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

Share (optional) --> PNG + text map to admin-ajax `altegena_save_share` --> `altegena_invitation` post at /davetiye/{token}
```

## File Structure

```
altegena-invitation-editor/
├── altegena-invitation-editor.php      # Main plugin file, bootstrap class, HPOS declaration, update checker
├── includes/
│   ├── class-settings.php            # Settings page (General + Font denetimi tabs) + static API
│   ├── class-design-config.php       # Template access, share merge, print snapshot, print canvas keys
│   ├── class-product-meta.php        # WordPress metabox: visual editor + JSON config for product editor
│   ├── class-cart-handler.php        # Cart/order integration + editor modal HTML + trigger button
│   ├── class-product-page-handler.php # Hides default add-to-cart & quantity for invitation products
│   ├── class-share-handler.php       # WhatsApp share: CPT, AJAX save, public page, OG tags, migration
│   ├── class-print-order-handler.php # Order-side print: design snapshot at checkout
│   ├── class-print-font-audit.php    # Font audit: fonts without files, safe fixes, glyph coverage
│   ├── print/
│   │   ├── class-print-text.php      # Print text sanitizer (no trimming) + code point helpers
│   │   ├── class-print-font.php      # Minimal sfnt reader (table directory, cmap, fsType)
│   │   └── class-print-font-registry.php # fonts.css @font-face parsing + CSS font matching (no fallback)
│   └── plugin-update-checker/        # Vendored YahnisElsts/plugin-update-checker v5.7 (do not edit)
├── templates/
│   └── single-invitation.php         # Theme-independent public share page (/davetiye/{token})
├── assets/
│   ├── js/
│   │   ├── editor.js                 # Frontend editor logic (Altegena_Editor object)
│   │   ├── admin-editor.js           # Admin visual editor logic (Altegena_AdminEditor object)
│   │   ├── public-invitation.js      # Read-only renderer for the public share page
│   │   └── vendor/html2canvas.min.js # Bundled html2canvas 1.4.1 (same-origin, untainted capture)
│   ├── css/
│   │   ├── editor.css                # Full-screen modal layout, sidebar, canvas, layers, share dialog
│   │   ├── admin-editor.css          # Admin visual editor styles (three-panel layout, properties, print notes)
│   │   ├── public-invitation.css     # Public share page styles
│   │   └── fonts.css                 # @font-face declarations (48 faces, 45 families) — also the print font registry
│   ├── fonts/                        # 45 .ttf + 3 .otf font files + .htaccess
│   └── backgrounds/                  # Invitation background images (referenced by JSON config)
├── readme.txt                        # Update-modal metadata + changelog (plugin-update-checker)
└── CLAUDE.md                         # This file
```

## PHP Classes

### `Altegena_Invitation_Editor` (altegena-invitation-editor.php)
- **Role:** Plugin bootstrap. Defines constants, includes files, initializes all handler classes, declares WooCommerce HPOS compatibility (`before_woocommerce_init` → `FeaturesUtil::declare_compatibility('custom_order_tables', …)`), registers the GitHub update checker.
- **Constants:** `ALTEGENA_PLUGIN_DIR`, `ALTEGENA_PLUGIN_URL`, `ALTEGENA_PLUGIN_FILE`, `ALTEGENA_VERSION`, `ALTEGENA_SHARE_MAX_CONFIG_BYTES`, `ALTEGENA_SHARE_MAX_IMAGE_BYTES`
- **Enqueue logic:** Only loads assets on single product pages (`is_product()`) that have `_invitation_json_config` meta set.
- **Script dependencies:** `jquery`, `wc-add-to-cart`, `altegena-html2canvas`
- **Localized data (`altegena_config`):**
  - `raw_config` - The raw JSON string from post meta
  - `is_admin_mode` - Boolean, true when user has `manage_options` cap AND `?mode=admin` query param is present
  - `ajax_url`, `share_nonce`, `share_action`, `product_id` - Share endpoint parameters
  - `share_message` - WhatsApp message prefix from `Altegena_Settings::text('share_message')`

### `Altegena_Settings` (class-settings.php)
- **Role:** Settings page (**Settings → Altegena Davetiye**, option `altegena_settings`) with two tabs: **Genel** (labels, colors, visibility, default line-height) and **Font denetimi** (rendered by `Altegena_Print_Font_Audit`). Static read API used by every other class for user-facing text, colors, visibility and the default layer line-height. See Theme Integration below.
- **`line_height()`** - Unitless default line-height for layers without their own `lineHeight` (setting `print_default_line_height`, default 1.55, filter `altegena_default_line_height`). Output as `--altegena-line-height` by `css_vars()`.
- **Adding a setting:** update `defaults()`, `text_defaults()` (for labels), the key list in `sanitize()`, and `render_page()`. If JS needs it, add it to the relevant `wp_localize_script` call — otherwise the JS side silently keeps a hardcoded default.

### `Altegena_Design_Config` (class-design-config.php)
- **Role:** Single place that reads the trusted template (`_invitation_json_config`). Styles, positions, fonts and canvas always come from the template, never from the client.
- **`get_template($product_id)`** → `{raw, config}` or `WP_Error`.
- **`merge($product_id, $text_map, $sanitizer)`** - Template + client text (used by the share handler with `sanitize_share_text`, which trims/strips tags as before).
- **`template_text($layer)`** - `default_text` with literal `\n` as real newlines (as editor.js renders it).
- **`clean_print_keys($canvas)`** - Validates `print_width_mm`, `print_height_mm`, `print_bg_image`, `print_bg_image_id`, `print_bg_px`; other canvas keys untouched.
- **`build_snapshot($product_id, $variation_id, $text_map, $backfilled)`** - Immutable print snapshot: `{schema, captured_at, product_id, variation_id, template_sha1, backfilled, canvas, layers[{id,label,hidden_on_frontend,style,text}], fonts{"Family|weight|style": {file, sha1, synthetic_bold, synthetic_italic} | {error}}}`. Hidden (fixed) layers always use template text.

### `Altegena_Product_Meta` (class-product-meta.php)
- **Role:** Registers a standalone WordPress metabox ("Invitation Editor") on the product edit screen with a full visual editor.
- **Metabox:** Registered via `add_meta_boxes` hook, rendered by `render_meta_box()`. Context: `normal`, priority: `high`.
- **Meta key:** `_invitation_json_config` (stored via hidden input, synced from visual editor or JSON textarea)
- **Admin asset enqueue:** Hooks `admin_enqueue_scripts`, guarded to `post.php`/`post-new.php` on `product` post type. Loads `wp.media`, `wp-color-picker`, `jquery-ui-sortable`, fonts CSS, `admin-editor.css` (+ inline `Altegena_Settings::css_vars()`), `admin-editor.js`.
- **Localized data (`altegena_admin_config`):** `fonts` (array of 45 font family names), `plugin_url`
- **`get_available_fonts()`** - Returns all custom font family names; must match `fonts.css` declarations.
- **Save validation:** `save_product_data_tab()` validates JSON structure (must have `canvas` and `layers` keys), runs `Altegena_Design_Config::clean_print_keys()` (re-encoding only if something invalid was dropped), and uses `wp_slash()` before `update_post_meta()` to prevent double-unslashing of backslash sequences (e.g., `\n` in JSON). Empty values delete the meta.
- **Metabox HTML:** Two tabs (Visual Editor / JSON). Visual tab contains a three-panel layout: left (canvas settings + print settings + layer list), center (live canvas preview), right (layer properties). JSON tab contains a raw textarea with validate button.

### `Altegena_Cart_Handler` (class-cart-handler.php)
- **Role:** Handles:
  1. **Editor trigger button** - Renders the customize button (label from settings), disabled until a variation is selected
  2. **Modal HTML** - Full-screen modal with header (title, add-to-cart button, share button, close button) and `#altegena-editor-app` container
  3. **Inline JS** - Modal open/close logic, variation-aware button enable/disable
  4. **Cart item data** - Decodes `altegena_custom_data` (≤256 KB, ≤200 layers) and keeps only `label` (sanitize_text_field), `text` (`Altegena_Print_Text::sanitize`, no trimming) and `fontFamily` per layer as `altegena_design_data`
  5. **Cart display** - Shows customized text per layer at checkout (hidden on cart page, only when visibility is `customer_and_admin`)
  6. **Order meta** - Stores `_altegena_design_data` blob + individual layer labels as separate order item meta
  7. **Order meta visibility** - `filter_order_item_meta()` hides per-layer rows according to the visibility setting
- **Hook:** Uses `woocommerce_single_variation` (priority 15) instead of `woocommerce_single_product_summary` for FSE/block theme compatibility.
- **Modal z-index:** `2147483647` (max int) - moved to `document.body` via JS to avoid stacking context issues.

### `Altegena_Product_Page_Handler` (class-product-page-handler.php)
- **Role:** Injects CSS via `wp_head` to hide the default WooCommerce add-to-cart button and quantity input for invitation products. Uses CSS `display: none !important` rather than removing template hooks, so the variation form stays functional.

### `Altegena_Share_Handler` (class-share-handler.php)
- **Role:** WhatsApp sharing of a finished design.
- **CPT:** `altegena_invitation`, rewrite slug `davetiye` (public URL `/davetiye/{token}`, token = 12-char random slug). Rewrite rules are flushed once per `ALTEGENA_VERSION`.
- **AJAX:** `wp_ajax(_nopriv)_altegena_save_share` → `ajax_save_share()`: nonce check, per-IP rate limit (10 per 10 min, transients), payload size cap, PNG/JPEG magic-byte + dimension validation, sideload into the media library.
- **Trusted merge:** `build_merged_config()` delegates to `Altegena_Design_Config::merge()`; the client supplies layer text only (sanitized, 2000 chars per layer).
- **Share post meta:** `_altegena_share_config`, `_altegena_share_image_id/_url/_dims`, `_altegena_share_product_id`, `_altegena_share_ip` (salted hash).
- **Public page:** `template_include` → `templates/single-invitation.php`; assets `public-invitation.js/.css`; `output_og_tags()` prints OG/Twitter tags at `wp_head` priority 1 and suppresses other SEO output (`altegena_seo_suppress_actions`, Yoast/Rank Math filters).
- **Migration:** `maybe_migrate()` (version-gated) renames the old `sie_*` meta keys / post type to `altegena_*`.

### `Altegena_Print_Order_Handler` (class-print-order-handler.php)
- **Role:** Order-side print integration. Currently: on `woocommerce_checkout_create_order_line_item` (priority 20; fires for the classic checkout and the Checkout block / Store API) builds `_altegena_print_snapshot` via `Altegena_Design_Config::build_snapshot()` for items with `altegena_design_data`. Logs failures to `wc_get_logger()` source `altegena-print`.
- **Planned (print PDF phases):** payment triggers (`woocommerce_payment_complete`, processing/completed) → Action Scheduler job, outlined + selectable-text PDFs, protected storage/download, HPOS order meta box with text overrides and regenerate.

### `Altegena_Print_Font_Audit` (class-print-font-audit.php)
- **Role:** "Font denetimi" settings tab.
  - Lists template layers whose font family/file doesn't exist in `fonts.css`.
  - **Safe fixes** (`admin_post_altegena_font_safe_fix`, `manage_options` + nonce): spelling-only matches whose exact weight/style file exists (e.g. `TrajanPro-Bold` → `Trajan Pro` + bold). The pre-fix template is kept once in `_invitation_json_config_font_fix_backup`; JSON is decoded as objects so `{}` survives re-encoding.
  - **Coverage report:** per font, missing Turkish letters (ÇçĞğİıÖöŞşÜü) and characters used in its layers' template texts; flags restricted embedding (fsType) and CFF outlines.
  - Lists layers that get synthetic bold/italic.

### Print helpers (includes/print/)
- **`Altegena_Print_Text`** - `sanitize()`: invalid UTF-8, CRLF/CR/U+2028/U+2029 → LF, tab → space, strips control characters (except LF) and bidi overrides, NFC, 2000 chars. **Never trims or strips tags** (layers use `white-space: pre`). Also `codepoints()` / `chr_utf8()`.
- **`Altegena_Print_Font`** - `load($path)` (cached) reads the sfnt table directory; cmap (format 12 > format 4 > symbol (3,0) with U+F000 mapping), `glyph_id()`, `has_glyph()`, `missing_codepoints()`, `fs_type()`, `embedding_allowed()`, `is_cff()`. Later phases add metrics/outlines/layout tables.
- **`Altegena_Print_Font_Registry`** - Parses `assets/css/fonts.css` @font-face blocks; `match($family, $weight, $style)` applies CSS weight/style matching within the family and reports `synthetic_bold` (≥600 requested, face <600) / `synthetic_italic`. Unknown family or missing file → `WP_Error('font_missing')`. `clean_family()`, `normalize_weight()`, `normalize_style()`, `file_sha1()`.

## JavaScript: Altegena_Editor (editor.js)

Single IIFE-wrapped object, jQuery-based. Key behaviors:

### Initialization
1. Parses `altegena_config.raw_config` JSON
2. Builds layout: sidebar (left, 360px) + preview area (right, flexible)
3. Renders text layers on canvas and input fields in sidebar (layers with `hidden_on_frontend` get no sidebar input and are not editable)
4. Loads autosaved data from `localStorage`
5. Binds all events

### Canvas Scaling
- Uses `ResizeObserver` on `.altegena-preview-area`
- Canvas is rendered at its natural pixel size from JSON config (`config.canvas.width`/`height`)
- CSS `transform: scale()` is applied to fit within the preview area while preserving aspect ratio
- `scaleFactor` is stored for coordinate translation

### Layer Positioning
- Layers use absolute positioning with percentage-based `left`/`top` values
- `left` + `width` from JSON config are converted to a center-point: `left = (left + width/2)%` with `transform: translateX(-50%)` for horizontal centering
- `width` is deleted after conversion (layers are auto-width, `white-space: pre`)
- `style.rotate` (degrees) is appended to the transform as `rotate(Ndeg)`
- Layer line-height comes from `style.lineHeight`, else the canvas' pinned `line-height: var(--altegena-line-height, 1.55)` (editor.css, public-invitation.css and admin-editor.css all pin it, so the theme/wp-admin line-height never leaks in)

### Two-Way Binding
- Sidebar textarea (`.altegena-layer-input`) input updates the preview layer text
- Preview `contenteditable` div input updates the sidebar textarea, reading `innerText` so line breaks typed on the canvas are kept
- The hidden input / cart data is always built from the sidebar textareas
- Focus on either highlights the counterpart (gold border/background)

### Admin Mode (`?mode=admin`)
- Activated when: user has `manage_options` AND URL has `?mode=admin`
- Layers become **draggable** (not contenteditable)
- Drag updates layer position in the config object
- "Copy Updated JSON" button appears in sidebar
- Updated JSON is logged to console on every drag

### Add to Cart
- Serializes `form.cart` (so variation attributes/ID are included), appends `add-to-cart={productId}`, and POSTs via AJAX to the form action
- Requires a selected variation on variable products (Turkish alert otherwise)
- On success: shows "Eklendi ✓" and redirects to the cart page
- On error: Turkish alert message, button restored

### WhatsApp Share
- Waits for the layers' custom fonts, captures an un-scaled off-screen clone of the canvas with html2canvas at native resolution
- POSTs the PNG data URL + text map to `altegena_save_share`, then shows a dialog: wa.me link, Web Share API image share (when supported), copy link, download image
- Message prefix comes from `altegena_config.share_message`

### Autosave
- Saves to `localStorage` with key `altegena_autosave_{productId}`
- Loads on init, restoring text values to both sidebar inputs and preview layers

## JavaScript: Altegena_AdminEditor (admin-editor.js)

Single IIFE-wrapped object, jQuery-based. Provides a visual design editor inside a standalone WordPress metabox on the product edit screen.

### Three-Panel Layout
```
┌─ Left (280px) ─┐  ┌─ Center (flex) ──────┐  ┌─ Right (300px) ─┐
│ Canvas Settings │  │   Live Canvas        │  │ Layer Properties │
│  - Width/Height │  │   Preview            │  │  - ID, Label     │
│  - BG Image     │  │   (scaled to fit)    │  │  - Group         │
│ Print (Baskı)   │  │                      │  │  - Default Text  │
│  - mm, BG, DPI  │  │  [draggable layers]  │  │  - Font/Size     │
│ Layer List      │  │                      │  │  - Color/Align   │
│  - Groups       │  │  [fullscreen toggle] │  │  - Position %    │
│  + Add Layer    │  │                      │  │  - Width %       │
└─────────────────┘  └──────────────────────┘  │  - Rotate        │
                                               │  [Duplicate/Del] │
                                               └─────────────────┘
```

### Tab Switching
- **Visual → JSON:** `syncVisualToJson()` serializes config to formatted JSON in textarea
- **JSON → Visual:** `syncJsonToVisual()` parses textarea, validates structure, rebuilds editor
- Validate button checks JSON syntax + required `canvas`/`layers` keys

### Canvas Scaling
- Same pattern as frontend: `ResizeObserver` + CSS `transform: scale()` + `transformOrigin: top left`
- Scale is not capped, so small canvases are enlarged for visibility
- `scaleFactor` stored for drag coordinate compensation
- **Fullscreen:** toggle button in the canvas area adds `.altegena-admin-fullscreen` and refits after the transition

### Print Settings (Baskı)
- **Fields:** `#altegena-print-width-mm` / `#altegena-print-height-mm` → `canvas.print_width_mm` / `print_height_mm` (0.1 mm rounding). "Oranı tuvale kilitle" (checked by default) derives the other dimension from the canvas px ratio.
- **Print background:** `openPrintBgPicker()` (wp.media) stores `canvas.print_bg_image`, `print_bg_image_id`, `print_bg_px {w,h}` (attachment size); remove button clears them.
- **Notes:** `updatePrintInfo()` (called from `updateCanvas()` and every print change) shows:
  - missing mm size (red, PDF can't be generated);
  - mm/canvas ratio mismatch >1% (red);
  - background DPI = px / (mm/25.4), using the print background or else the site `bg_image` (natural size loaded via `Image`): red <150, amber 150–299, green ≥300;
  - background/canvas ratio mismatch >1% (amber).
- `renderPrintFields()` runs from `renderCanvasSettings()`, so undo/redo and JSON edits refresh the fields.

### Layer Management
- **Add:** Creates layer with unique timestamp ID, default styles, selects it
- **Duplicate:** Deep clones selected layer, generates new ID, offsets top by 3%
- **Delete:** Removes all selected layers (confirmation shows the count when more than one), deselects, re-renders
- **Selection:** Click layer in list or on canvas; highlights both; shows properties panel
- **Multi-select:** Shift+click on layers (canvas or layer list) toggles them in/out of the selection. Regular click resets to single selection. `selectedLayerIds` array tracks all selected layers; `selectedLayerId` tracks the primary (last-clicked) layer for the properties panel.
- **Deselect:** Uses `mousedown` (not `click`) on the canvas background to avoid conflicts with layer mousedown/click event ordering.
- **Frontend visibility:** eye icon in the layer list / "Önyüzde Gizle" checkbox toggles `hidden_on_frontend` (fixed text the customer cannot edit)

### Layer List Sorting & Groups
- Single flat `jquery-ui-sortable` list; only layer items are draggable, group headers are fixed dividers. Array order = render order.
- Dropping a layer under a group header assigns that `group`; above all headers removes it.
- Group headers: collapse/expand, inline rename (updates every layer in the group), duplicate group (clones all its layers into `"<name> (kopya)"`)

### Drag & Drop
- Mousedown on canvas layer starts drag tracking
- **Multi-layer drag:** When dragging a layer that is part of a multi-selection, all selected layers move together by the same delta
- Mouse deltas compensated by `scaleFactor`: `dx / scaleFactor`
- Converts pixel position to percentage of canvas dimensions
- Reverse center-point conversion when storing back to config (`left - width/2`)
- Syncs property panel fields in real-time during drag (for the primary selected layer)

### Undo/Redo
- **Ctrl+Z:** Undo last change (works globally, no layer selection required)
- **Ctrl+Y / Ctrl+Shift+Z:** Redo
- Uses config snapshot history (`history[]` array of JSON strings, `historyIndex` pointer)
- `pushHistory()` is called from `syncConfigToHiddenField()`, which is the single chokepoint for all mutations
- Duplicate snapshots are skipped; history is capped at 50 entries (`maxHistory`)
- `_isRestoring` flag prevents `syncConfigToHiddenField` from pushing to history during undo/redo restore
- Initial state is pushed after `initializeConfig()` so it can always be reached via undo

### Keyboard Controls
- **Ctrl+Z:** Undo (see Undo/Redo section)
- **Ctrl+Y / Ctrl+Shift+Z:** Redo (see Undo/Redo section)
- **Arrow keys:** Move all selected layers 1px (10px with Shift)
- **Escape:** Deselect all layers
- **Delete:** Delete all selected layers (with confirmation)
- All keyboard handlers skip when focus is on input/textarea/select elements

### Text Rendering
- Layer text is rendered using `.html()` with escaped content and `<br>` tags for newlines (not `.text()`, which would strip line breaks)
- `escapeHtml()` sanitizes text first, then `\n` characters are converted to `<br>`

### Alignment & Distribution
- **Align to canvas** (1+ layers selected, `.altegena-admin-canvas-align-section`): left/center/right set `left` to `0` / `50 - width/2` / `100 - width`; top/center/bottom set `top` to `0%` / `50%` / `100%`
- **Align selection** (2+ layers selected, `.altegena-admin-align-section`): 8 buttons - Align Left, Center H, Right, Top, Center V, Bottom, Distribute H, Distribute V
- All operations work on stored config percentage values (`left`, `top`, `width`); layers have no stored height so vertical ops use `top` only
- Distribute requires 3+ layers; evenly spaces center-points (horizontal) or `top` values (vertical) between extremes
- Each alignment action calls `renderLayers()` + `refreshSelectionUI()` + `syncConfigToHiddenField()`, so undo/redo works automatically
- Methods: `bindAlignmentButtons()`, `getSelectedLayersBounds()`, `alignLayers(type)`, `alignLayersToCanvas(type)`

### Properties Panel
- **Text fields:** ID (alphanumeric + underscore/dash only), label, group, default_text, hidden on frontend
- **Style fields:**
  - fontFamily: dropdown with the 45 bundled fonts only. System fonts are not offered, because print has no fallback. A template font without a file is shown as "(dosyası yok)".
  - fontSize, textAlign (4 buttons), color (WordPress `wpColorPicker`), fontWeight, fontStyle, letterSpacing, lineHeight
- **Position fields:** left %, top %, width % (all use `step="any"` to accept any decimal value), rotate (degrees)
- Changes apply immediately to canvas DOM and config object
- Properties panel always shows the primary (last-clicked) selected layer

### Form Submission
- Hooks `#post` form submit event
- If the JSON tab is active, parses and applies the textarea first
- Calls `syncConfigToHiddenField()` → `JSON.stringify(config)` → hidden input value
- WordPress saves via standard `$_POST` processing

## JSON Configuration Format

Stored in `_invitation_json_config` post meta. Expected structure:

```json
{
  "canvas": {
    "width": 1200,
    "height": 1800,
    "bg_image": "https://example.com/wp-content/uploads/invitation-bg.jpg",
    "print_width_mm": 145,
    "print_height_mm": 217.5,
    "print_bg_image": "https://example.com/wp-content/uploads/invitation-bg-print.jpg",
    "print_bg_image_id": 987,
    "print_bg_px": { "w": 1713, "h": 2569 }
  },
  "layers": [
    {
      "id": "names",
      "type": "text",
      "label": "Isimler",
      "group": "Ön Yüz",
      "default_text": "Ad & Soyad",
      "hidden_on_frontend": false,
      "style": {
        "left": "10%",
        "top": "40%",
        "width": "80%",
        "rotate": "0",
        "fontFamily": "Darleston",
        "fontSize": "48px",
        "color": "#333333",
        "textAlign": "center"
      }
    }
  ]
}
```

### Fields
- **canvas.width/height** - Base dimensions in pixels (used for aspect ratio and scale calculations)
- **canvas.bg_image** - Absolute URL to background image
- **canvas.print_width_mm/print_height_mm** - Physical print size (optional; required for print PDFs; ratio must match the canvas within 1%)
- **canvas.print_bg_image / print_bg_image_id / print_bg_px** - Optional high-resolution print background (falls back to `bg_image`)
- **layers[].id** - Unique identifier, used as key in cart/order data
- **layers[].type** - Only `"text"` is implemented
- **layers[].label** - Human-readable name shown in sidebar and order meta
- **layers[].group** - Optional; admin layer-list grouping only (no frontend effect)
- **layers[].default_text** - Pre-filled text; supports `\n` for newlines
- **layers[].hidden_on_frontend** - Optional; fixed layer rendered on the card but without a customer input
- **layers[].style** - CSS properties applied directly to the layer div. Key properties:
  - `left`, `top` - Percentage positioning
  - `width` - Percentage width (converted to center-point positioning by JS)
  - `rotate` - Degrees; converted to a `rotate()` transform (not a CSS property)
  - `fontFamily` - Must match a name defined in `fonts.css` (no fallback in print)
  - Any valid CSS property (fontSize, color, textAlign, letterSpacing, lineHeight, etc.)

## Custom Fonts

48 font files (45 `.ttf` + 3 `.otf`) bundled in `assets/fonts/`, declared by 48 `@font-face` rules (45 families) in `fonts.css` with `font-display: swap`. Decorative/script faces suitable for wedding/event invitations.
- **Admin dropdown:** the authoritative family list is `Altegena_Product_Meta::get_available_fonts()`. Add a font there and in `fonts.css` together.
- **Print:** `Altegena_Print_Font_Registry` reads `fonts.css`, so it is the print font registry too.
- **CFF outlines:** Champignon, Christmas Wish Calligraphy and Marquette are CFF-outline OTFs, which need an offline TTF conversion for print.
- **Glyph gaps:** check Settings → Altegena Davetiye → Font denetimi for missing Turkish characters.

## WooCommerce Integration Points

| Hook | Class | Purpose |
|------|-------|---------|
| `add_meta_boxes` | Product_Meta | Register "Invitation Editor" metabox on product screen |
| `woocommerce_process_product_meta` | Product_Meta | Save JSON config (with validation, print key cleaning + wp_slash) |
| `admin_enqueue_scripts` | Product_Meta, Settings | Load admin editor assets / settings page color picker |
| `admin_menu`, `admin_init` | Settings | Register settings page and option |
| `admin_post_altegena_font_safe_fix` | Print_Font_Audit | Apply spelling-only template font fixes |
| `before_woocommerce_init` | Main file | Declare HPOS (custom order tables) compatibility |
| `woocommerce_single_variation` | Cart_Handler | Render trigger button + modal |
| `woocommerce_add_cart_item_data` | Cart_Handler | Capture (sanitized) design data into cart |
| `woocommerce_get_item_data` | Cart_Handler | Display design data at checkout |
| `woocommerce_checkout_create_order_line_item` | Cart_Handler (10), Print_Order_Handler (20) | Save design data / print snapshot to order item |
| `woocommerce_order_item_get_formatted_meta_data` | Cart_Handler | Hide per-layer rows per visibility setting |
| `wp_enqueue_scripts` | Main class, Share_Handler | Load CSS/JS on product pages / public share page |
| `wp_head` | Product_Page_Handler, Share_Handler | Hide default buttons / print OG tags |
| `init` | Share_Handler | Migration, CPT registration, rewrite flush |
| `wp_ajax(_nopriv)_altegena_save_share` | Share_Handler | Save a shared invitation |
| `template_include` | Share_Handler | Serve `templates/single-invitation.php` |

## Admin Visual Editor Usage

The primary way to design invitation layouts:
1. Go to **Products → Edit Product** in wp-admin
2. Scroll to the **"Invitation Editor"** metabox below the product data panel
3. The **Visual Editor** tab is active by default — set canvas dimensions, pick a background image
4. In **Baskı**, enter the print size in mm and optionally pick a high-resolution print background (check the DPI note)
5. Click **"+ Katman Ekle"** to add text layers
6. Drag layers on the canvas to position them, or use arrow keys for fine control (Shift for 10px steps)
7. **Shift+click** multiple layers to select them, then drag or arrow-key to move them together
8. Select a layer to edit its properties in the right panel (font, size, color, alignment, position)
9. Switch to the **JSON** tab to view/edit raw JSON, or validate it
10. Click **Update** to save the product — the config is stored as `_invitation_json_config` post meta

### Frontend Admin Mode (legacy)

Still available for quick position tweaks on the live product page:
1. Navigate to the product page as a logged-in admin
2. Append `?mode=admin` to the URL
3. Open the editor modal
4. Drag layers to desired positions
5. Click "Copy Updated JSON" in the sidebar
6. Paste the updated JSON into the product's "Invitation Editor" metabox in wp-admin

## Key Implementation Details

- **Block theme (FSE) compatibility:** The editor button hooks into `woocommerce_single_variation` rather than `woocommerce_single_product_summary` because FSE themes don't fire the latter.
- **Modal is moved to `document.body`** via JS to escape z-index stacking contexts from theme containers.
- **WordPress admin bar offset:** The modal CSS accounts for the admin bar (32px desktop, 46px mobile).
- **Variation awareness:** The "customize" button is disabled until a product variation is selected (listens for `found_variation`/`reset_data` jQuery events).
- **Cart page suppression:** Design data is intentionally hidden on the cart page (`is_cart()` check) but shown at checkout.
- **Print output:** Orders store text data plus `_altegena_print_snapshot`. The print-ready PDF engine (outlined + selectable text, pure PHP for shared hosting) is being built on top of the snapshot; the share PNG is a preview, not a print asset.

## Development Notes

- No npm/composer dependencies - pure WordPress + jQuery (vendored: html2canvas, plugin-update-checker)
- No REST API endpoints - uses WooCommerce's form-based `add-to-cart` POST and one admin-ajax action (`altegena_save_share`)
- Custom post type `altegena_invitation` for shares; everything else lives in post meta, order item meta and the `altegena_settings` option
- Settings page: **Settings → Altegena Davetiye** (`Altegena_Settings`, option `altegena_settings`) for visibility, colors, labels, share message, OG title/desc, default line-height; **Font denetimi** tab for fonts. Per-product design is still JSON post meta.
- JS strings are mostly hardcoded Turkish; PHP labels/messages are configurable via settings + `altegena_*` filters (see Theme Integration below). Full `__()` i18n not yet done.
- PHP code must stay compatible with PHP 7.4 (plugin header) through 8.4: no `match`, nullsafe operator, named arguments, union types or `str_contains`.
- **wp_slash gotcha (post meta):** `update_post_meta()` internally calls `wp_unslash()`, so if you've already unslashed `$_POST` data, you must wrap with `wp_slash()` before saving — otherwise backslash sequences like `\n` in JSON get stripped. This applies to any meta value containing JSON with escape sequences.
- **wp_slash gotcha (WC order item meta arrays):** `WC_Data_Store_WP::add_meta()` only slashes string values, but `add_metadata()` / `update_metadata_by_mid()` unslash arrays too. Always store meta arrays as `wp_slash($array)` (e.g. `_altegena_print_snapshot`), or backslashes inside customer text are lost. In-request reads after such a write return the slashed copy.
- **Updates / releases:** `plugin-update-checker` watches the GitHub repo (`setBranch('main')`: releases → tags → branch). Bump `Version:` in the plugin header, `ALTEGENA_VERSION` and `Stable tag` in readme.txt together, then tag a release (tag name = version, no `v`) so sites receive it.

## Theme Integration / Extension API

The plugin is theme-agnostic: it ships sensible defaults and works on any theme. A theme/site integrates through settings (**Settings → Altegena Davetiye**), the documented filters, and CSS custom properties — never by depending on the plugin's internals. `Altegena_Settings` (includes/class-settings.php) is the hub: `::text($key)` (label/message with built-in default + `altegena_$key` filter), `::visibility()`, `::css_vars()`, `::line_height()`, `::get()`.

### Filters
- `altegena_label_customize`, `altegena_label_add_to_cart`, `altegena_label_share`, `altegena_modal_title` — UI button/title text
- `altegena_share_message` — WhatsApp message prefix; `altegena_og_title`, `altegena_og_description` — share-page OG text
- `altegena_personalization_visibility` — `admin_only` (default) | `customer_and_admin` | `hidden`
- `altegena_colors` — assoc array of CSS variable → value (overrides settings)
- `altegena_default_line_height` — unitless default layer line-height (editor, share page, print)
- `altegena_seo_suppress_actions` — array of `['callback'=>fn, 'priority'=>n]`; on share pages the plugin `remove_action`s each from `wp_head` (theme opts in to prevent duplicate OG — replaces any hardcoded theme knowledge)

### CSS custom properties (overridable by theme/settings)
- **Fed from settings** via `wp_add_inline_style` on `:root`: `--altegena-accent`, `--altegena-accent-dark`, `--altegena-share`, `--altegena-share-dark`, `--altegena-line-height`.
- **Fallback-only:** `--altegena-danger`, `--altegena-stage-bg`.
- Used in editor.css / public-invitation.css / admin-editor.css as `var(--altegena-*, <default>)`.

### Personalization visibility
The plugin owns whether the per-layer text rows appear to the customer. `Altegena_Cart_Handler::get_item_data()` only adds cart/checkout rows when `customer_and_admin`; `Altegena_Cart_Handler::filter_order_item_meta()` (on `woocommerce_order_item_get_formatted_meta_data`) hides the per-layer rows on the frontend for `admin_only` (kept in wp-admin) or everywhere for `hidden`, matching labels against the `_altegena_design_data` blob. Themes should NOT read the plugin's cart/order keys to hide these rows.

### dm-omni theme note
The active `dm-omni` theme integrates via `altegena_seo_suppress_actions` (registers its `dm_seo_print_*` callbacks) and decorates the modal via stable `altegena-*` classes. It no longer contains the old hardcoded data-key hiding filters (the plugin handles visibility).
