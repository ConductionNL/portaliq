// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
//
// Where the Nextcloud instance that serves the site starts, for a call to
// another app on it (opencatalogi, openregister).
//
// `/site` renders the whole document itself (`templates/site.php`), so the
// globals `@nextcloud/router` reads (`_oc_webroot`, `OC.config`) are not on
// the page, and `generateUrl()` then guesses the webroot from the address,
// which only works for an `/index.php/...` address. The server-generated
// content API base (`apiBase`, from `linkToRoute`) already carries the
// instance's sub-folder and, without pretty URLs, its `/index.php`, so the
// root is everything in front of this app's route.
//
// Imports nothing, so node specs run it as is.

const CONTENT_ROUTE = /^(.*?)\/apps\/portaliq\/api\/content(?:\/site)?\/?$/

/**
 * The instance root in front of `/apps/...`: '' at the domain root with pretty
 * URLs, '/index.php' without them, and the sub-folder in front of either.
 *
 * Falls back to '/index.php', the prefix that works on every instance, when
 * the base is not this app's content route (a public origin, or no config).
 *
 * @param {string} apiBase The content API base, e.g. from `resolveApiBase()`.
 * @return {string} The root, without a trailing slash.
 * @spec openspec/specs/portal-in-place-editing/spec.md#requirement-an-editor-must-be-able-to-edit-a-page-in-place-on-the-portal-req-pie-006
 */
export function instanceRootFrom(apiBase) {
	const match = CONTENT_ROUTE.exec(String(apiBase || ''))
	return match ? match[1] : '/index.php'
}
