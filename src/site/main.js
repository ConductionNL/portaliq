/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Boot for the built-in site renderer (ADR-084).
 *
 * This is the `host: 'public'` shape in miniature: it mounts a
 * caller-supplied element, resolves URLs from runtime configuration, and
 * touches no Nextcloud global. Nothing here reads `OC`, `OCA`, `OCP` or a
 * `requesttoken` — which is what lets the same bundle serve a public origin.
 *
 * The e2e suite asserts that absence directly, because "it happens to work
 * today" and "it does not depend on Nextcloud" look identical from inside a
 * Nextcloud page.
 */

import { createApp } from 'vue'
import App from './App.vue'
import { runtimeConfig } from './lib/contentApi.js'

// NL DESIGN SYSTEM, NOT NEXTCLOUD. The public site is a government portal and
// must look like one, so it renders Utrecht/NLDS components — the same set the
// reference implementation uses — rather than Nextcloud's.
//
// Two halves, and BOTH are required:
//
//   1. the component CSS (`@utrecht/*-css`), which is framework-agnostic. The
//      React library is a thin wrapper — `@utrecht/link-react` is literally
//      `<a className={clsx('utrecht-link', 'utrecht-link--html-a', …)}>` — so
//      Vue emitting the same class on the same element renders identically.
//      MEASURED: an `h2.utrecht-heading-2` in this Vue app, inside Nextcloud
//      with server.css (587 rules) loaded, matched the reference on font,
//      size, weight, line-height, colour and margins with ZERO differences.
//      Utrecht's class selectors outrank Nextcloud's element selectors.
//
//   2. the THEME TOKENS, which is where this was actually failing. Utrecht's
//      CSS reads `--utrecht-*` variables; nldesign's hand-converted
//      `tokens/vng.css` defines **607 of them fewer than zero** — it has none,
//      only `--nldesign-*` names. So every component fell back to its default
//      and the portal looked nothing like the reference no matter how many
//      colours the theme bridge mapped by hand. These files are the real
//      generated NLDS token sets (vng: 605 `--utrecht-*`, venray: 532),
//      scoped `.vng-theme` / `.venray-theme` — which is exactly what
//      `App.vue`'s `themeClass` already emits.
//
//      The TOKENS ARE NOT BUNDLED. They ship as static CSS under `css/themes/`
//      and the serving portal's one theme is linked at render time, the way
//      the reference implementation does it. Bundling both took the site
//      bundle from 203KB to 696KB and blew the public first-load budget that
//      e2e S18 enforces at 400KB — for two themes a given visitor will never
//      both need.
//      THE COMPONENT CSS PACKAGES ARE NOT IMPORTED, AND THAT IS MEASURED.
//
//      `templates/site.php` links the vendored NL Design System stylesheets,
//      which already carry `.utrecht-heading-*`, `.utrecht-paragraph`,
//      `.utrecht-link`, the nav list, the page header and footer and the
//      article. Importing the `@utrecht/*-css` packages here shipped the same
//      rules a second time, inside the JS.
//
//      Deleting every injected `<style>` on a rendered portal changed exactly
//      ONE computed property across header, hero, cards, footer, navigation,
//      buttons and headings — the skip link's `position`. That is why the skip
//      link's CSS is the one import that stays: it is the only one the linked
//      stylesheets do not provide.
import '@utrecht/skip-link-css/dist/index.css'

const MOUNT_ID = 'portaliq-site'

// A missing config is not an error: resolving the site by host is the normal
// path, and an explicit slug is only needed by a consumer that is not reaching
// Portaliq over the site's own hostname.

const element = document.getElementById(MOUNT_ID)

if (element) {
	const config = runtimeConfig()
	createApp(App, { portalSlug: config.portal || '' }).mount(element)
} else {
	// Say so. A missing mount point is how a bundle ends up "loaded and doing
	// nothing", which reads on screen as a blank page with no console output
	// at all — the hardest kind of failure to diagnose from a screenshot.
	// eslint-disable-next-line no-console
	console.error(`[portaliq-site] no #${MOUNT_ID} element to mount into`)
}
