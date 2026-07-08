# Altegena Invitation Editor - Plugin Documentation

## Overview

A lightweight, DOM-based invitation card editor for WooCommerce. Customers can customize text layers on invitation card templates directly on the product page before adding to cart. The customized text data flows through the WooCommerce cart and order system.

**Author:** Kayahan
**Version:** 1.0.0
**Text Domain:** `altegena-invitation-editor`
**Constants prefix:** `ALTEGENA_`
**UI Language:** Turkish (button labels, error messages)

## Architecture

### Design Philosophy
- **No canvas/image rendering** - Uses pure DOM elements (divs with `contenteditable`) positioned absolutely over a background image via CSS. This keeps it lightweight but means the "design" only exists as data, not as a rendered image.
- **JSON-driven configuration** - Each invitation product stores its entire design layout as a JSON blob in post meta. The JSON defines the canvas size, background image, and text layers with their positions/styles.
- **Singleton pattern** - All PHP classes use `get_instance()` singletons.
- **No build step** - Plain jQuery-based JS, no bundler/transpiler.

### Data Flow
```
Admin creates JSON config --> Saved as `_invitation_json_config` post meta
                                        |
Customer visits product page --> JS parses JSON --> Renders editor UI
                                        |
Customer edits text layers --> Data stored in hidden input `#altegena-custom-data`
                                        |
Add to cart (AJAX) --> `altegena_custom_data` POST param --> Saved as `altegena_design_data` in cart item
                                        |
Checkout --> Saved as `_altegena_design_data` order item meta + individual layer labels as separate meta
```

## File Structure

```
altegena-invitation-editor/
├── altegena-invitation-editor.php      # Main plugin file, bootstrap class
├── includes/
│   ├── class-product-meta.php        # WordPress metabox: visual editor + JSON config for product editor
│   ├── class-cart-handler.php        # Cart/order integration + editor modal HTML + trigger button
│   └── class-product-page-handler.php # Hides default add-to-cart & quantity for invitation products
├── assets/
│   ├── js/
│   │   ├── editor.js                 # Frontend editor logic (Altegena_Editor object)
│   │   └── admin-editor.js           # Admin visual editor logic (Altegena_AdminEditor object)
│   ├── css/
│   │   ├── editor.css                # Full-screen modal layout, sidebar, canvas, layers
│   │   ├── admin-editor.css          # Admin visual editor styles (three-panel layout, properties)
│   │   └── fonts.css                 # @font-face declarations for 37 custom fonts
│   ├── fonts/                        # 38 .ttf font files + .htaccess
│   └── backgrounds/                  # Invitation background images (referenced by JSON config)
└── CLAUDE.md                         # This file
```

## PHP Classes

### `Altegena_Invitation_Editor` (altegena-invitation-editor.php)
- **Role:** Plugin bootstrap. Defines constants, includes files, initializes all handler classes.
- **Constants:** `ALTEGENA_PLUGIN_DIR`, `ALTEGENA_PLUGIN_URL`, `ALTEGENA_VERSION`
- **Enqueue logic:** Only loads assets on single product pages (`is_product()`) that have `_invitation_json_config` meta set.
- **Script dependencies:** `jquery`, `wc-add-to-cart`
- **Localized data (`altegena_config`):**
  - `raw_config` - The raw JSON string from post meta
  - `is_admin_mode` - Boolean, true when user has `manage_options` cap AND `?mode=admin` query param is present

### `Altegena_Product_Meta` (class-product-meta.php)
- **Role:** Registers a standalone WordPress metabox ("Invitation Editor") on the product edit screen with a full visual editor.
- **Metabox:** Registered via `add_meta_boxes` hook, rendered by `render_meta_box()`. Context: `normal`, priority: `high`.
- **Meta key:** `_invitation_json_config` (stored via hidden input, synced from visual editor or JSON textarea)
- **Admin asset enqueue:** Hooks `admin_enqueue_scripts`, guarded to `post.php`/`post-new.php` on `product` post type. Loads `wp.media`, `wp-color-picker`, fonts CSS, `admin-editor.css`, `admin-editor.js`.
- **Localized data (`altegena_admin_config`):** `fonts` (array of 34 font family names), `plugin_url`
- **`get_available_fonts()`** - Returns all custom font family names matching `fonts.css` declarations.
- **Save validation:** `save_product_data_tab()` validates JSON structure (must have `canvas` and `layers` keys) before saving. Uses `wp_slash()` before `update_post_meta()` to prevent double-unslashing of backslash sequences (e.g., `\n` in JSON). Empty values delete the meta.
- **Metabox HTML:** Two tabs (Visual Editor / JSON). Visual tab contains a three-panel layout: left (canvas settings + layer list), center (live canvas preview), right (layer properties). JSON tab contains a raw textarea with validate button.

### `Altegena_Cart_Handler` (class-cart-handler.php)
- **Role:** The largest class. Handles:
  1. **Editor trigger button** - Renders "Davetiyeyi Sana Ozel Yap" button, disabled until a variation is selected
  2. **Modal HTML** - Full-screen modal with header (title, add-to-cart button, close button) and `#altegena-editor-app` container
  3. **Inline JS** - Modal open/close logic, variation-aware button enable/disable
  4. **Cart item data** - Captures `altegena_custom_data` from POST, decodes JSON, stores as `altegena_design_data`
  5. **Cart display** - Shows customized text per layer at checkout (hidden on cart page)
  6. **Order meta** - Stores `_altegena_design_data` blob + individual layer labels as separate order item meta
