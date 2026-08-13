=== WP Guardrail ===
Contributors: Vladimir Radisic
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 8.0
Stable tag: 0.7.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Admin diagnostics toolkit: session-scoped plugin isolation, URL testing, conflict detection, and update simulation.

== Description ==

WP Guardrail is a local WordPress diagnostics and debugging toolkit for administrators.

Current features:

* **Sandbox Mode** — session-scoped plugin isolation scoped to your admin session only. Does not affect other users or public traffic.
* **URL Tester** — run baseline vs sandbox HTTP checks and compare request outcomes. Detects PHP fatals via an HMAC-signed trace mechanism.
* **Conflict Wizard** — guided binary-search workflow to identify which plugin causes a problem.
* **Update Simulator** — download and test plugin updates in sandbox before applying them site-wide.
* **Diagnostic Reports** — export a JSON or plain-text report of your session for support tickets.
* **Snapshots** — save and restore sandbox session state.
* **Performance Diagnostics** — read-only metrics: autoload size, cron backlog, PHP memory, transient count, and DB table sizes with OK / Warning / Critical status badges.
* **Safe Mode Hardening** — protected-plugins list, auto-detected risk flags for auth/security/caching plugins, and a one-time emergency recovery link that stops the sandbox session even if admin navigation is broken.

WP Guardrail does not globally deactivate plugins and does not affect other users or public traffic.

== Installation ==

1. Upload the `wp-guardrail` folder to `/wp-content/plugins/`.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. On activation, WP Guardrail writes a small bootstrap loader to `wp-content/mu-plugins/wp-guardrail-loader.php`. This is required for true sandbox isolation (see below). If the directory is not writable, you will see an admin notice with instructions.
4. Navigate to **WP Guardrail** in the admin menu.

== Sandbox isolation and the mu-plugin loader ==

For sandbox-disabled plugins to genuinely stop executing, WP Guardrail installs a bootstrap loader in `wp-content/mu-plugins/`. This loader registers a filter before WordPress loads any regular plugin, so disabled plugins are removed from the load list at the correct phase.

If `wp-content/mu-plugins/` is not writable by the web server, the loader cannot be installed. In that case, sandbox mode falls back to a display-only filter: the admin UI reflects the disabled state, but the plugin code may still run. An admin notice explains the situation on all WP Guardrail pages.

To resolve: grant write permissions to `wp-content/mu-plugins/`, then deactivate and reactivate WP Guardrail.

The loader file is automatically removed when the plugin is deactivated.