# App Template — Nextcloud App Template

## Overview

App Template is the official starter template for Conduction Nextcloud apps. It provides the standard structure, configuration, and tooling that all Conduction apps share.

When creating a new app, clone this template and use `/app-create` to rename all identifiers.

## Architecture

- **Type**: Nextcloud App (PHP backend + Vue 2 frontend)
- **Data layer**: OpenRegister (all data stored as register objects)
- **Pattern**: Thin client — App Template provides UI/UX, OpenRegister handles persistence
- **License**: EUPL-1.2

## Tech Stack

| Layer | Technology |
|-------|------------|
| Backend | PHP 8.1+, Nextcloud AppFramework |
| Frontend | Vue 2.7, Pinia, @nextcloud/vue |
| Data | OpenRegister (JSON object store) |
| Testing | PHPUnit (unit + integration), Newman (API) |
| Quality | PHPCS, PHPMD, Psalm, PHPStan, ESLint, Stylelint |

## Key Files

| File | Purpose |
|------|---------|
| `lib/AppInfo/Application.php` | App bootstrap, listener + repair registration |
| `lib/Controller/SettingsController.php` | Settings API endpoints |
| `lib/Service/SettingsService.php` | Settings business logic, OpenRegister integration |
| `lib/Listener/DeepLinkRegistrationListener.php` | Not implemented yet: no deep link patterns are registered with OpenRegister search (#81) |
| `lib/Repair/InitializeSettings.php` | Import register on install/upgrade |
| `lib/Settings/app_template_register.json` | OpenAPI 3.0 register schema definition |
| `src/App.vue` | App shell (navigation + routing) |
| `src/navigation/MainMenu.vue` | App navigation sidebar |
| `src/views/settings/UserSettings.vue` | User settings dialog |
| `openspec/config.yaml` | OpenSpec project configuration |

## Development Setup

See the workspace-level `.claude/docs/` for:
- `commands.md` — available Claude commands
- `testing.md` — testing workflows
- `app-lifecycle.md` — full development lifecycle

## Standards

This app follows all [Conduction app standards](../.claude/openspec/architecture/).

### The React portal is retired

The React portal that lived in `src/portal/` and answered at `/portal` is gone (change `site-reaches-portal-parity`, 2 October 2026). The Vue site in `src/site/` does everything it did.

- `/portal` and `/portal/{path}` redirect to `/site` with the same query string. Never link to `/portal` from new code: link to the `portalPage.site` route.
- New signed-in screens and portal features go into `src/site/`. Framework-free logic shared by the site and the embed frame lives in `src/shared/`.
- The `/portal/api/*` endpoints, `/portal/manifest.webmanifest`, `/portal/sw.js` and `/portal/embed` stay at their addresses.
