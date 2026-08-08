# Bricks Import & Export

**Release 1.1.0** exports and imports Bricks configuration through the WordPress admin or WP-CLI. It combines Bricks' native unified global-transfer package with a Katsarov-owned payload for pages and supported non-template post types.

The compatibility target audited for this release is **Bricks 2.4-beta2**, using native schema `bricks/unified-global-transfer` version `1`. Bricks 2.4 stable support requires revalidation; this release does not claim it.

## Requirements

- WordPress 6.0 or newer
- PHP 7.4 or newer
- `ZipArchive`, including callable `getExternalAttributesIndex()` support and `ZipArchive::OPSYS_UNIX` (the capability is available from PHP 5.6+/PECL zip 1.12.4, but is checked at runtime)
- Bricks 2.4-beta2 for schema 2; schema 1 retains its exact-version compatibility requirement
- An administrator (`manage_options`); native domains may also require their Bricks manager permissions, `upload_files`, SVG upload, or code-execution permission

## Archive schemas

### Schema 2 (default when audited native contract passes)

On a compatible target, the exporter verifies the Bricks native class, schema, version, transfer type IDs, and public routes before selecting schema 2. The native package is structurally inspected (including its ZIP structure, manifest, and hashes), then embedded without semantic rewriting or duplication of native-owned options.

Native domains are:

- color palettes, theme styles, classes, variables, custom fonts, icon manager, breakpoints
- global queries, components, templates, settings, and custom capabilities

Templates are native-owned in schema 2 and are never also written through the Katsarov post path. The Katsarov payload contains ordinary `page` records and configured Bricks-enabled non-template post types. New dynamic/catalog records are not created by default.

Schema 2 intentionally omits general media, template conditions, `bricks_style_manager`, global pseudo classes, and UI/workflow state such as Element Manager, font favorites, and locked/trashed classes. Font and icon assets may still travel inside the native package. Remote template image download is disabled.

If the audited native contract is unavailable or has drifted, automatic export falls back to schema 1 and reports the reason. An explicitly requested schema 2 export fails closed instead.

### Schema 1 (legacy compatibility)

Schema 1 remains the exact-version legacy fallback and import compatibility format. It carries the historical raw option and post layout, including templates, and uses base64-encoded PHP serialization for legacy meta. It is hardened with archive validation, allowlists, safe decoding, and the same sensitive-data policy; it is not a cross-version migration format.

## Coverage and security policy

Export redaction and import authorization are separate controls. Sensitive API keys, custom code, and template passwords are redacted by default from schema 1 and schema 2 exports; sensitive export opt-in is available through WP-CLI or a programmatic exporter request, while direct admin exports remain redacted by default. On Bricks 2.4-beta2, Bricks cannot filter keys inside the native settings payload, so all incoming native settings selectors require `allow_sensitive_settings`. Schema 1 legacy values are recursively redacted for nested `apiKey`, `customCode`, `password`, and `pass` keys while ordinary sibling values are preserved; nested remote-template credentials are also default-denied (stripped) on import. Other sensitive settings are retained only when explicitly authorized. Imported code is never automatically approved, signed, or executed; it requires administrator review. The plugin does not automatically regenerate code signatures.

Every import has a mandatory no-write preflight. The plan is bound to the exact SHA-256 archive hash and plan hash returned by preflight. The default conflict policy is `skip`; `replace` requires explicit overwrite authorization. Backup acknowledgement and warnings acknowledgement are required where applicable. Imports are not fully transactional: a failure can leave partial changes, which are reported with `completed`, `partial`, `failed`, `blocked`, or `cancelled` status. Partial reports distinguish core post mutations from metadata mutations and do not claim work that did not complete.

The archive validator checks ZIP paths, duplicates, symlinks, sizes, compression ratios, JSON depth, manifests, and the embedded native package hash. Temporary files, staged sessions, and import leases are cleaned up on handled completion/failure paths; an expired session is cleaned during subsequent preflight. A site-wide lease prevents concurrent imports.

Successful schema 1 and schema 2 exports atomically replace a destination only after the temporary archive is closed and stat'ed; schema 2 also completes its final validation before publication. If close, stat, validation, or publication fails, the previous destination is preserved.

## Page, CPT, and reference behavior

- Pages and supported Bricks-enabled CPT records are matched by post type and slug.
- Missing `page` records may be created; missing dynamic/CPT records are skipped unless explicitly enabled through the relevant filter.
- Core title/status updates are limited to approved post types; Bricks meta is limited to the configured allowlist.
- Typed post and native references are mapped only in known fields. Ambiguous or unresolved references fail closed for the affected page rather than using broad numeric replacement.
- Existing media references may be normalized, but media files are not transported and missing files are not downloaded.
- After native/content stages, Bricks CSS is regenerated through a verified public/native route. Generated CSS and cache files are not archived. CSS verification failures are partial failures and include `assets` in the failed-step report.

## Archive structure

Schema 1:

```text
bricks-export.zip
├── manifest.json
├── options/
│   ├── bricks_global_settings.json
│   └── … .json
└── posts/
    ├── index.json
    ├── page__home.json
    └── bricks_template__site-header.json
```