- **Hook:** Uses `woocommerce_single_variation` (priority 15) instead of `woocommerce_single_product_summary` for FSE/block theme compatibility.
- **Modal z-index:** `2147483647` (max int) - moved to `document.body` via JS to avoid stacking context issues.

### `Altegena_Product_Page_Handler` (class-product-page-handler.php)
- **Role:** Injects CSS via `wp_head` to hide the default WooCommerce add-to-cart button and quantity input for invitation products. Uses CSS `display: none !important` rather than removing template hooks, so the variation form stays functional.

## JavaScript: Altegena_Editor (editor.js)

Single IIFE-wrapped object, jQuery-based. Key behaviors:

### Initialization
1. Parses `altegena_config.raw_config` JSON
2. Builds layout: sidebar (left, 360px) + preview area (right, flexible)
3. Renders text layers on canvas and input fields in sidebar
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
- `width` is deleted after conversion (layers are auto-width)

### Two-Way Binding
- Sidebar textarea input updates the preview layer text
- Preview `contenteditable` div input updates the sidebar textarea
- Focus on either highlights the counterpart (gold border/background)

### Admin Mode (`?mode=admin`)
- Activated when: user has `manage_options` AND URL has `?mode=admin`
- Layers become **draggable** (not contenteditable)
- Drag updates layer position in the config object
- "Copy Updated JSON" button appears in sidebar
- Updated JSON is logged to console on every drag

### Add to Cart
- Custom AJAX call to WooCommerce `add_to_cart` endpoint
- Sends `product_id`, `quantity: 1`, and `altegena_custom_data` (JSON string)
- On success: redirects to cart page
- On error: Turkish alert message

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
│  - BG Image     │  │   (scaled to fit)    │  │  - Default Text  │
│ Layer List      │  │                      │  │  - Font/Size     │
│  - Layer items  │  │  [draggable layers]  │  │  - Color/Align   │
│  + Add Layer    │  │                      │  │  - Position %    │
└─────────────────┘  └──────────────────────┘  │  - Width %       │
                                               │  [Duplicate/Del] │
                                               └─────────────────┘
