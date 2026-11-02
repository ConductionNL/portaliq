#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// intake-api.golden.spec.mjs: pins what every intake call sends and answers,
// for every answer the server can give (tests/Fixtures/Golden/intake-api.json).
//
// Usage:
//   node --test tests/golden/intake-api.golden.spec.mjs

import { test } from 'node:test'
import {
	checkEmailCode,
	decideStep,
	fetchCatalogue,
	fetchFamily,
	loadForm,
	lookupAddress,
	lookUpStatus,
	payIntake,
	requestEmailCode,
	submitIntake,
} from '../../src/site/lib/intakeApi.js'
import { golden, outcomeOf, recordingFetch } from '../support/golden.mjs'

const BASE = '/apps/portaliq/portal/api/'

const ANSWERS = {
	ok: [{ status: 200, body: { reference: 'REF-1', confirmationText: 'Dank', confirmation: { title: 'T' }, mailedTo: 'a@b.nl', receivedAt: '2026-01-01', termStartsAt: '2026-01-02', deadline: '2026-02-01', topics: [{ topic: 'Wonen', entries: [{ route: 'a/b' }, { route: '' }] }, { topic: 'Leeg', entries: [{}] }], kind: 'form', street: 'Laan', town: 'Stad', members: [{ ref: '1' }], outcome: 'yes', output: 'o', nextStep: 's2', checkoutUrl: 'https://pay', resendAfter: 30, proof: 'P' } }],
	empty: [{ status: 200, body: {} }],
	external: [{ status: 200, body: { kind: 'external' } }],
	noForm: [{ status: 200, body: { resolvesToNoForm: true } }],
	refused: [{ status: 400, body: { errors: { name: 'required' }, error: 'bad' } }],
	destination: [{ status: 422, body: { errors: { x: 'no' } } }],
	signIn: [{ status: 401, body: { level: 'midden' } }],
	forbidden: [{ status: 403 }],
	missing: [{ status: 404 }],
	down: [{ status: 503, body: { error: 'down' } }],
	noJson: [{ status: 200 }],
	network: [{ throws: true }],
}

const CALLS = {
	fetchCatalogue: [
		(f) => fetchCatalogue({ base: BASE, portal: 'zuid', fetchImpl: f }),
		(f) => fetchCatalogue({ base: '', portal: '', fetchImpl: f }),
	],
	loadForm: [
		(f) => loadForm({ base: BASE, portal: 'zuid', token: 'tok', fetchImpl: f }, 'aanvragen/verhuizing'),
		(f) => loadForm({ base: BASE, portal: '', token: '', fetchImpl: f }, 'r'),
	],
	submitIntake: [
		(f) => submitIntake({ base: BASE, portal: 'zuid', token: 'tok', fetchImpl: f }, { route: 'r', answers: { a: '1' }, statements: ['truth'], verifiedEmails: { 'a@b.nl': 'p' } }),
		(f) => submitIntake({ base: BASE, portal: '', token: '', fetchImpl: f }, { route: 'r', answers: null }),
		(f) => submitIntake({ base: BASE, portal: '', token: '', fetchImpl: f }, { route: 'r', answers: {}, statements: [], verifiedEmails: {} }),
	],
	lookUpStatus: [
		(f) => lookUpStatus({ base: BASE, portal: 'zuid', fetchImpl: f }, ' REF-1 '),
		(f) => lookUpStatus({ base: BASE, portal: 'zuid', fetchImpl: f }, '   '),
	],
	lookupAddress: [
		(f) => lookupAddress({ base: BASE, fetchImpl: f }, { postcode: '1234AB', number: '1', letter: 'a', addition: '' }),
	],
	fetchFamily: [
		(f) => fetchFamily({ base: BASE, token: 'tok', fetchImpl: f }, true),
		(f) => fetchFamily({ base: BASE, token: '', fetchImpl: f }, false),
	],
	decideStep: [
		(f) => decideStep({ base: BASE, portal: 'zuid', token: 'tok', fetchImpl: f }, { route: 'r', step: 's1', answers: { a: 1 } }),
		(f) => decideStep({ base: BASE, portal: '', token: '', fetchImpl: f }, { route: 'r', step: 's1', answers: null }),
	],
	payIntake: [
		(f) => payIntake({ base: BASE, portal: 'zuid', token: 'tok', fetchImpl: f }, 'REF-1'),
		(f) => payIntake({ base: BASE, portal: '', token: '', fetchImpl: f }, 'REF-1'),
	],
	requestEmailCode: [
		(f) => requestEmailCode({ base: BASE, portal: 'zuid', token: 'tok', fetchImpl: f }, { route: 'r', email: 'a@b.nl' }),
		(f) => requestEmailCode({ base: BASE, portal: '', token: '', fetchImpl: f }, { route: 'r', email: 'a@b.nl' }),
	],
	checkEmailCode: [
		(f) => checkEmailCode({ base: BASE, portal: 'zuid', token: 'tok', fetchImpl: f }, { route: 'r', email: 'a@b.nl', code: '123456' }),
		(f) => checkEmailCode({ base: BASE, portal: '', token: '', fetchImpl: f }, { route: 'r', email: 'a@b.nl', code: '1' }),
	],
}

test('every intake call sends and answers as pinned', async () => {
	const file = golden('intake-api')
	for (const [name, calls] of Object.entries(CALLS)) {
		for (const [index, call] of calls.entries()) {
			for (const [answerName, answers] of Object.entries(ANSWERS)) {
				const recorder = recordingFetch(answers)
				const outcome = await outcomeOf(() => call(recorder.fetch))
				file.check(`${name}#${index}:${answerName}`, { calls: recorder.calls, outcome })
			}
		}
	}
	file.save()
})
