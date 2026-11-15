#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// cosign-link.spec.mjs: the co-signer's link (resident-identity-in-forms T04,
// REQ-RIF-003). The token is stripped from the address and kept over the
// sign-in, read back after it, forgotten after the answer, and each refusal
// has its own sentence.
//
// Usage:
//   node --test tests/cosign-link.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import {
	COSIGN_KEY,
	cosignRefusal,
	forgetCosignToken,
	keepCosignToken,
	keptCosignToken,
} from '../src/shared/cosignLink.js'

/**
 * A sessionStorage stand-in.
 *
 * @return {Storage}
 */
function memoryStorage() {
	const map = new Map()
	return {
		getItem: (key) => (map.has(key) ? map.get(key) : null),
		setItem: (key, value) => map.set(key, String(value)),
		removeItem: (key) => map.delete(key),
	}
}

// @e2e exclude node unit test of the link keeper; the browser flow is T08
test('REQ-RIF-003: the link is stripped from the address and kept over the sign-in', () => {
	const storage = memoryStorage()
	const replaced = []
	const history = { replaceState: (...args) => replaced.push(args[2]) }
	const token = keepCosignToken(
		{ hash: '#cosign=abc.def', pathname: '/site/zuiddrecht', search: '' },
		history,
		storage,
	)

	assert.equal(token, 'abc.def')
	assert.deepEqual(replaced, ['/site/zuiddrecht'])
	assert.equal(storage.getItem(COSIGN_KEY), 'abc.def')

	// After the sign-in the address has no fragment; the kept token answers.
	assert.equal(
		keepCosignToken(
			{ hash: '', pathname: '/site/zuiddrecht', search: '' },
			history,
			storage,
		),
		'abc.def',
	)
	assert.equal(keptCosignToken(storage), 'abc.def')

	forgetCosignToken(storage)
	assert.equal(keptCosignToken(storage), '')
})

test('REQ-RIF-003: without storage nothing throws', () => {
	assert.equal(keepCosignToken({ hash: '#cosign=x.y' }, null, null), 'x.y')
	assert.equal(keptCosignToken(null), '')
	assert.doesNotThrow(() => forgetCosignToken(null))
})

test('REQ-RIF-003: each refusal has its own sentence', () => {
	assert.equal(
		cosignRefusal(403, 'same_subject'),
		'You cannot co-sign your own request.',
	)
	assert.equal(
		cosignRefusal(410, 'expired'),
		'The time to co-sign this request has passed.',
	)
	assert.equal(
		cosignRefusal(409, 'not_awaiting'),
		'This request has already been answered.',
	)
	assert.equal(
		cosignRefusal(400, 'invalid_link'),
		'This link does not work. Ask the applicant for a new one.',
	)
	assert.equal(
		cosignRefusal(503, 'try_again_later'),
		'Something went wrong. Try again later.',
	)
})