```

### Tab Switching
- **Visual → JSON:** `syncVisualToJson()` serializes config to formatted JSON in textarea
- **JSON → Visual:** `syncJsonToVisual()` parses textarea, validates structure, rebuilds editor
- Validate button checks JSON syntax + required `canvas`/`layers` keys

### Canvas Scaling
- Same pattern as frontend: `ResizeObserver` + CSS `transform: scale()` + `transformOrigin: top left`
- Scale capped at `1` (never enlarges beyond natural size)
- `scaleFactor` stored for drag coordinate compensation

### Layer Management
- **Add:** Creates layer with unique timestamp ID, default styles, selects it
- **Duplicate:** Deep clones selected layer, generates new ID, offsets top by 3%
- **Delete:** Splices from config, deselects, re-renders (with confirmation prompt)
- **Selection:** Click layer in list or on canvas; highlights both; shows properties panel
- **Multi-select:** Shift+click on layers (canvas or layer list) toggles them in/out of the selection. Regular click resets to single selection. `selectedLayerIds` array tracks all selected layers; `selectedLayerId` tracks the primary (last-clicked) layer for the properties panel.
- **Deselect:** Uses `mousedown` (not `click`) on the canvas background to avoid conflicts with layer mousedown/click event ordering.

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
- **Delete:** Delete selected layer (with confirmation)
- All keyboard handlers skip when focus is on input/textarea/select elements

### Text Rendering
- Layer text is rendered using `.html()` with escaped content and `<br>` tags for newlines (not `.text()`, which would strip line breaks)
- `escapeHtml()` sanitizes text first, then `\n` characters are converted to `<br>`

### Alignment & Distribution
- Appears at the top of the right panel when 2+ layers are selected (`.altegena-admin-align-section`, hidden by default)
- **8 buttons** in a compact grid: Align Left, Center H, Right, Top, Center V, Bottom, Distribute H, Distribute V
- All operations work on stored config percentage values (`left`, `top`, `width`); layers have no stored height so vertical ops use `top` only
- Distribute requires 3+ layers; evenly spaces center-points (horizontal) or `top` values (vertical) between extremes
- Each alignment action calls `renderLayers()` + `refreshSelectionUI()` + `syncConfigToHiddenField()`, so undo/redo works automatically
- Methods: `bindAlignmentButtons()`, `getSelectedLayersBounds()`, `alignLayers(type)`

### Properties Panel
- **Text fields:** ID (alphanumeric + underscore/dash only), label, default_text
- **Style fields:** fontFamily (dropdown with 5 system + 34 custom fonts), fontSize, textAlign, color (WordPress `wpColorPicker`), fontWeight, fontStyle, letterSpacing, lineHeight
- **Position fields:** left %, top %, width % (all use `step="any"` to accept any decimal value)
- Changes apply immediately to canvas DOM and config object
- Properties panel always shows the primary (last-clicked) selected layer

### Form Submission
- Hooks `#post` form submit event
- Calls `syncConfigToHiddenField()` → `JSON.stringify(config)` → hidden input value
- WordPress saves via standard `$_POST` processing

## JSON Configuration Format

Stored in `_invitation_json_config` post meta. Expected structure:

