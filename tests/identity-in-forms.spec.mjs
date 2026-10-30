#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// identity-in-forms.spec.mjs: the signature field and the e-mail code field
// (resident-identity-in-forms REQ-RIF-001, REQ-RIF-002). A signature is
// drawn or typed and travels as a PNG; an address the form wants verified is
// checked with a code, and the form sends the proof along and will not send
// without it.
//
// Usage:
//   node --test tests/identity-in-forms.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import { emailCodeProblem, identityWords, staffFillingFor, typedSignatureName } from '../src/site/components/forms/identityWords.js'
import { checkEmailCode, requestEmailCode, submitIntake } from '../src/site/lib/intakeApi.js'
import { rememberStaffChoice, staffRequestToken, startAsStaff, submitAsStaff, takeStaffChoice, workAccountLoginUrl } from '../src/site/lib/staffFillApi.js'
import { loadSfc, renderSfc } from './support/render-sfc.mjs'

const SIGNATURE = 'src/site/components/forms/SignatureField.vue'
const EMAIL = 'src/site/components/forms/EmailCodeField.vue'
const STAFF = 'src/site/components/forms/StaffFillChoice.vue'

test('the words exist in both languages with the same keys and no em-dash', () => {
	const nl = identityWords('nl')
	const en = identityWords('en-GB')
	assert.deepEqual(Object.keys(nl).sort(), Object.keys(en).sort())
	for (const text of [...Object.values(nl), ...Object.values(en)]) {
		assert.doesNotMatch(text, /—/)
	}
	assert.equal(nl.drawAgain, 'Opnieuw tekenen')
	assert.equal(nl.typeInstead, 'Typ uw naam in plaats van te tekenen')
	assert.equal(identityWords('').drawAgain, 'Opnieuw tekenen', 'Dutch is the default')
	assert.equal(emailCodeProblem(nl, 'wrong'), nl.emailWrong)
	assert.equal(emailCodeProblem(nl, 'something-else'), nl.emailFailed)
	assert.equal(typedSignatureName('  Sanne   de\nVries  '), 'Sanne de Vries')
	assert.equal(typedSignatureName('x'.repeat(200)).length, 80)
	assert.equal(typedSignatureName(null), '')
})

test('the signature box offers the typed name as an alternative to dragging', async () => {
	const html = await renderSfc(SIGNATURE, { modelValue: '', locale: 'nl' })
	assert.match(html, /Teken met uw muis, vinger of pen in het vak\./)
	assert.match(html, /Opnieuw tekenen/)
	assert.match(html, /Typ uw naam in plaats van te tekenen/)
	assert.match(html, /<canvas/)
})

test('a stroke hands the picture on, clearing hands on nothing, and no ink is no signature', async () => {
	const signature = await loadSfc(SIGNATURE)
	const calls = []
	const context = { beginPath() {}, moveTo() {}, lineTo() {}, stroke() {}, clearRect: () => calls.push('clear') }
	const canvas = {
		width: 600,
		height: 200,
		getContext: () => context,
		getBoundingClientRect: () => ({ left: 10, top: 10, width: 300 }),
		toDataURL: () => 'data:image/png;base64,AAAA',
	}
	const emitted = []
	const vm = { $refs: { canvas }, drawing: false, inked: false, $emit: (...a) => emitted.push(a) }
	vm.pen = signature.methods.pen.bind(vm)
	vm.point = signature.methods.point.bind(vm)

	signature.methods.stop.call(vm)
	assert.deepEqual(emitted, [], 'a pointer that never went down emits nothing')
	signature.methods.start.call(vm, { clientX: 20, clientY: 20 })
	signature.methods.move.call(vm, { clientX: 40, clientY: 50 })
	assert.equal(vm.drawing, true)
	signature.methods.stop.call(vm)
	assert.deepEqual(emitted, [['update:modelValue', 'data:image/png;base64,AAAA']])
	assert.deepEqual(vm.point({ clientX: 40, clientY: 50 }), { x: 60, y: 80 }, 'canvas pixels, not screen pixels')

	signature.methods.clear.call(vm)
	assert.equal(emitted.at(-1)[1], '')
	assert.deepEqual(calls, ['clear'])
	assert.equal(vm.inked, false)
})

