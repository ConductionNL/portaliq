/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Load the portal editor bundle on demand.
 *
 * Part of the site entry, so it stays a few lines: it adds one script tag,
 * next to the site's own script, the first time an editor asks to edit. A
 * visitor never gets here (portal-in-place-editing, REQ-PIE-007).
 *
 * @spec openspec/specs/portal-in-place-editing/spec.md#requirement-the-portal-editor-must-not-weigh-on-a-visitors-first-load-req-pie-007
 */

let loading = null

/**
 * The URL of a file next to the site bundle.
 *
 * @param {string} file The file name.
 * @return {string} The URL.
 */
function besideSiteBundle(file) {
	const site = document.querySelector('script[src*="portaliq-site.js"]')
	const base = site
		? site.src.replace(/portaliq-site\.js.*$/, '')
		: '/apps/portaliq/js/'
	return base + file
}

/**
 * The editor's mount API, loading the bundle once.
 *
 * @return {Promise<{mount: Function}>} The editor.
 * @spec openspec/specs/portal-in-place-editing/spec.md#requirement-the-portal-editor-must-not-weigh-on-a-visitors-first-load-req-pie-007
 */
export function loadSiteEditor() {
	if (window.PortaliqSiteEditor) {
		return Promise.resolve(window.PortaliqSiteEditor)
	}
	if (!loading) {
		loading = new Promise((resolve, reject) => {
			const script = document.createElement('script')
			script.src = besideSiteBundle('portaliq-site-editor.js')
			script.async = true
			script.onload = () =>
				window.PortaliqSiteEditor
					? resolve(window.PortaliqSiteEditor)
					: reject(new Error('The editor did not start.'))
			script.onerror = () => {
				loading = null
				reject(new Error('The editor could not be loaded.'))
			}
			document.head.appendChild(script)
		})
	}
	return loading
}
