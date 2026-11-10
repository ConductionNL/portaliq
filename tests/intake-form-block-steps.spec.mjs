#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// intake-form-block-steps.spec.mjs: what the request form block does when it
// sends, checks, reads a returned payment, a server refusal or a verified
// address, and how it writes an answer for the review, pinned before those
// methods were split into named steps.
//
// Usage:
//   node --test tests/intake-form-block-steps.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'

globalThis.window = {
	location: { hash: '', pathname: '/', search: '' },
	localStorage: { getItem: () => null, setItem() {}, removeItem() {} },
	sessionStorage: { getItem: () => null, setItem() {}, removeItem() {} },
	history: { replaceState() {} },
}

const { loadSfc } = await import('./support/render-sfc.mjs')
const BLOCK = await loadSfc('src/site/components/IntakeFormBlock.vue')
globalThis.document = { getElementById: () => null, querySelector: () => null, documentElement: { lang: 'nl' } }

/**
 * A component instance with every method bound and the given state.
 *
 * @param {object} state The data and props.
 * @return {object} The instance.
 */
function instance(state) {
	const vm = {
		portal: 'gemeente-x',
		bindingRoute: 'parkeren',
		fields: [],
		values: {},
		errors: {},
		statementErrors: {},
		accepted: [],
		verifiedEmails: {},
		render: { statements: [] },
		text: { statementRequired: 'Vink aan.', notCalculated: 'Nog niet.' },
		state: 'form',
		submitting: false,
		sendFailed: false,
		formError: '',
		$refs: {},
		$nextTick: (callback) => callback(),
		...state,
	}
	for (const [name, method] of Object.entries(BLOCK.methods)) {
		if (!Object.hasOwn(state, name)) {
			vm[name] = method.bind(vm)
		}
	}
	vm.shown = []
	vm.showErrors = (errors) => vm.shown.push(errors)
	return vm
}

/**
 * Answer every request with one response and keep what was sent.
 *
 * @param {object} answer The status and body, or a thrown error.
 * @return {Array<{url: string, init: object}>} The calls.
 */
function serve(answer) {
	const calls = []
	globalThis.window.fetch = async (url, init) => {
		calls.push({ url: String(url), init })
		if (answer.throws) {
			throw new Error('offline')
		}
		return { ok: answer.status < 300, status: answer.status, json: async () => answer.body }
	}
	return calls
}

test('submit stops on a field error before anything is sent', async () => {
	const calls = serve({ status: 200, body: {} })
	const vm = instance({ checkFields: (names) => ({ [names[0]]: 'Vul in.' }), fields: [{ name: 'a' }, { name: 'b' }], isShownField: (name) => name === 'b' })
	await BLOCK.methods.submit.call(vm)
	assert.deepEqual(vm.shown, [{ b: 'Vul in.' }])
	assert.equal(calls.length, 0)
	assert.equal(vm.submitting, false)
})

test('submit stops on a missing statement and clears the field errors', async () => {
	const calls = serve({ status: 200, body: {} })
	const vm = instance({ errors: { a: 'x' }, render: { statements: [{ key: 'privacy', required: true }] } })
	await BLOCK.methods.submit.call(vm)
	assert.deepEqual(vm.errors, {})
	assert.deepEqual(vm.statementErrors, { privacy: 'Vink aan.' })
	assert.equal(calls.length, 0)
})

test('a refusal shows the form error, the field messages and the statement messages', async () => {
	const calls = serve({
		status: 422,
		body: { errors: { _form: 'Niet gelukt', name: ['Te kort', 3, 'echt'], 'statement-privacy': 'Verplicht', other: 4 } },
	})
	const vm = instance({ values: { name: 'x' }, accepted: ['truth'], verifiedEmails: { 'a@b.nl': 'p' } })
	await BLOCK.methods.submit.call(vm)
	assert.equal(calls.length, 1)
	const sent = JSON.parse(calls[0].init.body)
	assert.deepEqual(sent, { route: 'parkeren', answers: { name: 'x' }, verifiedEmails: { 'a@b.nl': 'p' }, portal: 'gemeente-x', statements: ['truth'] })
	assert.equal(vm.formError, 'Niet gelukt')
	assert.deepEqual(vm.statementErrors, { privacy: 'Verplicht' })
	assert.deepEqual(vm.shown, [{ name: 'Te kort echt' }])
	assert.equal(vm.state, 'form')
	assert.equal(vm.submitting, false)
})

