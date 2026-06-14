# Temporary Site Connector

Temporary authenticated WordPress diagnostics connector for remote debugging.

## What it does

- Generates temporary Application Password based debug commands for the current admin user.
- Exposes authenticated, admin-only REST diagnostics endpoints.
- Can enable temporary debug logging and restore plugin-owned debug changes on uninstall.
- Tracks only Application Passwords created by this plugin and removes them on uninstall.
- Stores generated command text in browser session storage so it survives admin page reloads.

## Safety model

- No public endpoints.
- No shell command runner.
- No SSH or database secrets stored.
- Plaintext Application Passwords are shown only once by WordPress and are not saved in the database by this plugin.
- Remove the plugin after debugging is complete.

## Install

Upload the plugin folder or zip through WordPress Admin, then open:

`Tools > Temporary Site Connector`

