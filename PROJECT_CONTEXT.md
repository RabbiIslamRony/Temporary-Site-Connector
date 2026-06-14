# Temporary Site Connector Project Context

## Purpose

Temporary Site Connector is a small, single-file WordPress admin plugin for short-lived live-site debugging. It lets an admin generate temporary Application Password based curl commands, collect safe site diagnostics through authenticated REST endpoints, and remove the connector after the debugging session.

The plugin is designed for Codex-assisted WordPress debugging where SSH or WP-CLI access is not always available.

## Safety Boundaries

- Admin-only access: all admin UI and REST endpoints require `manage_options`.
- No public endpoints.
- No shell command runner.
- No SQL command runner.
- No file editor.
- No plugin/theme activation or deactivation endpoint.
- No SSH, database password, private key, token, or plaintext Application Password storage.
- Plugin-created Application Passwords are tracked by UUID only and can be revoked manually or cleaned on uninstall.
- Debug logging changes are reversible only when this plugin made those changes.

## Main Capabilities

- Generate temporary connector credentials for the current admin user.
- Copy ready-to-run curl commands for Codex or terminal usage.
- Persist generated command text in browser `sessionStorage` after admin page reload.
- Enable temporary debug logging with a `wp-config.php` backup.
- Disable only plugin-owned debug logging changes.
- Show detected site info, plugin list, theme list, and content counts.
- Provide an Issue Snapshot JSON report for quick debugging.
- Parse recent `debug.log` entries into fatal/error/warning/deprecated/notice summaries.
- Include conditional Elementor and Directorist context when those plugins are present.

## REST Endpoints

Base namespace: `/wp-json/wp-cli-helper/v1`

- `GET /health`: Auth and connector health check.
- `GET /diagnostics`: Environment, paths, debug constants, capabilities, plugins/themes/counts.
- `GET /plugins`: Installed plugin inventory with active states.
- `GET /theme`: Active and installed themes.
- `GET /options`: Safe selected options and largest autoloaded options.
- `GET /cron`: Upcoming and overdue WP-Cron events.
- `GET /transients`: Recent transient sample and value sizes.
- `GET /debug-log?lines=300`: Tail of `debug.log`.
- `GET /snapshot?lines=300`: Bundled read-only debug report for Codex.
- `GET /context`: Connector documentation and workflow hints.
- `GET /search?q=term&limit=10`: Read-only content search.
- `POST /ask`: Bundle a debugging question with selected site context.
- `GET /commands`: Copy-ready command examples.

## Typical Debug Workflow

1. Install and activate the plugin on the client site.
2. Open `Tools > Temporary Site Connector`.
3. Enable debug logging only if needed.
4. Generate and copy connector commands.
5. Run `/health` first to confirm authentication.
6. Reproduce the issue.
7. Run `/snapshot?lines=300` and share the JSON with Codex.
8. Revoke the generated Application Password.
9. Deactivate and delete the plugin after the work is done.

## Development Notes

- Main plugin file: `wp-cli-setup-helper.php`.
- Keep the plugin dependency-free and single-file unless there is a strong reason to split it.
- Prefer read-only diagnostics over mutating actions.
- If adding a mutating action, it must be nonce-protected, admin-only, explicit in the UI, and reversible when possible.
- Keep PHP 7.2 compatibility unless the plugin header is intentionally changed.

## Release Check

Run these before commit or packaging:

```bash
php -l wp-cli-setup-helper.php
git diff --check
```

Build zip from the parent plugins directory:

```powershell
Compress-Archive -Path .\wp-cli-setup-helper -DestinationPath .\temporary-site-connector-vX.Y.Z.zip -Force
```
