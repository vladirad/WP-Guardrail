# WP Guardrail — Architecture Notes

Internal reference for developers maintaining or extending the plugin.

---

## Directory structure

```
wp-guardrail/
├── wp-guardrail.php              Main plugin file. Defines constants, loads all classes, registers hooks.
├── README.md                     User-facing product documentation.
├── readme.txt                    WordPress.org-compatible plugin description and changelog.
│
├── core/                         Shared runtime infrastructure — no WordPress admin dependency.
│   ├── class-wpg-bootstrap.php   Plugin lifecycle (activation, deactivation, mu-plugin management).
│   ├── class-wpg-session-manager.php  Session start/stop/apply. Owns cookie and transient I/O.
│   ├── class-wpg-container.php   Singleton. Per-request session cache and central context.
│   ├── class-wpg-logger.php      Session-scoped ring-buffer logger (50 entries, info/warning/error).
│   ├── class-wpg-result-store.php  Generalized per-user result persistence layer.
│   ├── class-wpg-report.php      Builds JSON/text diagnostic reports from session state.
│   └── class-wpg-snapshot.php    Creates and restores shareable session snapshots.
│
├── modules/                      Feature modules — one subdirectory per feature.
│   ├── isolation/
│   │   └── class-wpg-virtual-plugins.php   Display-layer filter; fallback isolation when mu-plugin absent.
│   ├── url-tester/
│   │   └── class-wpg-url-tester.php        HMAC-signed URL tests with error capture.
│   ├── conflict-detector/
│   │   └── class-wpg-conflict-wizard.php   Binary-search conflict isolation workflow.
│   ├── update-simulator/
│   │   └── class-wpg-update-simulator.php  Preflight-hardened download/extract/shim/test flow.
│   ├── performance-analyzer/
│   │   └── class-wpg-diagnostics.php       Read-only performance metrics collector.
│   └── safe-mode/
│       ├── class-wpg-safety-guard.php      Protected-plugins list and risk heuristics.
│       └── class-wpg-recovery.php          Emergency recovery token (single-use, 24h TTL).
│
├── admin/                        WordPress admin layer.
│   ├── class-wpg-admin.php       Thin coordinator. Owns SLUG_* constants. Wires hooks.
│   ├── class-wpg-admin-pages.php Menu registration, routing helpers, shared render helpers.
│   │                             Dispatches to page render classes in admin/pages/.
│   ├── class-wpg-admin-actions.php  All POST/GET action handling (20+ action cases).
│   ├── class-wpg-admin-bar.php   Admin bar integration.
│   ├── class-wpg-ui.php          Shared UI component helpers.
│   └── pages/                    One class per admin page, each exposing static render($ctx).
│       ├── class-wpg-page-dashboard.php
│       ├── class-wpg-page-plugin-isolation.php
│       ├── class-wpg-page-url-tester.php
│       ├── class-wpg-page-conflict-wizard.php
│       ├── class-wpg-page-update-simulator.php
│       ├── class-wpg-page-reports.php
│       └── class-wpg-page-diagnostics.php
│
├── assets/
│   ├── css/admin.css
│   └── js/admin.js
│
├── tests/                        Automated test suite (PHPUnit, standalone WP stub bootstrap).
│   ├── bootstrap.php             WordPress function stubs for testing without a WP install.
│   ├── WPG_TestCase.php          Base test case.
│   ├── test-result-store.php     WPG_Result_Store generalized API tests.
│   ├── test-session-manager.php  Session state tests.
│   ├── test-recovery.php         Recovery token tests.
│   └── test-snapshot.php         Snapshot structure tests.
│
└── docs/
    ├── ARCHITECTURE.md           This file.
    └── DEVELOPER-CHANGELOG.md    Internal refactor history and migration notes.
```

---

## Core services

### WPG_Bootstrap

Manages the plugin lifecycle. On activation, writes `wp-content/mu-plugins/wp-guardrail-loader.php`. On every admin page load, self-heals the loader if it is missing. On deactivation, removes the loader and clears the recovery token.

The mu-plugin registers an `option_active_plugins` filter at priority 0, before any regular plugin loads. This is the correct phase for sandbox isolation and simulation shimming.

### WPG_Container (singleton)

Central per-request context. Reads the session transient at most once per page load, caches in memory. `get_session()` is a pure reader. `update_session()` writes to both the transient and the in-memory cache. Validates token from `$_COOKIE['wsm_token']`.

### WPG_Session_Manager

Owns session start/stop/apply logic. Session token is a 64-character alphanumeric string stored as a transient under `wsm_` + MD5(token), with a 2-hour TTL. The `apply_disabled()` method computes the new disabled list from the baseline, filtering out any protected plugins.

### WPG_Result_Store

Generalized per-user result persistence. Two legacy types (`url_test`, `url_comparison`) retain their original transient keys for backward compatibility. New types (e.g. `sim_run`) use the normalized `wpg_results_{type}_{user_id}` key. Ring buffer, 50 items max, 24-hour TTL.

