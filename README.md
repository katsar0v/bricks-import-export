<h1 align="center">Bricks Import & Export</h1>

<p align="center">
  Export and import your Bricks Builder configuration — settings, Style Manager, theme styles, global classes, variables, pages, and templates — as a single zip archive. Supports both the WordPress admin UI and WP-CLI.
</p>

<p align="center">
  <img alt="Version 1.0.0" src="https://img.shields.io/badge/version-1.0.0-1f6feb?style=for-the-badge">
  <img alt="WordPress 6.0+" src="https://img.shields.io/badge/WordPress-6.0%2B-21759b?style=for-the-badge&logo=wordpress&logoColor=white">
  <img alt="PHP 7.4+" src="https://img.shields.io/badge/PHP-7.4%2B-777bb4?style=for-the-badge&logo=php&logoColor=white">
  <img alt="License GPL-2.0-or-later" src="https://img.shields.io/badge/license-GPL--2.0--or--later-0f766e?style=for-the-badge">
</p>

## Overview

Bricks Import & Export adds a dedicated submenu page under Bricks in the WordPress admin for one-click site backups and migrations. It bundles Bricks settings, Style Manager data, theme styles, global classes, global variables, color palettes, components, query manager data, global elements, pages, and templates into a portable zip archive. The same archive can be restored on any WordPress site running the same Bricks version.

The plugin is intentionally lightweight — no external dependencies, no database tables, and no build step. Everything is plain PHP using the native `ZipArchive` extension and standard WordPress APIs.

## Highlights

| Area | What it gives you |
| --- | --- |
| Export | Downloads a zip containing Bricks global options, all pages, and all Bricks templates with their full meta. |
| Import | Uploads and restores a previously exported archive, remapping post IDs and regenerating Bricks CSS/code signatures where possible. |
| Manifest | Every archive includes a `manifest.json` with plugin version, Bricks version, and export date for version-safe imports. |
| Admin UI | Clean export/import screen registered as the last submenu item under Bricks. |
| WP-CLI | `wp bricks export` and `wp bricks import` for scripted backups, migrations, and CI/CD pipelines. |
| Filters | Three action hooks let you extend the exported options, meta keys, and post types without modifying the plugin. |
| Security | All admin actions are protected by `manage_options` capability checks and WordPress nonces. |

## Requirements

| Requirement | Version |
| --- | --- |
| WordPress | 6.0 or newer |
| PHP | 7.4 or newer |
| PHP extension | `ZipArchive` (enabled by default on most hosts) |
| Theme | Bricks Builder (any version — version is recorded in the manifest) |
| Permissions | Administrator role |

## What Gets Exported

**Options**

| Option | Contains |
| --- | --- |
| `bricks_global_settings` | Global Bricks builder settings. |
| `bricks_theme_styles` | Registered theme styles and their element overrides. |
| `bricks_global_classes` | All global CSS classes and their style definitions. |
| `bricks_color_palette` | Custom color palette swatches. |
| `bricks_style_manager` | Style Manager settings such as modes and scales. |
| `bricks_global_variables` | Global variables used in Bricks styles. |
| `bricks_global_variables_categories` | Variable categories, scale metadata, and utility-class configuration. |
| `bricks_components` | Bricks components. |
| `bricks_global_queries` | Query Manager global queries. |
| `bricks_global_queries_categories` | Query Manager categories. |
| `bricks_global_elements` | Bricks global elements. |
| `bricks_global_classes_categories` | Global class categories. |
| `bricks_global_classes_locked` | Locked global class state. |
| `bricks_global_classes_trash` | Global class trash state. |
| `bricks_global_pseudo_classes` | Custom pseudo selectors. |
| `bricks_breakpoints` | Custom Bricks breakpoints. |
| `bricks_icon_sets` | Icon set registry data. |
| `bricks_custom_icons` | Custom icon registry data. |
| `bricks_disabled_icon_sets` | Disabled icon set state. |
| `bricks_font_favorites` | Builder font favorites. |
| `bricks_sidebars` | Bricks sidebars. |
| `bricks_element_manager` | Element Manager state. |

Media-backed custom font files, uploads, generated CSS/cache markers, remote template cache, and builder UI preferences are not bundled.

**Post types**

| Post type | Description |
| --- | --- |
| `page` | All WordPress pages with Bricks content meta. |
| `bricks_template` | All Bricks templates (headers, footers, sections, popups, etc.). |

**Post meta** — `_bricks_page_content_2`, `_bricks_page_header_2`, `_bricks_page_footer_2`, `_bricks_editor_mode`, `_bricks_template_type`, `_bricks_template_settings`, `_bricks_page_settings`

## Archive Structure

```text
bricks-export-YYYY-MM-DD.zip
├── manifest.json              Plugin version, Bricks version, export timestamp
├── options/
│   ├── bricks_global_settings.json
│   ├── bricks_theme_styles.json
│   ├── bricks_global_classes.json
│   ├── bricks_color_palette.json
│   ├── bricks_style_manager.json
│   ├── bricks_global_variables.json
│   └── ...
└── posts/
    ├── index.json             Slug, type, and filename for every exported post
    ├── page__home.json
    ├── page__about.json
    └── bricks_template__site-header.json
```

## Installation

Place the plugin directory inside WordPress:

```bash
wp-content/plugins/bricks-import-export
```

Activate the plugin in WordPress:

```bash
wp plugin activate bricks-import-export
```

You can also activate it from **Plugins** in the WordPress admin.

## Usage

### Admin UI

1. Open **Bricks → Import & Export** in the WordPress admin sidebar.
2. Click **Export** to download the current Bricks state as a zip archive.
3. To restore, choose the zip file under **Import** and click **Import**.

### WP-CLI

**Export**

```bash
# Export to the current directory with a date-stamped filename
wp bricks export

# Export to a specific path
wp bricks export /backups/bricks-backup.zip
```

**Import**

```bash
# Import with a confirmation prompt
wp bricks import --file=bricks-export-2026-05-12.zip

# Import without prompting (useful in CI/CD pipelines)
wp bricks import --file=bricks-export-2026-05-12.zip --yes
```

## Filters

Three filters let you extend the export/import scope without editing the plugin.

**`bricks_ie_options`** — add or remove WordPress option names:

```php
add_filter( 'bricks_ie_options', function ( $options ) {
    $options[] = 'my_custom_bricks_option';
    return $options;
} );
```

**`bricks_ie_meta_keys`** — add or remove post meta keys:

```php
add_filter( 'bricks_ie_meta_keys', function ( $keys ) {
    $keys[] = '_my_custom_bricks_meta';
    return $keys;
} );
```

**`bricks_ie_post_types`** — add or remove exported post types:

```php
add_filter( 'bricks_ie_post_types', function ( $types ) {
    $types[] = 'my_custom_post_type';
    return $types;
} );
```

## Architecture

```text
bricks-import-export.php          Plugin bootstrap, admin_post handlers, WP-CLI registration
includes/
  class-bricks-exporter.php       Builds the zip archive (options, posts, manifest)
  class-bricks-importer.php       Restores a zip archive, remaps post IDs
  class-admin-page.php            Registers and renders the Bricks submenu page
  class-cli-command.php           WP-CLI `wp bricks export` and `wp bricks import` commands
assets/
  admin.css                       Styles for the admin Import & Export screen
```

## License

Bricks Import & Export is licensed under `GPL-2.0-or-later`.

Copyright (C) 2026 Katsarov Design.
