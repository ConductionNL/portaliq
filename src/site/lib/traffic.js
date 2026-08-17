/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

/**
 * Binding the shared traffic client to this renderer's browser.
 *
 * The DECISIONS all live in `src/shared/trafficClient.js`, which is the file a
 * statically built portal loads too. This one only supplies the environment:
 * which URL to post to, and which real browser objects stand in for the
 * injected ones. Keeping the split here is what stops the two renderers from
 * growing different answers to "may we store this".
 *
 * @spec openspec/changes/portal-traffic-analytics/tasks.md
 */

import { createTrafficClient } from '../../shared/trafficClient.js'

/**
 * The collector URL, derived from the content API base.
 *
 * `/api/content` and `/api/traffic` are siblings under the app root, exactly as
 * `/portal/api` is — so one derivation rather than a second configured value
 * that can disagree with the first.
 *
 * @param {string} apiBase The content API base, no trailing slash.
 * @return {string} The collector URL.
 */
export function trafficEndpointFrom(apiBase) {
	return String(apiBase || '').replace(/\/api\/content\/?$/, '/api/traffic')
}

/**
 * Build the client for this page, or a silent one.
 *
 * NEVER RETURNS NULL. A caller that has to check gets it wrong once and then
 * measures a portal that never asked to be measured; a silent client answers
 * `pageView()` and does nothing, so there is no branch to get wrong.
 *
 * @param {object} options         The binding.
 * @param {object} options.config  The `traffic` block from `/api/content/site`.
 * @param {string} options.apiBase The content API base.
 * @param {string} options.portal  The portal slug, when one was configured.
 * @return {object} The traffic client.
 */
export function trafficClientFor({ config, apiBase, portal = '' }) {
	return createTrafficClient({
		config: config || {},
		endpoint: trafficEndpointFrom(apiBase),
		portal,
		// `localStorage` throws on access in some privacy modes — before any
		// method is called, on the property read itself.
		storage: safeLocalStorage(),
		navigator: window.navigator,
		window,
		now: () => Date.now(),
		fetchImpl: window.fetch ? window.fetch.bind(window) : null,
		setTimeoutImpl: window.setTimeout.bind(window),
	})
}

/**
 * `localStorage`, or null when the browser refuses to hand it over.
 *
 * @return {object|null} The storage.
 */
function safeLocalStorage() {
	try {
		return window.localStorage
	} catch {
		return null
	}
}