---

## How sandbox/session/recovery interact

```
Admin browser
    |
    | wsm_token cookie
    v
WPG_Container::get_session()
    |— reads transient once per request
    |— validates user_id, authorization, expiry
    |
    v
mu-plugin loader (priority 0, before plugins load)
    |— reads same transient directly (no WordPress stack yet)
    |— filters option_active_plugins to remove disabled plugins
    |— loads simulation shims if simulation active
    |
    v
WordPress loads remaining plugins normally
```

Recovery flow:
1. Admin generates a recovery token via Dashboard.
2. Token stored in `wpg_recovery_token` option (non-autoloaded, 24h TTL).
3. Admin navigates to `?wpg_recover=TOKEN` (can be done outside WP admin).
4. `WPG_Recovery::maybe_handle_recovery()` fires at `init` priority 0.
5. Validates token with `hash_equals()`, stops session, deletes token, redirects.

---

## How the update simulator works internally

1. **Preflight** — `run_preflight_checks()` validates: session active, plugins have pending updates, uploads dir writable, shims dir creatable, all plugins have package URLs.
2. **Download** — `download_url()` fetches each package ZIP to a temp file.
3. **Extract** — `unzip_file()` extracts to `wp-content/uploads/wp-guardrail/sim/{hash}/{plugin_slug}/`.
4. **Entry file** — `find_file_recursive()` locates the main plugin PHP file within the extracted tree.
5. **Shims** — `create_shims()` writes `require_once` wrapper files to `wp-content/uploads/wp-guardrail/shims/{hash}-{md5_suffix}.php`.
6. **Enable** — `enable_simulation()` writes the simulation state (hash, plugin list, shim paths) into the session transient.
7. **Test** — `run_simulation_tests()` calls `WPG_URL_Tester::run_compare()` for each URL. Baseline uses the current plugin version; sandbox uses the shim (new version).
8. **Cleanup** — `cleanup_simulation_artifacts()` returns a structured `{attempted, failed}` report. Stale artifacts are surfaced in the UI.

### Known simulator limitations

- Requires the WordPress.org package URL to be non-empty (premium plugins often omit this).
- Plugins that hardcode `WP_PLUGIN_DIR . '/' . plugin_basename()` paths may not resolve correctly under the shim.
- Database migrations are not executed.
- The simulation directory is publicly inaccessible (`.htaccess` deny + `index.php` silence), but this relies on Apache/LiteSpeed. Nginx setups should ensure directory listing is disabled.

---

## Result store architecture

```
WPG_Result_Store::save_result( 'url_test', $payload )
  → transient key: wpg_url_tests_{user_id}          (legacy)

WPG_Result_Store::save_result( 'url_comparison', $payload )
  → transient key: wpg_url_comparisons_{user_id}    (legacy)

WPG_Result_Store::save_result( 'sim_run', $payload )
  → transient key: wpg_results_sim_run_{user_id}    (new)
```

All types: ring buffer, newest first, 50 items, 24h TTL.

Legacy wrapper methods (`append_test`, `get_tests`, etc.) delegate to the generalized API. They are kept indefinitely for compatibility with any external callers.

---

## Known risks and current limitations

| Risk | Status |
|---|---|
| Simulator fidelity for premium plugins (no package URL) | Preflight check added, fails early with clear error |
| Simulator shim path assumptions | Documented limitation in UI and README |
| mu-plugin missing / fallback isolation | Admin notice on all plugin pages |
| Session cookie lost mid-session | TTL is 2 hours; recovery link mitigates |
| Cleanup failure leaving stale sim artifacts | Cleanup now returns structured report; UI warns if failed |
| Test coverage of admin rendering | Not covered (UI tests omitted intentionally; service layer covered) |
| No database migration support in simulator | Documented limitation |

---

## Design decisions

**Why static classes?** The plugin was built with a static-method pattern throughout (consistent with WordPress conventions). Adding constructor injection would require a significant rewrite with limited benefit for a single-site admin tool of this scope.

**Why transients instead of custom tables?** Keeps the storage footprint minimal and avoids requiring `dbDelta` migrations. The 24-hour TTL fits the usage pattern well. A custom table would be warranted if result history needed to persist across users or for longer periods.

**Why the mu-plugin bootstrap?** The `option_active_plugins` filter must fire before any regular plugin loads. `init` priority 1 (the originally used hook) fires too late. The mu-plugin runs at PHP include time, which is the only reliable way to achieve true isolation.

**Why not use WP_Filesystem for sim directory operations?** The update simulator needs to write to `wp-content/uploads/` which is always web-server writable. `WP_Filesystem` adds credential prompts that would break the flow. Direct `file_put_contents`/`unlink`/`rmdir` with path traversal guards is appropriate here.