test('a refusal without a form error leaves the form error empty', async () => {
	serve({ status: 400, body: {} })
	const vm = instance({})
	await BLOCK.methods.submit.call(vm)
	assert.equal(vm.formError, '')
	assert.deepEqual(vm.shown, [{}])
})

test('a sent request shows the reference and focuses the heading', async () => {
	serve({ status: 200, body: { reference: 'AANVRAAG-1', confirmation: { title: 'Bedankt' }, mailedTo: 'a@b.nl' } })
	let focused = 0
	const vm = instance({ $refs: { doneHeading: { focus: () => { focused += 1 } } } })
	await BLOCK.methods.submit.call(vm)
	assert.equal(vm.reference, 'AANVRAAG-1')
	assert.equal(vm.state, 'done')
	assert.equal(vm.mailedTo, 'a@b.nl')
	assert.deepEqual(vm.confirmation, { title: 'Bedankt' })
	assert.deepEqual(vm.moments, { receivedAt: '', termStartsAt: '', deadline: '' })
	assert.equal(focused, 1)
	assert.equal(vm.submitting, false)
	assert.equal(vm.sendFailed, false)
})

test('a sent request without a heading to focus still shows the reference', async () => {
	serve({ status: 200, body: { reference: 'AANVRAAG-2', moments: { deadline: '2026-12-01' } } })
	const vm = instance({})
	await BLOCK.methods.submit.call(vm)
	assert.equal(vm.state, 'done')
})

test('a failed send says so and stops submitting', async () => {
	serve({ throws: true })
	const vm = instance({})
	await BLOCK.methods.submit.call(vm)
	assert.equal(vm.sendFailed, true)
	assert.equal(vm.submitting, false)
})

test('checkFields: an address problem counts when required or partly filled', () => {
	const fields = [
		{ name: 'home', type: 'addressNL', required: true },
		{ name: 'work', type: 'addressNL' },
		{ name: 'other', type: 'addressNL' },
	]
	const vm = instance({
		fields,
		values: { home: {}, work: { postcode: '1234AB' }, other: {} },
		checkPlainFields: () => ({}),
	})
	const errors = BLOCK.methods.checkFields.call(vm, ['home', 'work', 'other'])
	assert.deepEqual(Object.keys(errors).sort(), ['home', 'work'])
	assert.equal(typeof errors.home, 'string')
})

test('checkFields: a verified e-mail field asks for the code unless verified or already wrong', () => {
	const fields = [
		{ name: 'a', type: 'email', verify: true },
		{ name: 'b', type: 'email', verify: true },
		{ name: 'c', type: 'email', verify: true },
		{ name: 'd', type: 'email', verify: true },
		{ name: 'e', type: 'email' },
	]
	const vm = instance({
		fields,
		values: { a: ' Sanne@Example.nl ', b: 'kees@example.nl', c: '', d: 'x@y.nl', e: 'z@y.nl' },
		verifiedEmails: { 'kees@example.nl': 'proof' },
		checkPlainFields: () => ({ d: 'Ongeldig' }),
	})
	const errors = BLOCK.methods.checkFields.call(vm, ['a', 'b', 'c', 'd', 'e'])
	assert.deepEqual(Object.keys(errors).sort(), ['a', 'd'])
	assert.equal(errors.d, 'Ongeldig')
	assert.notEqual(errors.a, '')
})

test('checkFields only checks the names asked', () => {
	const vm = instance({
		fields: [{ name: 'a', type: 'email', verify: true }],
		values: { a: 'x@y.nl' },
		checkPlainFields: () => ({}),
	})
	assert.deepEqual(BLOCK.methods.checkFields.call(vm, []), {})
})

