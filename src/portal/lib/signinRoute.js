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
// @spec openspec/changes/signin-integriq-broker-login/specs/portal-broker-envelope-login/spec.md

/**
 * The start address for one provider on the route the organisation chose.
 *
 * @param {string} base The portal API base, e.g. `/apps/portaliq/portal/api`.
 * @param {string} org The organisation slug.
 * @param {string} provider `digid`, `eherkenning`, `eidas` or `generic`.
 * @param {string} route `oidc` or `broker`; anything else is `oidc`.
 * @return {string}
 * @spec openspec/changes/signin-integriq-broker-login/specs/portal-broker-envelope-login/spec.md#requirement-the-organisation-chooses-the-login-route-per-provider-req-bel-001
 */
export function loginStartUrl(base, org, provider, route) {
	const path = route === 'broker' ? 'broker' : 'oidc'
	return `${base}/session/${path}/start?org=${encodeURIComponent(org || '')}&provider=${encodeURIComponent(provider || '')}`
}

/**
 * Whether the page was reached from a failed login, read from the
 * `#signin=failed` fragment and removed from the address bar.
 *
 * @param {Location} location The window location.
 * @param {History} history The window history.
 * @return {boolean}
 * @spec openspec/changes/signin-integriq-broker-login/specs/portal-broker-envelope-login/spec.md#requirement-a-failed-login-returns-to-the-login-screen-without-a-reason-req-bel-006
 */
export function consumeSigninFailed(location, history) {
	if (String(location?.hash || '') !== '#signin=failed') {
		return false
	}
	history.replaceState(null, '', `${location.pathname}${location.search}`)
	return true
}
