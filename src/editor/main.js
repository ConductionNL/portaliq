/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Boot for the page editor.
 *
 * A SEPARATE BUNDLE FROM THE SITE, deliberately. The editor is behind a login
 * and pays none of the public renderer's byte budget; bundling it with the site
 * would put a block library, an inspector and an undo stack into every
 * anonymous visitor's first page load, for a surface they can never open.
 *
 * It renders on the same PAGE FURNITURE as the public site — `templates/
 * editor.php` links the same stylesheets — because the canvas mounts the real
 * blocks and they must land under the real CSS.
 */

import { createApp } from 'vue'
import App from './App.vue'

const MOUNT_ID = 'portaliq-editor'
const CONFIG_ID = 'portaliq-editor-config'

/**
 * The runtime configuration the template emitted.
 *
 * READ FROM THE JSON TAG, which is where the template puts it — not from a
 * global, which nothing sets. Reading `window.PORTALIQ_EDITOR_CONFIG` was the
 * first version and it failed silently in the worst possible way: every field
 * fell back to its default, so the editor opened the HOME page of the
 * host-resolved portal and looked entirely functional while editing a
 * different page than the one in the URL.
 *
 * @return {object} The configuration, or an empty object.
 */
function runtimeConfig() {
	const tag = document.getElementById(CONFIG_ID)
	if (!tag) {
		return {}
	}

	try {
		return JSON.parse(tag.textContent || '{}')
	} catch {
		return {}
	}
}

const element = document.getElementById(MOUNT_ID)

if (element) {
	const config = runtimeConfig()
	createApp(App, {
		portalSlug: String(config.portal || ''),
		route: String(config.route || '/'),
	}).mount(element)
} else {
	// Say so. A missing mount point is how a bundle ends up loaded and doing
	// nothing, which reads on screen as a blank page with no console output.
	// eslint-disable-next-line no-console
	console.error(`[portaliq] editor mount point #${MOUNT_ID} is missing`)
}