test('a typed name is drawn as the signature, and an empty one is no signature', async () => {
	const signature = await loadSfc(SIGNATURE)
	const drawn = []
	globalThis.document = {
		createElement: () => ({
			width: 0,
			height: 0,
			getContext: () => ({ fillText: (...a) => drawn.push(a) }),
			toDataURL: () => 'data:image/png;base64,TYPED',
		}),
	}
	try {
		const emitted = []
		const vm = { typed: '  Sanne  de Vries ', $emit: (...a) => emitted.push(a) }
		signature.methods.typedChanged.call(vm)
		assert.equal(drawn[0][0], 'Sanne de Vries')
		assert.deepEqual(emitted[0], ['update:modelValue', 'data:image/png;base64,TYPED'])
		vm.typed = '   '
		signature.methods.typedChanged.call(vm)
		assert.deepEqual(emitted[1], ['update:modelValue', ''])
		const back = { mode: 'type', typed: 'x', inked: true, $emit: (...a) => emitted.push(a) }
		signature.methods.switchToDraw.call(back)
		assert.equal(back.mode, 'draw')
		assert.equal(emitted.at(-1)[1], '', 'a typed signature is dropped when the box returns')
	} finally {
		delete globalThis.document
	}
})

test('the api asks the code routes and says why a code was refused', async () => {
	const asked = []
	const answer = (status, body) => async (url, init) => {
		asked.push({ url, init })
		return { ok: status < 400, status, json: async () => body }
	}
	const sent = await requestEmailCode('/portal/api', 'aanvragen/x', 'a@b.nl', 'zuid', 'tok', answer(200, { sent: true, resendAfter: 60 }))
	assert.deepEqual(sent, { ok: true, error: '', resendAfter: 60 })
	assert.equal(asked[0].url, '/portal/api/intake/email-code')
	assert.deepEqual(JSON.parse(asked[0].init.body), { route: 'aanvragen/x', email: 'a@b.nl', portal: 'zuid' })
	assert.equal(asked[0].init.headers.Authorization, 'Bearer tok')
	assert.deepEqual(await requestEmailCode('/p', 'r', 'a', '', '', answer(429, { error: 'wait' })), { ok: false, error: 'wait', resendAfter: 60 })
	assert.equal((await requestEmailCode('/p', 'r', 'a', '', '', async () => { throw new Error('offline') })).error, 'failed')

	assert.deepEqual(await checkEmailCode('/portal/api', 'r', 'a@b.nl', '123456', '', '', answer(200, { verified: true, proof: 'p.q' })), { ok: true, error: '', proof: 'p.q' })
	assert.equal(asked.at(-1).url, '/portal/api/intake/email-code/check')
	assert.deepEqual(JSON.parse(asked.at(-1).init.body), { route: 'r', email: 'a@b.nl', code: '123456' })
	assert.deepEqual(await checkEmailCode('/p', 'r', 'a', '1', '', '', answer(400, { error: 'too_many_tries' })), { ok: false, error: 'too_many_tries', proof: '' })
	assert.equal((await checkEmailCode('/p', 'r', 'a', '1', '', '', answer(200, { verified: true }))).ok, false, 'a proof is needed')
})

test('the submit carries the proofs of the verified addresses, and none when there are none', async () => {
	const bodies = []
	const fetchImpl = async (url, init) => {
		bodies.push(JSON.parse(init.body))
		return { ok: true, status: 200, json: async () => ({ reference: 'AANVRAAG-1', confirmationText: '' }) }
	}
	await submitIntake('/p', 'r', { mail: 'a@b.nl' }, '', '', fetchImpl, [], { 'a@b.nl': 'p.q' })
	await submitIntake('/p', 'r', {}, '', '', fetchImpl, [], {})
	assert.deepEqual(bodies[0].verifiedEmails, { 'a@b.nl': 'p.q' })
	assert.equal('verifiedEmails' in bodies[1], false)
})

