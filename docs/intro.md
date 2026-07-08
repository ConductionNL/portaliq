---
sidebar_position: 1
description: Scaffold for new Conduction-style Nextcloud apps. Manifest-first Vue 2, OpenRegister-backed data, Dashboard widget, AI tools, full quality gate.
---

# Nextcloud App Template

A starting point for building Nextcloud apps following ConductionNL
conventions — a manifest-first Vue 2 frontend rendered by CnAppRoot, an
OpenRegister data layer, a Dashboard widget, an admin settings panel, an
AI Chat Companion tool provider, and the full PHP + frontend quality
pipeline.

## What is this?

This is the **template every new Conduction app is scaffolded from**. It
ships:

- **A manifest-driven UI** — pages, navigation, and dependencies are
  declared in `src/manifest.json`; the shell (CnAppRoot) reads the
  manifest at boot and renders index / detail / dashboard / settings
  pages without per-page Vue files.
- **Admin settings** — a settings panel wired through
  `NcAppSettingsDialog`, backed by an OpenRegister settings register.
- **An MCP tool provider** — `ExampleToolProvider` exposes the app's
  capabilities to the in-app AI Chat Companion over MCP.
- **OpenRegister integration** — `manifest.dependencies` lists
  `openregister`, so the dependency-check phase ensures it is installed
  before the UI mounts. Remove the entry if your app does not need it.
- **The quality pipeline** — PHPCS, PHPMD, Psalm, PHPStan, ESLint,
  Stylelint, plus manifest/register/JSON-strict validators.
- **This documentation site** — Docusaurus on `@conduction/docusaurus-preset`,
  the journeydoc tutorial scaffold, and a Playwright `docs-capture`
  project for screenshots (ADR-030).

## Getting started

Clone the template, rename `portaliq` to your slug, and build:

```bash
cd /var/www/html/custom_apps
git clone https://codeberg.org/Conduction/portaliq.git portaliq
cd portaliq
npm install && npm run build
php occ app:enable portaliq
```

> OpenRegister must be installed first unless you remove the dependency
> from `src/manifest.json`, `appinfo/info.xml`, and `openspec/app-config.json`.

- New here? Start with the **[User guide](/docs/category/user-guide)** — open
  the app for the first time.
- Setting things up? See the **[Admin guide](/docs/category/admin-guide)** —
  manage the app's settings.

Free and open source under the EUPL-1.2 license. For support, contact
support@conduction.nl.
