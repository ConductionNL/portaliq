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

const MOUNT_ID = 'portaliq-site'

// A missing config is not an error: resolving the site by host is the normal
// path, and an explicit slug is only needed by a consumer that is not reaching
// Portaliq over the site's own hostname.

const element = document.getElementById(MOUNT_ID)

if (element) {
	const config = runtimeConfig()
	createApp(App, { siteSlug: config.site || '' }).mount(element)
} else {
	// Say so. A missing mount point is how a bundle ends up "loaded and doing
	// nothing", which reads on screen as a blank page with no console output
	// at all — the hardest kind of failure to diagnose from a screenshot.
	// eslint-disable-next-line no-console
	console.error(`[portaliq-site] no #${MOUNT_ID} element to mount into`)
}
