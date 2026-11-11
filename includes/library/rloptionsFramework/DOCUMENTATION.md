# RL Options Framework Documentation

> Portable WordPress options framework for plugins/themes with validation, async providers, and reusable geo metadata APIs.

---

## Table of Contents

1. [Installation](#1-installation)
2. [Canonical Hooks](#2-canonical-hooks)
3. [Configuration](#3-configuration)
4. [Field Types](#4-field-types)
5. [Declarative Dependencies and Async Providers](#5-declarative-dependencies-and-async-providers)
6. [Framework Preset and Bundle Tools](#6-framework-preset-and-bundle-tools)
7. [Typed Field Aliases](#7-typed-field-aliases)
8. [Provider Shortcut and Schema Defaults](#8-provider-shortcut-and-schema-defaults)
9. [Country Reference Service and API Layer](#9-country-reference-service-and-api-layer)
10. [Validation and Save UX](#10-validation-and-save-ux)
11. [Security and Performance Notes](#11-security-and-performance-notes)
12. [Internal Service Architecture](#12-internal-service-architecture)
13. [Migration Notes](#13-migration-notes)
14. [Requirements & Dependencies](#14-requirements-dependencies)
15. [Complete Field Definition Schema Reference](#15-complete-field-definition-schema-reference)
16. [Public API Reference](#16-public-api-reference)
17. [Visibility Rules (`conditions`)](#17-visibility-rules-conditions)
18. [Validation Rules & Required-If Syntax](#18-validation-rules-required-if-syntax)
19. [Callback Signatures](#19-callback-signatures)
20. [Common Patterns & Recipes](#20-common-patterns-recipes)
21. [Troubleshooting & FAQ](#21-troubleshooting-faq)
22. [Error Messages Reference](#22-error-messages-reference)
23. [Universal Logging](#23-universal-logging)
24. [Visual Schema Builder (RL Builder)](#24-visual-schema-builder-rl-builder)

---

## 1. Installation

This section explains how to load and initialize the framework in your plugin or theme context. This is required before any options can be rendered.

### What it is

Load and initialize the framework in your plugin or theme context.

### How to use

```php
require_once __DIR__ . '/includes/library/rloptionsFramework/main.php';

$config = [
    'option_name'       => 'my_project_options',
    'form_field_prefix' => 'my_project',
    'page_slug'         => 'my-project-settings',
    'menu_title'        => 'My Project',
    'page_title'        => 'My Project Settings',
    'text_domain'       => 'my-project',
];

$framework = new RL_FSBI_Options_Framework($config);
$framework->init();
```

### Bundling the library in more than one plugin

All classes are global (`RL_FSBI_Options_Framework`, `RL_FSBI_Field_*`, `RL_FSBI_Logger`), so WordPress keeps whichever
copy loads first — two plugins bundling different library versions would otherwise run one plugin's
settings page with the other plugin's build. `main.php` therefore:

- declares `RL_FSBI_OPTIONS_FRAMEWORK_VERSION` and `RL_FSBI_OPTIONS_FRAMEWORK_FILE` for the copy that loaded;
- when a second copy is required, reports the conflict (path + version of the winning copy) through
  `error_log()` when `WP_DEBUG` is on and the `rl_options_framework_duplicate_copy` action;
- scopes shared vendor handles per instance (`asset_handle_prefix`, see
  [Configuration](#3-configuration)).

A host that must guarantee its own build can compare `RL_FSBI_OPTIONS_FRAMEWORK_FILE` with its expected path
on `admin_init`. The durable fix is to ship the library as a Composer package
(`rosendolabs/rl-options-framework`) pinned per host instead of copying the folder — see the
Installation section of the [README](./README.md).

---

### Field showcase (demo plugin)

The library ships a ready-to-activate demo at `demo/rl-options-fields-demo.php` that renders every
field type with its sample code:

```bash
ln -s "/path/to/rloptionsFramework/demo" "/path/to/wp-content/plugins/rl-options-fields-demo"
wp plugin activate rl-options-fields-demo   # Settings → Fields Demo
```

---

## 2. Canonical Hooks

### What it is

Runtime hook names are derived from `option_name`.

### Dynamic Hooks (Based on option_name)

| Hook Type | Pattern | Example |
|----------|---------|---------|
| Tabs filter | `{option_name}_framework_tabs` | `my_project_options_framework_tabs` |
| Boot action | `{option_name}_framework_boot` | `my_project_options_framework_boot` |
| Before reset action | `rl_options_before_reset_{option_name}` | `rl_options_before_reset_my_project_options` |
| After reset action | `rl_options_after_reset_{option_name}` | `rl_options_after_reset_my_project_options` |
| Before section reset | `rl_options_before_reset_section_{option_name}` | `rl_options_before_reset_section_my_project_options` |
| After section reset | `rl_options_after_reset_section_{option_name}` | `rl_options_after_reset_section_my_project_options` |
| Settings saved action | `{option_name}_settings_saved` | `my_project_options_settings_saved` |
| Settings reset action | `{option_name}_settings_reset` | `my_project_options_settings_reset` |

### Available Filters

The framework provides several filters you can use with `add_filter` to modify its behavior:

| Filter Name | Description | Parameters |
|-------------|-------------|------------|
| `rl_options_framework_header_meta_html` | Inject custom HTML into the header area of the options page. | `string $html`, `array $config`, `RL_FSBI_Options_Framework $framework` |
| `rl_options_framework_html_allowed_html` | Modify the `wp_kses` allowed tags for the `html` field type. | `array $allowed_html`, `array $field`, `array $context` |
| `rl_options_framework_resolved_provider_options` | Filter the resolved options array before it's sent to the field. | `array $options`, `array $provider`, `array $field`, `array $state`, `RL_FSBI_Options_Framework $framework` |
| `rl_options_framework_country_reference_sources` | Modify or add data sources for the country reference service. | `array $sources`, `RL_FSBI_Options_Framework $framework` |
| `rl_options_framework_country_reference_data` | Filter the normalized array of country data before caching. | `array $data`, `array $sources`, `RL_FSBI_Options_Framework $framework` |
| `rl_options_framework_country_reference_ttl` | Change the transient caching TTL (in seconds) for geo endpoints. | `int $ttl`, `RL_FSBI_Options_Framework $framework` |
| `rl_options_framework_country_subdivisions` | Filter the subdivisions (states/districts) for a specific country. | `array $out`, `string $country_code`, `RL_FSBI_Options_Framework $framework` |
| `rl_options_framework_country_municipalities` | Filter municipalities (cities) for a specific country & subdivision. | `array $out`, `string $country_code`, `string $subdivision`, `RL_FSBI_Options_Framework $framework` |
| `rl_options_color_palette` | Filter global default color palette array for color pickers and swatches. | `array $palette`, `array $config` |
| `rl_options_framework_array_field_types` | Field types allowed to store array values (everything else rejects array payloads as invalid). | `string[] $types`, `array $field`, `RL_FSBI_Options_Framework $framework` |
| `rl_options_framework_non_storing_field_types` | Field types that are rendered/validated but never persisted. | `string[] $types`, `array $field`, `RL_FSBI_Options_Framework $framework` |

### Global Actions

| Action Name | Description |
|-------------|-------------|
| `rl_options_before_reset` | Fires before any framework option is reset. Passes `$option_name`. |
| `rl_options_after_reset` | Fires after any framework option is reset. Passes `$option_name`, `$defaults`. |
| `rl_options_before_reset_section` | Fires before one section is reset. Passes `$option_name`, `$tab_id`, `$section_id`. |
| `rl_options_after_reset_section` | Fires after one section is reset. Passes `$option_name`, `$current_options`, `$tab_id`, `$section_id`. |
| `rl_options_framework_settings_saved` | Fires after any framework option saves. Passes `$saved_data`, `$config`, `$framework`. |
| `rl_options_framework_settings_reset` | Fires after any framework option resets. Passes `$config`, `$framework`. |
| `rl_options_framework_field_dependency_resolved` | Fires when an async dependency field is resolved. |
| `rl_options_framework_country_reference_warmed` | Fires when the country reference cache is refreshed. |
| `rl_options_framework_duplicate_copy` | Fires when `main.php` is loaded by a second plugin copy and skips it. Passes `$message`, `$loaded_file`, `$loaded_version`. |

### Example

```php
add_filter('my_project_options_framework_tabs', function(array $tabs) {
    $tabs['general'] = [
        'label' => __('General', 'my-project'),
        'priority' => 10,
        'sections' => [
            'main' => [
                'id' => 'main',
                'title' => __('Main', 'my-project'),
                'fields' => [
                    'enable_feature' => [
                        'id' => 'enable_feature',
                        'type' => 'toggle',
                        'label' => __('Enable Feature', 'my-project'),
                        'default' => true,
                    ],
                ],
            ],
        ],
    ];

    return $tabs;
});
```

---

## 3. Configuration

Use the configuration options to customize how the framework behaves within your host application, including asset resolution and menu registration.

### Options

Every key accepted by the constructor, with its default:

| Option | Default | Description |
|--------|---------|-------------|
| `option_name` | `'rl_framework_settings'` | `wp_options` key that stores all field values |
| `form_field_prefix` | `'rl_options'` | HTML `name` prefix (`{prefix}[field_id]`) |
| `page_slug` | `'rl-options-settings'` | Admin page slug (`admin.php?page=...`) |
| `menu_title` | `'Plugin Settings'` | Sidebar menu label |
| `page_title` | `'Plugin Settings'` | Page `<h1>` and document title |
| `capability` | `'manage_options'` | Capability required to view and save |
| `parent_menu` | `'options-general.php'` | Parent menu file (e.g. `'woocommerce'`, `'edit.php?post_type=x'`) |
| `text_domain` | `'rl-options-framework'` | Text domain used by framework-owned strings |
| `ajax_action` | `'rl_save_options_ajax'` | `wp_ajax_` action used by the AJAX save |
| `assets_url` | `''` (auto) | Explicit URL to the framework `assets/` directory |
| `plugin_url` | `''` | Optional host plugin URL (informational) |
| `version` | `RL_FSBI_Options_Framework::VERSION` | Asset cache-buster (`?ver=`) |
| `asset_handle_prefix` | `''` → `page_slug` | Prefix for shared vendor handles (`-sweetalert2`, `-tippy`, `-popper`); set it when two plugins bundle the framework |
| `context` | `'auto'` | Asset URL resolution: `auto`, `plugin`, `theme` |
| `register_menu` | `true` | Register the admin menu/submenu automatically |
| `sync_history` | `false` | Keep `?page&tab` in the URL via `history.replaceState()` |
| `debug_level` | `'error'` | `error`, `warn`, `info`, `debug` (PHP + JS logging) |
| `swal_fallback` | `true` | Fall back to `window.alert` if SweetAlert2 is unavailable |
| `debug_field_id` | `'enable_debug'` | Saved-option key that turns verbose debug on at runtime |
| `use_local_assets_toggle` | `true` | Expose a Support-tab toggle for local vs CDN vendor assets |
| `local_assets_field_id` | `'rloptions_local_assets'` | Saved-option key for that toggle |
| `color_palette` | `[]` | Global default palette for `color` / `color_palette` fields |
| `builder` | `false` | Enable the opt-in drag-and-drop schema builder (Section 24) |
| `builder_page_slug` | `''` (auto) | Override the builder page slug; defaults to `{page_slug}-builder` |

> [!NOTE]
> If `register_menu` is `false`, host code must wire the menu callback to `$framework->render_page()`.
> If `assets_url` is provided, it takes precedence over context-based resolution.
> `context => 'plugin'` requires the host object to be passed to `init($plugin)` and expose `get_plugin_url()`.
> `use_local_assets_toggle` only affects RL Options Framework libraries, not unrelated plugin/theme assets.
> The AJAX field endpoints are instance-scoped (`rl_of_{option_name}_field_options` / `..._field_validate`);
> the legacy global actions remain registered once for backward compatibility.
---

## 4. Field Types

### Field Architecture (Best Practice)

Framework fields now follow an ACF-style architecture:

- Single responsibility: each field type has its own class file under [fields/class-rl-field-*.php](fields).
- Registry/dispatcher: renderers are resolved through [fields/class-rl-field-registry.php](fields/class-rl-field-registry.php).
- Bootstrap loader: built-in renderers are registered in [fields/class-rl-field-bootstrap.php](fields/class-rl-field-bootstrap.php).
- Backward compatible: unknown types still fall back to legacy rendering path.
- Service-based core: the orchestration layer delegates to 4 independent services (Render, Admin Handler, Schema Manager, REST API). See [Section 11](#11-internal-service-architecture).

This makes field maintenance and additions safer than editing a single large switch statement.

### Register custom field renderer

```php
class My_Project_Field_Rating implements RL_FSBI_Field_Interface {
    public function type(): string {
        return 'rating';
    }

    public function render(array $field, $value, array $context = []): void {
        $input_id = (string) ($context['input_id'] ?? '');
        $field_name = (string) ($context['field_name'] ?? '');
        printf('<input type="number" min="1" max="5" id="%1$s" name="%2$s" value="%3$s" />', esc_attr($input_id), esc_attr($field_name), esc_attr((string) $value));
    }
}

add_action('my_project_options_framework_boot', function(RL_FSBI_Options_Framework $framework) {
    $framework->register_field_renderer(new My_Project_Field_Rating());
});
```

> [!TIP]
> Keep renderers output-only (no save logic inside renderer classes).
> Keep sanitize/validate logic in framework validation pipeline.
> Use preset/bundle registry for reusable field config, not renderer classes.
> Add new field types by creating a new file in [fields](fields) and registering it.
### Type contract

47 renderers ship out of the box (plus 6 compatibility aliases). The saved value column tells you
what lands in the options array.

| Type | Saved value | Notes |
|------|-------------|-------|
| `text` | `string` | Standard input (`input_type` supported) |
| `textarea` | `string` | Multi-line text |
| `password` | `string` | Masked input with reveal toggle (`minlength`) |
| `number` | `int\|float` | `min`, `max`, `step`, `fallback` |
| `slider` | `int\|float` | Range + numeric readout (`unit`) |
| `spinner` | `int\|float` | Number with −/+ buttons (`unit`) |
| `select` | `string` | Options map; async providers supported |
| `multiselect` | `string[]` | Options map; async providers supported |
| `radio` | `string` | Options map |
| `checkbox` | `bool` | Single checkbox with `text` |
| `toggle` | `bool` | On/off switch |
| `switch` | `bool` | Switch with inline label (`text`) |
| `button_set` | `string` / `string[]` | Segmented buttons; `multiple` for multi-select |
| `image_select` | `string` | Option schema `['src','label']` |
| `image` | `string` | Single image URL (media picker) |
| `media` | `string` | Any media URL (`media_type`: image/video/audio/file) |
| `gallery` | `int[]` | Ordered attachment IDs |
| `icon_select` | `string` | Dashicon class (`dashicons-*`) |
| `color` | `string` | Hex / rgb / rgba (palette swatches) |
| `color_palette` | `string` | Swatch selector |
| `color_gradient` | `array` | `from`, `to`, `angle`, `type` |
| `link_color` | `array` | `link`, `hover`, `active`, `visited` |
| `border` | `array` | `width`, `unit`, `style`, `color` |
| `box_shadow` | `array` | `x`, `y`, `blur`, `spread`, `color`, `inset` |
| `dimensions` | `array` | `top`, `right`, `bottom`, `left`, `unit` |
| `spacing` | `array` | Same payload as `dimensions` |
| `typography` | `array` | family, weight, size, line-height, spacing, transform, style, color |
| `background` | `array` | `color`, `image`, `repeat`, `position`, `size`, `attachment` |
| `multi_text` | `string[]` | Repeatable text rows (`max_rows`) |
| `sortable` | `string[]` | Ordered enabled keys (drag to sort) |
| `sorter` | `string[]` | Same payload as `sortable` |
| `date` | `string` | `Y-m-d` |
| `datetime` | `string` | `Y-m-d H:i` |
| `country` | `string` | ISO2 country code |
| `state` | `string` | Subdivision code/name key |
| `city` | `string` | Municipality code/name key |
| `country_state_city` | `array` | Combined location payload |
| `code` | `string` | CodeMirror editor (verbatim value) |
| `editor` | `string` | Rich text (`wp_editor`, `wp_kses_post`) |
| `html` | n/a | Trusted markup (`sanitize_html` opt-in) |
| `raw` | n/a | `callback` output or trusted `html` |
| `info` | n/a | Informational block |
| `divider` | n/a | Visual separator (`heading`) |
| `export` | n/a | Download JSON backup |
| `import` | n/a | JSON upload |
| `reset` | n/a | Reset all settings |
| `reset_section` | n/a | Reset current section |

#### Compatibility aliases

| Alias | Renders as |
|-------|------------|
| `select_image` | `image_select` |
| `color_rgba` | `color` |
| `palette_color` | `color_palette` |
| `content` | `html` |
| `ace_editor` | `code` |
| `select2` | `select` |

#### Redux parity

Mapping against [Redux core fields](https://devs.redux.io/core-fields/) and
[core extensions](https://devs.redux.io/core-extensions/).

| Redux field | This framework | |
|-------------|----------------|---|
| `ace-editor` | `code` | CodeMirror instead of Ace (no extra vendor) |
| `background` | `background` | ✅ |
| `border` | `border` | ✅ |
| `box-shadow` | `box_shadow` | ✅ |
| `button-set` | `button_set` | ✅ |
| `checkbox` | `checkbox` | ✅ |
| `color` | `color` | ✅ |
| `color-gradient` | `color_gradient` | ✅ |
| `color-palette` / `palette-color` | `color_palette` | ✅ (+ aliases) |
| `color-rgba` | `color` | Alpha supported; `color_rgba` alias |
| `content` | `html` | ✅ (`content` alias) |
| `date` | `date` | ✅ |
| `dimensions` | `dimensions` | ✅ |
| `divide` | `divider` | ✅ |
| `editor` | `editor` | ✅ `wp_editor()` |
| `gallery` | `gallery` | ✅ |
| `image-select` / `select-image` | `image_select` | ✅ (+ `select_image` alias) |
| `info` | `info` | ✅ |
| `link-color` | `link_color` | ✅ |
| `media` | `media` | ✅ |
| `multi-text` | `multi_text` | ✅ |
| `password` | `password` | ✅ |
| `radio` | `radio` | ✅ |
| `raw` | `raw` | ✅ |
| `select` | `select` | ✅ (`select2` alias) |
| `slider` | `slider` | ✅ |
| `sortable` | `sortable` | ✅ |
| `sorter` | `sorter` | ✅ |
| `spacing` | `spacing` | ✅ |
| `spinner` | `spinner` | ✅ |
| `switch` | `switch` | ✅ (+ `toggle`) |
| `text` | `text` | ✅ |
| `textarea` | `textarea` | ✅ |
| `typography` | `typography` | ✅ |
| `section` / `slides` | — | Use schema sections / roadmap |

| Redux extension | Status |
|-----------------|--------|
| accordion | ✅ section `accordion => true` |
| tabbed | ✅ tabs |
| import-export | ✅ `import` / `export` fields + backup/restore API |
| date-time-picker | ✅ `datetime` |
| icon-select | ✅ `icon_select` |
| multi-media | ✅ `gallery` + `media` |
| custom-fonts | ⏳ filterable font list today; font uploader on the roadmap |
| google-maps, js-button, repeater, shortcodes, social-profiles | ⏳ roadmap |
| color-schemes, metaboxes, options-object, taxonomy, user-metaboxes | Not applicable to an options page |

### 4.1 `country` field

#### What it is

Single dropdown with all normalized countries.

#### How to use

```php
'country' => [
    'id'    => 'country_field',
    'type'  => 'country',
    'label' => __('Country', 'my-plugin'),
],
```

#### Saved format

```php
'PT' // ISO2 country code
```

### 4.2 `state` field

#### What it is

Subdivision dropdown tied to either a fixed country or another country field.

#### How to use (fixed country)

```php
'state' => [
    'id'      => 'state_field',
    'type'    => 'state',
    'country' => 'pt',
    'label'   => __('Distrito', 'acro-manager'),
],
```

#### How to use (linked country field)

```php
'state' => [
    'id'            => 'state_field',
    'type'          => 'state',
    'country_field' => 'country_field',
    'label'         => __('State', 'my-plugin'),
],
```

### 4.3 `city` field

#### What it is

Municipality dropdown tied to fixed or linked country/subdivision.

#### How to use

```php
'city' => [
    'id'      => 'city_field',
    'type'    => 'city',
    'country' => 'pt',
    'label'   => __('Localidade', 'acro-manager'),
],
```

### 4.4 `country_state_city` field

#### What it is

Combined Country > State > City field rendered as three coordinated selects.

#### How to use

```php
'club_location' => [
    'id'            => 'club_location',
    'type'          => 'country_state_city',
    'label'         => __('Location', 'my-plugin'),
    'country_label' => __('Pais', 'my-plugin'),
    'state_label'   => __('Distrito', 'my-plugin'),
    'city_label'    => __('Localidade', 'my-plugin'),
],
```

#### Saved format

```php
[
    'country' => 'PT',
    'state'   => 'lisboa',
    'city'    => 'sintra',
]
```

### 4.5 `date` field

#### What it is

Native HTML date input.

#### How to use

```php
'club_founding_date' => [
    'id'          => 'club_founding_date',
    'type'        => 'date',
    'label'       => __('Data de Fundacao', 'acro-manager'),
    'description' => __('Founding Date (YYYY-MM-DD)', 'acro-manager'),
    'default'     => '2026-01-01',
],
```

#### Field options

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `default` | `string` | `''` | Initial value in `Y-m-d` |
| `required` | `bool` | `false` | Enforce non-empty |
| `min` | `string` | `''` | Minimum `Y-m-d` |
| `max` | `string` | `''` | Maximum `Y-m-d` |

#### Behavior

- Strict regex validation: `^\d{4}-\d{2}-\d{2}$`.
- Calendar date validity checked server-side.
- Stored format is always `Y-m-d`.

### 4.6 `datetime` field

#### What it is

jQuery UI datepicker + native time input.

#### How to use

```php
'launch_at' => [
    'id'          => 'launch_at',
    'type'        => 'datetime',
    'label'       => __('Launch Date & Time', 'my-plugin'),
    'description' => __('Date the feature becomes active.', 'my-plugin'),
    'default'     => '2026-01-01 09:00',
    'required'    => false,
    'time_step'   => 900,
],
```

#### Behavior

- Backward compatible with existing `Y-m-d H:i` values.
- No migration needed for existing datetime fields.
- Datepicker localization uses `wp_localize_jquery_ui_datepicker()`.

### 4.7 `html` field

#### What it is

Read-only HTML content block for admin-only help, notices, and structured markup.

#### How to use

```php
'help_text' => [
    'id'   => 'help_text',
    'type' => 'html',
    'html' => '<div class="notice notice-info"><p>' . __( 'This is an info box.', 'my-plugin' ) . '</p></div>',
],
```

#### Supported keys

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `html` | `string` | `''` | HTML markup rendered inside the field wrapper |
| `sanitize_html` | `bool` | `false` | Sanitize the markup with `wp_kses()` before rendering |
| `allowed_html` | `array` | `wp_kses_allowed_html('post')` | Optional `wp_kses` allowlist override |

#### Saved value format

- No option value is stored. The field is render-only.

#### Validation and sanitization rules

- The `html` field does not participate in save sanitization because it is not persisted.
- By default the markup is rendered as-is and is treated as **trusted, schema-authored HTML**
  (it may contain inline scripts, e.g. framework-provided UI snippets). Never populate `html`
  from user input, request data, or a database value an end user can edit.
- Set `'sanitize_html' => true` to run the markup through `wp_kses()`; this is required whenever
  the markup is dynamic.
- When sanitizing, default allowed tags come from `wp_kses_allowed_html('post')`. You may override
  the allowlist per field with `allowed_html` or globally with the
  `rl_options_framework_html_allowed_html` filter.

### 4.8 `image_select` schema

```php
'options' => [
    'grid' => ['src' => 'https://example.com/grid.png', 'label' => 'Grid'],
    'list' => ['src' => 'https://example.com/list.png', 'label' => 'List'],
]
```

### 4.9 `export` field

#### What it is

Renders a button to download the current framework options as a JSON file.

#### How to use

```php
'export_settings' => [
    'id'           => 'export_settings',
    'type'         => 'export',
    'button_label' => __('Download Backup', 'my-plugin'),
    'description'  => __('Export your settings.', 'my-plugin'),
],
```

### 4.10 `import` field

#### What it is

Renders a file upload input to import a JSON backup. Automatically intercepts the form submission to apply the settings.

#### How to use

```php
'import_settings' => [
    'id'             => 'import_settings',
    'type'           => 'import',
    'status_message' => __('File ready to import. Save changes to apply.', 'my-plugin'),
    'description'    => __('Import your settings backup.', 'my-plugin'),
],
```

### 4.11 `reset` field

#### What it is

Renders a reset button that deletes all settings and restores defaults, with a built-in browser confirmation prompt.

#### How to use

```php
'reset_settings' => [
    'id'              => 'reset_settings',
    'type'            => 'reset',
    'button_label'    => __('Reset to Defaults', 'my-plugin'),
    'confirm_message' => __('Are you sure?', 'my-plugin'),
    'description'     => __('This will wipe all data.', 'my-plugin'),
],
```

### 4.12 `reset_section` field

#### What it is

Renders a reset button that deletes and restores defaults **only for the specific section** the button is placed in, leaving all other sections and settings intact. It includes a built-in browser confirmation prompt.

#### How to use

```php
'reset_display_options' => [
    'id'              => 'reset_display_options',
    'type'            => 'reset_section',
    'button_label'    => __('Reset Section Defaults', 'my-plugin'),
    'confirm_message' => __('Are you sure you want to reset this section?', 'my-plugin'),
],
```

### 4.13 `color` field (with palette support)

#### What it is

Renders a WordPress Iris color picker input with support for field-level palettes, framework-level default palettes, and optional alpha channel support.

#### How to use

```php
// With custom field-level palette swatches
'primary_color' => [
    'id'      => 'primary_color',
    'type'    => 'color',
    'label'   => __('Primary Brand Color', 'my-plugin'),
    'default' => '#10b981',
    'palette' => ['#10b981', '#059669', '#d1fae5', '#0f172a', '#ffffff'],
],

// Explicitly disable palette swatches
'custom_color' => [
    'id'      => 'custom_color',
    'type'    => 'color',
    'label'   => __('Custom Color', 'my-plugin'),
    'default' => '#3b82f6',
    'palette' => false,
],
```

### 4.14 `color_palette` field (visual swatch selector)

#### What it is

Renders a dedicated, accessible visual swatch selector where users can choose a color from a grid of circular or square swatches without opening a color picker spectrum. Features selected checkmark states, smooth hover elevations, tooltip labels via Tippy.js, and optional text labels.

#### How to use

```php
// Associative array: hex => display label (shown in tooltip / text label)
'accent_preset' => [
    'id'         => 'accent_preset',
    'type'       => 'color_palette',
    'label'      => __('Theme Accent Color', 'my-plugin'),
    'desc'       => __('Choose a predefined accent color for your storefront.', 'my-plugin'),
    'default'    => '#10b981',
    'options'    => [
        '#10b981' => __('Emerald', 'my-plugin'),
        '#3b82f6' => __('Royal Blue', 'my-plugin'),
        '#8b5cf6' => __('Purple', 'my-plugin'),
        '#f59e0b' => __('Amber', 'my-plugin'),
        '#ef4444' => __('Ruby Red', 'my-plugin'),
        '#0f172a' => __('Dark Slate', 'my-plugin'),
    ],
    'shape'      => 'round',   // 'round' (circle) or 'square'
    'size'       => 'medium',  // 'small', 'medium', 'large'
    'show_label' => true,      // Display text label beneath each swatch
],

// Flat array (uses global framework palette if options omitted)
'quick_theme' => [
    'id'      => 'quick_theme',
    'type'    => 'color_palette',
    'label'   => __('Brand Color', 'my-plugin'),
    'default' => '#10b981',
    'options' => ['#10b981', '#059669', '#d1fae5', '#0b1220'],
],
```

---

## 5. Declarative Dependencies and Async Providers

### What it is

Opt-in dependency engine for reactive field visibility/options/validation without reloading the page.

### Supported field keys

| Key | Type | Purpose |
|-----|------|---------|
| `depends_on` | `string[]` | Declares parent fields |
| `visibility_rules` | `array` | Declarative visibility logic |
| `options_provider` | `array` | Async options source config |
| `required_if` | `array` | Conditional required rules |

### Example

```php
'country' => [
    'id'    => 'country',
    'type'  => 'country',
],
'district' => [
    'id'          => 'district',
    'type'        => 'state',
    'country_field' => 'country',
    'depends_on'  => ['country'],
    'required_if' => [
        ['field' => 'country', 'operator' => 'truthy'],
    ],
],
'municipality' => [
    'id'               => 'municipality',
    'type'             => 'city',
    'country_field'    => 'country',
    'subdivision_field'=> 'district',
    'depends_on'       => ['country', 'district'],
],
```

### Behavior

- Runs on change and on save.
- Shows inline field errors.
- Blocks save when dependency validation fails.
- Falls back to static/local options if provider request fails.

---

## 6. Framework Preset and Bundle Tools

### What it is

The framework provides a neutral preset/bundle registry. It does not ship business presets.
Theme/plugin developers register their own reusable field definitions and bundle resolvers.

### Registry API

```php
$framework->register_field_preset('my_email', [
    'id' => 'contact_email',
    'type' => 'email',
    'label' => __('Email', 'my-plugin'),
]);

$framework->register_field_bundle('contact_bundle', function(array $config, $registry): array {
    return [
        $registry->get_preset('my_email'),
    ];
});

$framework->add_preset_field('general', 'main', 'my_email');
$framework->add_bundle_fields('general', 'main', 'contact_bundle');
```

### Preset registry access

```php
$registry = $framework->presets();
$field = $registry ? $registry->get_preset('my_email', ['id' => 'support_email']) : [];
```

---

## 7. Typed Field Aliases

### What it is

Aliases map semantic field types to a base field with built-in sanitize and validate behavior.

### Supported aliases

| Alias | Base Type | Built-in behavior |
|------|-----------|-------------------|
| `email` | `text` | `sanitize_email` + `is_email` |
| `phone` | `text` | phone regex validate + tel attributes |
| `postal_code` | `text` | postal format regex validate |
| `url` | `text` | `esc_url_raw` + `wp_http_validate_url` |
| `nif` | `text` | PT NIF sanitize + checksum validate |

### Example

```php
'club_email' => [
    'id' => 'club_email',
    'type' => 'email',
],
'club_phone' => [
    'id' => 'club_phone',
    'type' => 'phone',
],
```

---

## 8. Provider Shortcut and Schema Defaults

### Provider shortcut

You can use shorthand syntax:

```php
'district' => [
    'id' => 'district',
    'type' => 'select',
    'provider' => 'subdivisions',
],
```

Framework normalizes to:

```php
'options_provider' => [
    'endpoint' => 'subdivisions',
]
```

### Callback aliases

- `sanitize` is normalized to `sanitize_callback` when callable.
- `validate` is normalized to `validate_callback` when callable.

### Pattern normalization

If `pattern` is provided without regex delimiters, framework wraps it as anchored regex automatically.

---

## 9. Country Reference Service and API Layer

### What it is

Global geo reference service (`RL_FSBI_Options_Rest_Api`) with normalized outputs for framework and external consumers. Logic lives in `services/class-rl-options-rest-api.php`. The framework exposes proxy methods for backward compatibility.

### Data model keys

- `code`
- `name`
- `region`
- `capital`

### Caching behavior

- Transient cache is effectively eternal by default (`TTL = 0`).
- Data refreshes when transients are cleared or cache payload is empty.
- Source timeout and graceful fallback are built-in.

### REST endpoints

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/wp-json/rl-options/v1/countries` | `GET` | Country list |
| `/wp-json/rl-options/v1/countries/{code}/subdivisions` | `GET` | Country subdivisions |
| `/wp-json/rl-options/v1/countries/{code}/municipalities?subdivision=...` | `GET` | Municipalities |

### PHP helper functions

```php
rl_options_framework_get_countries();
rl_options_framework_get_country_subdivisions('PT');
rl_options_framework_get_country_municipalities('PT', 'lisboa');
```

### Extensibility hooks

#### Filters

- `rl_options_framework_country_reference_sources`
- `rl_options_framework_country_reference_data`
- `rl_options_framework_country_subdivisions`
- `rl_options_framework_country_municipalities`
- `rl_options_framework_resolved_provider_options`

#### Actions

- `rl_options_framework_country_reference_warmed`
- `rl_options_framework_field_dependency_resolved`

---

## 10. Validation and Save UX

### Validation behavior

- Fields are optional by default.
- Blank values are never a type error: an optional `number`/`slider` left empty validates.
- `required => true` enforces required validation.
- `required_if` adds dependency-aware required validation.
- AJAX responses include field-level metadata (`field_id`, `field_label`, `tab_id`, `section_id`, `message`).

### Save behavior

- AJAX save uses SweetAlert when available.
- If SweetAlert fails and `swal_fallback` is enabled, fallback is `window.alert`.
- On validation errors, framework focuses invalid field and opens the relevant tab/section.

---

## 11. Security and Performance Notes

### Security

- All AJAX endpoints validate nonce and user capability.
- Incoming request params are sanitized before use.
- Imports are validated and sanitized against the registered field schema before being saved.
- The `html` field renders trusted, schema-authored markup as-is; set `sanitize_html => true` to
  force `wp_kses()` filtering (required for any dynamic markup).
- Every field is rendered inside the framework form. A field renderer that emits `<form>`/`</form>`
  (nested forms make the browser close the outer form, orphaning the Save button) has those tags
  stripped and triggers a `RL_FSBI_Logger::warn()` entry naming the field.
- Remote geo reference sources are fetched over HTTPS with `wp_safe_remote_get()`.
- Logger context redacts sensitive keys such as nonce/token/password/secret values.

### Performance

- Geo metadata is cached in transients.
- Empty payloads are not persisted as long-lived cache values.
- Remote source requests use timeout and fallback strategy.

---

## 12. Internal Service Architecture

### What it is

The framework core (`class-rl-options-framework.php`) is an orchestration layer. All feature domains are delegated to dedicated service classes under `services/`.

### Service map

| Service class | File | Responsibility |
|---|---|---|
| `RL_FSBI_Options_Render_Service` | `services/class-rl-options-render-service.php` | Admin page rendering, field output (single field included), tooltip formatting |
| `RL_FSBI_Options_Admin_Handler` | `services/class-rl-options-admin-handler.php` | Form save (POST), AJAX save, AJAX field options, AJAX inline validation |
| `RL_FSBI_Options_Schema_Manager` | `services/class-rl-options-schema-manager.php` | Tab/section/field normalization, schema building, condition filtering |
| `RL_FSBI_Options_Rest_Api` | `services/class-rl-options-rest-api.php` | REST route registration, geo reference data, transient caching |
| `RL_FSBI_Options_Builder` | `services/class-rl-options-builder.php` | Opt-in drag-and-drop schema builder, draft storage, PHP export model |

### Service access

All services are accessible via framework accessor methods after `init()` is called:

```php
$framework->rest_api();      // RL_FSBI_Options_Rest_Api
```

### REST API service — public methods

The `RL_FSBI_Options_Rest_Api` service exposes geo data methods for use in field providers, custom endpoints, or third-party integrations:

```php
$rest = $framework->rest_api();

// Countries: [{code, name, region, capital}]
$countries = $rest->get_country_reference_countries();

// Raw dataset: ['PT' => ['code', 'name', 'region', 'capital'], ...]
$data = $rest->get_country_reference_data();

// Subdivisions: [{value, label}]
$states = $rest->get_country_subdivisions_data('PT');

// Municipalities: [{value, label}]
$cities = $rest->get_country_municipalities_data('PT', 'lisboa');
```

The same methods are also proxied on the framework instance for backward compatibility:

```php
$framework->get_country_reference_countries();
$framework->get_country_subdivisions('PT');
$framework->get_country_municipalities('PT', 'lisboa');
```

### normalize_options_for_transport()

This utility method is public on `RL_FSBI_Options_Framework` so services and custom field renderers can normalize their options into the `[{value, label}]` transport format:

```php
$options = $framework->normalize_options_for_transport([
    'PT' => 'Portugal',
    'ES' => 'Spain',
]);
// → [['value' => 'PT', 'label' => 'Portugal'], ['value' => 'ES', 'label' => 'Spain']]
```

---

## 13. Migration Notes

### Hook naming

Old custom naming:

```text
my_plugin_settings_tabs
```

Framework canonical naming:

```text
my_plugin_options_framework_tabs
```

The framework runtime reads only `{option_name}_framework_tabs`.

### Upgrading to 2.3.0

No host-side changes are required.

| Area | Status |
|------|--------|
| `show_if` / `visibility_rules` | Still supported; normalized into `conditions` (including definitions added through the tabs filter) |
| `conditions` | Canonical shape is `['relation' => 'AND\|OR', 'rules' => [...]]`; a flat rule list is treated as AND |
| `required_if` | Rules use the `value` key (not `expected`); `contains` and `match` are available |
| AJAX endpoints | New instance-scoped actions; the legacy global actions remain registered once |
| `html` fields | Trusted markup is rendered as-is; set `'sanitize_html' => true` for dynamic markup |
| Display-only fields | `html`, `info`, `export`, `import`, `reset`, `reset_section` are no longer persisted |
| Versioning | `RL_FSBI_Options_Framework::VERSION` is the single source; pass your own `version` only if you need a different cache-buster |

---

## 14. Requirements & Dependencies

### PHP

- **Minimum:** PHP 7.4+
- **Recommended:** PHP 8.0+
- Required extensions: `json`, `filter`

### Running the test suite

The framework ships a dependency-free suite (no PHPUnit, no `composer install` required):

```bash
php tests/run.php     # or: composer test
```

It boots the framework against WordPress stubs and covers boot/services, schema registration,
condition evaluation (including legacy `show_if`), validation/sanitization hardening, storage
(export/import/reset) and the asset pipeline.

### WordPress

- **Minimum:** WordPress 5.0+
- **Recommended:** WordPress 6.0+
- Admin context required (framework only works in wp-admin)

### Browsers

| Browser | Support |
|---------|---------|
| Chrome | Latest 2 versions |
| Firefox | Latest 2 versions |
| Safari | Latest 2 versions |
| Edge | Latest 2 versions |
| IE 11 | Not supported |

---

## 15. Complete Field Definition Schema Reference

### Universal field properties

Every field accepts these properties:

| Property | Type | Default | Required | Description |
|----------|------|---------|----------|-------------|
| `id` | `string` | — | ✅ | Unique field identifier within section |
| `type` | `string` | — | ✅ | Field type (see type reference below) |
| `label` | `string` | `''` | ❌ | Display label shown to admin (`title` works as an alias) |
| `desc` / `description` | `string` | `''` | ❌ | Help text, rendered in the info tooltip next to the label |
| `tooltip` | `string` | `''` | ❌ | Alias for `desc`/`description` (used when both are empty) |
| `placeholder` | `string` | `''` | ❌ | Input placeholder text |
| `default` | mixed | `null` | ❌ | Default value when no option is saved |
| `required` | `bool` | `false` | ❌ | Enforce non-empty on save |
| `required_if` | `array` | `[]` | ❌ | Conditional required rules (see Section 18) |
| `depends_on` | `array` | `[]` | ❌ | Parent field IDs that drive async provider refreshes |
| `conditions` | `array` | `[]` | ❌ | Visibility rules (JS + backend filtering, see Section 17) |
| `show_if` | `array` | `[]` | ❌ | Legacy alias for `conditions` (still supported) |
| `visibility_rules` | `array` | `[]` | ❌ | Legacy alias for `conditions` |
| `sanitize_callback` | `callable` | `null` | ❌ | Custom sanitize function |
| `sanitize` | `callable` | `null` | ❌ | Alias for `sanitize_callback` |
| `validate_callback` | `callable` | `null` | ❌ | Custom validation function |
| `validate` | `callable` | `null` | ❌ | Alias for `validate_callback` |
| `class` | `string` | `''` | ❌ | Extra CSS classes on the `.rl-field` wrapper |
| `width` | `string` | `''` | ❌ | Adds `rl-field-width-{value}` to the wrapper for host CSS (no built-in layout) |
| `priority` | `int` | `10` | ❌ | Sort order within the section |
| `store` | `bool` | auto | ❌ | Force persisting (or not persisting) the field value |
| `multiple` | `bool` | `false` | ❌ | Treat the value as an array (multi-value fields) |
| `fields` | `array` | `[]` | ❌ | Nested subfields rendered indented under this field |
| `indent` | `bool` | `false` | ❌ | Indent the field (inherits nesting level) |
| `indent_level` | `int` | `0` | ❌ | Explicit indentation level |

Sections accept `id`, `title`, `description`, `priority`, `accordion`, `class`, `conditions`
(`show_if` is accepted as a legacy alias). Tabs accept `label`, `priority`, `sections`, `conditions`,
`description`.

> [!NOTE]
> `__tab_id`, `__section_id` and `type_alias` are internal annotations; do not set them yourself.
> `type_alias` is filled automatically when a typed alias (`email`, `url`, `phone`, `postal_code`, `nif`) is expanded.
> Need arbitrary HTML attributes on the control itself? Register a custom field renderer (Section 4).

### Type-specific properties

#### `text` / `email` / `url` / `phone` / `postal_code` / `nif`

| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `maxlength` | `int` | `''` | Character limit |
| `pattern` | `string` | `''` | Regex (anchored automatically) |
| `input_type` | `string` | `text` | HTML input type attribute |

#### `textarea`

| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `rows` | `int` | `4` | Number of rows |
| `maxlength` | `int` | `''` | Character limit |

#### `number`

| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `min` | `float\|int` | `''` | Minimum allowed value |
| `max` | `float\|int` | `''` | Maximum allowed value |
| `step` | `float\|int` | `1` | Increment step |
| `fallback` | `float\|int` | `0` | Value if invalid (non-numeric) |

Leaving the field empty is valid unless `required => true`; an empty optional number is not persisted as
a validation error and never blocks the rest of the form. `slider` follows the same rule.

#### `select` / `multiselect` / `radio` / `checkbox`

| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `options` | `array` | `[]` | Static key-value options |
| `options_provider` | `array` | `[]` | Async provider config (see Section 5) |
| `provider` | `string` | `''` | Shorthand for provider endpoint |

#### `html`

| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `html` | `string` | `''` | Render-only HTML markup |
| `sanitize_html` | `bool` | `false` | Sanitize markup with `wp_kses()` (required for dynamic content) |
| `allowed_html` | `array` | `wp_kses_allowed_html('post')` | Optional `wp_kses` allowlist for rendered markup |

#### `country` / `state` / `city`

| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `country` | `string` | `''` | Fixed ISO2 country code |
| `country_field` | `string` | `''` | Field ID to link country |
| `subdivision_field` | `string` | `''` | Field ID to link state (for city only) |
| `subdivision` | `string` | `''` | Fixed subdivision slug when no linked field is used (city only) |

#### `country_state_city`

| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `country_label` | `string` | `'Country'` | Label for country select |
| `state_label` | `string` | `'State'` | Label for state select |
| `city_label` | `string` | `'City'` | Label for city select |

#### `date` / `datetime`

| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `min` | `string` | `''` | Minimum date (Y-m-d) |
| `max` | `string` | `''` | Maximum date (Y-m-d) |
| `time_step` | `int` | `900` | Time increment in seconds (datetime only) |

#### `color`

| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `palette` | `array\|bool\|string` | `[]` | Predefined color palette (array of hex colors, `true` for global/default, or `false` to disable). `palettes` is accepted as a plural alias. |
| `alpha` | `bool` | `true` | Allow alpha channel (rgba) |

#### `color_palette`

| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `options` | `array` | `[]` | Color swatches map (`['#hex' => 'Label']`, `['#hex1', '#hex2']`, or array of `['value', 'label']`). Falls back to `$config['color_palette']` if empty. |
| `size` | `string` | `'medium'` | Swatch size: `'small'`, `'medium'`, or `'large'` |
| `shape` | `string` | `'round'` | Swatch shape: `'round'` (circle) or `'square'` |
| `show_label` | `bool` | `false` | Display text label below each color swatch |

#### `image` / `image_select`

| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `button_label` | `string` | `'Select Image'` | Media picker button text |

#### `toggle` / `checkbox`

| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `text` | `string` | `''` | Label text next to checkbox/toggle |

#### `info`

| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `description` | `string` | `''` | Informational copy, rendered through `wp_kses_post()` |

#### `export` / `import`

| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `button_label` | `string` | `'Download Export File'` | Export button text (`export` only) |
| `description` | `string` | `''` | Helper text above the control |
| `status_message` | `string` | `'File loaded! …'` | Message shown after a file is loaded (`import` only) |

#### `reset` / `reset_section`

| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `button_label` | `string` | `'Reset All Settings'` / `'Reset Section Settings'` | Button text |
| `description` | `string` | Built-in warning | Helper text (`reset` only) |
| `confirm_message` | `string` | Built-in warning | Text of the `window.confirm()` prompt |

#### `button_set`

| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `options` | `array` | `[]` | **Required.** `['value' => 'Label']` |
| `multiple` | `bool` | `false` | Allow several active buttons (stored as an array) |

#### `switch`

| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `text` | `string` | `''` | Label rendered next to the switch |

#### `slider` / `spinner`

| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `min` | `int\|float` | `0` | Lower bound (value is clamped) |
| `max` | `int\|float` | `100` (`''` for spinner) | Upper bound |
| `step` | `int\|float` | `1` | Step size; a decimal step stores a float |
| `unit` | `string` | `''` | Suffix shown after the control (`px`, `%`, …) |

#### `password`

| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `minlength` | `int` | `0` | Optional minimum length enforced on save |

#### `divider`

| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `heading` | `string` | `''` | Optional section heading |
| `description` | `string` | `''` | Optional helper text |

#### `raw`

| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `callback` | `callable` | `null` | `function (array $field, $value, array $context)` |
| `html` | `string` | `''` | Trusted markup, filtered with `wp_kses_post()` |

#### `code`

| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `language` | `string` | `'text/html'` | CodeMirror mode |
| `rows` | `int` | `12` | Textarea height |

> The value is stored verbatim (CSS/JS snippets); only invalid UTF‑8 is stripped. Saving requires the
> configured capability, and CodeMirror is only enqueued when a `code` field is on the page.

#### `editor`

| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `rows` | `int` | `10` | Editor height |
| `teeny` | `bool` | `false` | Use the compact editor |

#### `icon_select`

No per-field properties. Choices come from the `rl_options_framework_icons` filter.

#### `media`

| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `media_type` | `string` | `'image'` | `image`, `video`, `audio` or `file` (library filter + preview) |

#### `gallery`

Stores an ordered `int[]` of attachment IDs; the picker is limited to images.

#### `multi_text`

| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `max_rows` | `int` | `0` | Maximum stored rows (`0` = unlimited) |

#### `sortable` / `sorter`

| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `options` | `array` | `[]` | **Required.** `['value' => 'Label']`; unchecked rows are not stored |

#### Composite value keys

Every composite field stores an array; unknown keys are discarded and each value is sanitized.

| Type | Keys |
|------|------|
| `border` | `width` (int, 0–100), `unit`, `style` (`none`/`solid`/`dashed`/`dotted`/`double`), `color` |
| `box_shadow` | `x`, `y` (−200…200), `blur` (0…200), `spread` (−200…200), `color`, `inset` (bool) |
| `dimensions` / `spacing` | `top`, `right`, `bottom`, `left` (−1000…1000), `unit` |
| `link_color` | `link`, `hover`, `active`, `visited` |
| `background` | `color`, `image` (URL), `repeat`, `position`, `size`, `attachment` |
| `typography` | `font_family`, `font_weight`, `font_size`, `font_size_unit`, `line_height`, `line_height_unit`, `letter_spacing`, `letter_spacing_unit`, `text_transform`, `font_style`, `color` |
| `color_gradient` | `from`, `to`, `angle` (0…360), `type` (`linear`/`radial`) |

> Colors accept `#rgb`, `#rrggbb`, `#rrggbbaa`, `rgb()`, `rgba()` and `transparent`; anything else
> falls back to the sub-field default.

#### Composite field UI (block-editor style)

Composite fields render like the WordPress block editor inspector, not like stacked admin rows:

| Element | Class | Behaviour |
|---|---|---|
| Row | `.rl-gb-row` | Uppercase 11px label on the left, control cluster on the right, hairline separators |
| Unit box | `.rl-gb-number` / `.rl-gb-suffix` | Number input with the current unit inside the box (`0` `px`); the suffix follows the `unit` select live |
| Slider | `.rl-gb-range` | Opt-in per sub-field with `'range' => true` + `min`/`max`; mirrors the number input both ways |
| Link sides | `.rl-gb-link` | Only on composites with `top`/`right`/`bottom`/`left`: links the four sides so one edit drives them all |
| Colour | swatch | The stock picker button becomes a 40 × 40 swatch; the hex field opens on click |
| Controls | `.rl-gb-input` / `.rl-gb-select` | 40px height, 2px radius, `#949494` border with the admin accent on focus |

Opt a numeric sub-field into a slider from a custom composite:

```php
protected function sub_fields(): array {
	return [
		'width' => [
			'type'    => 'number',
			'label'   => __( 'Width', 'my-plugin' ),
			'default' => 0,
			'min'     => 0,
			'max'     => 100,
			'range'   => true, // adds the slider
		],
	];
}
```

The builder canvas loads the same stylesheet, so a preview always matches the front-of-house page.

---

## 16. Public API Reference

### Version & Text Domain

```php
public const VERSION = '2.5.1';
public static function version(): string;
public static function text_domain(): string;
```

Single source of truth for the framework version (used as the default asset cache-buster) and
for the configured text domain, which stateless field renderers resolve statically.

### Constructor & Initialization

```php
public function __construct(array $config = []): void
```

Initialize framework with configuration array. See Section 3 for config keys.

```php
public function init($plugin = null): void
```

Boot framework: lock config, instantiate services, register hooks. Call once in `plugins_loaded` or `after_setup_theme`.

```php
public function boot($plugin): void
```

Register WordPress menu/submenu and AJAX handlers. Called automatically by `init()` if `register_menu` is `true`.

### Tab/Section/Field Registration

```php
public function add_tab(string $slug, array $args): void
public function add_section(string $tab_slug, string $section_id, array $section): void
public function add_field(string $tab_slug, string $section_id, array $field): void
public function add_fields(string $tab_slug, string $section_id, array $fields): void

public function get_tabs(): array          // filtered + sorted, for rendering
public function set_tabs(array $tabs): void
public function get_raw_tabs(): array       // unfiltered, for registration
public function set_raw_tabs(array $tabs): void
```

Register tabs, sections, and fields. Can be called anytime before rendering.

**Keys:**

- Tab: `id` (string), `label` (string), `priority` (int), `sections` (array), `conditions` (array)
- Section: `tab_id` (string), `id` (string), `title` (string), `class` (string), `conditions` (array)
- Field: See Section 14

### Preset & Bundle Registry

```php
public function register_field_preset(string $preset_id, array $definition): void
```

Register reusable field configuration. Usage: `$framework->add_preset_field('tab_id', 'section_id', 'preset_id')`.

```php
public function register_field_bundle(string $bundle_id, callable $resolver): void
```

Register field bundle resolver. Resolver signature:

```php
function($config, $registry): array { return [/* fields */]; }
```

```php
public function add_preset_field(string $tab_slug, string $section_id, string $preset_id, array $overrides = []): void

public function add_bundle_fields(string $tab_slug, string $section_id, string $bundle_id, array $config = []): void
```

Add preset or bundle instance to a section.

```php
public function presets(): ?RL_FSBI_Field_Presets
```

Get preset registry accessor.

### Option Access

```php
public function get_option(string $key = '', $default = null): mixed
public function get_options(): array
```

Retrieve single option or all options. Maps to `get_option('{option_name}')` internally.

```php
public function set_option(string $key, $value): bool
```

Update single option.

```php
public function reset_to_defaults(): bool
```

Reset all framework options to their default values, creating a backup first. Fires `rl_options_before_reset` and `rl_options_after_reset` hooks.

### Service Accessors

```php
public function rest_api(): ?RL_FSBI_Options_Rest_Api
public function builder(): ?RL_FSBI_Options_Builder
```

Access the REST API service for geo data (Section 11) or the visual schema builder (Section 24).
`builder()` always returns the service; check `$framework->builder()->is_enabled()` or the `builder`
config flag before using the page.

```php
public function field_registry(): ?RL_FSBI_Field_Registry
public function get_storage_service(): ?RL_FSBI_Options_Storage_Service
public function get_field_processor(): ?RL_FSBI_Options_Field_Processor
public function get_admin_handler(): ?RL_FSBI_Options_Admin_Handler
public function plugin_instance()
```

Access the field registry, storage/import-export service, field processor, admin handler,
or the host object passed to `init()`.

```php
public function is_storable_field(array $field): bool
```

Whether a field persists a value. Display-only types (`html`, `info`, `export`, `import`,
`reset`, `reset_section`) return `false`; override per field with `'store' => true` or globally
with the `rl_options_framework_non_storing_field_types` filter.

```php
public function provider_action(): string
public function validate_action(): string
```

Instance-scoped AJAX actions used by the bundled JS for async option providers and inline
validation. They are already localized as `rlFramework.provider_action` / `.validate_action`.

### Field Renderer Registration

```php
public function register_field_renderer(RL_FSBI_Field_Interface $renderer): void
```

Register custom field type. Callback must implement `RL_FSBI_Field_Interface`:

```php
interface RL_FSBI_Field_Interface {
    public function type(): string;
    public function render(array $field, $value, array $context = []): void;
}
```

### Rendering & Display

```php
public function render_page(): void
```

Render admin options page. Called by WordPress menu callback if `register_menu` is `true`.

```php
public function render_single_field( array $field, array $options = [] ): string
```

Render one normalized field (label, control and inline-error host) exactly as the settings page would,
and return the markup. `$options` is the full option array used to resolve the current value. Used by
the builder preview endpoint; handy for custom previews, emails or REST responses:

```php
$renderer = new RL_FSBI_Options_Render_Service( $framework );

$html = $renderer->render_single_field(
    [
        'id'      => 'headline',
        'type'    => 'text',
        'label'   => 'Headline',
        'default' => 'Welcome',
    ]
);
```

```php
public function get_country_reference_countries(): array

public function get_country_subdivisions(string $country_code): array

public function get_country_municipalities(string $country_code, string $subdivision): array
```

Retrieve geo reference data. See Section 6.

```php
public function normalize_options_for_transport(array $options, array $mapping = []): array
```

Normalize to `[{value, label}]` format. Used internally; available for custom renderers.

### Validation & Hooks

```php
public function filter_tabs_by_conditions(array $tabs, array $options): array
```

Apply visibility rules. Filters tabs based on `conditions`.

```php
public function register_menu(): void
```

Register WordPress menu/submenu manually if `register_menu` is `false`.

### Advanced & Internal-ish Public API

These are public and stable, but usually called by the framework itself (hook callbacks) or by
custom renderers.

```php
// Configuration & request context
public function get_config(string $key = '', $default = null)
public function get_validation_context(): array
public function set_validation_context(array $context): void

// Schema lookup
public function get_fields_index(): array
public function get_field_label(array $field): string
public function normalize_field_label(string $label): string
public function get_input_name(string $field_id): string
public function get_input_id(string $field_id): string
public function get_current_tab_slug(array $tabs): string
public function get_tab_url(string $slug): string

// Processing (used by custom renderers and imports)
public function sanitize_field_value(array $field, $value)
public function validate_field_value(array $field, $value, string &$error = ''): bool
public function prepare_value_for_validation(array $field, $value)
public function resolve_field_provider_options(array $field, array $state = [], bool $fallback_static = true): array
public function get_geo_field_options(array $field, string $type, array $state = []): array

// Backup helpers
public function create_backup()
public function restore_backup(): bool

// Hook callbacks (registered by init())
public function enqueue_assets(string $hook): void
public function enqueue_frontend_assets(): void
public function render_notices(): void
public function handle_save(): void
public function handle_ajax_save(): void
public function handle_ajax_field_options(): void
public function handle_ajax_field_validate(): void
```

---

## 17. Visibility Rules (`conditions`)

The `conditions` array is used to control the frontend and backend visibility of fields, sections, and tabs based on the values of other fields. It evaluates dynamically in JS when the user interacts with the form, and statically in PHP when saving.

### Canonical shape

```php
'conditions' => [
    'relation' => 'AND',           // AND | OR
    'rules'    => [
        [ 'field' => 'gallery_type', 'operator' => 'equals', 'value' => 'slider' ],
        [
            'relation' => 'OR',    // nested groups are supported at any depth
            'rules'    => [
                [ 'field' => 'autoplay', 'operator' => 'equals', 'value' => true ],
                [ 'field' => 'mode',     'operator' => 'equals', 'value' => 'fade' ],
            ],
        ],
    ],
],
```

A flat list of rules is also accepted and is treated as `relation => AND`.

### Legacy `show_if` (supported)

Tabs, sections and fields may still use the 1.x/2.x alias; it is normalized into `conditions`
at registration time, so both forms behave identically:

```php
'show_if' => [ 'field' => 'enable_feature', 'value' => true ],
```

Prefer `conditions` for new code; `show_if` remains supported for drop-in compatibility.

### Visibility Operators

| Operator | Type | Example | Meaning |
|----------|------|---------|---------|
| `equals` / `==` | `mixed` | `['field' => 'type', 'operator' => 'equals', 'value' => 'image']` | Value is exactly equal to expected |
| `not_equals` / `!=` | `mixed` | `['field' => 'type', 'operator' => 'not_equals', 'value' => 'image']` | Value is not equal to expected |
| `in` | `array` | `['field' => 'role', 'operator' => 'in', 'value' => ['admin', 'editor']]` | Value is in array |
| `not_in` | `array` | `['field' => 'role', 'operator' => 'not_in', 'value' => ['guest']]` | Value is not in array |
| `>` / `greater_than` | `int\|float` | `['field' => 'age', 'operator' => '>', 'value' => 18]` | Value is strictly greater than |
| `>=` / `greater_than_or_equal` | `int\|float` | `['field' => 'age', 'operator' => '>=', 'value' => 18]` | Value is greater than or equal to |
| `<` / `less_than` | `int\|float` | `['field' => 'age', 'operator' => '<', 'value' => 18]` | Value is strictly less than |
| `<=` / `less_than_or_equal` | `int\|float` | `['field' => 'age', 'operator' => '<=', 'value' => 18]` | Value is less than or equal to |
| `truthy` | `bool` | `['field' => 'enable', 'operator' => 'truthy']` | Value evaluates to true/not empty |
| `falsy` | `bool` | `['field' => 'enable', 'operator' => 'falsy']` | Value evaluates to false/empty |

> [!NOTE]
> For boolean toggles, saving often results in a string `"1"` or `"0"`. The `equals` operator is
> designed to smartly match `true` with `"1"` and `false` with `"0"` or `""`. Comparisons are
> type-tolerant in general, so a stored `"3"` also matches the integer `3`.

## 18. Validation Rules & Required-If Syntax

### Dependency operators

Rules read the compared value from the `value` key (not `expected`). A rule may be a single
associative array or a list of rules; a list is evaluated with **AND** semantics.

| Operator | Value type | Example | Meaning |
|----------|------------|---------|---------|
| `equals` / `==` | `mixed` | `['field' => 'status', 'operator' => 'equals', 'value' => 'active']` | Equal (type-tolerant: `'3'` = `3`, `'1'` = `true`) |
| `not_equals` / `!=` | `mixed` | `['field' => 'status', 'operator' => 'not_equals', 'value' => 'active']` | Not equal |
| `in` | `array` | `['field' => 'role', 'operator' => 'in', 'value' => ['admin', 'editor']]` | Current value is one of the list |
| `not_in` | `array` | `['field' => 'role', 'operator' => 'not_in', 'value' => ['guest']]` | Current value is not in the list |
| `empty` | `bool` | `['field' => 'vat', 'operator' => 'empty', 'value' => true]` | Current value is `null`, `''` or `[]` |
| `not_empty` | `bool` | `['field' => 'vat', 'operator' => 'not_empty', 'value' => true]` | Current value is not empty |
| `truthy` | `bool` | `['field' => 'has_details', 'operator' => 'truthy']` | Current value is truthy |
| `falsy` | `bool` | `['field' => 'has_details', 'operator' => 'falsy']` | Current value is falsy |
| `contains` | `string\|array` | `['field' => 'tags', 'operator' => 'contains', 'value' => 'featured']` | String contains the value, or array contains the value |
| `match` | `string` | `['field' => 'email', 'operator' => 'match', 'value' => '^[^@]+@.+$']` | PCRE match; delimiters optional (`/…/` also accepted) |

> [!TIP]
> Unknown operators fall back to `truthy`, so add validation to custom rules when in doubt.

### Example: Complex required_if

```php
'newsletter_frequency' => [
    'id'    => 'newsletter_frequency',
    'type'  => 'select',
    'label' => __('Newsletter Frequency', 'my-plugin'),
    'options' => [
        'weekly'  => 'Weekly',
        'monthly' => 'Monthly',
        'never'   => 'Never',
    ],
    'required_if' => [
        // Required if email is not empty AND newsletter toggle is ON
        ['field' => 'email', 'operator' => 'truthy'],
        ['field' => 'enable_newsletter', 'operator' => 'equals', 'value' => true],
    ],
],
```

---

## 19. Callback Signatures

### Custom sanitize callback

```php
/**
 * @param mixed $value Current value to sanitize
 * @param array $field Field definition
 * @return mixed Sanitized value
 */
function my_custom_sanitize($value, $field) {
    // Return sanitized value
    return sanitize_text_field($value);
}

'my_field' => [
    'id' => 'my_field',
    'type' => 'text',
    'sanitize_callback' => 'my_custom_sanitize',
]
```

### Custom validate callback

```php
/**
 * @param mixed $value Value to validate
 * @param array $field Field definition
 * @param array $state All form values
 * @return array|true Validation result: true for pass, [ 'error' => 'message' ] for fail
 */
function my_custom_validate($value, $field, $state) {
    if (empty($value)) {
        return true; // Let required rule handle it
    }
    
    if (strlen($value) < 3) {
        return ['error' => 'Value must be at least 3 characters'];
    }
    
    return true;
}

'my_field' => [
    'id' => 'my_field',
    'type' => 'text',
    'validate_callback' => 'my_custom_validate',
]
```

### Field bundle resolver

```php
function my_bundle_resolver(array $config, $registry) {
    $label_prefix = $config['label_prefix'] ?? '';
    
    return [
        [
            'id' => 'first_name',
            'type' => 'text',
            'label' => $label_prefix . ' First Name',
        ],
        [
            'id' => 'last_name',
            'type' => 'text',
            'label' => $label_prefix . ' Last Name',
        ],
    ];
}

$framework->register_field_bundle('name_pair', 'my_bundle_resolver');
```

---

## 20. Common Patterns & Recipes

### Pattern 1: Multi-step form with conditional sections

```php
$framework->add_tab([
    'id'    => 'onboarding',
    'label' => __('Onboarding', 'my-plugin'),
]);

$framework->add_section([
    'tab_id' => 'onboarding',
    'id'     => 'step1_business',
    'title'  => __('Business Type', 'my-plugin'),
]);

$framework->add_field([
    'tab_id'     => 'onboarding',
    'section_id' => 'step1_business',
    'id'         => 'business_type',
    'type'       => 'select',
    'label'      => __('Business Type', 'my-plugin'),
    'options'    => [
        'partnership'   => 'Partnership',
        'sole_trader'   => 'Sole Trader',
        'company'       => 'Limited Company',
    ],
]);

// Show only for partnerships
$framework->add_section([
    'tab_id'     => 'onboarding',
    'id'         => 'step2_partners',
    'title'      => __('Partner Details', 'my-plugin'),
    'conditions' => [
        ['field' => 'business_type', 'operator' => 'equals', 'value' => 'partnership'],
    ],
]);

$framework->add_field([
    'tab_id'       => 'onboarding',
    'section_id'   => 'step2_partners',
    'id'           => 'num_partners',
    'type'         => 'number',
    'label'        => __('Number of Partners', 'my-plugin'),
    'required_if'  => [
        ['field' => 'business_type', 'operator' => 'equals', 'value' => 'partnership'],
    ]
]);
```

### Pattern 3: Nested Conditions (AND / OR logic)

The `conditions` array supports nested groups with explicit `relation` keys (`AND` or `OR`). This allows you to build complex visibility logic.

```php
$framework->add_field([
    'tab_id'     => 'main',
    'section_id' => 'section_global',
    'id'         => 'custom_banner',
    'type'       => 'image',
    'label'      => __('Custom Banner', 'my-plugin'),
    'conditions' => [
        'relation' => 'OR', // Either condition must be true
        ['field' => 'display_style', 'operator' => 'equals', 'value' => 'custom'],
        [
            'relation' => 'AND', // Nested AND group
            ['field' => 'display_style', 'operator' => 'equals', 'value' => 'automatic'],
            ['field' => 'override_automatic', 'operator' => 'equals', 'value' => '1'],
        ]
    ]
]);
```

### Pattern 4: Geographic location form with cascading selects

```php
$framework->add_field([
    'tab_id'       => 'location',
    'section_id'   => 'address',
    'id'           => 'office_country',
    'type'         => 'country',
    'label'        => __('Country', 'my-plugin'),
    'required'     => true,
]);

$framework->add_field([
    'tab_id'        => 'location',
    'section_id'    => 'address',
    'id'            => 'office_region',
    'type'          => 'state',
    'label'         => __('Region / State', 'my-plugin'),
    'country_field' => 'office_country',
    'depends_on'    => ['office_country'],
    'required_if'   => [
        ['field' => 'office_country', 'operator' => 'truthy', 'value' => true],
    ],
]);

$framework->add_field([
    'tab_id'            => 'location',
    'section_id'        => 'address',
    'id'                => 'office_city',
    'type'              => 'city',
    'label'             => __('City', 'my-plugin'),
    'country_field'     => 'office_country',
    'subdivision_field' => 'office_region',
    'depends_on'        => ['office_country', 'office_region'],
]);
```

### Pattern 3: Email subscription with validation

```php
$framework->add_field([
    'tab_id'     => 'general',
    'section_id' => 'contact',
    'id'         => 'contact_email',
    'type'       => 'email',
    'label'      => __('Contact Email', 'my-plugin'),
    'required'   => true,
    'validate_callback' => function($value, $field, $state) {
        // Built-in email validation handles basic checks
        // Add custom checks here
        if (strpos($value, '+') !== false) {
            return ['error' => 'Email aliases (+) not supported'];
        }
        return true;
    },
]);

$framework->add_field([
    'tab_id'     => 'general',
    'section_id' => 'contact',
    'id'         => 'subscribe_newsletter',
    'type'       => 'toggle',
    'label'      => __('Subscribe to Newsletter', 'my-plugin'),
    'text'       => __('Yes, subscribe me', 'my-plugin'),
]);

$framework->add_field([
    'tab_id'      => 'general',
    'section_id'  => 'contact',
    'id'          => 'newsletter_frequency',
    'type'        => 'radio',
    'label'       => __('Newsletter Frequency', 'my-plugin'),
    'options'     => [
        'daily'   => 'Daily Digest',
        'weekly'  => 'Weekly Newsletter',
        'monthly' => 'Monthly Summary',
    ],
    'depends_on'     => ['subscribe_newsletter'],
    'required_if'    => [
        ['field' => 'subscribe_newsletter', 'operator' => 'equals', 'value' => true],
    ],
]);
```

### Pattern 4: Brand color palette configuration and swatch selector

```php
// Step 1: Define global brand palette when initializing framework
$config = [
    'option_name'       => 'my_project_options',
    'form_field_prefix' => 'my_project',
    'page_slug'         => 'my-project-settings',
    'color_palette'     => [
        '#10b981', // Emerald
        '#059669', // Dark Emerald
        '#d1fae5', // Pale Emerald
        '#0f172a', // Slate
        '#ffffff', // White
    ],
];

// Step 2: Use color field with global palette automatically inherited
$framework->add_field([
    'tab_id'     => 'styling',
    'section_id' => 'colors',
    'id'         => 'primary_color',
    'type'       => 'color',
    'label'      => __('Primary Color', 'my-plugin'),
    'default'    => '#10b981',
]);

// Step 3: Use color_palette field for 1-click theme presets
$framework->add_field([
    'tab_id'     => 'styling',
    'section_id' => 'colors',
    'id'         => 'theme_preset',
    'type'       => 'color_palette',
    'label'      => __('Theme Preset', 'my-plugin'),
    'default'    => '#10b981',
    'size'       => 'medium',
    'shape'      => 'round',
    'show_label' => true,
    'options'    => [
        '#10b981' => __('Emerald', 'my-plugin'),
        '#3b82f6' => __('Sapphire', 'my-plugin'),
        '#8b5cf6' => __('Amethyst', 'my-plugin'),
        '#0f172a' => __('Obsidian', 'my-plugin'),
    ],
]);
```

---

## 21. Troubleshooting & FAQ

### Issue: Fields not appearing

**Check:**
1. Is `init()` called on `plugins_loaded`?
2. Are tab/section IDs spelled consistently?
3. Are `$section_id` keys matching between `add_section` and `add_field`?
4. Run: `echo json_encode($framework->rest_api()->get_schema());` to inspect schema

### Issue: Clicking Save does nothing

**Cause:** a field renderer printed its own `<form>` markup. The browser drops the nested start tag but
honours its `</form>`, which closes the framework form and leaves `type="submit"` outside any form.

**Fix:** the framework strips `<form>` / `</form>` from field output and logs a `RL_FSBI_Logger::warn()`
naming the field (set `debug_level => 'debug'` to see it). Move the markup out of the field — a nested
form cannot work inside the settings page.

### Issue: Validation not firing

**Check:**
1. Is `required` or `required_if` set?
2. Is custom `validate_callback` returning true for valid, array for errors?
3. Check browser console for AJAX errors
4. Verify nonce is present: `wp_nonce_field('rl_options_save', 'rl_options_nonce')`

### Issue: Async options not loading

**Check:**
1. Is REST API registered? `get_transient('rl_options_rest_initialized')`
2. Is `options_provider` config correct? Should have `endpoint` key
3. Check Network tab: is `/wp-json/rl-options/v1/*` responding?
4. Is field `depends_on` correct?

### Issue: Geo fields (country/state/city) showing no options

**Check:**
1. Is REST API warmed? `get_transient('rl_options_geo_countries')`
2. Try clearing transients: `delete_transients_like('rl_options%')`
3. Check `RL_FSBI_Logger` debug output if `debug_level` is `debug`
4. Is data source responding? `curl https://restcountries.com/v3.1/all?fields=cca2,name,region,capital`

### Q: Can I use this outside WordPress admin?

**A:** No. Framework is designed for admin context only (tabs, admin styles, nonces). For frontend use, integrate only the field type classes.

### Q: Does framework track save history?

**A:** No. Use backup/restore features or hook into `rl_options_framework_settings_saved` (or `{option_name}_settings_saved`) instead.

### Q: Can I migrate from older framework versions?

**A:** Yes. Hook names changed; update any `my_plugin_settings_tabs` filters to `my_plugin_options_framework_tabs`. See Section 12.

### Q: How do I add custom CSS?

**A:** Use `'class'` and `'input_class'` on fields, or hook `wp_enqueue_scripts` to load theme CSS.

---

## 22. Error Messages Reference

### Validation errors

| Error | Trigger | Message |
|-------|---------|---------|
| `field_required` | `required: true` + empty | `"This field is required"` |
| `field_required_if` | `required_if` rules matched + empty | `"This field is required"` |
| `field_invalid_email` | `type: 'email'` + invalid format | `"Invalid email address"` |
| `field_invalid_url` | `type: 'url'` + invalid URL | `"Invalid URL"` |
| `field_invalid_phone` | `type: 'phone'` + invalid format | `"Invalid phone number"` |
| `field_invalid_nif` | `type: 'nif'` + invalid PT NIF | `"Invalid NIF"` |
| `field_invalid_date` | `type: 'date'` + invalid format | `"Invalid date (YYYY-MM-DD)"` |
| `field_pattern_mismatch` | `pattern` regex no match | `"Value does not match required format"` |
| `field_min_length` | text < minlength | `"Minimum length: N characters"` |
| `field_max_length` | text > maxlength | `"Maximum length: N characters"` |
| `field_min_value` | number < min | `"Minimum value: N"` |
| `field_max_value` | number > max | `"Maximum value: N"` |

### AJAX errors

| Error | Cause | Response |
|-------|-------|----------|
| `invalid_nonce` | Nonce missing/invalid | HTTP 403 + `"Security check failed"` |
| `insufficient_capability` | User can't manage options | HTTP 403 + `"Insufficient permissions"` |
| `invalid_payload` | Malformed request body | HTTP 400 + `"Invalid request"` |
| `save_failed` | `update_option()` returned false | HTTP 500 + `"Failed to save options"` |
| `validation_failed` | Field validation error | HTTP 422 + validation details |

---

## 23. Universal Logging

### What it is

The framework provides a unified logging utility that can be configured and consumed globally by the plugin that implements the framework, both on the PHP backend and JS frontend.

### PHP Logging (`RL_FSBI_Logger`)

You can set a custom prefix for all logs generated via the `RL_FSBI_Logger` class.

#### How to use

```php
// Set a custom prefix instead of the default '[RL Framework]'
RL_FSBI_Logger::set_prefix( '[My Plugin]' );

// Log messages
RL_FSBI_Logger::info( 'This is an info log.' );
RL_FSBI_Logger::error( 'This is an error log.', [ 'context' => 'data' ] );
```

### JS Logging (`window.rlFramework`)

The JS logging utility has been extracted to a **standalone script** (`rl-logger.js`) so that it can be loaded independently of the options page.

#### How to use

1. Set the `debug_field_id` in your framework config.
2. Add a native `toggle` field with that ID in your settings.

```php
$framework = new RL_FSBI_Options_Framework([
    // ...
    'debug_field_id' => 'enable_debug_mode',
]);

$framework->add_field([
    'id'      => 'enable_debug_mode',
    'type'    => 'toggle',
    'label'   => 'Enable Debug Mode',
    'default' => false,
]);
```

When this toggle is ON, the framework automatically enqueues the lightweight `rl-logger.js` on **all admin and frontend pages**. You can then safely log globally:

```javascript
// Set a custom prefix
window.rlFramework.setLogPrefix( 'My Plugin' );

// Log messages anywhere (will only output if debug is ON)
window.rlFramework.log( 'This is a debug log.' );
window.rlFramework.info( 'This is an info log.' );
window.rlFramework.warn( 'This is a warning.' );
window.rlFramework.error( 'This is an error.' );
```

---

## 24. Visual Schema Builder (RL Builder)

### What it is

An **opt-in** admin screen that lets you assemble tabs, sections and fields by drag and drop,
attach visibility conditions and `required_if` rules per field, then **export ready-to-paste PHP**.
It is a design aid for developers — it never writes to your source files, and it never touches the
saved option values (only its own draft option).

The builder is disabled by default so a drop-in never surprises a host plugin with an extra menu.

```php
$framework = new RL_FSBI_Options_Framework([
    'option_name' => 'my_plugin_settings',
    'page_slug'   => 'my-plugin-settings',
    'builder'     => true,                       // enables the builder menu
    // 'builder_page_slug' => 'my-plugin-builder', // optional custom slug
]);
```

| Setting | Value | Result |
|---|---|---|
| `builder` | `false` (default) | No menu, no assets, no AJAX endpoint |
| `builder` | `true` | Submenu **RL Builder** next to the options page at `admin.php?page={page_slug}-builder` |
| `builder_page_slug` | `''` (default) | Slug derives from `page_slug` |
| `builder_page_slug` | `'x'` | Slug is `sanitize_key( 'x' )` |

The page is registered with the same `capability` and `parent_menu` as the framework.

### What the screen does

| Area | Purpose |
|---|---|
| **Palette** (left) | All 47 registered field types grouped like the docs (`Text & numbers`, `Choice`, `Media`, `Design`, `Content & layout`, `Collections`, `Geo & dates`, `System`), with a filter box |
| **Canvas** (center) | Tabs → sections → fields. Drag chips in, drag rows to reorder, cross-section and cross-tab moves. Every field row shows the **real control** the settings page would render |
| **Inspector** (right) | Per-node form: id, label, description, default, required, width, class, plus the type-specific props (`options`, `min`/`max`/`step`, `rows`, `media_type`, `language`, `multiple`, `unit`, …) |
| **Rules editors** | `conditions` (AND/OR + field/operator/value rows) and `required_if` per field; tab and section visibility conditions too |
| **Export** (bottom) | The generated PHP in two formats, with copy and `.php` download |

Buttons in the toolbar:

| Button | Behaviour |
|---|---|
| **Undo / Redo** | Walks the in-memory history (60 snapshots) of the canvas model; also `Ctrl/⌘+Z` and `Ctrl/⌘+Shift+Z` |
| **Preview page** | WYSIWYG mode: hides the palette, the inspector and all builder chrome, and renders the canvas full width exactly like the settings page (the controls become interactive). Toggles back to **Edit mode** |
| **New** | Empties the canvas (the stored draft is only replaced when you press *Save draft*) |
| **Load current tabs** | Replaces the canvas with the tabs the framework is currently registering — a fast way to start from an existing schema |
| **Save draft** | `POST` to `wp_ajax_rl_of_{option_name}_builder_save` (nonce + capability checked); stored in the `{option_name}_builder_draft` option |
| **Export PHP** | Renders the export panel for the current canvas |

The status next to the buttons (**All changes saved** / **Unsaved changes**) compares the canvas with
the last successful draft save.

### WYSIWYG canvas

Each field row is rendered server-side with the same renderer the settings page uses, so a drag and
drop edit immediately shows the final markup (label + tooltip, control, required badge, width).

| Element | Behaviour |
|---|---|
| Preview request | `POST` `wp_ajax_rl_of_{option_name}_builder_field` with `field` (JSON) + `nonce`; answers `{ html }` |
| Batch request | The same action accepts `fields` (JSON array, max 200) and answers `{ fields: { id: html } }` — the canvas hydrates in chunks of 40, so a 100-field schema costs ~3 requests, not 100 |
| Caching | Responses are cached in memory by a hash of the field definition, so re-rendering 100 fields does not re-request unchanged ones |
| Debounce | 140 ms per field on incremental edits, so typing in the inspector does not flood the endpoint |
| Conditions | Fields whose `conditions` do not match the current defaults are dimmed and marked *Hidden on the real page until its conditions match* |
| `raw` / callback fields | Cannot be transported over JSON, so they show a muted *Rendered by PHP* note instead of markup |
| Interactions | Tabs and sections collapse from their caret; each row has duplicate/delete tools; `Ctrl/⌘+D` duplicates, `Delete` removes, `Esc` closes the inline picker |
| Quick add | Each section ends with **+ Add field**, an inline searchable picker that inserts on `Enter` |

In **Edit mode** the preview is `pointer-events: none` so it never steals a drag; in **Preview page**
mode it is interactive so you can click through the real controls.

On load, the builder hydrates from the stored draft when one exists, otherwise from the live tabs.
Local edits are also mirrored into `localStorage` (`rlBuilderDraft:{option_name}`) so an accidental
reload does not lose work.

### Export formats

**Format 1 — fluent calls** (`add_tab` / `add_section` / `add_field`):

```php
$framework->add_tab( 'general', [
	'label' => 'General',
	'priority' => 5,
] );

$framework->add_section( 'general', 'main', [
	'title' => 'Main',
	'priority' => 10,
] );

$framework->add_field( 'general', 'main', [
	'id' => 'headline',
	'type' => 'text',
	'label' => 'Headline',
	'description' => 'Shown above the fold.',
	'default' => 'Welcome',
	'required' => true,
	'width' => 'half',
	'conditions' => [
		'relation' => 'AND',
		'rules' => [
			[
				'field' => 'show_headline',
				'operator' => 'equals',
				'value' => '1',
			],
		],
	],
] );
```

**Format 2 — tabs filter array** (registers through `{option_name}_framework_tabs`):

```php
add_filter( 'my_plugin_settings_framework_tabs', function ( $tabs ) {
	return [
		'general' => [
			'label' => 'General',
			'priority' => 5,
			'sections' => [
				'main' => [
					'title' => 'Main',
					'fields' => [
						'headline' => [
							'id' => 'headline',
							'type' => 'text',
							'label' => 'Headline',
						],
					],
				],
			],
		],
	] + $tabs;
} );
```

Notes:

- `+ $tabs` keeps tabs registered elsewhere (other plugins/themes) working.
- Both formats are normalized by the same schema manager, so conditions, `required_if`, aliases and
  defaults behave exactly like hand-written code. Exported conditions use the canonical
  `conditions` shape (Section 17); `required_if` uses the operators of Section 18.
- Numeric props (`min`, `max`, `step`, `rows`, `max_rows`, `minlength`) are emitted as numbers,
  booleans (`required`, `multiple`, `accordion`) as `true`, and everything else as quoted strings.

### Draft storage and cleanup

| Item | Value |
|---|---|
| Option key | `{option_name}_builder_draft` |
| Stored shape | `{"tabs":[…]}` — the sanitized JS model |
| Autoload | `false` |
| AJAX action | `wp_ajax_rl_of_{option_name}_builder_save` |
| Nonce | `rl_of_{option_name}_builder_save_nonce` |

The draft is never read by the settings page; deleting the option simply resets the builder canvas.
Uninstall cleanup:

```php
delete_option( 'my_plugin_settings_builder_draft' );
```

### Service API

```php
$builder = $framework->builder();

$builder->is_enabled(): bool          // `builder` config flag
$builder->page_slug(): string         // resolved, sanitized slug
$builder->draft_key(): string         // {option_name}_builder_draft
$builder->save_action(): string       // rl_of_{option_name}_builder_save
$builder->field_action(): string      // rl_of_{option_name}_builder_field
$builder->get_draft(): array          // [ 'tabs' => [...] ]
$builder->sanitize_schema( array $schema ): array
```

`sanitize_schema()` is public because it is the same whitelist the AJAX endpoint applies: unknown
keys are dropped, ids are `sanitize_key()`-ed, text is sanitized, boolean props are cast, and
condition rules without a `field` are discarded. Use it when you accept a builder-style schema from
your own form, an import file or a REST call.

### Security

- Menu, page and AJAX handler all require the framework `capability`.
- The AJAX endpoints (draft save and field preview) verify the nonce **before** reading the payload and reject payloads over 1 MB.
- The preview endpoint whitelists the posted field through the same sanitizer as the draft, then renders it with `RL_FSBI_Options_Render_Service::render_single_field()`; it never returns unsanitized input.
- Stored data is the sanitized model only — the builder never echoes unsanitized input back.

---
