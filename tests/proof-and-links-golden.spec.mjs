#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// proof-and-links-golden.spec.mjs: which link an action's answer may show
// (allowed and refused), what a registration and a filed report send with
// their challenge proof and honeypot, and what they answer, pinned before
// each was split into named steps.
//
// Usage:
//   node --test tests/proof-and-links-golden.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { answerLink } from '../src/shared/rowAction.js'
import { reportApi } from '../src/site/lib/reportApi.js'
import { waysInApi } from '../src/site/lib/waysIn.js'

const OWN = 'http://localhost:8080'

test('answerLink: allowed links', () => {
	for (const [link, shown] of [
		['https://x.example/a', 'https://x.example/a'],
		['  HTTPS://x.example  ', 'HTTPS://x.example'],
		['/mijn/dossier', '/mijn/dossier'],
		['/', '/'],
		[`${OWN}/index.php/apps/portaliq/site?route=x`, `${OWN}/index.php/apps/portaliq/site?route=x`],
	]) {
		assert.equal(answerLink({ ok: true, body: { link } }, OWN), shown, link)
	}
})

test('answerLink: refused links and answers', () => {
	for (const result of [
		null,
		{ ok: false, body: { link: 'https://x.example' } },
		{ ok: true },
		{ ok: true, body: { link: 5 } },
		{ ok: true, body: { link: 'javascript:alert(1)' } },
		{ ok: true, body: { link: '//evil.example/x' } },
		{ ok: true, body: { link: 'https://x.example/a b' } },
		{ ok: true, body: { link: 'http://evil.example/x' } },
		{ ok: true, body: { link: 'http://localhost:8081/x' } },
		{ ok: true, body: { link: 'http://[bad' } },
		{ ok: true, body: { link: 'data:text/html,x' } },
		{ ok: true, body: { link: '' } },
	]) {
		assert.equal(answerLink(result, OWN), '', JSON.stringify(result))
	}
	assert.equal(answerLink({ ok: true, body: { link: `${OWN}/x` } }, ''), '', 'no origin: no plain http link')
})

/**
 * A fetch that records each call and answers with one body and status.
 *
 * @param {object} answer `{status, body}` or `{throws: true}`.
 * @return {{fetchImpl: Function, calls: Array<object>}} The fetch and its calls.
 */
function recorder(answer) {
	const calls = []
	const fetchImpl = async (url, init = {}) => {
		calls.push({ url, init, body: init.body ? JSON.parse(init.body) : undefined })
		if (answer.throws) {
			throw new Error('offline')
		}
		return { ok: answer.status < 300, status: answer.status, json: async () => answer.body }
	}
	return { fetchImpl, calls }
}

test('a registration sends the portal, the person, the proof and a filled honeypot', async () => {
	const { fetchImpl, calls } = recorder({ status: 201, body: { ok: 1 } })
	const api = waysInApi('/portal/api', 'gemeente-x', fetchImpl)
	const answer = await api.registerAccount({
		email: 'a@x.nl',
		displayName: 'A',
		challenge: { nonce: 'n', expiresAt: 99, signature: 's' },
		solution: '7',
		honeypot: { field: 'website', value: 'bot' },
		contactInvitation: 'inv',
	})
	assert.deepEqual(answer, { ok: true, status: 201, error: '', data: { ok: 1 } })
	assert.equal(calls[0].url, '/portal/api/identity/register')
	assert.equal(calls[0].init.body, JSON.stringify({
		portal: 'gemeente-x',
		email: 'a@x.nl',
		displayName: 'A',
		contactInvitation: 'inv',
		nonce: 'n',
		expiresAt: 99,
		signature: 's',
		solution: '7',
		website: 'bot',
	}))
	await waysInApi('/portal/api', '', fetchImpl).registerAccount({ email: 'b@x.nl', honeypot: { field: 'website' } })
	assert.equal(calls[1].init.body, JSON.stringify({
		portal: '',
		email: 'b@x.nl',
		displayName: '',
		contactInvitation: '',
		nonce: '',
		expiresAt: 0,
		signature: '',
		solution: '',
		website: '',
	}))
	await api.registerAccount({ email: 'c@x.nl', honeypot: { value: 'x' } })
	assert.equal(Object.hasOwn(calls[2].body, 'undefined'), false)
	assert.deepEqual(Object.keys(calls[2].body), ['portal', 'email', 'displayName', 'contactInvitation', 'nonce', 'expiresAt', 'signature', 'solution'])
	const refused = recorder({ status: 409, body: { error: 'taken' } })
	assert.deepEqual(await waysInApi('/p', 'x', refused.fetchImpl).registerAccount({ email: 'd@x.nl' }), { ok: false, status: 409, error: 'taken', data: { error: 'taken' } })
})

