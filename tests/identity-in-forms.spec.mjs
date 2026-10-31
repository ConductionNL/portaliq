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
import { emailCodeProblem, identityWords, typedSignatureName } from '../src/site/components/forms/identityWords.js'
import { checkEmailCode, requestEmailCode, submitIntake } from '../src/site/lib/intakeApi.js'
import { loadSfc, renderSfc } from './support/render-sfc.mjs'

const SIGNATURE = 'src/site/components/forms/SignatureField.vue'
const EMAIL = 'src/site/components/forms/EmailCodeField.vue'

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
