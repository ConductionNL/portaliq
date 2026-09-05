/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Submits a landing-page form through Portaliq's EXISTING anonymous
 * contribution-create endpoint (landing-page-provisioning) — no new HTTP
 * route. Reuses `resolveApiBase()` from `contentApi.js` (the same runtime
 * config channel every other public call in this renderer uses) rather than
 * inventing a second way to find the app's root path.
 */

import { compactTouch } from './campaignTracking.js'
import { resolveApiBase } from './contentApi.js'

const REGISTER = 'portaliq'
const SUBMISSION_SCHEMA = 'landingPageSubmission'

/**
 * Derive the portal API root from the content API base.
 *
 * `resolveApiBase()` already strips a trailing `/site` off the configured
 * value, leaving something like `/apps/portaliq/api/content` (inside
 * Nextcloud) or the public-origin fallback `/api/content`. The portal API
 * lives one level up, at `{root}/portal/api/...` — so this strips the
 * trailing `/api/content` segment too, which is `''` for the public-origin
 * fallback (correctly resolving to a root-relative `/portal/api/...`).
 *
 * @return {string} The portal API root, no trailing slash.
 */
function resolvePortalApiRoot() {
	return resolveApiBase().replace(/\/api\/content$/, '')
}

/**
 * Submit a landing-page form's values through the anonymous create
 * endpoint. A non-2xx is thrown as an Error carrying `.status`, mirroring
 * `contentApi.js`'s `get()` convention.
 *
 * @param {object} values The visitor's field values, keyed by the form's own field ids.
 * @param {object} [tracking] `{utmFirstTouch, utmLastTouch, referrer}` captured client-side.
 * @return {Promise<object>} The created `landingPageSubmission` object.
 *
 * @spec openspec/specs/landing-page-provisioning/spec.md#requirement-a-landing-pages-form-is-submittable-with-no-portal-session
 */
export async function submitLandingPageForm(values, tracking = {}) {
	const url = new URL(
		`${resolvePortalApiRoot()}/portal/api/collections/${REGISTER}/${SUBMISSION_SCHEMA}`,
		window.location.origin,
	)

	// Only captured parameters travel: a touch of nulls fails the store's
	// validation of the nested strings, and the form could not be sent by
	// anyone who arrived without a campaign link.
	const body = {
		...values,
		utmFirstTouch: compactTouch(tracking.utmFirstTouch),
		utmLastTouch: compactTouch(tracking.utmLastTouch),
		referrer: tracking.referrer || '',
	}

	const response = await fetch(url.toString(), {
		method: 'POST',
		headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
		body: JSON.stringify(body),
	})

	if (!response.ok) {
		const error = new Error(`landing page form submission ${response.status}`)
		error.status = response.status
		throw error
	}

	const parsed = await response.json()
	return parsed.object || parsed
}
