/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * ONE KEY PER SUBMISSION (action-forward-survives-a-slow-answer).
 *
 * A form makes a key when it opens and sends it with every send of the same
 * answers. Portaliq relays it to the app that does the work, and that app
 * answers a repeated key with what the first send made. So a resident who
 * sends again after a slow answer gets the same case, not a second one.
 *
 * Pure functions that import nothing, so the site and a node test share them.
 */

/** The header the key travels in, to portaliq and on to the app. */
export const SUBMISSION_HEADER = 'Idempotency-Key'

/** What a key may look like; portaliq drops anything else. */
const KEY = /^[A-Za-z0-9_-]{16,128}$/

/**
 * A new submission key: a random uuid, or 32 random hex characters where the
 * browser has no `randomUUID`.
 *
 * @param {Crypto|undefined} [cryptoImpl] The Web Crypto object (test seam).
 * @return {string} The key.
 * @spec openspec/changes/action-forward-survives-a-slow-answer/specs/portal-contribution-contract/spec.md#requirement-a-repeated-submission-carries-the-same-key-req-afs-001
 */
export function newSubmissionKey(cryptoImpl = globalThis.crypto) {
	if (cryptoImpl && typeof cryptoImpl.randomUUID === 'function') {
		return cryptoImpl.randomUUID()
	}
	const bytes = new Uint8Array(16)
	if (cryptoImpl && typeof cryptoImpl.getRandomValues === 'function') {
		cryptoImpl.getRandomValues(bytes)
	} else {
		for (let i = 0; i < bytes.length; i++) {
			bytes[i] = Math.floor(Math.random() * 256)
		}
	}
	return Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('')
}

/**
 * The header that carries a send's key, or none when the send has no usable key.
 *
 * @param {{submissionKey?: string}|undefined} options The send's options.
 * @return {Record<string, string>} The header to add.
 * @spec openspec/changes/action-forward-survives-a-slow-answer/specs/portal-contribution-contract/spec.md#requirement-a-repeated-submission-carries-the-same-key-req-afs-001
 */
export function submissionHeaders(options) {
	const key = options && options.submissionKey
	return typeof key === 'string' && KEY.test(key)
		? { [SUBMISSION_HEADER]: key }
		: {}
}

/**
 * Whether a failed send may still be done on the app's side: the forward
 * timed out (504), or the app is still busy with the same key.
 *
 * @param {{status?: number, object?: object, body?: object}} result The send's result.
 * @return {boolean} True when the page should say "may still be processing".
 * @spec openspec/changes/action-forward-survives-a-slow-answer/specs/portal-contribution-contract/spec.md#requirement-a-forward-that-times-out-says-the-request-may-still-be-processing-req-afs-002
 */
export function mayStillBeProcessing(result) {
	const body = (result && (result.object || result.body)) || {}
	return (
		(result && result.status === 504)
		|| body.error === 'forward_timeout'
		|| body.error === 'submission_processing'
	)
}