test('the code field walks idle, sent and verified, drops the proof when the address changes, and waits to resend', async () => {
	const field = await loadSfc(EMAIL)
	const emitted = []
	const replies = []
	const storage = { getItem: () => null, setItem() {}, removeItem() {} }
	globalThis.window = {
		fetch: async (url) => replies.shift()(url),
		location: { hash: '', pathname: '/', search: '' },
		sessionStorage: storage,
		localStorage: storage,
	}
	const reply = (status, body) => async () => ({ ok: status < 400, status, json: async () => body })
	try {
		const vm = {
			state: 'idle',
			code: '',
			busy: false,
			problem: '',
			canResend: true,
			timer: null,
			modelValue: 'Sanne@Example.nl',
			base: '/p',
			route: 'r',
			portal: '',
			words: identityWords('nl'),
			$emit: (...a) => emitted.push(a),
		}
		replies.push(reply(200, { sent: true, resendAfter: 1 }))
		await field.methods.send.call(vm)
		assert.equal(vm.state, 'sent')
		assert.equal(vm.canResend, false, 'a new code waits')
		clearTimeout(vm.timer)

		vm.code = '000000'
		replies.push(reply(400, { error: 'wrong' }))
		await field.methods.check.call(vm)
		assert.equal(vm.state, 'sent')
		assert.equal(vm.problem, identityWords('nl').emailWrong)
		assert.deepEqual(emitted, [])

		vm.code = ' 123456 '
		replies.push(reply(200, { verified: true, proof: 'p.q' }))
		await field.methods.check.call(vm)
		assert.equal(vm.state, 'verified')
		assert.deepEqual(emitted.at(-1), ['verified', { address: 'sanne@example.nl', proof: 'p.q' }])

		field.methods.addressChanged.call(vm, 'ander@example.nl')
		assert.deepEqual(emitted.at(-2), ['verified', { address: 'Sanne@Example.nl', proof: '' }], 'the old proof is dropped')
		assert.equal(vm.state, 'idle')
		assert.deepEqual(emitted.at(-1), ['update:modelValue', 'ander@example.nl'])

		replies.push(reply(429, { error: 'throttled' }))
		await field.methods.send.call(vm)
		assert.equal(vm.state, 'idle')
		assert.equal(vm.problem, identityWords('nl').emailThrottled)
	} finally {
		delete globalThis.window
	}
})

test('the form block shows a signature, a verified address and neither as a long text', () => {
	const block = readFileSync('src/site/components/IntakeFormBlock.vue', 'utf8')
	assert.match(block, /<SignatureField[^>]*v-else-if="field.type === 'signature'"/)
	assert.match(block, /<EmailCodeField[^>]*v-else-if="field.type === 'email' && field.verify === true"/)
	assert.match(block, /@verified="onVerified"/)
	assert.match(block, /this\.verifiedEmails,\s*\)/, 'the proofs go with the submit')
	assert.match(block, /!this\.verifiedEmails\[address\]/, 'an unverified address stops the step')
	assert.match(block, /identityWords\([^)]*\)\.signatureSet/, 'the review never prints the picture')
})

// REQ-RIF-005: an employee fills a form in for a resident or a company.

test('"Voor medewerkers" offers the three ways on, with the board words', async () => {
	const html = await renderSfc(STAFF, { locale: 'nl' })
	assert.match(html, /Voor medewerkers/)
	assert.match(html, /Verder als/)
	assert.match(html, /Inwoner/)
	assert.match(html, /Bedrijf/)
	assert.match(html, /Uzelf, met de inwoner als aanvrager/)
	assert.match(html, /BSN van de inwoner/)
	assert.match(html, /Inloggen met werkaccount/)
	assert.match(html, /<legend[^>]*>\s*Verder als/, 'the choice is a fieldset with a legend')
})

test('the choice hands on the applicant and refuses an empty value', async () => {
	const choice = await loadSfc(STAFF)
	const emitted = []
	const vm = { ...choice.data(), $emit: (...args) => emitted.push(args) }
	choice.methods.start.call(vm)
	assert.equal(vm.empty, true)
	assert.deepEqual(emitted, [])

	vm.kind = 'kvk'
	vm.value = ' 12345678 '
	choice.methods.start.call(vm)
	assert.deepEqual(emitted, [['start', { kind: 'kvk', value: '12345678' }]])
})

