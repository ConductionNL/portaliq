// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The invitation of a resident to be someone's contact
// (own-contacts-and-invitations).
//
// The mail carries `#contact-invitation=<secret>`. The shell reads and strips
// it on load and keeps it in sessionStorage so it survives sign-in and
// registration. Signed in, the shell hands it back once. A fragment never
// reaches a server access log.
//
// Imports nothing, so tests/resident-contacts.spec.mjs runs it as plain node.
//
// @spec openspec/changes/own-contacts-and-invitations/tasks.md#t05

import { contactsApi } from './areaApi.js'

export const CONTACT_INVITATION_KEY = 'portaliq.contactInvitation'

const LINK = /^#contact-invitation=([^&]+)$/

/**
 * A fragment value, decoded, or '' when it is not decodable.
 *
 * @param {string} value The encoded value.
 * @return {string}
 */
function decodedSecret(value) {
	try {
		return decodeURIComponent(value)
	} catch {
		return ''
	}
}

/**
 * The secret kept before the sign-in, or ''.
 *
 * @param {Storage|null} storage sessionStorage, or null.
 * @return {string}
 */
function keptSecret(storage) {
	try {
		return storage?.getItem(CONTACT_INVITATION_KEY) || ''
	} catch {
		return ''
	}
}

/**
 * Read the secret from the address, strip it and keep it for after sign-in.
 *
 * @param {{hash: string, pathname: string, search: string}} location The location.
 * @param {{replaceState: (...args: unknown[]) => void}} history The history.
 * @param {Storage|null} storage sessionStorage, or null.
 * @return {string} The secret, or '' when the page was not opened from the link.
 *
 * @spec openspec/changes/own-contacts-and-invitations/tasks.md#t05
 */
export function keepContactInvitation(location, history, storage) {
	const match = String(location?.hash || '').match(LINK)
	if (!match) {
		return ''
	}
	const secret = decodedSecret(match[1])
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
			storage?.setItem(CONTACT_INVITATION_KEY, secret)
		} catch {
			// Without storage the link in the mail still works afterwards.
		}
	}
	return secret
}

/**
 * Hand a kept secret back once the visitor is signed in.
 *
 * Without a kept secret this answers null. Without a session the secret stays
 * kept and the visitor is asked to sign in. An answer about the invitation
 * itself forgets the secret; a server that could not be reached keeps it.
 *
 * @param {object} options The options.
 * @param {{contactAction: (action: string, args: object) => Promise<object>}} options.api The portal API adapter.
 * @param {object|null} options.session The session, or null.
 * @param {(key: string) => string} options.t The translator.
 * @param {Storage|null} options.storage sessionStorage, or null.
 * @return {Promise<{role: string, text: string, claimed: boolean}|null>}
 *
 * @spec openspec/changes/own-contacts-and-invitations/tasks.md#t05
 */
export async function redeemKeptContactInvitation({ api, session, t, storage }) {
	const secret = keptSecret(storage)
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
	const answer = await contactsApi(api).contactAction('accept', { token: secret })
	if (answer.ok || (answer.status >= 400 && answer.status < 500)) {
		try {
			storage?.removeItem(CONTACT_INVITATION_KEY)
		} catch {
			// Nothing more to do.
		}
	}
	if (answer.ok) {
		return {
			role: 'status',
			text: t('You are now connected with the person who invited you.'),
			claimed: true,
		}
	}
	return {
		role: 'alert',
		text: t('This invitation is no longer valid. Ask for a new one.'),
		claimed: false,
	}
}