test('checkPlainFields flattens addresses and family members and skips groups and computed fields', () => {
	const vm = instance({
		fields: [
			{ name: 'name', label: 'Naam', required: true },
			{ name: 'born', type: 'date' },
			{ name: 'home', type: 'addressNL', required: true },
			{ name: 'kids', type: 'familyMembers', required: true },
			{ name: 'list', type: 'group', required: true },
			{ name: 'sum', calculate: 'a+b', required: true },
			{ name: 'out', computed: true, required: true },
			{ name: 'code', format: 'postcode' },
			{ name: 'skipped', required: true },
		],
		values: { name: '', born: '2026-02-31', home: null, kids: ['x'], code: 'nope' },
	})
	const errors = BLOCK.methods.checkPlainFields.call(vm, ['name', 'born', 'home', 'kids', 'list', 'sum', 'out', 'code'])
	assert.deepEqual(Object.keys(errors).sort(), ['born', 'code', 'home', 'name'])
})

test('messagesOf joins lists, keeps text and drops the rest', () => {
	assert.deepEqual(BLOCK.methods.messagesOf.call({}, { a: ['x', 1, 'y'], b: 'z', c: 3, d: [], e: '' }), { a: 'x y', b: 'z' })
	assert.deepEqual(BLOCK.methods.messagesOf.call({}, null), {})
})

test('onVerified keeps, replaces and drops a proof per lower-cased address', () => {
	const vm = { verifiedEmails: { 'old@x.nl': 'p0' } }
	BLOCK.methods.onVerified.call(vm, { address: ' New@X.nl ', proof: 'p1' })
	assert.deepEqual(vm.verifiedEmails, { 'old@x.nl': 'p0', 'new@x.nl': 'p1' })
	BLOCK.methods.onVerified.call(vm, { address: 'OLD@x.nl', proof: '' })
	assert.deepEqual(vm.verifiedEmails, { 'new@x.nl': 'p1' })
	const before = vm.verifiedEmails
	BLOCK.methods.onVerified.call(vm, { address: '  ' })
	BLOCK.methods.onVerified.call(vm, null)
	assert.equal(vm.verifiedEmails, before)
})

test('showReturnedPayment reads the status only on a form with a fee and a returned reference', async () => {
	const calls = serve({ status: 200, body: { reference: 'AANVRAAG-1', confirmation: { deadline: 'x' }, payment: { state: 'paid' } } })
	globalThis.window.location.search = ''
	const none = instance({ render: { fee: { amount: '1' } } })
	await BLOCK.methods.showReturnedPayment.call(none)
	assert.equal(calls.length, 0)

	globalThis.window.location.search = '?reference=AANVRAAG-1'
	const free = instance({ render: { fee: null } })
	await BLOCK.methods.showReturnedPayment.call(free)
	const done = instance({ state: 'done', render: { fee: { amount: '1' } } })
	await BLOCK.methods.showReturnedPayment.call(done)
	assert.equal(calls.length, 0)

	const paying = instance({ render: { fee: { amount: '1' } } })
	await BLOCK.methods.showReturnedPayment.call(paying)
	assert.equal(calls.length, 1)
	assert.equal(paying.state, 'done')
	assert.equal(paying.reference, 'AANVRAAG-1')
	assert.deepEqual(paying.moments, { deadline: 'x' })
	assert.deepEqual(paying.payment, { state: 'paid' })
	globalThis.window.location.search = ''
})

test('showReturnedPayment keeps the form when the status has no reference or no answer', async () => {
	globalThis.window.location.search = '?reference=AANVRAAG-1'
	serve({ status: 200, body: {} })
	const empty = instance({ render: { fee: { amount: '1' } } })
	await BLOCK.methods.showReturnedPayment.call(empty)
	assert.equal(empty.state, 'form')

	serve({ status: 404, body: {} })
	const missing = instance({ render: { fee: { amount: '1' } } })
	await BLOCK.methods.showReturnedPayment.call(missing)
	assert.equal(missing.state, 'form')

	serve({ status: 200, body: { reference: 'AANVRAAG-1' } })
	const bare = instance({ render: { fee: { amount: '1' } } })
	await BLOCK.methods.showReturnedPayment.call(bare)
	assert.deepEqual(bare.moments, {})
	assert.equal(bare.payment, null)
	globalThis.window.location.search = ''
})

