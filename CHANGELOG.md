# Changelog

## 1.1.2 — 2026-08-10

### Fixed

- Import completion now shows every native Bricks item with its explicit `imported`, `replaced`, or `skipped` status instead of hiding native outcomes behind a generic success message.
- Page outcomes are grouped as created, updated, skipped, and failed, with an accurate terminal summary and idempotent rendering across repeated progress callbacks.
- Preflight conflict rows now retain their action/status and message instead of displaying only the domain label.

### Tests

- Added backend result-normalization and browser completion-contract regressions; the isolated suite now passes 285/285 tests under `E_ALL`.

## 1.1.1 — 2026-08-10

### Fixed

- Admin imports now capture the selected ZIP before disabling the form controls, so the browser includes `bricks_ie_import_file` in the AJAX preflight request.

### Tests

- Added regression coverage for the browser upload ordering; the isolated suite now passes 283/283 tests under `E_ALL`.

## 1.1.0 — 2026-08-08

### Added

- Schema 2 archives using the audited Bricks 2.4-beta2 native unified-transfer contract (`bricks/unified-global-transfer` v1).
- Structurally inspected native package transport for Bricks global domains, plus Katsarov-owned JSON payloads for pages and supported non-template post types; native contents are not semantically rewritten.
- Mandatory no-write preflight, archive/plan hash binding, conflict planning, import leases, staged admin sessions, typed reference mapping, and CSS regeneration.
- Hardened ZIP validation, SHA-256 verification, sensitive-data policy, partial-result reporting, and exact WP-CLI controls.
- Verified release evidence: 282/282 isolated tests under `E_ALL`, plugin-wide PHP lint 24/24, passing Node/git-diff checks and root integration, and a disposable WordPress 7.0.3 / Bricks 2.4-beta2 / PHP 8.4.24 round trip with no CLI warnings or temporary ZIP leaks, frontend HTTP 200, clean logs, and cleanup.

### Changed

- Schema 2 is the default only when the audited native contract verifies; schema 1 remains the exact-version legacy fallback and compatibility path.
- Default conflicts are `skip`; replacement requires explicit overwrite authorization. Admin imports require JavaScript staging; non-JavaScript form submissions do not mutate.
- Templates are native-owned in schema 2. General media, template conditions, Style Manager/pseudo-class omissions, and builder workflow state remain outside schema 2.
- Native package structure, manifests, and hashes are inspected, but native-owned settings are not semantically rewritten. Export redaction is separate from import authorization: on Bricks 2.4-beta2, every incoming native settings selector requires `allow_sensitive_settings` because Bricks cannot filter keys inside its native settings payload. Schema 1 imports default-deny sensitive settings and strip nested remote-template credentials.
- Successful schema 1 and schema 2 exports atomically replace a destination only after the temporary archive is closed and stat'ed; schema 2 additionally completes validation before publication. Failures preserve the previous destination. CSS verification failures are partial and report `assets` in `failed`. Structured WP-CLI omissions no longer produce conversion warnings.
- Schema 2 authorized replacement now verifies removal of absent allowlisted Bricks meta, writes incoming meta, preserves unrelated meta, and regenerates CSS. Partial results report core post mutations and metadata mutations truthfully rather than implying complete success.

### Security

- Exports redact API keys, custom code, and template passwords by default. Import authorization is separate: native settings selectors require `allow_sensitive_settings`, while schema 1 nested remote-template credentials are redacted on export and default-denied (stripped) on import.
- Schema 1 legacy redaction recursively strips nested `apiKey`, `customCode`, `password`, and `pass` keys while preserving ordinary sibling values.
- Imported code remains unapproved for administrator review; no automatic code-signature approval or execution is performed.
- Archive quotas, unsafe-path/symlink checks, JSON limits, native package checksums, user-bound sessions, and site-wide import locking are enforced.

### Known limitations

- Compatibility is tested against Bricks 2.4-beta2 only. Bricks 2.4 stable requires revalidation.
- General media files are not transported; remote template image download is disabled.
- Imports are not fully transactional. Failures can leave partial changes, which are reported and require review.
- Missing dynamic/CPT records are skipped by default, and ambiguous or unresolved typed references cause the affected page to be skipped.

## 1.0.1 — Historical baseline

The 1.0.1 archive format is retained only as the schema 1 compatibility baseline; it is not the release target for new schema 2 exports.
