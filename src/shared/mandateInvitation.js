// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The invitation to act on someone's behalf (site-mandates-the-represented-
// manage T4). The mail carries `#mandate-invitation=<secret>`. The shell
// reads and strips it on load and keeps it in sessionStorage so it survives
// the sign-in. Signed in, the shell hands it back once; the mandate is then
// written for the account that accepted. A fragment never reaches a server
// access log.
//
// @spec openspec/changes/site-mandates-the-represented-manage/tasks.md#t4

export const MANDATE_INVITATION_KEY = 'portaliq.mandateInvitation'

const LINK = /^#mandate-invitation=([^&]+)$/

/**
 * Read the secret from the address, strip it and keep it for after sign-in.
 *
 * @param {{hash: string, pathname: string, search: string}} location The location.
 * @param {{replaceState: (...args: unknown[]) => void}} history The history.
 * @param {Storage|null} storage sessionStorage, or null.
 * @return {string} The secret, or '' when the page was not opened from the link.
 */
export function keepMandateInvitation(location, history, storage) {
	const match = String(location?.hash || '').match(LINK)
	if (!match) {
		return ''
	}
	let secret
	try {
		secret = decodeURIComponent(match[1])
	} catch {
		secret = ''
	}
	try {
		history?.replaceState(
			null,
			'',
			`${location.pathname || ''}${location.search || ''}`,
		)
	} catch {
		// An address bar that cannot be rewritten still keeps the secret.
	}
	if (secret !== '') {
		try {
			storage?.setItem(MANDATE_INVITATION_KEY, secret)
		} catch {
			// Without storage the link in the mail still works afterwards.
		}
	}
	return secret
}

/**
 * Hand a kept secret back once the visitor is signed in.
 *
 * Without a kept secret this answers null; without a session the secret stays
 * kept and the visitor is asked to sign in. An answer about the invitation
 * itself forgets the secret; a server that could not be reached keeps it.
 *
 * @param {object} options The options.
 * @param {{request: Function}} options.api The portal API adapter.
 * @param {object|null} options.session The session, or null.
 * @param {(key: string) => string} options.t The translator.
 * @param {Storage|null} options.storage sessionStorage, or null.
 * @return {Promise<{role: string, text: string, claimed: boolean}|null>}
 */
export async function redeemKeptMandateInvitation({ api, session, t, storage }) {
	let secret
	try {
		secret = storage?.getItem(MANDATE_INVITATION_KEY) || ''
	} catch {
		secret = ''
	}
	if (secret === '') {
		return null
	}
	if (!session) {
		return {
			role: 'status',
			text: t('Sign in to accept your invitation.'),
			claimed: false,
		}
	}
	const answer = await api.request('POST', '/mandates/invitations/accept', {
		token: secret,
	})
	if (answer.ok || (answer.status >= 400 && answer.status < 500)) {
		try {
			storage?.removeItem(MANDATE_INVITATION_KEY)
		} catch {
			// Nothing more to do.
		}
	}
	if (answer.ok) {
		return {
			role: 'status',
			text: t(
				'You may now arrange matters on behalf of the person who invited you.',
			),
			claimed: true,
		}
	}
	if (answer.status === 409) {
		return {
			role: 'alert',
			text: t('You cannot accept your own invitation.'),
			claimed: false,
		}
	}
	if (!(answer.status >= 400 && answer.status < 500)) {
		// The server could not be reached or failed: the invitation itself
		// may be fine, and the kept secret is tried again on the next visit.
		return {
			role: 'alert',
			text: t(
				'The invitation could not be accepted right now. Try again later.',
			),
			claimed: false,
		}
	}
	return {
		role: 'alert',
		text: t('This invitation is no longer valid. Ask for a new one.'),
		claimed: false,
	}
}
