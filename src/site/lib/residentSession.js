// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

/**
 * The resident's manifest and bearer, read once per page for every save
 * button on it (woo-journey-entry-points D2).
 *
 * A publication page shows a save button per document; without this cache
 * each would ask for the whole manifest. Kept out of `residentActions.js` so
 * that file stays free of browser state and a node test can import it.
 *
 * @spec openspec/changes/woo-journey-entry-points/specs/portal-federated-search/spec.md#requirement-the-save-actions-must-show-only-to-a-signed-in-resident-who-is-offered-them-req-wje-001
 */

import { adoptSessionToken, authBaseFrom } from './authApi.js'
import { resolveApiBase } from './contentApi.js'
import { getJson } from './residentActions.js'

let manifest = null

/**
 * The portal API base of this site.
 *
 * @return {string} The base, `.../portal/api`.
 *
 * @spec openspec/changes/woo-journey-entry-points/specs/portal-federated-search/spec.md#requirement-the-save-actions-must-show-only-to-a-signed-in-resident-who-is-offered-them-req-wje-001
 */
export function residentAuthBase() {
	return authBaseFrom(resolveApiBase())
}

/**
 * The resident's bearer, or ''.
 *
 * @return {string} The bearer.
 *
 * @spec openspec/changes/woo-journey-entry-points/specs/portal-federated-search/spec.md#requirement-the-save-actions-must-show-only-to-a-signed-in-resident-who-is-offered-them-req-wje-001
 */
export function residentToken() {
	return adoptSessionToken()
}

/**
 * The resident's manifest, fetched once per page load.
 *
 * @return {Promise<object|null>} The manifest, or null.
 *
 * @spec openspec/changes/woo-journey-entry-points/specs/portal-federated-search/spec.md#requirement-the-save-actions-must-show-only-to-a-signed-in-resident-who-is-offered-them-req-wje-001
 */
export function residentManifest() {
	if (manifest === null) {
		manifest = getJson(`${residentAuthBase()}/contributions`, residentToken())
	}

	return manifest
}