/**
 * A fetch that answers the challenge and the report each in their own way.
 *
 * @param {object} challenge `{status, body}` or `{throws}` for the challenge.
 * @param {object} filed `{status, body}` or `{throws}` for the report.
 * @return {{fetchImpl: Function, calls: Array<object>}} The fetch and its calls.
 */
function reportServer(challenge, filed) {
	const calls = []
	const fetchImpl = async (url, init = {}) => {
		calls.push({ url, init })
		const answer = url.includes('/identity/challenge') ? challenge : filed
		if (answer.throws) {
			throw new Error('offline')
		}
		return { ok: answer.status < 300, status: answer.status, json: async () => answer.body }
	}
	return { fetchImpl, calls }
}

const FILING = { caseType: 'melding', register: 'r', schema: 's', report: { what: 'lamp' }, contact: { email: 'a@x.nl' } }

test('a report solves a hard challenge, fills the honeypot and answers its code', async () => {
	const solved = []
	const { fetchImpl, calls } = reportServer(
		{ status: 200, body: { nonce: 'n', difficulty: 3, expiresAt: 9, signature: 's', honeypotField: 'website' } },
		{ status: 201, body: { code: 1234 } },
	)
	const api = reportApi('/portal/api', 'gemeente x', fetchImpl, async (nonce, difficulty) => {
		solved.push([nonce, difficulty])
		return 'sol'
	})
	assert.deepEqual(await api.file({ ...FILING, trap: 'bot' }), { ok: true, status: 201, error: '', code: '1234' })
	assert.deepEqual(solved, [['n', 3]])
	assert.equal(calls[0].url, '/portal/api/identity/challenge?surface=report&portal=gemeente%20x')
	assert.deepEqual(calls[0].init, { credentials: 'omit', headers: { Accept: 'application/json' } })
	assert.equal(calls[1].url, '/portal/api/reports?portal=gemeente%20x')
	assert.equal(calls[1].init.credentials, 'omit')
	assert.equal(calls[1].init.body, JSON.stringify({
		caseType: 'melding',
		register: 'r',
		schema: 's',
		report: { what: 'lamp', website: 'bot' },
		contact: { email: 'a@x.nl' },
		nonce: 'n',
		solution: 'sol',
		expiresAt: 9,
		signature: 's',
	}))
	assert.deepEqual(FILING.report, { what: 'lamp' }, 'the filing itself is not changed')
})

test('a report without a challenge, with a refused or failed one, and a refused filing', async () => {
	for (const challenge of [{ status: 503, body: {} }, { throws: true }, { status: 200, body: { nonce: 'n' } }]) {
		const solved = []
		const { fetchImpl, calls } = reportServer(challenge, { status: 201, body: {} })
		const api = reportApi('/p', '', fetchImpl, async () => {
			solved.push(1)
			return 'x'
		})
		assert.deepEqual(await api.file(FILING), { ok: true, status: 201, error: '', code: '' })
		assert.equal(solved.length, 0)
		const sent = JSON.parse(calls[1].init.body)
		assert.equal(sent.solution, '')
		assert.equal(sent.nonce, challenge.body?.nonce || '')
		assert.equal(sent.expiresAt, 0)
		assert.equal(sent.signature, '')
		assert.deepEqual(sent.report, { what: 'lamp' })
		assert.equal(calls[0].url, '/p/identity/challenge?surface=report&portal=')
	}
	const refused = reportServer({ status: 200, body: {} }, { status: 400, body: { error: 'too_many' } })
	assert.deepEqual(await reportApi('/p', 'x', refused.fetchImpl).file(FILING), { ok: false, status: 400, error: 'too_many', code: '' })
	const failed = reportServer({ status: 200, body: {} }, { throws: true })
	assert.deepEqual(await reportApi('/p', 'x', failed.fetchImpl).file(FILING), { ok: false, status: 0, error: '', code: '' })
})
