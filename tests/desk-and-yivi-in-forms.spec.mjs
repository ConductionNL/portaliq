#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// desk-and-yivi-in-forms.spec.mjs: an employee fills a form in for a
// resident (resident-identity-in-forms REQ-RIF-005) and the Yivi way in
// (REQ-RIF-004). The section offers the work account sign-in, then asks whom
// the form is for; the lookup and the submit carry the applicant; Yivi gets
// its own button with the hint that it shares only what the form needs.
//
// Usage:
//   node --test tests/desk-and-yivi-in-forms.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { deskProblem, identityWords, yiviAttributeName } from '../src/site/components/forms/identityWords.js'
import { signInRoutes } from '../src/site/lib/authApi.js'
import { deskSignInUrl, lookUpApplicant, submitIntake } from '../src/site/lib/intakeApi.js'
import { loadSfc, renderSfc } from './support/render-sfc.mjs'

const DESK = 'src/site/components/forms/DeskApplicant.vue'

test('before signing in the section offers the work account', async () => {
	const html = await renderSfc(DESK, { staff: null, signInHref: '/portal/api/session/desk?route=woo', locale: 'nl' })
	assert.match(html, /Voor medewerkers/)
	assert.match(html, /Medewerker vult in voor een inwoner\. De inwoner staat als aanvrager op de zaak\./)
	assert.match(html, /href="\/portal\/api\/session\/desk\?route=woo"[^>]*>\s*Inloggen met werkaccount/)
	assert.doesNotMatch(html, /Verder als/)
})

test('signed in, it asks whom the form is for in three ways', async () => {
	const html = await renderSfc(DESK, { staff: { uid: 'balie1', displayName: 'Bart Balie' }, locale: 'nl' })
	assert.match(html, /U bent ingelogd met uw werkaccount als Bart Balie\./)
	assert.match(html, /Verder als/)
	assert.match(html, /Inwoner \(BSN\)/)
	assert.match(html, /Bedrijf \(KvK-nummer\)/)
	assert.match(html, /Uzelf, met de inwoner als aanvrager/)
	assert.match(html, /BSN van de inwoner/)
	assert.doesNotMatch(html, /<form/, 'it sits inside the intake form, and forms do not nest')
})

test('choosing hands the applicant and the prefill to the form, and a number without details still names the applicant', async () => {
	const desk = await loadSfc(DESK)
	const emitted = []
	const vm = {
		kind: 'bsn',
		value: ' 999993653 ',
		busy: false,
		problem: '',
		chosen: null,
		words: identityWords('nl'),
		lookUp: async (kind, value) => {
			assert.deepEqual([kind, value], ['bsn', '999993653'])
			return { ok: true, error: '', applicant: { kind, value }, available: false, prefill: {}, reason: 'not_found' }
		},
		$emit: (...a) => emitted.push(a),
	}
	await desk.methods.choose.call(vm)
	assert.deepEqual(emitted, [['applicant', { applicant: { kind: 'bsn', value: '999993653' }, prefill: {} }]])
	assert.equal(vm.problem, identityWords('nl').deskNotFound)
	assert.deepEqual(vm.chosen.applicant, { kind: 'bsn', value: '999993653' })

	desk.methods.change.call(vm)
	assert.equal(vm.chosen, null)
	assert.deepEqual(emitted.at(-1), ['applicant', { applicant: null, prefill: {} }])
})

test('a refused lookup names no applicant and says why', async () => {
	const desk = await loadSfc(DESK)
	const emitted = []
	const vm = {
		kind: 'bsn',
		value: '123',
		busy: false,
		problem: '',
		chosen: null,
		words: identityWords('nl'),
		lookUp: async () => ({ ok: false, error: 'invalid_applicant', applicant: null, available: false, prefill: {}, reason: '' }),
		$emit: (...a) => emitted.push(a),
	}
	await desk.methods.choose.call(vm)
	assert.deepEqual(emitted, [])
	assert.equal(vm.problem, identityWords('nl').deskInvalid)
	vm.value = '  '
	await desk.methods.choose.call(vm)
	assert.equal(vm.problem, identityWords('nl').deskInvalid, 'nothing typed is not looked up')
})

