#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// intake-conditional-site.spec.mjs: intake-conditional-questions-and-drafts
// T05 on the public site. The intake block shows a field only while its
// local-mode `visibleWhen` holds over the answers so far, with nextcloud-vue's
// own predicate (REQ-ICQ-001), sends only the answers of fields it shows,
// in the order the server reads them (REQ-ICQ-002), and solves the portal's
// challenge when the form carries one (REQ-ICQ-004).
//
// The predicate's imports reach @nextcloud/auth and @nextcloud/capabilities,
// which read browser state when loaded, so a window with empty storage is set
// up before the dynamic import, as tests/visible-when-local.spec.mjs does.
//
// Usage:
//   node --test tests/intake-conditional-site.spec.mjs

import assert from 'node:assert/strict'
import { createHash } from 'node:crypto'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')

/**
 * A Storage with nothing in it.
 *
 * @return {Storage}
 */
function memoryStorage() {
	const items = new Map()
	return {
		getItem: (key) => (items.has(key) ? items.get(key) : null),
		setItem: (key, value) => items.set(key, String(value)),
		removeItem: (key) => items.delete(key),
		key: (index) => [...items.keys()][index] ?? null,
		clear: () => items.clear(),
		get length() {
			return items.size
		},
	}
}

globalThis.window = {
	localStorage: memoryStorage(),
	sessionStorage: memoryStorage(),
	OC: {},
}

const { shownFields, shownAnswers } = await import(
	join(ROOT, 'src/site/lib/intakeVisibility.js')
)
const { challengeProof, submitIntake } = await import(
	join(ROOT, 'src/site/lib/intakeApi.js')
)

const FIELDS = [
	{ name: 'together', label: 'Do you live together?', options: ['yes', 'no'] },
	{
		name: 'partner',
		label: 'Name of your partner',
		required: true,
		visibleWhen: { field: 'together', op: 'eq', value: 'yes' },
	},
	{
		name: 'since',
		label: 'Since when?',
		visibleWhen: { field: 'partner', op: 'notEmpty' },
	},
	{ name: 'remote', label: 'Remote', visibleWhen: { endpoint: '/x' } },
]

test('a question appears when it applies and disappears when it does not', () => {
	const yes = shownFields(FIELDS, { together: 'yes', partner: 'Sam' })
	assert.deepEqual(
		yes.map((f) => f.name),
		['together', 'partner', 'since'],
	)
	const no = shownFields(FIELDS, { together: 'no', partner: 'Sam' })
	assert.deepEqual(
		no.map((f) => f.name),
		['together'],
	)
})

test('a field without a condition is always shown; a non-local condition hides', () => {
	const shown = shownFields(FIELDS, {})
	assert.deepEqual(
		shown.map((f) => f.name),
		['together'],
	)
})

test('the answers of hidden fields are not sent, in declared order like the server', () => {
	const answers = shownAnswers(FIELDS, {
		together: 'no',
		partner: 'Sam',
		since: '2020',
	})
	assert.deepEqual(answers, { together: 'no' })
	const kept = shownAnswers(FIELDS, {
		together: 'yes',
		partner: 'Sam',
		since: '2020',
	})
	assert.deepEqual(kept, { together: 'yes', partner: 'Sam', since: '2020' })
})

test('the challenge is solved to the difficulty the form was issued', async () => {
	const challenge = {
		nonce: 'abc123',
		difficulty: 4,
		expiresAt: 1900000000,
		signature: 'sig',
	}
	const proof = await challengeProof(challenge)
	assert.equal(proof.nonce, 'abc123')
	assert.equal(proof.expiresAt, 1900000000)
	assert.equal(proof.signature, 'sig')
	const digest = createHash('sha256').update(`abc123:${proof.solution}`).digest()
	assert.equal(digest[0] >> 4, 0, 'four leading zero bits')
	assert.equal(await challengeProof(null), null)
})

test('submit carries the challenge proof the server checks', async () => {
	const calls = []
	const fetchImpl = async (url, init) => {
		calls.push({ url, body: JSON.parse(init.body) })
		return { ok: true, status: 202, json: async () => ({ reference: 'R-1' }) }
	}
	const proof = { nonce: 'n', solution: '7', expiresAt: 5, signature: 's' }
	await submitIntake('/b', 'route', { a: 1 }, 'p', '', fetchImpl, proof)
	assert.deepEqual(calls[0].body, {
		route: 'route',
		answers: { a: 1 },
		portal: 'p',
		nonce: 'n',
		solution: '7',
		expiresAt: 5,
		signature: 's',
	})
	await submitIntake('/b', 'route', { a: 1 }, '', '', fetchImpl)
	assert.deepEqual(calls[1].body, { route: 'route', answers: { a: 1 } })
})

test('the intake block renders the shown fields, sends the shown answers and the proof', () => {
	const source = readFileSync(
		join(ROOT, 'src/site/components/IntakeFormBlock.vue'),
		'utf8',
	)
	assert.match(source, /from '\.\.\/lib\/intakeVisibility\.js'/)
	assert.match(source, /shownFields\(/)
	assert.match(source, /shownAnswers\(/)
	assert.match(source, /challengeProof\(this\.render\.challenge/)
})
