# WP Guardrail — Developer Changelog & Migration Notes

Internal changelog for developers. For the user-facing changelog, see `readme.txt`.

---

## 0.8.0 — Refactor Pass: Architecture Hardening

### Priority 1 — Admin page split

**Problem:** `class-wpg-admin-pages.php` had grown to ~1773 lines with all page rendering inlined inside a single `render_page()` switch. This made the file hard to navigate, diff, and extend.

**What changed:**
- Created `admin/pages/` directory with seven dedicated page render classes:
  - `WPG_Page_Dashboard`
  - `WPG_Page_Plugin_Isolation`
  - `WPG_Page_Url_Tester`
  - `WPG_Page_Conflict_Wizard`
  - `WPG_Page_Update_Simulator`
  - `WPG_Page_Reports`
  - `WPG_Page_Diagnostics`
- Each class exposes `static render( array $ctx ): void` and receives the full shared context array.
- `WPG_Admin_Pages::render_page()` is now a thin dispatcher: resolves shared context, opens the wrapper `<div>`, renders global UI (notice, mode banner, snapshot link), then switches on `$tab` and delegates.
- `render_diagnostics_section()` moved to `WPG_Page_Diagnostics::render_diagnostics_section()`.
- All routing helpers, `plugin_label()`, shared render helpers (notice, snapshot button, report section) remain in `WPG_Admin_Pages`.
- Page class files are loaded via `require_once` at the top of `class-wpg-admin-pages.php`.

**Backward compatibility:**
- All menu slugs, page slugs, tab slugs, nonce actions, and form actions are unchanged.
- `WPG_Admin_Pages::plugin_label()` remains public static — page classes call it.
- `WPG_Admin_Pages::render_snapshot_button()` and `render_report_section()` remain public static.

**Migration notes for callers:**
- If any external code calls `WPG_Admin_Pages::render_page()` directly (unusual), it continues to work.
- The `render_diagnostics_section()` method no longer exists in `WPG_Admin_Pages`. Use `WPG_Page_Diagnostics::render_diagnostics_section()` if calling externally.

---

### Priority 2 — Update simulator hardening

**Problem:** The simulator had no preflight validation, unstructured errors, and cleanup that returned a bare `true` without verifying deletions.

**What changed:**

**New: `run_preflight_checks( array $plugins ): array`**
Runs before the download/extract loop. Validates:
- Active sandbox session exists.
- Selected plugins have pending updates.
- Uploads base directory is writable.
- Shims subdirectory is creatable/writable.
- Each plugin has a package URL.

Returns `{ passed: bool, checks: [], errors: [] }` where each error has `code`, `summary`, `details`, `next`.

**New: Error code constants on `WPG_Update_Simulator`:**
```php
ERR_NO_PLUGINS, ERR_NO_PENDING, ERR_UPLOAD_DIR, ERR_SIM_DIR_CREATE,
ERR_NO_PACKAGE_URL, ERR_DOWNLOAD_FAILED, ERR_ZIP_OPEN_FAILED,
ERR_ENTRY_NOT_FOUND, ERR_FILESYSTEM_NOT_WRITE, ERR_SHIM_DIR_CREATE,
ERR_SHIM_WRITE_FAILED, ERR_NO_SESSION, ERR_SIM_NOT_ACTIVE,
ERR_NO_VALID_URLS, ERR_EXTRACT_ALL_FAILED
```

**Changed: `cleanup_simulation_artifacts()` return type**
Was: `bool` (always `true`).
Now: `array { attempted: string[], failed: string[] }`.
Failed paths are logged via `WPG_Logger` and surfaced in the UI.

**Changed: `cleanup_all_leftovers()` return type**
Same change: now returns `{ attempted, failed }` instead of `bool`.

**Changed: `disable_simulation()`**
Now stores the cleanup result in the session under `simulation.cleanup`.

**Changed: `enable_simulation()`**
Now accepts and stores a `preflight` key in the simulation state, used by the UI to display preflight error details.

**New: Limitation notice in `WPG_Page_Update_Simulator`**
Always-visible notice explaining the simulator's scope and limitations.

**New: Preflight error display in `WPG_Page_Update_Simulator`**
`render_preflight_errors()` renders structured error details from `simulation.preflight` when preflight failed.

**New: Run details table in simulation results**
Includes: tested URL, timestamp, preflight status, cleanup status, final pass/warning result.

