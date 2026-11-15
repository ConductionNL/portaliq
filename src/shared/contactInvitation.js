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
// Imports no browser code, so tests/resident-contacts.spec.mjs runs it as
// plain node.
//
// @spec openspec/changes/own-contacts-and-invitations/tasks.md#t05

import { contactsApi } from './areaApi.js'
import {
	acceptedNotice,
	forgetSecret,
	heldInvitation,
	keepInvitationSecret,
	noLongerValid,
	settlesInvitation,
} from './invitationSecret.js'

export const CONTACT_INVITATION_KEY = 'portaliq.contactInvitation'

const LINK = /^#contact-invitation=([^&]+)$/

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
	return keepInvitationSecret(
		{ pattern: LINK, key: CONTACT_INVITATION_KEY },
		{ location, history, storage },
	)
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
	const { secret, notice } = heldInvitation({ session, t, storage }, CONTACT_INVITATION_KEY)
	if (notice !== false) {
		return notice
	}
	const answer = await contactsApi(api).contactAction('accept', { token: secret })
	if (settlesInvitation(answer)) {
		forgetSecret(storage, CONTACT_INVITATION_KEY)
	}
	return answer.ok
		? acceptedNotice(t('You are now connected with the person who invited you.'))
		: noLongerValid(t)
}
