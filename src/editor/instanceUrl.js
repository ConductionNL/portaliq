/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * An address on the Nextcloud instance the site runs on, for the editor's
 * calls to openregister. Not `generateUrl()`: on the standalone `/site` page
 * it guesses the webroot from the address and misses on a pretty-URL instance
 * (see `src/site/lib/instanceRoot.js`).
 */

import { resolveApiBase } from '../site/lib/contentApi.js'
import { instanceRootFrom } from '../site/lib/instanceRoot.js'

/**
 * The address of a path on this instance.
 *
 * @param {string} path The path from `/apps/...` on.
 * @return {string} The address.
 * @spec openspec/specs/portal-in-place-editing/spec.md#requirement-an-editor-must-be-able-to-edit-a-page-in-place-on-the-portal-req-pie-006
 */
export function instanceUrl(path) {
	return instanceRootFrom(resolveApiBase()) + path
}
