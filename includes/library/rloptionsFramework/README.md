# RL Options Framework

**by [David Rosendo](https://github.com/drosendo) · a [RosendoLabs](https://rosendolabs.com) product**

A modern, standalone, **plugin-agnostic** options framework for WordPress plugins and themes. Built for developers who want a clean, powerful settings UI without bloat or dependencies on any specific plugin.

---

## ✨ Features

**Field Types (47 built-in)**
- Text & numbers: `text`, `textarea`, `password`, `number`, `slider`, `spinner`
- Choice: `select`, `multiselect`, `radio`, `checkbox`, `toggle`, `switch`, `button_set`
- Media: `image`, `image_select`, `media` (image/video/audio/file), `gallery`
- Styling (composite arrays): `color`, `color_palette`, `color_gradient`, `link_color`, `border`,
  `box_shadow`, `dimensions`, `spacing`, `typography`, `background`
- Collections: `multi_text`, `sortable`, `sorter`
- Date & time: `date`, `datetime`
- Geo: `country`, `state`, `city`, `country_state_city`
- Editors: `code` (CodeMirror), `editor` (TinyMCE)
- System: `html`, `raw`, `info`, `divider`, `icon_select`, `export`, `import`, `reset`, `reset_section`
- Aliases: `select_image`, `color_rgba`, `palette_color`, `content`, `ace_editor`, `select2`
- Custom field types via the field registry

Full parity map with Redux core fields: see
[`DOCUMENTATION.md` → Redux parity](./DOCUMENTATION.md#redux-parity).

**UI & Layout**
- Tabbed interface with icon support
- Collapsible accordion sections
- Sidebar navigation layout for complex pages
- Responsive design · Dashicons integration

**Advanced Logic**
- Nested `AND` / `OR` visibility conditions on fields, sections, and tabs
- Field validation (built-in + custom callbacks)
- Field sanitization per type
- AJAX save with a busy submit button and a single non-blocking success toast
- Import / Export settings as JSON (schema-validated)
- Backup & Restore
- Tab state persistence via `localStorage`
- Opt-in **RL Builder**: drag-and-drop schema designer with conditions/rules editors and PHP export

**Developer-Friendly**
- Zero plugin-specific coupling
- Type-safe PHP (typed properties & return types)
- Filter hooks for full extensibility
- `composer.json` included — installable via Composer

---

## 🧪 Live field demo

`demo/rl-options-fields-demo.php` is a standalone plugin that renders **every field type** with a
copy-ready PHP snippet under each field:

```bash
ln -s "$(pwd)/demo" /path/to/wp-content/plugins/rl-options-fields-demo
wp plugin activate rl-options-fields-demo
# Settings → Fields Demo
```

See [`demo/README.md`](./demo/README.md) for details.

## 🧱 Visual schema builder (optional)

Setting `'builder' => true` in the config adds a **RL Builder** screen under the same menu where you
can drag fields into tabs/sections, wire `conditions` and `required_if` rules per field, and export
the result as ready-to-paste PHP (`add_tab()`/`add_section()`/`add_field()` calls or a
`{option_name}_framework_tabs` filter array). Drafts are stored in `{option_name}_builder_draft`;
the builder never touches saved option values.

```php
$framework = new RL_Options_Framework([
    'option_name' => 'my_plugin_settings',
    'page_slug'   => 'my-plugin-settings',
    'builder'     => true, // Settings → RL Builder
]);
```

See [DOCUMENTATION.md § 24](./DOCUMENTATION.md#24-visual-schema-builder-rl-builder).

## 📦 Installation

### Option A – Copy & Require

```php
// In your plugin's main file:
require_once plugin_dir_path( __FILE__ ) . 'includes/library/rloptionsFramework/main.php';
```

### Option B – Composer

```bash
composer require rosendolabs/rl-options-framework
```

> [!IMPORTANT]
> All classes are global, so only one copy of a major line can load. From 2.6.0 the bundled copies
> coordinate: each one registers with `RL_Options_Framework_Loader` and the **newest** copy wins
> regardless of load order (resolved on `plugins_loaded`). Losing copies are reported through the
> `rl_options_framework_duplicate_copy` action. Composer is still the cleanest option when several
> plugins share the same version, since it guarantees a single build.

See [Bundling the library in more than one plugin](./DOCUMENTATION.md#bundling-the-library-in-more-than-one-plugin).

---

## 🚀 Quick Start

```php
add_action( 'plugins_loaded', function () {

    $framework = new RL_Options_Framework([
        'option_name'       => 'my_plugin_settings',
        'form_field_prefix' => 'my_plugin',
        'page_slug'         => 'my-plugin-settings',
        'menu_title'        => 'My Plugin',
        'page_title'        => 'My Plugin Settings',
        'capability'        => 'manage_options',
    ]);

    $framework->init();

    // Tab
    $framework->add_tab([
        'id'    => 'general',
        'label' => 'General',
    ]);

    // Section
    $framework->add_section([
        'tab_id' => 'general',
        'id'     => 'api',
        'title'  => 'API Settings',
    ]);

    // Fields
    $framework->add_field([
        'tab_id'     => 'general',
        'section_id' => 'api',
        'id'         => 'enable_api',
        'type'       => 'toggle',
        'label'      => 'Enable API',
        'default'    => false,
    ]);

    $framework->add_field([
        'tab_id'     => 'general',
        'section_id' => 'api',
        'id'         => 'api_key',
        'type'       => 'text',
        'label'      => 'API Key',
        'conditions' => [
            ['field' => 'enable_api', 'operator' => 'truthy'],
        ],
    ]);
});
```

### Retrieve a saved value

```php
$value = get_option('my_plugin_settings')['api_key'] ?? '';
// or
$value = $framework->get_option('api_key', '');
```

---

## 🧩 Field Types Reference

### `text`

```php
[
    'id'          => 'api_key',
    'type'        => 'text',
    'label'       => 'API Key',
    'description' => 'Your secret API key.',
    'placeholder' => 'sk-...',
    'default'     => '',
]
```

---

### `number`

```php
[
    'id'      => 'items_per_page',
    'type'    => 'number',
    'label'   => 'Items Per Page',
    'default' => 10,
    'min'     => 1,
    'max'     => 100,
    'step'    => 1,
]
```

An empty optional number is valid and is never a reason to reject the save; add `'required' => true`
to enforce a value.

---

### `textarea`

```php
[
    'id'      => 'custom_css',
    'type'    => 'textarea',
    'label'   => 'Custom CSS',
    'default' => '',
    'rows'    => 10,
]
```

---

### `toggle`

```php
[
    'id'      => 'enable_feature',
    'type'    => 'toggle',
    'label'   => 'Enable Feature',
    'default' => true,
]
```

---

### `checkbox`

```php
[
    'id'      => 'agree_terms',
    'type'    => 'checkbox',
    'label'   => 'Terms',
    'text'    => 'I agree to the terms and conditions.',
    'default' => false,
]
```

---

### `radio`

```php
[
    'id'      => 'layout',
    'type'    => 'radio',
    'label'   => 'Layout Style',
    'default' => 'grid',
    'options' => [
        'grid' => 'Grid',
        'list' => 'List',
    ],
]
```

---

### `select`

```php
[
    'id'      => 'environment',
    'type'    => 'select',
    'label'   => 'Environment',
    'default' => 'production',
    'options' => [
        'production'  => 'Production',
        'staging'     => 'Staging',
        'development' => 'Development',
    ],
]
```

---

### `multiselect`

```php
[
    'id'      => 'roles_allowed',
    'type'    => 'multiselect',
    'label'   => 'Allowed Roles',
    'default' => ['editor'],
    'options' => [
        'administrator' => 'Administrator',
        'editor'        => 'Editor',
        'author'        => 'Author',
        'subscriber'    => 'Subscriber',
    ],
]
```

---

### `color`

```php
[
    'id'      => 'primary_color',
    'type'    => 'color',
    'label'   => 'Primary Color',
    'default' => '#0073aa',
]
```

---

### `image`

Opens the WordPress Media Library picker. Saves the attachment URL.

```php
[
    'id'    => 'logo',
    'type'  => 'image',
    'label' => 'Site Logo',
]
```

---

### `image_select`

A visual radio-style picker using image thumbnails.

```php
[
    'id'      => 'theme_style',
    'type'    => 'image_select',
    'label'   => 'Theme Style',
    'default' => 'light',
    'options' => [
        'light' => 'Light',
        'dark'  => 'Dark',
    ],
]
```

---

### `date`

Renders a date picker (WP jQuery UI Datepicker). Saves as `Y-m-d`.

```php
[
    'id'      => 'start_date',
    'type'    => 'date',
    'label'   => 'Start Date',
    'default' => '',
]
```

---

### `datetime`

Date + time picker. Saves as `Y-m-d H:i`.

```php
[
    'id'      => 'launch_at',
    'type'    => 'datetime',
    'label'   => 'Launch Date & Time',
    'default' => '2026-01-01 09:00',
]
```

---

### `country`

Renders a country dropdown populated via a `geo_options_callback`. Cascades with `state` and `city`.

```php
[
    'id'          => 'billing_country',
    'type'        => 'country',
    'label'       => 'Country',
    'placeholder' => '— Select Country —',
]
```

---

### `state`

Cascades from a `country` field. Requires `country_field`.

```php
[
    'id'            => 'billing_state',
    'type'          => 'state',
    'label'         => 'State / Province',
    'country_field' => 'billing_country',
    'depends_on'    => ['billing_country'],
]
```

---

### `city`

Cascades from `country` + `state`. Requires `country_field` and `subdivision_field`.

```php
[
    'id'               => 'billing_city',
    'type'             => 'city',
    'label'            => 'City',
    'country_field'    => 'billing_country',
    'subdivision_field'=> 'billing_state',
    'depends_on'       => ['billing_country', 'billing_state'],
]
```

---

### `html`

Renders arbitrary HTML. Sanitized with `wp_kses_post()`.

```php
[
    'id'   => 'help_box',
    'type' => 'html',
    'html' => '<div class="notice notice-info"><p>This is a help notice.</p></div>',
]
```

---

### `info`

Renders the field's `description` as a styled info block (no input).

```php
[
    'id'          => 'upgrade_notice',
    'type'        => 'info',
    'label'       => 'Pro Feature',
    'description' => 'Upgrade to Pro to unlock this feature.',
]
```

---

### `export`

Renders a button to download the current options as a JSON file.

```php
[
    'id'           => 'export_settings',
    'type'         => 'export',
    'button_label' => 'Download Backup',
    'description'  => 'Export your settings.',
]
```

---

### `import`

Renders a file upload input to import a JSON backup. Automatically intercepts the form submission to apply the settings.

```php
[
    'id'             => 'import_settings',
    'type'           => 'import',
    'status_message' => 'File ready to import. Save changes to apply.',
    'description'    => 'Import your settings backup.',
]
```

---

### `reset`

Renders a reset button that deletes all settings and restores defaults, with a built-in browser confirmation prompt.

```php
[
    'id'              => 'reset_settings',
    'type'            => 'reset',
    'button_label'    => 'Reset to Defaults',
    'confirm_message' => 'Are you sure?',
    'description'     => 'This will wipe all data.',
]
```

---

## 👁 Visibility Conditions

Show or hide any field, section, or tab based on another field's value.

### Simple (AND — all rules must pass)

```php
'conditions' => [
    ['field' => 'enable_api', 'operator' => 'truthy'],
    ['field' => 'mode',       'operator' => 'equals', 'value' => 'advanced'],
]
```

### Nested OR / AND

```php
'conditions' => [
    'relation' => 'OR',
    ['field' => 'mode', 'operator' => 'equals', 'value' => 'custom'],
    [
        'relation' => 'AND',
        ['field' => 'mode',     'operator' => 'equals', 'value' => 'auto'],
        ['field' => 'override', 'operator' => 'equals', 'value' => '1'],
    ],
]
```

### Supported Operators

| Operator | Meaning |
|----------|---------|
| `equals` / `==` | Exactly equal |
| `not_equals` / `!=` | Not equal |
| `in` | Value is in array |
| `not_in` | Value is not in array |
| `>` / `greater_than` | Greater than |
| `>=` | Greater than or equal |
| `<` / `less_than` | Less than |
| `<=` | Less than or equal |
| `truthy` | Value is truthy / not empty |
| `falsy` | Value is falsy / empty |

> [!TIP]
> Toggle fields save as `"1"` (on) or `""` (off). Use `truthy` / `falsy` for the cleanest evaluation.

---

## ✅ Validation

### Built-in

```php
[
    'id'       => 'port',
    'type'     => 'number',
    'required' => true,
    'min'      => 1024,
    'max'      => 65535,
]
```

### Custom callback

```php
[
    'id'                => 'email',
    'type'              => 'text',
    'validate_callback' => function( $value, $field ) {
        return is_email( $value ) ? true : new WP_Error( 'invalid', 'Enter a valid email.' );
    },
]
```

### Conditional required (`required_if`)

```php
[
    'id'          => 'vat_number',
    'type'        => 'text',
    'label'       => 'VAT Number',
    'required_if' => [
        ['field' => 'business_type', 'operator' => 'equals', 'value' => 'company'],
    ],
]
```

---

## 🔧 Configuration Reference

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `option_name` | `string` | `'rl_framework_settings'` | wp_options key |
| `form_field_prefix` | `string` | `'rl_options'` | HTML form field prefix |
| `page_slug` | `string` | `'rl-options-settings'` | Admin menu slug |
| `menu_title` | `string` | `'Plugin Settings'` | Sidebar menu text |
| `page_title` | `string` | `'Plugin Settings'` | `<h1>` heading |
| `capability` | `string` | `'manage_options'` | Required capability |
| `parent_menu` | `string` | `'options-general.php'` | Parent menu page |
| `text_domain` | `string` | `'rl-options-framework'` | i18n text domain |
| `ajax_action` | `string` | `rl_of_{option_name}_save` | AJAX save action; the default is scoped per host, and an explicit value is kept (suffixed if another instance already claimed it) |
| `assets_url` | `string` | Auto-detected | URL to the `assets/` folder |
| `plugin_url` | `string` | `''` | Optional host plugin URL (informational) |
| `version` | `string` | `RL_Options_Framework::VERSION` | For cache-busting enqueued assets |
| `asset_handle_prefix` | `string` | `rl_of_{option_name}` | Prefix for shared vendor handles (`-sweetalert2`, `-tippy`, `-popper`); override it only if you need a specific name |
| `context` | `string` | `'auto'` | Asset resolution: `auto`, `plugin` (needs `init($plugin)` with `get_plugin_url()`), `theme` |
| `register_menu` | `bool` | `true` | Register the admin submenu automatically |
| `sync_history` | `bool` | `false` | Keep the active tab in the URL |
| `debug_level` | `string` | `'error'` | `error`, `warn`, `info`, `debug` (PHP + JS logging) |
| `swal_fallback` | `bool` | `true` | Use `window.alert` when SweetAlert2 is unavailable |
| `debug_field_id` | `string` | `'enable_debug'` | Saved-option key that enables verbose debug |
| `use_local_assets_toggle` | `bool` | `true` | Add a Support-tab toggle for vendor asset delivery (assets always ship locally) |
| `local_assets_field_id` | `string` | `'rloptions_local_assets'` | Saved-option key for that toggle |
| `color_palette` | `array` | `[]` | Global default palette for `color` / `color_palette` fields |

Full hook and API reference: [`DOCUMENTATION.md`](./DOCUMENTATION.md).

---

## 🪝 Filter Hooks

```php
// Register tabs/fields
add_filter( 'my_plugin_settings_framework_tabs', function( $tabs, $framework ) {
    // Modify $tabs and return
    return $tabs;
}, 10, 2 );

// Run logic after the framework boots
add_action( 'my_plugin_settings_framework_boot', function( $framework ) {
    // e.g. register custom field types
}, 10 );
```

---

## 💾 Import / Export / Backup

```php
$json   = $framework->export_settings();           // Export as JSON string
$result = $framework->import_settings( $json );    // Validates before saving
$backup = $framework->create_backup();             // Snapshot current state
        $framework->restore_backup();              // Roll back to last backup
        $framework->reset_to_defaults();           // Wipe and restore defaults
```

Imported JSON is validated against the registered field schema — unknown keys are silently discarded.

---

## 📝 Universal Logging

The framework provides a unified logging utility for both PHP and JS. You can configure the prefix so all logs reflect your plugin name.

### PHP Logging

```php
RL_Logger::set_prefix( '[My Plugin]' );
RL_Logger::info( 'This is an info log.' );
```

### JS Logging

The JS logger is a standalone script that is automatically enqueued globally when debugging is enabled. Add a debug toggle to your settings:

```php
$framework = new RL_Options_Framework([
    'debug_field_id' => 'enable_debug',
]);
// Add 'enable_debug' as a toggle field...
```

Then use the global logger anywhere:

```javascript
window.rlFramework.setLogPrefix( 'My Plugin' );
window.rlFramework.log( 'This is a debug log.' );
```

---

## 📋 Requirements

- WordPress 5.0+
- PHP 7.4+
- jQuery (bundled with WordPress)
- jQuery UI Datepicker (bundled with WordPress)
- WP Color Picker (bundled with WordPress)
- SweetAlert2 (local vendor or CDN fallback)
- Tippy.js + Popper.js (local vendor or CDN fallback)

---

## 📄 License

GPL-2.0-or-later — compatible with the WordPress ecosystem.

---

## 👤 Credits

Developed by **[David Rosendo](https://github.com/drosendo)** · a **[RosendoLabs](https://rosendolabs.com)** product.

---

## 📦 Changelog

### 2.6.0

Several bundled copies can now coexist without stepping on each other.

- **The newest copy wins regardless of load order.** Copies register with
  `RL_Options_Framework_Loader` at include time and the winner is resolved once on `plugins_loaded`
  (priority `-1000`); losing copies are reported through `rl_options_framework_duplicate_copy`
- **Major lines coexist.** Copies compete only inside their family, so a future major can ship next to
  the 2.x line instead of replacing it
- **No cross-host collisions.** Save action and nonce (`rl_of_{option_name}_save`), asset handles
  (`rl_of_{option_name}-framework` / `-rl-logger` / `-builder`), geo cache keys
  (`rl_of_{option_name}_…`) and REST routes (`rl-options-{option_name}/v1`) are scoped per host; the
  legacy `rl-options/v1` namespace and the shared AJAX endpoints keep answering for compatibility
- No host code change is required; see the migration notes in [CHANGELOG.md](./CHANGELOG.md) if you
  hardcoded the old action, handle or route names

### 2.5.2

Hardening and internal cleanup — one behaviour change to note.

- **Geo REST routes now require the options-page capability** (`/wp-json/rl-options/v1/*` was public).
  Opt out with `rl_options_framework_rest_reference_permission` if you serve that data elsewhere
- `register_field_renderer()` works before `init()` — the registry is created on first use and reused
  on boot, so custom fields registered on `plugins_loaded` are no longer dropped
- One shared save pipeline for form and AJAX saves; a capability failure on the AJAX save now returns
  403 instead of 200
- Import JSON is decoded with a nesting limit; reset flags are sanitized before comparison
- Media and gallery previews are built from DOM attributes instead of string concatenation
- Dead code removed (unused locals, orphaned docblock) and the empty-tabs notice markup fixed

### 2.5.1

Framework-level fixes for hosts that bundle this library — no API changes.

- A blank optional `number` / `slider` field no longer aborts the whole save; empty values are left to
  `required` / `required_if` instead of failing the numeric check
- A field renderer that prints its own `<form>` can no longer close the settings form and orphan the
  Save button — the tags are stripped and the field is named in a log warning
- Vendor handles (`sweetalert2` / `popper` / `tippy`) are prefixed per instance (default `page_slug`,
  override with `asset_handle_prefix`) so two plugins bundling the library cannot collide
- A second bundled copy now reports the conflict (path + version) via `RL_OPTIONS_FRAMEWORK_VERSION`,
  `RL_OPTIONS_FRAMEWORK_FILE` and the `rl_options_framework_duplicate_copy` action

### 2.5.0

Live RL Builder: the canvas renders the real field controls while you build.

- WYSIWYG canvas — every row is rendered by the settings-page renderer
  (`RL_Options_Render_Service::render_single_field()`) through a new preview endpoint, cached per
  definition and debounced
- **Preview page** mode hides the palette/inspector and shows the schema full width exactly like the
  settings page, with interactive controls
- Undo/redo (60 snapshots), dirty indicator, duplicate (`Ctrl/⌘+D`), delete (`Delete`), collapsible
  tabs/sections, palette filter and an inline searchable **+ Add field** picker
- Conditional fields are dimmed with an explanation; `raw`/callback fields show *Rendered by PHP*
- Composite fields (`border`, `box_shadow`, `dimensions`, `spacing`, `typography`, `background`,
  `link_color`, `color_gradient`) use the block-editor look: uppercase labels, hairline rows, 40px
  controls, unit shown inside the number box, opt-in range sliders and a link-sides toggle

### 2.4.0

- 24 new field types (47 total): `button_set`, `switch`, `slider`, `spinner`, `password`,
  `divider`, `raw`, `code`, `editor`, `icon_select`, `media`, `gallery`, `multi_text`,
  `sortable`, `sorter`, `border`, `box_shadow`, `dimensions`, `spacing`, `link_color`,
  `background`, `typography`, `color_gradient` (+ `info` listed)
- Aliases for Redux naming: `select_image`, `color_rgba`, `palette_color`, `content`,
  `ace_editor`, `select2`
- CodeMirror is enqueued only when a `code` field is present; TinyMCE content is synced on save
- Fixed integer steps storing floats and composite fields being rejected by the array guard

### 2.3.0

Drop-in hardening. Host plugins need no migration.

- Legacy `show_if` is accepted again for tabs, sections and fields (normalized into `conditions`)
- Fixed canonical `['relation' => 'OR', 'rules' => [...]]` normalization (OR groups were flattened to AND)
- Type-tolerant condition comparisons (`'3'` matches `3`, `'1'` matches `true`)
- Array payloads (`field[]=x`) return a validation error instead of a `TypeError`/HTTP 500
- Added the documented `get_country_subdivisions()` / `get_country_municipalities()` helpers
- Added `get_storage_service()` (import/reset handlers previously referenced an undefined method)
- Display-only field types are no longer validated or persisted
- Instance-scoped AJAX endpoints so multiple frameworks can coexist
- Namespaced vendor handles, WAF-safe assets URL, no hardcoded host strings
- Dependency-free test suite: `php tests/run.php`

### 2.2.0
- Added nested `AND` / `OR` condition groups for fields, sections and tabs
- Legacy visibility aliases (`show_if`) were deprecated in favour of `conditions`; see 2.3.0 for the current compatibility guarantee
- Renamed plugin-specific CSS classes to generic `rl-options-*` prefix
- Added GPL-2.0 `LICENSE` file and `example-plugin.php` quick-start

### 2.1.1
- Hardened `html` fields to sanitize rendered markup with a configurable allowlist
- Hardened JSON imports to validate and sanitize recognized fields before saving
- Removed nonce value logging from AJAX save debug logs
- Restricted remote geo reference fetches to HTTPS via `wp_safe_remote_get()`
- Added logger context redaction for sensitive keys (nonce/token/password/secret)

### 2.1.0
- Added `datetime` field type with WordPress datepicker calendar + time input
- Added server-side datetime validation and sanitization (`Y-m-d H:i`)

### 2.0.0
- Complete refactor to be plugin-agnostic
- Dynamic configuration via constructor
- Conditional tab/section/field visibility
- Type-safe PHP code

### 1.0.0
- Initial release
