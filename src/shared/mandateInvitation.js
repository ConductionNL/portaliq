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

import {
	acceptedNotice,
	forgetSecret,
	heldInvitation,
	keepInvitationSecret,
	noLongerValid,
	refusedNotice,
	settlesInvitation,
} from './invitationSecret.js'

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
	return keepInvitationSecret(
		{ pattern: LINK, key: MANDATE_INVITATION_KEY },
		{ location, history, storage },
	)
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
	const { secret, notice } = heldInvitation({ session, t, storage }, MANDATE_INVITATION_KEY)
	if (notice !== false) {
		return notice
	}
	const answer = await api.request('POST', '/mandates/invitations/accept', {
		token: secret,
	})
	if (settlesInvitation(answer)) {
		forgetSecret(storage, MANDATE_INVITATION_KEY)
	}
	return mandateOutcome(answer, t)
}

/**
 * What the shell shows after the accept route answered.
 *
 * @param {{ok: boolean, status: number}} answer The answer.
 * @param {(key: string) => string} t The translator.
 * @return {{role: string, text: string, claimed: boolean}}
 */
function mandateOutcome(answer, t) {
	if (answer.ok) {
		return acceptedNotice(
			t('You may now arrange matters on behalf of the person who invited you.'),
		)
	}
	if (answer.status === 409) {
		return refusedNotice(t('You cannot accept your own invitation.'))
	}
	return noLongerValid(t)
}