test('the calculated watcher writes each calculated value as text and empties a missing one', () => {
	const vm = {
		fields: [{ name: 'sum', calculate: 'a+b' }, { name: 'fee', calculate: 'x' }, { name: 'plain' }],
		values: { sum: '', fee: 'old', plain: 'keep' },
	}
	BLOCK.watch.calculated.call(vm, { sum: 12, plain: 9 })
	assert.deepEqual(vm.values, { sum: '12', fee: '', plain: 'keep' })
})

test('answerText writes each kind of answer for the review', () => {
	const vm = instance({
		values: {
			sig: 'data:image/png',
			nosig: '',
			home: { street: 'Dorpsstraat', number: '1', postcode: '1234AB', city: 'Ons Dorp' },
			kids: ['a', 'b'],
			pick: 'b',
			day: '2026-03-04',
			free: 'tekst',
			loose: 'zomaar',
		},
	})
	assert.notEqual(BLOCK.methods.answerText.call(vm, { name: 'sig', type: 'signature' }), '')
	assert.equal(BLOCK.methods.answerText.call(vm, { name: 'nosig', type: 'signature' }), '')
	assert.notEqual(BLOCK.methods.answerText.call(vm, { name: 'home', type: 'addressNL' }), '')
	assert.equal(BLOCK.methods.answerText.call(vm, { name: 'kids', type: 'familyMembers' }), '2')
	assert.equal(BLOCK.methods.answerText.call(vm, { name: 'none', type: 'familyMembers' }), '0')
	assert.equal(BLOCK.methods.answerText.call(vm, { name: 'none', type: 'group' }), '')
	assert.equal(BLOCK.methods.answerText.call(vm, { name: 'pick', options: [{ value: 'a', label: 'Aa' }, { value: 'b', label: 'Bee' }] }), 'Bee')
	assert.equal(BLOCK.methods.answerText.call(vm, { name: 'pick', options: ['a', 'b'] }), 'b')
	assert.equal(BLOCK.methods.answerText.call(vm, { name: 'day', type: 'date' }), '4 maart 2026')
	assert.equal(BLOCK.methods.answerText.call(vm, { name: 'free', type: 'date' }), 'tekst')
	assert.equal(BLOCK.methods.answerText.call(vm, { name: 'loose', options: 'nope' }), 'zomaar')
	assert.equal(BLOCK.methods.answerText.call(vm, { name: 'missing' }), '')
})

test('load: no route is not found, a failed read is not found, a form with an intro starts on the intro', async () => {
	const noRoute = instance({ bindingRoute: '', reference: 'old', errors: { a: 'x' }, sendFailed: true })
	await BLOCK.methods.load.call(noRoute)
	assert.equal(noRoute.state, 'notFound')
	assert.equal(noRoute.reference, '')
	assert.deepEqual(noRoute.errors, {})
	assert.equal(noRoute.sendFailed, false)

	serve({ throws: true })
	const broken = instance({})
	await BLOCK.methods.load.call(broken)
	assert.equal(broken.state, 'notFound')

	const calls = serve({ status: 200, body: { fields: [{ name: 'name' }], settings: { intro: { blocks: [{ title: 'Vooraf' }] } } } })
	const intro = instance({})
	await BLOCK.methods.load.call(intro)
	assert.equal(intro.state, 'intro')
	assert.deepEqual(Object.keys(intro.values), ['name'])
	assert.equal(intro.render.fields.length, 1)
	assert.match(calls[0].url, /\/intake\/form\?route=parkeren&portal=gemeente-x/)

	serve({ status: 200, body: { fields: [] } })
	const plain = instance({})
	await BLOCK.methods.load.call(plain)
	assert.equal(plain.state, 'form')

	serve({ status: 403, body: { fee: null } })
	const signIn = instance({})
	await BLOCK.methods.load.call(signIn)
	assert.equal(signIn.state, 'signIn')
})
