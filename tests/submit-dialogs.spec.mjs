#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// submit-dialogs.spec.mjs: the dialogs that post through a `submit` prop and
// close with its success sentence (InviteDialog, IssueAccountDialog,
// NewsItemDialog). Pins their initial state, the audience select of the two
// account dialogs, the payload each submits, the busy flag around the post,
// and the refusal shown when the post is refused.
//
// Usage:
//   node --test tests/submit-dialogs.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { sfcScriptOptions } from './support/sfc-script-options.mjs'

/**
 * A dialog instance as plain state over its options, with a recording submit.
 *
 * @param {object} component The options.
 * @param {object} answer What submit answers.
 * @param {object} props Further props.
 * @return {object} The instance, `posted`, `emitted` and `busyDuring`.
 */
function instance(component, answer, props = {}) {
	const vm = {
		posted: [],
		emitted: [],
		busyDuring: [],
		...props,
		$emit: (...args) => vm.emitted.push(args),
	}
	vm.submit = async (payload) => {
		vm.posted.push(payload)
		vm.busyDuring.push([vm.busy, vm.refusal])
		return answer
	}
	Object.assign(vm, component.data.call(vm))
	for (const [name, fn] of Object.entries(component.methods)) {
		vm[name] = fn.bind(vm)
	}
	for (const [name, def] of Object.entries(component.computed || {})) {
		Object.defineProperty(vm, name, {
			get: (typeof def === 'function' ? def : def.get).bind(vm),
			set: def.set ? def.set.bind(vm) : undefined,
		})
	}
	return vm
}

test('InviteDialog: state, audience, payload, success and refusal', async () => {
	const component = await sfcScriptOptions('src/dialogs/InviteDialog.vue')
	assert.equal(component.name, 'InviteDialog')
	assert.deepEqual(component.emits, ['close'])
	assert.deepEqual(Object.keys(component.props), ['look', 'submit'])
	assert.equal(component.props.submit.required, true)
	assert.deepEqual(Object.keys(component.computed).sort(), ['audience', 'audiences'])

	const ok = instance(component, { ok: true, message: 'Sent.' })
	assert.deepEqual(
		{ fields: ok.fields, refusal: ok.refusal, busy: ok.busy },
		{ fields: { email: '', organisation: '', audience: 'client' }, refusal: '', busy: false },
	)
	ok.fields.email = 'a@example.org'
	ok.refusal = 'old'
	await ok.confirm()
	assert.deepEqual(ok.busyDuring, [[true, '']])
	assert.deepEqual(ok.posted, [{ ...ok.fields }])
	assert.notStrictEqual(ok.posted[0], ok.fields)
	assert.deepEqual(ok.emitted, [['close', 'Sent.']])
	assert.equal(ok.busy, false)

	const refused = instance(component, { ok: false, message: 'Not allowed.' })
	await refused.confirm()
	assert.deepEqual(refused.emitted, [])
	assert.equal(refused.refusal, 'Not allowed.')
	assert.equal(refused.busy, false)
})

test('IssueAccountDialog: state, payload with the identity type, success and refusal', async () => {
	const component = await sfcScriptOptions('src/dialogs/IssueAccountDialog.vue')
	assert.equal(component.name, 'IssueAccountDialog')
	assert.deepEqual(component.emits, ['close'])
	assert.deepEqual(Object.keys(component.props), ['look', 'submit'])
	assert.deepEqual(Object.keys(component.computed).sort(), ['audience', 'audiences', 'identityTypes'])

	const ok = instance(component, { ok: true, message: 'Issued.' })
	assert.deepEqual(ok.fields, {
		organisation: '',
		audience: 'client',
		displayName: '',
		identityRef: '',
		email: '',
		verifiedEmail: false,
	})
	assert.equal(ok.identityType, null)
	assert.deepEqual(ok.identityTypes.map((type) => type.id), ['digid', 'eherkenning', 'eidas', 'generic'])
	await ok.confirm()
	assert.deepEqual(ok.posted, [{ ...ok.fields, identityType: '' }])
	ok.identityType = { id: 'digid' }
	await ok.confirm()
	assert.equal(ok.posted[1].identityType, 'digid')
	assert.deepEqual(ok.busyDuring, [[true, ''], [true, '']])
	assert.deepEqual(ok.emitted, [['close', 'Issued.'], ['close', 'Issued.']])

	const refused = instance(component, { ok: false, message: 'Exists.' })
	await refused.confirm()
	assert.deepEqual([refused.emitted, refused.refusal, refused.busy], [[], 'Exists.', false])
})

test('InviteDialog and IssueAccountDialog: the audience select maps to the stored value', async () => {
	for (const file of ['src/dialogs/InviteDialog.vue', 'src/dialogs/IssueAccountDialog.vue']) {
		const component = await sfcScriptOptions(file)
		const vm = instance(component, { ok: true, message: '' })
		const options = vm.audiences
		assert.ok(Array.isArray(options) && options.length > 0, file)
		for (const option of options) {
			vm.audience = option
			assert.deepEqual(vm.audience, option, file)
		}
	}
})

test('NewsItemDialog: missing fields stop the post, a refusal is shown', async () => {
	const component = await sfcScriptOptions('src/dialogs/NewsItemDialog.vue')
	assert.deepEqual(component.emits, ['close'])

	const blank = instance(component, { ok: true, message: 'Saved.' }, { item: null })
	blank.refusal = 'old'
	await blank.confirm()
	assert.ok(blank.missing.length > 0)
	assert.equal(blank.refusal, '')
	assert.deepEqual([blank.posted, blank.emitted, blank.busy], [[], [], false])

	const filled = instance(component, { ok: false, message: 'Refused.' }, { item: null })
	Object.assign(filled.form, { title: 'Titel', body: 'Tekst', schoolRef: 'school-1' })
	await filled.confirm()
	assert.deepEqual(filled.missing, [])
	assert.deepEqual(filled.busyDuring, [[true, '']])
	assert.deepEqual(filled.posted, [{ ...filled.form }])
	assert.notStrictEqual(filled.posted[0], filled.form)
	assert.deepEqual([filled.emitted, filled.refusal, filled.busy], [[], 'Refused.', false])

	const saved = instance(component, { ok: true, message: 'Saved.' }, { item: null })
	Object.assign(saved.form, { title: 'Titel', body: 'Tekst', schoolRef: 'school-1' })
	await saved.confirm()
	assert.deepEqual([saved.emitted, saved.refusal, saved.busy], [[['close', 'Saved.']], '', false])
})
