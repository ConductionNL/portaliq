// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The secret an invitation mail carries in the address fragment
// (`#claim=`, `#contact-invitation=`, `#mandate-invitation=`): read and
// stripped from the address on load, kept in sessionStorage so it survives
// the sign-in, and forgotten once an answer settles the invitation. Shared by
// claimInvitation.js, contactInvitation.js and mandateInvitation.js; each
// names its own fragment and storage key.
//
// Imports nothing, so the invitation tests run it as plain node.

/**
 * A fragment value, decoded, or '' when it is not decodable.
 *
 * @param {string} value The encoded value.
 * @return {string}
 */
export function decodedSecret(value) {
	try {
		return decodeURIComponent(value)
	} catch {
		return ''
	}
}

/**
 * Read an invitation's secret from the address, strip it from the address
 * bar and keep it for after the sign-in.
 *
 * @param {{pattern: RegExp, key: string}} link The fragment the secret comes in and the storage key it is kept under.
 * @param {{location: object, history: object|null, storage: Storage|null}} page The location, the history and sessionStorage (or null).
 * @return {string} The secret, or '' when the page was not opened from the link.
 */
export function keepInvitationSecret(link, page) {
	const match = String(page.location?.hash || '').match(link.pattern)
	if (!match) {
		return ''
	}

	const secret = decodedSecret(match[1])
	stripFragment(page.location, page.history)
	if (secret !== '') {
		storeSecret(page.storage, { key: link.key, secret })
	}
	return secret
}

/**
 * Rewrite the address without its fragment, keeping the path and the query.
 *
 * @param {{pathname: string, search: string}} location The location.
 * @param {{replaceState: (...args: unknown[]) => void}|null} history The history.
 * @return {void}
 */
function stripFragment(location, history) {
	try {
		history?.replaceState(
			null,
			'',
			`${location.pathname || ''}${location.search || ''}`,
		)
	} catch {
		// An address bar that cannot be rewritten still keeps the secret.
	}
}

/**
 * @param {Storage|null} storage sessionStorage, or null.
 * @param {{key: string, secret: string}} kept The storage key and the secret.
 * @return {void}
 */
function storeSecret(storage, kept) {
	try {
		storage?.setItem(kept.key, kept.secret)
	} catch {
		// Without storage the invitation is lost at the sign-in; the link in
		// the mail still works afterwards.
	}
}

/**
 * The secret kept before the sign-in, or ''.
 *
 * @param {Storage|null} storage sessionStorage, or null.
 * @param {string} key The storage key.
 * @return {string}
 */
export function keptSecret(storage, key) {
	try {
		return String(storage?.getItem(key) || '')
	} catch {
		return ''
	}
}

/**
 * Forget a kept secret.
 *
 * @param {Storage|null} storage sessionStorage, or null.
 * @param {string} key The storage key.
 * @return {void}
 */
export function forgetSecret(storage, key) {
	try {
		storage?.removeItem(key)
	} catch {
		// Without storage nothing was kept.
	}
}

/**
 * What the shell shows for a kept invitation while nobody is signed in.
 *
 * @param {(key: string) => string} t The translator.
 * @return {{role: string, text: string, claimed: boolean}}
 */
export function signInToAccept(t) {
	return {
		role: 'status',
		text: t('Sign in to accept your invitation.'),
		claimed: false,
	}
}

/**
 * The kept invitation of this tab, and what to show before it can be handed
 * back: nothing without a kept secret, the sign-in sentence without a
 * session, and `false` when it can go to the server now.
 *
 * @param {{session: object|null, t: (key: string) => string, storage: Storage|null}} visitor The session, the translator and sessionStorage.
 * @param {string} key The storage key.
 * @return {{secret: string, notice: object|null|false}}
 */
export function heldInvitation(visitor, key) {
	const secret = keptSecret(visitor.storage, key)
	if (secret === '') {
		return { secret, notice: null }
	}
	return { secret, notice: visitor.session ? false : signInToAccept(visitor.t) }
}

/**
 * What the shell shows for an accepted invitation.
 *
 * @param {string} text The translated sentence.
 * @return {{role: string, text: string, claimed: boolean}}
 */
export function acceptedNotice(text) {
	return { role: 'status', text, claimed: true }
}

/**
 * What the shell shows for an invitation that could not be accepted.
 *
 * @param {string} text The translated sentence.
 * @return {{role: string, text: string, claimed: boolean}}
 */
export function refusedNotice(text) {
	return { role: 'alert', text, claimed: false }
}

/**
 * What the shell shows for an invitation that is no longer valid.
 *
 * @param {(key: string) => string} t The translator.
 * @return {{role: string, text: string, claimed: boolean}}
 */
export function noLongerValid(t) {
	return refusedNotice(t('This invitation is no longer valid. Ask for a new one.'))
}

/**
 * Whether an answer settles the invitation, so its secret can go: accepted,
 * or refused for the invitation itself (a 4xx). A server that could not be
 * reached, or failed, leaves it for the next page load.
 *
 * @param {{ok: boolean, status: number}} answer The answer.
 * @return {boolean}
 */
export function settlesInvitation(answer) {
	return answer.ok || (answer.status >= 400 && answer.status < 500)
}
