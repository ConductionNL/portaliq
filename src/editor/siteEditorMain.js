/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Entry of the portal editor bundle (`js/portaliq-site-editor.js`).
 *
 * The site loads this file only when an editor chooses "Deze pagina
 * bewerken" (`src/site/lib/loadSiteEditor.js`). It is its own Vue app with its
 * own Vue, mounted where the page was, so nothing it uses is shared with, and
 * so nothing is added to, the bundle every visitor downloads (see the editor
 * config in webpack.site.js for the measurement).
 *
 * @spec openspec/changes/portal-in-place-editing/specs/portal-in-place-editing/spec.md#requirement-the-portal-editor-must-not-weigh-on-a-visitors-first-load-req-pie-007
 */

import { createApp, h } from 'vue'
import SiteEditMode from './SiteEditMode.vue'

/**
 * Mount the editor for one page.
 *
 * @param {HTMLElement} element Where the page was rendered.
 * @param {{pageId: string, portal: string, onLeave: Function}} options The page, its portal, and what to do on leaving.
 * @return {Function} Unmounts the editor.
 * @spec openspec/changes/portal-in-place-editing/specs/portal-in-place-editing/spec.md#requirement-an-editor-must-be-able-to-edit-a-page-in-place-on-the-portal-req-pie-006
 */
function mount(element, { pageId, portal, onLeave }) {
	const app = createApp({
		render: () => h(SiteEditMode, { pageId, portal, onLeave }),
	})
	app.mount(element)
	return () => app.unmount()
}

window.PortaliqSiteEditor = { mount }
