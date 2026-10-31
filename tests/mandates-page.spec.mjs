#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// mandates-page.spec.mjs: the Machtigingen sections of "Toegang tot zaken"
// (site-mandates-the-represented-manage T4, boards Machtigingen and
// Machtigen). The end date as day, month and year, the words of the list, the
// api calls, the dialog that sends nothing until it is right, stopping a held
// mandate, and the mailed link kept across sign-in.
//
// Usage:
//   node --test tests/mandates-page.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { mandatesApi } from '../src/shared/areaApi.js'
import {
	keepMandateInvitation,
	MANDATE_INVITATION_KEY,
	redeemKeptMandateInvitation,
} from '../src/shared/mandateInvitation.js'
import {
	endActionText,
	endDayOf,
	endText,
	mandateProblemText,
	scopeText,
	stateText,
	whoOf,
} from '../src/site/components/e/mandates.js'
import strings from '../src/site/pages/e/strings.js'
import { loadSfc, renderSfc } from './support/render-sfc.mjs'

function t(key, vars = {}) {
	return key.replace(/\{(\w+)\}/g, (_, name) => String(vars[name] ?? ''))
}
const MODAL = 'src/site/modals/e/MandateInviteModal.vue'
const GIVEN = 'src/site/components/e/MandatesGiven.vue'
const HELD = 'src/site/components/e/MandatesHeld.vue'
const PAGE = 'src/site/pages/e/AccessRequestsPage.vue'

/**
 * A portal api double that records its calls.
 *
 * @param {object} answers Answer per `METHOD path`.
 * @return {{api: object, calls: Array}}
 */
function fakeApi(answers = {}) {
	const calls = []
	return {
		calls,
		api: {
			request: async (method, path, body) => {
				calls.push([method, path, body])
				return (
					answers[`${method} ${path}`] || {
						ok: true,
						status: 200,
						json: {},
					}
				)
			},
		},
	}
}

test('the end date is a whole day, or nothing', () => {
	assert.equal(endDayOf({ day: '', month: '', year: '' }), '')
	assert.equal(endDayOf({ day: '31', month: '12', year: '2026' }), '2026-12-31')
	assert.equal(endDayOf({ day: '1', month: '7', year: '2027' }), '2027-07-01')
	assert.equal(
		endDayOf({ day: '31', month: '2', year: '2027' }),
		null,
		'no such day',
	)
	assert.equal(
		endDayOf({ day: '12', month: '', year: '2027' }),
		null,
		'incomplete',
	)
	assert.equal(
		endDayOf({ day: '1', month: '1', year: '27' }),
		null,
		'a two-digit year',
	)
})

test('the list says who, what, until when and the state in words', () => {
	assert.deepEqual(whoOf({ name: 'Mark de Vries', email: 'mark@example.nl' }), {
		name: 'Mark de Vries',
		email: 'mark@example.nl',
	})
	assert.deepEqual(whoOf({ kind: 'invitation', email: 't.visser@example.nl' }), {
		name: 't.visser@example.nl',
		email: '',
	})
	assert.deepEqual(whoOf({ holder: 'kvk:12345678' }), {
		name: 'kvk:12345678',
		email: '',
	})
	assert.equal(scopeText([], [], t), 'All cases')
	assert.equal(
		scopeText(['ct-p', 'ct-x'], [{ id: 'ct-p', title: 'Parkeervergunning' }], t),
		'Parkeervergunning, ct-x',
	)
	assert.equal(
		endText('', (v) => v, t),
		'No end date',
	)
	assert.equal(
		endText('2026-12-31', () => '31 december 2026', t),
		'31 december 2026',
	)
	assert.equal(stateText({ state: 'pending' }), 'Waiting for an answer')
	assert.equal(stateText({ state: 'active' }), 'Active')
	assert.equal(endActionText({ kind: 'invitation' }), 'Withdraw invitation')
	assert.equal(endActionText({ kind: 'mandate' }), 'Revoke')
	assert.match(mandateProblemText('bad_end_date'), /after today/)
	assert.equal(
		mandateProblemText('something'),
		'That did not work. Try again later.',
	)
})

