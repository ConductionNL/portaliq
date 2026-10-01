// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Which address a login button starts, and the failed-login fragment
// (signin-integriq-broker-login T09, T10).
//
// An organisation routes each provider to its own OIDC broker (`oidc`, the
// default) or to integriq (`broker`). The button reads the same either way;
// only the address it starts differs. A failed broker login comes back as
// `#signin=failed`, with no reason, and the login screen shows one sentence.
//
// Imports nothing, so tests/broker-login.spec.mjs runs it as plain node.
//
// @spec openspec/specs/portal-broker-envelope-login/spec.md

/**
 * The start address for one provider on the route the organisation chose.
 *
 * @param {string} base The portal API base, e.g. `/apps/portaliq/portal/api`.
 * @param {string} org The organisation slug.
 * @param {string} provider `digid`, `eherkenning`, `eidas` or `generic`.
 * @param {string} route `oidc` or `broker`; anything else is `oidc`.
 * @param {string} portal The serving portal's slug, or ''.
 * @return {string}
 * @spec openspec/specs/portal-broker-envelope-login/spec.md#requirement-the-organisation-chooses-the-login-route-per-provider-req-bel-001
 */
export function loginStartUrl(base, org, provider, route, portal = '') {
	const path = route === 'broker' ? 'broker' : 'oidc'
	const url = `${base}/session/${path}/start?org=${encodeURIComponent(org || '')}&provider=${encodeURIComponent(provider || '')}`
	// The serving portal rides along so the login returns to it and keeps its
	// title (portal-signin-on-its-own-address T3); the server only echoes a
	// portal that exists.
	return portal ? `${url}&portal=${encodeURIComponent(portal)}` : url
}

/**
 * The organisation a login button starts with.
 *
 * The runtime config's `organisationSlug` is the serving PORTAL's slug once a
 * portal is resolved, which is not an organisation, so a portal whose slug
 * differs from its organisation sent the login to a tenant with no broker.
 * The server now names the organisation in `signinOrganisation`; an older
 * server without that key keeps the old behaviour.
 *
 * @param {{organisationSlug?: string, signinOrganisation?: string}} config The runtime config.
 * @return {string}
 * @spec openspec/changes/portal-signin-on-its-own-address/tasks.md#T1
 */
export function signinOrganisation(config) {
	return String(config?.signinOrganisation || config?.organisationSlug || '')
}

/**
 * Whether the page was reached from a failed login, read from the
 * `#signin=failed` fragment and removed from the address bar.
 *
 * @param {Location} location The window location.
 * @param {History} history The window history.
 * @return {boolean}
 * @spec openspec/specs/portal-broker-envelope-login/spec.md#requirement-a-failed-login-returns-to-the-login-screen-without-a-reason-req-bel-006
 */
export function consumeSigninFailed(location, history) {
	if (String(location?.hash || '') !== '#signin=failed') {
		return false
	}
	history.replaceState(null, '', `${location.pathname}${location.search}`)
	return true
}