test('the api posts the lookup and the submit carries the applicant', async () => {
	const asked = []
	const answer = (status, body) => async (url, init) => {
		asked.push({ url, init })
		return { ok: status < 400, status, json: async () => body }
	}
	const found = await lookUpApplicant(
		{ base: '/portal/api', portal: 'zuid', token: 'desk', fetchImpl: answer(200, { applicant: { kind: 'bsn', value: '999993653' }, available: true, prefill: { applicantName: 'Sanne' }, reason: '' }) },
		{ route: 'woo', kind: 'bsn', value: '999993653' },
	)
	assert.equal(asked[0].url, '/portal/api/intake/on-behalf')
	assert.deepEqual(JSON.parse(asked[0].init.body), { route: 'woo', kind: 'bsn', value: '999993653', portal: 'zuid' })
	assert.equal(asked[0].init.headers.Authorization, 'Bearer desk')
	assert.deepEqual(found, { ok: true, error: '', applicant: { kind: 'bsn', value: '999993653' }, available: true, prefill: { applicantName: 'Sanne' }, reason: '' })
	const refused = await lookUpApplicant({ base: '/p', portal: '', token: '', fetchImpl: answer(403, { error: 'staff_mode_refused' }) }, { route: 'woo', kind: 'bsn', value: '1' })
	assert.equal(refused.error, 'staff_mode_refused')
	assert.equal(refused.applicant, null)

	await submitIntake(
		{ base: '/portal/api', portal: 'zuid', token: 'desk', fetchImpl: answer(200, { reference: 'Z-1' }) },
		{ route: 'woo', answers: {}, onBehalf: { kind: 'named', value: 'Sanne' } },
	).catch(() => null)
	assert.deepEqual(JSON.parse(asked.at(-1).init.body).onBehalf, { kind: 'named', value: 'Sanne' })
	await submitIntake({ base: '/portal/api', portal: 'zuid', token: '', fetchImpl: answer(200, { reference: 'Z-2' }) }, { route: 'woo', answers: {} }).catch(() => null)
	assert.equal(Object.hasOwn(JSON.parse(asked.at(-1).init.body), 'onBehalf'), false, "a resident's submit names no applicant")
})

test('the desk sign-in starts at the desk route with the form and comes back', () => {
	assert.equal(
		deskSignInUrl('/apps/portaliq/portal/api', { portal: 'zuid', route: 'aanvragen/woo', returnTo: '/apps/portaliq/site?portal=zuid' }),
		'/apps/portaliq/portal/api/session/desk?portal=zuid&route=aanvragen%2Fwoo&returnTo=%2Fapps%2Fportaliq%2Fsite%3Fportal%3Dzuid',
	)
})

test('Yivi is a way in of its own with the hint that it shares only what the form needs', () => {
	const routes = signInRoutes({ slug: 'zuid', authentication: { modes: ['digid', 'yivi'] } }, { authBase: '/a' })
	const yivi = routes.find((route) => route.mode === 'yivi')
	assert.equal(yivi.label, 'Inloggen met Yivi')
	assert.equal(yivi.card.text, 'Deel alleen de gegevens die dit formulier nodig heeft.')
	assert.match(yivi.href, /^\/a\/session\/oidc\/start\?provider=yivi&portal=zuid/)
	assert.equal(Object.hasOwn(routes.find((route) => route.mode === 'digid'), 'card'), false, 'the other ways get no card of their own')
})

test('the words exist in both languages, a Yivi attribute reads by its last part, and an unknown reason is the generic one', () => {
	const nl = identityWords('nl')
	const en = identityWords('en')
	assert.deepEqual(Object.keys(nl).sort(), Object.keys(en).sort())
	assert.equal(nl.deskSignIn, 'Inloggen met werkaccount')
	assert.equal(en.deskSignIn, 'Log in with your work account')
	assert.equal(yiviAttributeName('pbdf.gemeente.personalData.fullname'), 'fullname')
	assert.equal(deskProblem(nl, 'source_unavailable'), nl.deskUnavailable)
	assert.equal(deskProblem(nl, 'staff_mode_refused'), nl.deskRefused)
})
