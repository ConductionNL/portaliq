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
// Imports nothing, so tests/claim-invitation.spec.mjs runs it as a plain
// node script.
//
// @spec openspec/changes/invitation-secret-joins-the-signed-in-account/specs/portal-identity-space/spec.md

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
	const match = String(location?.hash || '').match(LINK)
	if (!match) {
		return ''
	}

	const secret = decoded(match[1])
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
			storage?.setItem(CLAIM_STORAGE_KEY, secret)
		} catch {
			// Without storage the invitation is lost at the sign-in; the
			// link in the mail still works afterwards.
		}
	}
	return secret
}

/**
 * A fragment value, decoded, or '' when it is not decodable.
 *
 * @param {string} value The encoded value.
 * @return {string}
 */
function decoded(value) {
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
export function keptClaimSecret(storage) {
	try {
		return String(storage?.getItem(CLAIM_STORAGE_KEY) || '')
	} catch {
		return ''
	}
}

/**
 * Forget the kept secret.
 *
 * @param {Storage|null} storage sessionStorage, or null.
 * @return {void}
 */
export function forgetClaimSecret(storage) {
	try {
		storage?.removeItem(CLAIM_STORAGE_KEY)
	} catch {
		// Without storage nothing was kept.
	}
}

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
	if (error === 'too_many_attempts') {
		return {
			role: 'alert',
			text: 'Too many attempts. Try again in an hour.',
		}
	}
	if (error === 'trust_too_low') {
		return {
			role: 'alert',
			text: 'You need a more secure way to sign in for this invitation.',
		}
	}
	if (error === 'account_cannot_receive') {
		return {
			role: 'alert',
			text: 'You cannot accept an invitation with this account. Sign in another way and try again.',
		}
	}
	if (error === 'invitation_conflict') {
		return {
			role: 'alert',
			text: 'Your account is already linked in another way. Contact the organisation that invited you.',
		}
	}
	if (error === 'invitation_not_valid') {
		return {
			role: 'alert',
			text: 'This invitation is no longer valid. Ask for a new one.',
		}
	}
	return { role: 'alert', text: 'That did not work. Try again later.' }
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
	const secret = keptClaimSecret(storage)
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

	const answer = await api.claimInvitation(secret)
	if (!keepsTheSecret(answer)) {
		forgetClaimSecret(storage)
	}
	const outcome = claimOutcome(answer)
	return {
		role: outcome.role,
		text: t(outcome.text),
		claimed: Boolean(answer && answer.ok),
	}
}