test('the api reaches the mandate routes with the right verbs, and a refusal reads as none', async () => {
	const { api, calls } = fakeApi({
		'GET /mandates/given': {
			ok: false,
			status: 403,
			json: { error: 'forbidden' },
		},
		'GET /mandates/held': {
			ok: true,
			status: 200,
			json: { items: [{ id: 'm1' }] },
		},
		'GET /mandates/case-types?portal=zuid%20drecht': {
			ok: true,
			status: 200,
			json: { items: [{ id: 'ct', title: 'T' }] },
		},
	})
	const client = mandatesApi(api)
	assert.equal(
		await client.given(),
		null,
		'a session that may not manage sees no list',
	)
	assert.deepEqual(await client.held(), [{ id: 'm1' }])
	assert.deepEqual(await client.caseTypes('zuid drecht'), [
		{ id: 'ct', title: 'T' },
	])
	await client.invite({
		email: 'a@b.nl',
		caseTypes: ['ct'],
		expiresAt: '2027-01-01',
	})
	await client.end({ kind: 'invitation', id: 'i 1' })
	await client.end({ kind: 'mandate', id: 'm1' })
	await client.stop('m1')
	assert.deepEqual(calls.slice(3), [
		[
			'POST',
			'/mandates/invitations',
			{
				email: 'a@b.nl',
				caseTypes: ['ct'],
				label: '',
				expiresAt: '2027-01-01',
			},
		],
		['DELETE', '/mandates/invitations/i%201', null],
		['POST', '/mandates/m1/revoke', {}],
		['POST', '/mandates/held/m1/stop', {}],
	])
})

test('the dialog sends nothing until the date and the choice are right, and keeps what was typed on a refusal', async () => {
	const modal = await loadSfc(MODAL)
	const { api, calls } = fakeApi({
		'POST /mandates/invitations': {
			ok: false,
			status: 400,
			json: { error: 'bad_email' },
		},
	})
	const emitted = []
	const vm = {
		email: ' tom@example.nl ',
		scope: 'all',
		chosen: [],
		end: { day: '31', month: '2', year: '2027' },
		problem: '',
		busy: false,
		api,
		$emit: (...a) => emitted.push(a),
	}
	await modal.methods.submit.call(vm)
	assert.equal(vm.problem, 'bad_end_date')
	vm.end = { day: '', month: '', year: '' }
	vm.scope = 'some'
	await modal.methods.submit.call(vm)
	assert.equal(vm.problem, 'no_case_type')
	assert.deepEqual(calls, [], 'nothing sent yet')
	vm.chosen = ['ct-p']
	await modal.methods.submit.call(vm)
	assert.deepEqual(calls[0], [
		'POST',
		'/mandates/invitations',
		{ email: 'tom@example.nl', caseTypes: ['ct-p'], label: '', expiresAt: '' },
	])
	assert.equal(vm.problem, 'bad_email')
	assert.equal(vm.email, ' tom@example.nl ', 'the address stays')
	const ok = fakeApi()
	await modal.methods.submit.call({ ...vm, api: ok.api, scope: 'all' })
	assert.deepEqual(emitted, [['sent']])
	const html = await renderSfc(MODAL, {
		api: {},
		t,
		caseTypes: [{ id: 'ct-p', title: 'Parkeervergunning' }],
	})
	assert.match(html, /Authorise someone/)
	assert.match(html, /Only these kinds of case/)
	assert.match(html, /<label for="pq-mandate-email"/)
	assert.doesNotMatch(html, /BSN|burgerservicenummer/i, 'nobody is looked up')
	assert.doesNotMatch(
		await renderSfc(MODAL, { api: {}, t }),
		/Only these kinds of case/,
		'no kinds to choose, no choice',
	)
})

test('the given section ends an item and reads again; it stays away for a session that may not manage', async () => {
	const given = await loadSfc(GIVEN)
	const { api, calls } = fakeApi({
		'GET /mandates/given': { ok: true, status: 200, json: { items: [] } },
	})
	const vm = { notice: 'x', problem: '', items: null, client: mandatesApi(api) }
	vm.load = () => given.methods.load.call(vm)
	await given.methods.end.call(vm, { kind: 'mandate', id: 'm1' })
	assert.deepEqual(
		calls.map((c) => `${c[0]} ${c[1]}`),
		['POST /mandates/m1/revoke', 'GET /mandates/given'],
	)
	assert.deepEqual(vm.items, [])
	assert.equal(vm.notice, '')
	assert.doesNotMatch(
		await renderSfc(GIVEN, { api: {}, t }),
		/mandates-given/,
		'nothing before the list is read',
	)
})

