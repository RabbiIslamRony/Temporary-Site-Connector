# Temporary Site Connector

Temporary authenticated WordPress diagnostics connector for remote debugging.

## What it does

- Generates temporary Application Password based debug commands for the current admin user.
- Exposes authenticated, admin-only REST diagnostics endpoints.
- Shows explicit enable and disable controls for temporary debug logging.
- Tracks only Application Passwords created by this plugin and removes them on uninstall.
- Lists plugin-created Application Passwords and lets admins revoke them manually.
- Stores generated command text in browser session storage so it survives admin page reloads.
- Provides a read-only Issue Snapshot report with REST health, recent debug-log error summary, and conditional Elementor/Directorist context.

## Safety model

- No public endpoints.
- No shell command runner.
- No SSH or database secrets stored.
- No shell, SQL, file editor, or plugin-deactivation endpoint.
- Plaintext Application Passwords are shown only once by WordPress and are not saved in the database by this plugin.
- Remove the plugin after debugging is complete.

## Install

Upload the plugin folder or zip through WordPress Admin, then open:

`Tools > Temporary Site Connector`

## Project context

For architecture, safety rules, endpoints, and the recommended debug workflow, see [PROJECT_CONTEXT.md](PROJECT_CONTEXT.md).
