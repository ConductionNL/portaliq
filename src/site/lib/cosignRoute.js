// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
//
// The route of the co-sign page (resident-identity-in-forms REQ-RIF-003), on its
// own so the site entry can recognise the route without carrying the rest of
// cosign.js: the page itself loads on demand, and a module the entry shares
// with a lazy chunk stays whole in the entry (public-bundle-budget).
//
// Imports nothing, so the node specs run it as a plain script.
//
// @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-a-form-can-require-a-second-person-to-co-sign-req-rif-003

/** The in-site route of the co-sign page. */
export const COSIGN_ROUTE = '/mede-ondertekenen'

/**
 * Whether a route is the co-sign page.
 *
 * @param {string} route The in-site route.
 * @return {boolean}
 * @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-a-form-can-require-a-second-person-to-co-sign-req-rif-003
 */
export function isCosignRoute(route) {
	const value = String(route || '').replace(/\/$/, '')
	return value === COSIGN_ROUTE
}
