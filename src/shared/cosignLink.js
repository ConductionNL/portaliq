// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The co-signer's link (resident-identity-in-forms T04, decision 271). The
// mail carries `#cosign=<token>`. The shell reads and strips it on load and
// keeps it in sessionStorage so it survives the sign-in; the co-sign page
// forgets it once the answer is in. A fragment never reaches a server
// access log.
//
// @spec openspec/changes/resident-identity-in-forms/tasks.md#t04

export const COSIGN_KEY = 'portaliq.cosign'

const LINK = /^#cosign=([^&]+)$/

/**
 * Read the token from the address, strip it and keep it for after sign-in.
 *
 * @param {{hash: string, pathname: string, search: string}} location The location.
 * @param {{replaceState: (...args: unknown[]) => void}} history The history.
 * @param {Storage|null} storage sessionStorage, or null.
 * @return {string} The token from the address, else the one kept earlier, else ''.
 * @spec openspec/changes/resident-identity-in-forms/tasks.md#t04
 */
export function keepCosignToken(location, history, storage) {
	const match = String(location?.hash || '').match(LINK)
	if (!match) {
		return keptCosignToken(storage)
	}
	let token
	try {
		token = decodeURIComponent(match[1])
	} catch {
		token = ''
	}
	try {
		history?.replaceState(
			null,
			'',
			`${location.pathname || ''}${location.search || ''}`,
		)
	} catch {
		// An address bar that cannot be rewritten still keeps the token.
	}
	if (token !== '') {
		try {
			storage?.setItem(COSIGN_KEY, token)
		} catch {
			// Without storage the page still has the token until a reload.
		}
	}
	return token
}

/**
 * The token kept from the link, or ''.
 *
 * @param {Storage|null} storage sessionStorage, or null.
 * @return {string}
 * @spec openspec/changes/resident-identity-in-forms/tasks.md#t04
 */
export function keptCosignToken(storage) {
	try {
		return storage?.getItem(COSIGN_KEY) || ''
	} catch {
		return ''
	}
}

/**
 * Forget the kept token.
 *
 * @param {Storage|null} storage sessionStorage, or null.
 * @return {void}
 * @spec openspec/changes/resident-identity-in-forms/tasks.md#t04
 */
export function forgetCosignToken(storage) {
	try {
		storage?.removeItem(COSIGN_KEY)
	} catch {
		// Nothing more to do.
	}
}

/**
 * The sentence for a refusal from the co-sign routes.
 *
 * @param {number} status The HTTP status.
 * @param {string} error The `error` the route answered.
 * @return {string} The English key for the site translator.
 * @spec openspec/changes/resident-identity-in-forms/tasks.md#t04
 */
export function cosignRefusal(status, error) {
	if (error === 'same_subject') {
		return 'You cannot co-sign your own request.'
	}
	if (status === 410) {
		return 'The time to co-sign this request has passed.'
	}
	if (status === 409) {
		return 'This request has already been answered.'
	}
	if (status === 400) {
		return 'This link does not work. Ask the applicant for a new one.'
	}
	return 'Something went wrong. Try again later.'
}