```json
{
  "canvas": {
    "width": 1200,
    "height": 1800,
    "bg_image": "https://example.com/wp-content/uploads/invitation-bg.jpg"
  },
  "layers": [
    {
      "id": "names",
      "type": "text",
      "label": "Isimler",
      "default_text": "Ad & Soyad",
      "style": {
        "left": "10%",
        "top": "40%",
        "width": "80%",
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
- **layers[].id** - Unique identifier, used as key in cart/order data
- **layers[].type** - Only `"text"` is implemented
- **layers[].label** - Human-readable name shown in sidebar and order meta
- **layers[].default_text** - Pre-filled text; supports `\n` for newlines
- **layers[].style** - CSS properties applied directly to the layer div. Key properties:
  - `left`, `top` - Percentage positioning
  - `width` - Percentage width (converted to center-point positioning by JS)
  - `fontFamily` - Must match a name defined in `fonts.css`
  - Any valid CSS property (fontSize, color, textAlign, letterSpacing, etc.)

## Custom Fonts

38 TTF font files bundled in `assets/fonts/`. All loaded via `@font-face` in `fonts.css` with `font-display: swap`. Fonts include decorative/script faces suitable for wedding/event invitations:

Aire Light Pro, Amostely Signature, Andellia Davilton, Angers Script, Anthem Of The Angels, Avant Garde, Blacksword, Breakfast And Chill, CAC Champagne, Candlescript, Darleston, Fenice (Regular + Bold), Flemish Script, Fragrance, Kastangel, Madina, Mokka, Mussica Swash, Neutraface Condensed (Light + Regular), Nexa Light, Optimus Princeps (Regular + SemiBold), Perpetua (Bold Italic), Riesling, Rosellinda Alyamore, Silk Script, Silmastin, Sweety Lovers, Trajan Pro (Bold), University Roman (Bold), Vollkorn SC (Regular + Bold + Bold Italic), Yaquote Script, Zalitta

## WooCommerce Integration Points

| Hook | Class | Purpose |
|------|-------|---------|
| `add_meta_boxes` | Product_Meta | Register "Invitation Editor" metabox on product screen |
| `woocommerce_process_product_meta` | Product_Meta | Save JSON config (with validation + wp_slash) |
| `admin_enqueue_scripts` | Product_Meta | Load admin editor CSS/JS, wp.media, color picker, fonts |
| `woocommerce_single_variation` | Cart_Handler | Render trigger button + modal |
| `woocommerce_add_cart_item_data` | Cart_Handler | Capture design data into cart |
| `woocommerce_get_item_data` | Cart_Handler | Display design data at checkout |
| `woocommerce_checkout_create_order_line_item` | Cart_Handler | Save design data to order |
| `wp_enqueue_scripts` | Main class | Load CSS/JS on product pages |
| `wp_head` | Product_Page_Handler | Inject CSS to hide default buttons |

## Admin Visual Editor Usage

The primary way to design invitation layouts:
1. Go to **Products → Edit Product** in wp-admin
2. Scroll to the **"Invitation Editor"** metabox below the product data panel
3. The **Visual Editor** tab is active by default — set canvas dimensions, pick a background image
4. Click **"+ Katman Ekle"** to add text layers
5. Drag layers on the canvas to position them, or use arrow keys for fine control (Shift for 10px steps)
6. **Shift+click** multiple layers to select them, then drag or arrow-key to move them together
7. Select a layer to edit its properties in the right panel (font, size, color, alignment, position)
8. Switch to the **JSON** tab to view/edit raw JSON, or validate it
9. Click **Update** to save the product — the config is stored as `_invitation_json_config` post meta

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
- **No image generation:** The plugin stores text data only. If a rendered image (e.g., PDF) is needed, that must be handled separately (server-side rendering from the stored data).

## Development Notes

- No npm/composer dependencies - pure WordPress + jQuery
- No REST API endpoints - uses WooCommerce's built-in AJAX `add_to_cart`
- No database tables - all data stored in post meta and order item meta
- Settings page: **Settings → Altegena Davetiye** (`Altegena_Settings`, option `altegena_settings`) for visibility, colors, labels, share message, OG title/desc. Per-product design is still JSON post meta.
- JS strings are hardcoded Turkish; PHP labels/messages are configurable via settings + `altegena_*` filters (see Theme Integration below). Full `__()` i18n not yet done.
- **wp_slash gotcha:** `update_post_meta()` internally calls `wp_unslash()`, so if you've already unslashed `$_POST` data, you must wrap with `wp_slash()` before saving — otherwise backslash sequences like `\n` in JSON get stripped. This applies to any meta value containing JSON with escape sequences.

## Theme Integration / Extension API

The plugin is theme-agnostic: it ships sensible defaults and works on any theme. A theme/site integrates through settings (**Settings → Altegena Davetiye**), the documented filters, and CSS custom properties — never by depending on the plugin's internals. `Altegena_Settings` (includes/class-settings.php) is the hub: `::text($key)` (label/message with built-in default + `altegena_$key` filter), `::visibility()`, `::css_vars()`, `::get()`.

### Filters
- `altegena_label_customize`, `altegena_label_add_to_cart`, `altegena_label_share`, `altegena_modal_title` — UI button/title text
- `altegena_share_message` — WhatsApp message prefix; `altegena_og_title`, `altegena_og_description` — share-page OG text
- `altegena_personalization_visibility` — `admin_only` (default) | `customer_and_admin` | `hidden`
- `altegena_colors` — assoc array of CSS variable → value (overrides settings)
- `altegena_seo_suppress_actions` — array of `['callback'=>fn, 'priority'=>n]`; on share pages the plugin `remove_action`s each from `wp_head` (theme opts in to prevent duplicate OG — replaces any hardcoded theme knowledge)

### CSS custom properties (overridable by theme/settings)
`--altegena-accent`, `--altegena-accent-dark`, `--altegena-share`, `--altegena-share-dark` (fed from settings via `wp_add_inline_style` on `:root`); plus fallback-only `--altegena-danger`, `--altegena-stage-bg`. Used in editor.css / public-invitation.css as `var(--altegena-*, <default>)`.

### Personalization visibility
The plugin owns whether the per-layer text rows appear to the customer. `get_item_data()` only adds cart/checkout rows when `customer_and_admin`; `filter_order_item_meta()` (on `woocommerce_order_item_get_formatted_meta_data`) hides the per-layer rows on the frontend for `admin_only` (kept in wp-admin) or everywhere for `hidden`, matching labels against the `_altegena_design_data` blob. Themes should NOT read the plugin's cart/order keys to hide these rows.

### dm-omni theme note
The active `dm-omni` theme integrates via `altegena_seo_suppress_actions` (registers its `dm_seo_print_*` callbacks) and decorates the modal via stable `altegena-*` classes. It no longer contains the old hardcoded data-key hiding filters (the plugin handles visibility).