**Migration notes:**
- Any code that checks `if ( cleanup_simulation_artifacts($hash) )` will need to update to `if ( empty( cleanup_simulation_artifacts($hash)['failed'] ) )`.
- `cleanup_all_leftovers()` return value change is the same.
- `WP_Error` codes from the simulator are now the structured constants (e.g. `WPG_Update_Simulator::ERR_DOWNLOAD_FAILED`) rather than arbitrary strings. If you were checking specific error codes, update those checks.

---

### Priority 3 — Generalized result store

**Problem:** `WPG_Result_Store` only supported two hard-coded result types (`url_test`, `url_comparison`). Adding a third type (e.g. simulation runs) would require duplicating the same pattern.

**What changed:**

**New: Generalized API**
```php
WPG_Result_Store::save_result( string $type, array $payload, array $meta = [] ): bool
WPG_Result_Store::get_results( string $type, array $args = [] ): array
WPG_Result_Store::set_results( string $type, array $results ): bool
WPG_Result_Store::clear_results( string $type ): void
```

**Storage key strategy:**
- `url_test` → `wpg_url_tests_{user_id}` (legacy key preserved, no data loss on upgrade)
- `url_comparison` → `wpg_url_comparisons_{user_id}` (legacy key preserved)
- Any new type → `wpg_results_{type}_{user_id}`

**Kept: All legacy wrapper methods**
`append_test`, `get_tests`, `set_tests`, `clear_tests`, `append_comparison`, `get_comparisons`, `set_comparisons`, `clear_comparisons` — all delegate to the generalized API. These are kept indefinitely.

**Migration notes:**
- No action required for existing code using the legacy wrapper methods.
- To store a new result type, call `WPG_Result_Store::save_result( 'your_type', $data )`.

---

### Priority 4 — Documentation rewrite

**What changed:**
- `README.md` — new user-facing product documentation (replaces the absent `README.md`).
- `docs/ARCHITECTURE.md` — internal developer reference (directory structure, service responsibilities, design decisions, known risks).
- `docs/DEVELOPER-CHANGELOG.md` — this file. Internal refactor history and migration notes.
- `readme.txt` — unchanged (WordPress.org-compatible format, includes prior changelog).

---

### Priority 5 — Automated test suite

**What changed:**
- `phpunit.xml` — standalone PHPUnit configuration targeting `tests/`.
- `tests/bootstrap.php` — WordPress function stubs enabling tests to run without a full WP install.
- `tests/WPG_TestCase.php` — base test case with per-test state reset helpers.
- `tests/test-result-store.php` — covers generalized API and legacy wrappers.
- `tests/test-session-manager.php` — covers session state structure.
- `tests/test-recovery.php` — covers recovery token logic.
- `tests/test-snapshot.php` — covers snapshot structure and restore.

**How to run tests:**
```bash
# From the plugin root:
composer require --dev phpunit/phpunit ^10
vendor/bin/phpunit
```
Or if PHPUnit is installed globally:
```bash
phpunit --config phpunit.xml
```

**Coverage targets:** Service-layer logic (result store, session state, recovery, snapshot). Admin rendering is explicitly excluded — UI tests are brittle and the rendering is covered by manual inspection.

---

## 0.7.0 — Folder / File Restructure

See `readme.txt` for the full 0.7.0 changelog entry. Summary:

- `includes/` replaced by `core/`, `modules/`, `admin/`.
- Class renames: `WPG_Plugin` → `WPG_Bootstrap`, `WPG_Session` → `WPG_Session_Manager`, `WP_Guardrail_Context` → `WPG_Container`, `WPG_Adminbar` → `WPG_Admin_Bar`.
- Admin layer split into `WPG_Admin` (coordinator), `WPG_Admin_Pages` (pages/routing), `WPG_Admin_Actions` (form handlers).
- Assets moved to `assets/css/` and `assets/js/`.

## 0.6.0 — Safe Mode Hardening

Added `WPG_Safety_Guard` (protected plugins, risk heuristics) and `WPG_Recovery` (emergency recovery link). See `readme.txt`.

## 0.5.0 — Performance Diagnostics

Added `WPG_Diagnostics` with five metric collectors. Added Diagnostics submenu page. See `readme.txt`.

## 0.4.0 — Conflict Detection Hardening

Added automated URL test per wizard step, confidence scoring, step history. See `readme.txt`.

## 0.3.0 — URL Tester Stabilization

Added `WPG_Result_Store`, fixed sandbox isolation scope, auth cookie forwarding. See `readme.txt`.

## 0.2.0 — Core Stabilization

Added mu-plugin bootstrap, CSRF fix on snapshot restore, per-request session cache, `WPG_Logger`, uploads directory protection. See `readme.txt`.

## 0.1.0

Initial MVP release.