test('stopping a held mandate asks first, and acting under it falls back to yourself', async () => {
	const held = await loadSfc(HELD)
	const { actingFor, chooseActingFor } =
		await import('../src/site/components/e/actingFor.js')
	chooseActingFor('m1', null)
	const { api, calls } = fakeApi({
		'GET /mandates/held': { ok: true, status: 200, json: { items: [] } },
	})
	const emitted = []
	const vm = {
		api,
		held: [{ id: 'm1' }],
		confirming: 'm1',
		problem: '',
		$emit: (...a) => emitted.push(a),
	}
	await held.methods.stop.call(vm, { id: 'm1' })
	assert.deepEqual(
		calls.map((c) => `${c[0]} ${c[1]}`),
		['POST /mandates/held/m1/stop', 'GET /mandates/held'],
	)
	assert.equal(actingFor.id, 'self')
	assert.deepEqual(vm.held, [])
	assert.deepEqual(emitted, [['stopped', 'm1']])
	const reader = {
		t,
		locale: 'nl',
		caseTypes: [],
		partyOf: held.methods.partyOf,
	}
	const line = held.methods.line.call(reader, {
		givenBy: 'organisation',
		grantedAt: '2026-09-12T10:00:00Z',
		expiresAt: '',
	})
	assert.match(line, /^All cases · given by the municipality on .+ · No end date$/)
	const byParty = held.methods.line.call(reader, {
		id: 'm9',
		onBehalfOf: 'kvk:12345678',
		givenBy: 'party',
		grantedAt: '2026-10-02T10:00:00Z',
		expiresAt: '',
	})
	assert.match(byParty, /^All cases · given by 12345678 on .+ · No end date$/)
	assert.equal(
		held.methods.line.call(reader, {
			givenBy: 'party',
			onBehalfOf: 'kvk:1',
			expiresAt: '',
		}),
		'All cases · given by 1 · No end date',
		'a mandate without its day still says who gave it',
	)
})

test('a mailed link is kept across sign-in, handed back once, and forgotten when answered', async () => {
	const kept = new Map()
	const storage = {
		getItem: (k) => kept.get(k) ?? null,
		setItem: (k, v) => kept.set(k, v),
		removeItem: (k) => kept.delete(k),
	}
	let replaced = ''
	const secret = keepMandateInvitation(
		{ hash: '#mandate-invitation=abc%20123', pathname: '/mijn', search: '' },
		{
			replaceState: (a, b, url) => {
				replaced = url
			},
		},
		storage,
	)
	assert.equal(secret, 'abc 123')
	assert.equal(replaced, '/mijn', 'the secret leaves the address bar')
	assert.equal(kept.get(MANDATE_INVITATION_KEY), 'abc 123')
	assert.equal(keepMandateInvitation({ hash: '#other=x' }, null, storage), '')

	const waiting = await redeemKeptMandateInvitation({
		api: {},
		session: null,
		t,
		storage,
	})
	assert.equal(waiting.claimed, false)
	assert.equal(kept.has(MANDATE_INVITATION_KEY), true, 'kept until signed in')

	const own = fakeApi({
		'POST /mandates/invitations/accept': {
			ok: false,
			status: 409,
			json: { error: 'own_invitation' },
		},
	})
	assert.match(
		(
			await redeemKeptMandateInvitation({
				api: own.api,
				session: {},
				t,
				storage,
			})
		).text,
		/own invitation/,
	)
	assert.equal(
		kept.has(MANDATE_INVITATION_KEY),
		false,
		'an answer forgets the secret',
	)

	kept.set(MANDATE_INVITATION_KEY, 's')
	const { api, calls } = fakeApi({
		'POST /mandates/invitations/accept': {
			ok: true,
			status: 200,
			json: { id: 'm9' },
		},
	})
	const done = await redeemKeptMandateInvitation({ api, session: {}, t, storage })
	assert.deepEqual(calls[0], [
		'POST',
		'/mandates/invitations/accept',
		{ token: 's' },
	])
	assert.equal(done.claimed, true)
	assert.equal(
		await redeemKeptMandateInvitation({ api, session: {}, t, storage }),
		null,
		'once',
	)
})

test('the page shows the board order, and every string is Dutch and English without em-dashes', async () => {
	const html = await renderSfc(PAGE, {
		api: { fetchMyAccessRequests: async () => [] },
		t,
		initialRequests: [],
	})
	const order = [
		'Access to cases',
		'Ask for access',
		'Your requests',
		'Mandates through DigiD Machtigen',
	]
	const at = order.map((text) => html.indexOf(text))
	assert.ok(
		at.every((i) => i >= 0) && at.every((i, n) => n === 0 || i > at[n - 1]),
		`board order: ${at}`,
	)
	for (const lang of ['nl', 'en']) {
		for (const [key, text] of Object.entries(strings[lang])) {
			assert.doesNotMatch(`${key} ${text}`, /—/, `${lang}: ${key}`)
		}
	}
	for (const key of [
		'Authorise someone',
		'Who may arrange matters for you?',
		'Stop mandate',
		'Your mandate for {party}',
		'Send the invitation',
		'Only these kinds of case',
	]) {
		assert.ok(strings.nl[key] && strings.en[key], key)
	}
	assert.equal(
		strings.nl['Send the invitation'],
		'Uitnodiging sturen',
		'the board Machtigen',
	)
})
