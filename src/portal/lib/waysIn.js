// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The ways into the portal without an account yet (identity-ways-in-screens):
// the mailed links' fragments, the proof of work the registration form solves
// in the browser, and the sentence for each outcome.

const FRAGMENTS = ['activate', 'invitation', 'reference']

/**
 * Read a mailed link's secret once and strip it from the address bar:
 * `#activate=`, `#invitation=` or `#reference=`. A fragment never reaches a
 * server access log.
 *
 * @param {Location|object} location The window location.
 * @param {History|object} history The window history.
 * @return {{kind: string, token: string}|null} The link, or null when the page was not opened from one.
 *
 * @spec openspec/changes/archive/2026-10-01-identity-ways-in-screens/tasks.md#T06
 */
export function consumeWayInFragment(location, history) {
	const match = String(location?.hash || '').match(/^#(activate|invitation|reference)=([^&]+)$/)
	if (!match || !FRAGMENTS.includes(match[1])) {
		return null
	}

	const url = String(location.href || '').replace(/#.*$/, '')
	history?.replaceState?.(null, '', url)
	return { kind: match[1], token: decodeURIComponent(match[2]) }
}

/**
 * How many leading zero bits a digest carries.
 *
 * @param {Uint8Array} bytes The digest.
 * @return {number}
 */
export function leadingZeroBits(bytes) {
	let bits = 0
	for (const byte of bytes) {
		if (byte === 0) {
			bits += 8
			continue
		}
		return bits + Math.clz32(byte) - 24
	}
	return bits
}

/**
 * Solve the portal's challenge: the first counter whose
 * `sha256(nonce + ':' + counter)` carries `difficulty` leading zero bits, the
 * check PortalChallengeService::solves() makes on the server.
 *
 * @param {string} nonce The issued nonce.
 * @param {number} difficulty The leading zero bits required.
 * @param {SubtleCrypto} subtle The browser's digest.
 * @return {Promise<string>} The solution, or '' when none was found in reach.
 *
 * @spec openspec/changes/archive/2026-10-01-identity-ways-in-screens/tasks.md#T02
 */
export async function solveChallenge(nonce, difficulty, subtle = globalThis.crypto?.subtle) {
	if (!subtle || !nonce) {
		return ''
	}

	const encoder = new TextEncoder()
	const limit = 2 ** Math.min(30, Math.max(1, difficulty) + 8)
	for (let counter = 0; counter < limit; counter++) {
		const digest = new Uint8Array(await subtle.digest('SHA-256', encoder.encode(`${nonce}:${counter}`)))
		if (leadingZeroBits(digest) >= difficulty) {
			return String(counter)
		}
	}
	return ''
}

/**
 * The sentence for a refusal the ways-in routes answer, as an English
 * source key.
 *
 * @param {string} code The refusal code.
 * @return {string} The sentence key.
 *
 * @spec openspec/specs/portal-ways-in/spec.md#requirement-you-can-create-an-account-where-the-portal-allows-it-req-iwi-002
 */
export function wayInRefusalText(code) {
	const texts = {
		registration_off: 'This portal does not take new accounts.',
		domain_not_allowed: 'This portal takes accounts for some e-mail domains only, and yours is not one of them.',
		invalid_email: 'Check the address and try again.',
		challenge_failed: 'The check against automated sign-ups did not pass. Try again.',
		activation_not_valid: 'This link is no longer valid.',
		link_not_valid: 'This link is no longer valid.',
		invitation_not_valid: 'This invitation is no longer valid.',
		route_not_offered: 'Cases of this kind cannot be followed with a case number.',
	}
	return texts[code] || 'That did not work. Try again later.'
}

/**
 * What a visitor reads after a registration was accepted.
 *
 * @param {string} awaiting The policy the server answered: `activation` or `approval`.
 * @return {string} The sentence key.
 *
 * @spec openspec/specs/portal-ways-in/spec.md#requirement-you-can-create-an-account-where-the-portal-allows-it-req-iwi-002
 */
export function registrationOutcomeText(awaiting) {
	if (awaiting === 'activation') {
		return 'We sent you an e-mail. Follow the link in it to activate your account.'
	}
	return 'We will let you know when your account is ready.'
}