Schema 2:

```text
bricks-export-YYYY-MM-DD.zip
├── manifest.json
├── bricks/
│   ├── package.zip
│   └── package.sha256
└── katsarov/
    ├── export-warnings.json
    └── posts/
        ├── index.json
        ├── page__home.json
        └── product__catalog-item.json
```

`katsarov/template-conditions.json` is accepted only as a reviewed sidecar; release exports set template conditions to omitted and do not apply an untyped sidecar automatically.

## Installation

Copy the plugin to `wp-content/plugins/bricks-import-export`, then activate it:

```bash
wp plugin activate bricks-import-export
```

The admin screen is **Bricks → Import & Export** (or **Tools → Import & Export** when Bricks is inactive). Export is direct. Admin import is staged: JavaScript uploads and runs preflight, displays the plan, and then confirms it. The non-JavaScript form deliberately refuses mutation.

## WP-CLI

All commands require a real authorized WordPress user; use the actual administrator identity, for example `--user=administrator`. The plugin never selects or impersonates the first administrator.

```bash
# Export (default filename or an explicit path)
wp bricks export --user=administrator
wp bricks export /backups/bricks.zip --user=administrator

# Export with explicitly authorized sensitive settings (the sole plugin export flag)
wp bricks export /backups/bricks.zip --user=administrator --allow-sensitive-settings

# Mandatory no-write preflight
wp bricks import --file=/backups/bricks.zip --user=administrator --dry-run

# Import using default skip policy after backup acknowledgement
wp bricks import --file=/backups/bricks.zip --user=administrator \
  --backup-acknowledged --yes

# Replace only with explicit overwrite authorization
wp bricks import --file=/backups/bricks.zip --user=administrator \
  --conflict=replace --allow-overwrite --backup-acknowledged --yes

# Accept a warning-bearing preflight and explicitly authorize sensitive settings
wp bricks import --file=/backups/bricks.zip --user=administrator \
  --accept-warnings --allow-sensitive-settings --backup-acknowledged --yes
```

The sole plugin-specific export flag is `--allow-sensitive-settings`; without it, CLI exports are redacted like direct admin exports. Import flags are `--file=<path>`, `--dry-run`, `--conflict=skip|replace`, `--allow-overwrite`, `--allow-sensitive-settings`, `--backup-acknowledged`, `--accept-warnings`, and `--yes`. `--yes` skips only the interactive prompt; it does not bypass validation or policy. Dry-run prints the preflight report and performs no writes. WP-CLI exits non-zero for blocked, failed, partial, or cancelled imports, and for warning/backup/overwrite policy failures.

## Filters

The supported extension points are:

| Filter | Purpose |
| --- | --- |
| `bricks_ie_options` | Filter legacy option names |
| `bricks_ie_meta_keys` | Filter allowed Bricks post-meta keys |
| `bricks_ie_post_types` | Filter exported/imported post types |
| `bricks_ie_create_missing_post_types` | Permit creation of selected missing post types |
| `bricks_ie_update_post_fields_post_types` | Permit title/status updates for selected types |
| `bricks_ie_legacy_sensitive_settings_keys` | Adjust legacy sensitive-key policy |

Example:

```php
add_filter( 'bricks_ie_post_types', function ( $types ) {
    $types[] = 'my_bricks_cpt';
    return $types;
} );
```

## Architecture

```text
Admin UI / WP-CLI
        ↓
Exporter / Importer
   ├── Archive Validator       no-write validation, quotas, hashes
   ├── Bricks Transfer Adapter native abilities/public contract
   └── Page/CPT orchestration  typed mappings, leases, CSS regeneration
```

```text
bricks-import-export.php          Bootstrap, hooks, AJAX endpoints, CLI registration
includes/class-bricks-exporter.php       Schema 1/2 archive creation
includes/class-bricks-importer.php       Preflight, sessions, import state machine
includes/class-archive-validator.php      ZIP/schema/resource validation
includes/class-bricks-transfer-adapter.php Native Bricks transfer boundary
includes/class-admin-page.php             Admin UI and staged import surface
includes/class-cli-command.php            `wp bricks export/import`
assets/admin.js                   AJAX preflight, confirmation, progress, cleanup UI
assets/admin.css                  Admin presentation
```

## Testing

The isolated suite contains **282 tests** and passes **282/282 under `E_ALL`**. A fully disposable integration run passes on **WordPress 7.0.3, Bricks 2.4-beta2, and PHP 8.4.24**: schema 2 authorized replacement removes absent allowlisted Bricks meta, writes incoming meta, preserves unrelated meta, and regenerates CSS; schema 1 recursively strips nested `apiKey`, `customCode`, `password`, and `pass` while preserving ordinary siblings. The run also verifies no CLI warnings or temporary ZIP leaks, frontend HTTP 200, clean logs, and cleanup. Plugin-wide PHP lint remains **24/24**; the Node check, git diff check, and root `tests/integration.sh` pass. Bricks 2.4 stable remains unclaimed and requires revalidation; PHP 7.4 runtime execution and builder UI interaction are not claimed.

## License

GPL-2.0-or-later. Copyright © 2026 Katsarov Design.
