# WP Guardrail

A local WordPress diagnostics and safe-testing toolkit for site administrators.

---

## What it does

WP Guardrail gives WordPress administrators a safe, session-scoped environment for diagnosing plugin conflicts, testing updates, and inspecting site health — without affecting other users or public traffic.

Everything WP Guardrail does is scoped to your browser session. Changes you make in the sandbox do not affect live visitors and reset completely when your session ends or you stop the sandbox.

---

## Who it is for

- WordPress site administrators diagnosing plugin conflicts or performance issues.
- Developers testing plugin updates before applying them site-wide.
- Support engineers investigating issues on production sites where disruption must be avoided.

---

## Core features

| Feature | What it does |
|---|---|
| **Sandbox Mode** | Session-scoped plugin isolation. Disable plugins only for your session, leaving all other users unaffected. |
| **Conflict Wizard** | Guided binary-search workflow to identify which plugin causes a reported problem in the fewest steps. |
| **URL Tester** | Run HTTP tests against your site in baseline or sandbox mode and compare results side-by-side. Detects PHP fatals via HMAC-signed traces. |
| **Update Simulator** | Download and smoke-test plugin updates in your sandbox session before applying them site-wide. |
| **Plugin Isolation** | Manage a protected-plugins list. Flagged plugins (auth, security, caching) are highlighted to prevent accidental disabling. |
| **Performance Diagnostics** | Read-only snapshot of autoload size, cron backlog, PHP memory, transient count, and DB table sizes. |
| **Snapshots** | Save and restore your sandbox session state, including disabled plugins, wizard progress, and URL test results. |
| **Emergency Recovery** | Generate a one-time recovery link that stops your sandbox session even if admin navigation is broken. |
| **Diagnostic Reports** | Export a full JSON or plain-text diagnostic report for support tickets. |

---

## How sandbox mode works

When you start Sandbox Mode, WP Guardrail:

1. Captures the current list of active plugins as your **baseline**.
2. Sets a session cookie (`wsm_token`) scoped to your browser.
3. Any plugins you disable in the Plugin Isolation tab are removed from your load list **only for requests carrying your session cookie**.

Public visitors and other logged-in users see the fully active site. Only you see the sandbox configuration.

### The mu-plugin bootstrap

True sandbox isolation requires a small bootstrap loader at `wp-content/mu-plugins/wp-guardrail-loader.php`. This loader registers the isolation filter before WordPress loads any regular plugin (priority 0), ensuring disabled plugins genuinely never execute.

If `wp-content/mu-plugins/` is not writable, sandbox mode falls back to a display-only filter. The admin UI will show an orange notice explaining the limitation.

**To resolve:** grant write permissions to `wp-content/mu-plugins/`, then deactivate and reactivate WP Guardrail.

---

## How safe mode works

WP Guardrail includes several layers of safety hardening:

- **Protected plugins** — admin-curated list of plugins that cannot be disabled from the sandbox, regardless of what is submitted.
- **Flagged plugins** — heuristic detection of ~30 known authentication, security, and caching plugin patterns. Flagged plugins display an amber "⚠ Risky" badge as a warning.
- **Emergency recovery link** — a single-use, 24-hour-TTL URL that stops the sandbox session and restores all plugins, even if you cannot reach the admin dashboard.

Generate the recovery link from the Dashboard before starting any risky operations, and copy it to a safe location.

---

## How snapshots work

A snapshot captures your current sandbox state, including:

- Which plugins are disabled.
- The current Conflict Wizard state and history.
- Your saved URL test results.

Snapshots are stored as transients with a 6-hour TTL and generate a shareable restore link. Restoring a snapshot replaces your current session state.

---

## How update simulation works

1. Start a sandbox session.
2. Navigate to **Update Simulator** and select one or more plugins with pending updates.
3. Enter one or more test URLs (relative paths or same-host URLs).
4. Click **Run Update Simulation**.

WP Guardrail will:

1. Download the update package(s) from WordPress.org.
2. Extract them to a temporary directory inside `wp-content/uploads/wp-guardrail/sim/`.
3. Create shim loader files that redirect plugin loading to the extracted new versions.
4. Run HTTP comparisons — baseline (current version) vs simulated (new version) — for each test URL.
5. Report any differences in HTTP status, response time, or detected PHP errors.

---

## Important limitations

The Update Simulator is a **compatibility smoke test**, not a full staging environment.

**What it checks:**
- Whether the tested URLs return the same HTTP status codes.
- Whether the simulated version introduces PHP fatals or errors on those URLs.
- Response time differences between baseline and simulated versions.

**What it does not check:**
- Database schema migrations — if a plugin update requires a DB migration, the simulation will not run it.
- JavaScript or visual correctness — HTTP comparisons cannot detect frontend rendering issues.
- Exact filesystem path dependencies — plugins that hardcode their own directory paths may not behave accurately under the shim.
- Full feature compatibility — the simulation only exercises the URLs you provide.

**A passing result means:** the tested URLs responded without HTTP errors or detected fatals under the simulated version. It does not mean the update is safe to apply in all scenarios.

**Always take a full site backup before applying real updates.**

---

## Typical workflows

### Diagnosing a plugin conflict

1. Navigate to **WP Guardrail → Dashboard** and click **Start Sandbox**.
2. Open **Conflict Wizard**, enter the URL where you can reproduce the issue, and click **Start**.
3. Follow the wizard steps — each step disables half the plugin pool and asks whether your issue persists.
4. The wizard identifies the most likely conflicting plugin in log₂(n) steps.

### Testing a plugin update

1. Start a sandbox session.
2. Navigate to **Update Simulator**.
3. Select the plugin(s) to test and enter the test URLs.
4. Review the simulation results. If no issues are detected, proceed with the real update.

### Investigating a slow site

1. Navigate to **WP Guardrail → Diagnostics** (no sandbox required).
2. Review the autoload size, cron backlog, memory usage, and transient counts.
3. Use **Download Report** to export a full diagnostic snapshot.

---

## Safety and recovery notes

- Sandbox Mode affects only your browser session. Other users are never affected.
- If you get stuck (e.g., the disabled plugins break your admin session), use the emergency recovery link to immediately restore all plugins.
- The mu-plugin bootstrap is automatically removed when WP Guardrail is deactivated.
- Simulation artifacts (extracted packages and shim files) are cleaned up after each simulation run. Use **Clear Leftover Data** if any artifacts remain.

---

## Installation

1. Upload the `wp-guardrail` folder to `/wp-content/plugins/`.
2. Activate via **Plugins → Installed Plugins**.
3. Navigate to **WP Guardrail** in the admin menu.

On activation, WP Guardrail writes a bootstrap loader to `wp-content/mu-plugins/wp-guardrail-loader.php`. If the directory is not writable, an admin notice explains the fallback behavior.

**Requirements:** WordPress 6.0+, PHP 8.0+.

---

## Development status

WP Guardrail is under active development. Current version: **0.8.0** (refactor pass).

This is a local diagnostics tool. It does not send data to external services and requires no subscription or API keys.