test('the staff line names the applicant, and nothing outside staff mode', () => {
	const nl = identityWords('nl')
	assert.equal(staffFillingFor(nl, { kind: 'bsn', value: '111222333' }), 'U vult dit formulier in voor de inwoner met BSN 111222333. U staat als medewerker in de zaak.')
	assert.match(staffFillingFor(identityWords('en'), { kind: 'named', value: 'Henk' }), /for Henk\./)
	assert.equal(staffFillingFor(nl, null), '')
})

test('the work account sign-in comes back to the form, never to another site', () => {
	assert.equal(workAccountLoginUrl('/index.php', '/apps/portaliq/site/woo?x=1'), '/index.php/login?redirect_url=%2Fapps%2Fportaliq%2Fsite%2Fwoo%3Fx%3D1')
	assert.equal(workAccountLoginUrl('', '//evil.example'), '/login?redirect_url=%2F')
	assert.equal(workAccountLoginUrl('', 'https://evil.example'), '/login?redirect_url=%2F')
})

test('the chosen applicant waits through the sign-in once, for this form only', () => {
	const store = new Map()
	const storage = { setItem: (k, v) => store.set(k, v), getItem: (k) => store.get(k) ?? null, removeItem: (k) => store.delete(k) }
	rememberStaffChoice('woo', { kind: 'bsn', value: '111222333' }, storage)
	assert.deepEqual(takeStaffChoice('woo', storage), { kind: 'bsn', value: '111222333' })
	assert.equal(takeStaffChoice('woo', storage), null, 'taken once')

	rememberStaffChoice('woo', { kind: 'bsn', value: '111222333' }, storage)
	assert.equal(takeStaffChoice('other-form', storage), null)
})

test('the staff calls carry the request token and read every refusal', async () => {
	const seen = []
	const answer = (status, body) => async (url, init = {}) => {
		seen.push({ url, init })
		return { ok: status < 300, status, json: async () => body }
	}
	assert.equal(await staffRequestToken('/index.php', answer(200, { token: 'tok' })), 'tok')
	assert.equal(seen[0].url, '/index.php/csrftoken')

	const started = await startAsStaff('/p', 'woo', 'zuiddrecht', { kind: 'bsn', value: '111222333' }, 'tok', answer(200, { fields: [], prefill: { applicantName: 'Sanne' } }))
	assert.equal(started.state, 'form')
	assert.equal(seen[1].url, '/p/intake/staff/start')
	assert.equal(seen[1].init.headers.requesttoken, 'tok')
	assert.deepEqual(JSON.parse(seen[1].init.body), { route: 'woo', applicant: { kind: 'bsn', value: '111222333' }, portal: 'zuiddrecht' })

	assert.equal((await startAsStaff('/p', 'woo', '', {}, '', answer(401, {}))).state, 'signIn')
	assert.equal((await startAsStaff('/p', 'woo', '', {}, '', answer(400, {}))).state, 'invalid')
	assert.equal((await startAsStaff('/p', 'woo', '', {}, '', answer(403, { error: 'staff_not_allowed' }))).state, 'notAllowed')

	const sent = await submitAsStaff('/p', { route: 'woo', applicant: { kind: 'named', value: 'Henk' }, answers: { naam: 'Henk' } }, 'tok', answer(200, { reference: '2026-0077' }))
	assert.equal(sent.reference, '2026-0077')
	assert.equal(seen.at(-1).url, '/p/intake/staff/submit')
	assert.deepEqual(JSON.parse(seen.at(-1).init.body), { route: 'woo', applicant: { kind: 'named', value: 'Henk' }, answers: { naam: 'Henk' } })
})

test('the form block offers staff mode, resumes it after the sign-in and sends as the employee', () => {
	const block = readFileSync('src/site/components/IntakeFormBlock.vue', 'utf8')
	assert.match(block, /<StaffFillChoice\s+v-if="offersStaffFill"/)
	assert.match(block, /takeStaffChoice\(this\.bindingRoute\)/, 'the choice kept before the sign-in is taken on the way back')
	assert.match(block, /this\.staffApplicant\s*\?\s*await submitAsStaff\(/)
	assert.match(block, /data-testid="intake-form-staff"/)
})
