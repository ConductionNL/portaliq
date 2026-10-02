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
 * @spec openspec/specs/portal-in-place-editing/spec.md#requirement-the-portal-editor-must-not-weigh-on-a-visitors-first-load-req-pie-007
 */

import { createApp, h } from 'vue'
import SiteEditMode from './SiteEditMode.vue'

/**
 * Mount the editor for one page.
 *
 * @param {HTMLElement} element Where the page was rendered.
 * @param {{pageId: string, portal: string, onLeave: Function, onSaved: Function}} options The page, its portal, what to do on leaving, and what to do after a publish.
 * @return {Function} Unmounts the editor.
 * @spec openspec/specs/portal-in-place-editing/spec.md#requirement-an-editor-must-be-able-to-edit-a-page-in-place-on-the-portal-req-pie-006
 * @spec openspec/changes/site-shows-what-was-published/specs/portal-in-place-editing/spec.md#requirement-the-site-must-show-what-an-editor-published-not-a-cached-copy-req-ssp-001
 */
function mount(element, { pageId, portal, onLeave, onSaved }) {
	const app = createApp({
		render: () => h(SiteEditMode, { pageId, portal, onLeave, onSaved }),
	})
	app.mount(element)
	return () => app.unmount()
}

window.PortaliqSiteEditor = { mount }
