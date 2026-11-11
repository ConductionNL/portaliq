// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// An invitation of a waiting account (invitation-secret-joins-the-signed-in-account).
//
// The mail carries `#claim=<secret>`. The shell reads and strips it on load,
// the way it reads `#open=`, and keeps it in sessionStorage so it survives
// the sign-in round trip, whatever route the sign-in takes. Signed in, the
// shell hands the secret back once and shows what came of it. A fragment
// never reaches a server access log, and the secret is never put in a
// return address.
//
// Imports only the shared invitation secret steps, so
// tests/claim-invitation.spec.mjs runs it as a plain node script.
//
// @spec openspec/changes/invitation-secret-joins-the-signed-in-account/specs/portal-identity-space/spec.md

import {
	forgetSecret,
	heldInvitation,
	keepInvitationSecret,
	keptSecret,
} from './invitationSecret.js'

export const CLAIM_STORAGE_KEY = 'portaliq.claimInvitation'

const LINK = /^#claim=([^&]+)$/

/**
 * Read an invitation's secret from the address, strip it from the address
 * bar and keep it for after the sign-in.
 *
 * @param {{hash: string, pathname: string, search: string}} location The location.
 * @param {{replaceState: (...args: unknown[]) => void}} history The history.
 * @param {Storage|null} storage sessionStorage, or null.
 * @return {string} The secret, or '' when the page was not opened from an invitation.
 *
 * @spec openspec/changes/invitation-secret-joins-the-signed-in-account/specs/portal-identity-space/spec.md
 */
export function keepClaimSecret(location, history, storage) {
	return keepInvitationSecret(
		{ pattern: LINK, key: CLAIM_STORAGE_KEY },
		{ location, history, storage },
	)
}

/**
 * The secret kept before the sign-in, or ''.
 *
 * @param {Storage|null} storage sessionStorage, or null.
 * @return {string}
 */
export function keptClaimSecret(storage) {
	return keptSecret(storage, CLAIM_STORAGE_KEY)
}

/**
 * Forget the kept secret.
 *
 * @param {Storage|null} storage sessionStorage, or null.
 * @return {void}
 */
export function forgetClaimSecret(storage) {
	forgetSecret(storage, CLAIM_STORAGE_KEY)
}

/** The sentence for each refusal of the redeem route, by its error. */
const CLAIM_REFUSALS = new Map([
	['too_many_attempts', 'Too many attempts. Try again in an hour.'],
	['trust_too_low', 'You need a more secure way to sign in for this invitation.'],
	[
		'account_cannot_receive',
		'You cannot accept an invitation with this account. Sign in another way and try again.',
	],
	[
		'invitation_conflict',
		'Your account is already linked in another way. Contact the organisation that invited you.',
	],
	['invitation_not_valid', 'This invitation is no longer valid. Ask for a new one.'],
])

/**
 * The sentence for what the redeem route answered, as an English source key.
 * Wrong, expired and used are one sentence, as they are one answer.
 *
 * @param {{ok: boolean, status: number, error: string}} answer The answer.
 * @return {{role: string, text: string}}
 *
 * @spec openspec/changes/invitation-secret-joins-the-signed-in-account/specs/portal-identity-space/spec.md
 */
export function claimOutcome(answer) {
	if (answer && answer.ok) {
		return {
			role: 'status',
			text: 'Your invitation is accepted. You now see what is shared with you.',
		}
	}
	const error = (answer && answer.error) || ''
	return {
		role: 'alert',
		text: CLAIM_REFUSALS.get(error) || 'That did not work. Try again later.',
	}
}

/**
 * The sentence for what the redeem route answered to a typed code, as an
 * English source key. A wrong, an expired and a used code are one sentence.
 *
 * @param {{ok: boolean, status: number, error: string}} answer The answer.
 * @return {{role: string, text: string}}
 *
 * @spec openspec/changes/invitation-code-from-a-letter/specs/portal-identity-space/spec.md
 */
export function codeOutcome(answer) {
	if (answer && answer.ok) {
		return {
			role: 'status',
			text: 'The code is right. You now see what is shared with you.',
		}
	}
	if (answer && answer.error === 'invitation_not_valid') {
		return {
			role: 'alert',
			text: 'This code is not right or no longer valid. Check the code, or ask for a new one.',
		}
	}
	return claimOutcome(answer)
}

/**
 * The answers about the caller's own session or account, or a request that
 * could not finish. Nothing about the invitation was spent, so the secret is
 * kept: signed in another way in this tab, the visitor can still accept it
 * (security review L6).
 */
const KEEP_AFTER = ['trust_too_low', 'account_cannot_receive', 'try_again']

/**
 * Whether the server's answer leaves the invitation for a later try.
 *
 * @param {{ok: boolean, status: number, error: string}|null} answer The answer.
 * @return {boolean}
 */
function keepsTheSecret(answer) {
	if (!answer || answer.status <= 0) {
		return true
	}
	return !answer.ok && KEEP_AFTER.includes(answer.error || '')
}

/**
 * Hand a kept invitation back once the visitor is signed in.
 *
 * Without a kept secret this answers null. Without a session the secret
 * stays kept and the visitor is asked to sign in. With a session the secret
 * is posted once. It is forgotten after an answer about the invitation
 * itself (accepted, not valid, a conflict, too many tries). An answer about
 * the visitor's own session or account, a request that could not finish and
 * a server that could not be reached keep it for the next page load.
 *
 * @param {object} options The options.
 * @param {{claimInvitation: (secret: string) => Promise<object>}} options.api The portal API adapter.
 * @param {object|null} options.session The session, or null.
 * @param {(key: string) => string} options.t The translator.
 * @param {Storage|null} options.storage sessionStorage, or null.
 * @return {Promise<{role: string, text: string, claimed: boolean}|null>}
 *
 * @spec openspec/changes/invitation-secret-joins-the-signed-in-account/specs/portal-identity-space/spec.md
 */
export async function redeemKeptClaim({ api, session, t, storage }) {
	const { secret, notice } = heldInvitation({ session, t, storage }, CLAIM_STORAGE_KEY)
	if (notice !== false) {
		return notice
	}

	const answer = await api.claimInvitation(secret)
	if (!keepsTheSecret(answer)) {
		forgetClaimSecret(storage)
	}
	return claimNotice(answer, t)
}

/**
 * What the shell shows after the redeem route answered.
 *
 * @param {{ok: boolean, status: number, error: string}|null} answer The answer.
 * @param {(key: string) => string} t The translator.
 * @return {{role: string, text: string, claimed: boolean}}
 */
function claimNotice(answer, t) {
	const outcome = claimOutcome(answer)
	return {
		role: outcome.role,
		text: t(outcome.text),
		claimed: Boolean(answer && answer.ok),
	}
}
