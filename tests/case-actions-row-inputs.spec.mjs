// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
//
// A row action asks for the inputs its row declares, is offered on some rows
// only, and shows what happened (case-actions-row-inputs-and-conditions).
//
// @spec openspec/changes/case-actions-row-inputs-and-conditions/specs/portal-row-action-inputs/spec.md

import assert from 'node:assert/strict'
import { test } from 'node:test'
import {
	answerWords,
	isAvailable,
	offersRowAction,
	rowInputsOf,
	runRowAction,
	unavailableReason,
} from '../src/shared/rowAction.js'
import { mountSfc } from './support/mount-sfc.mjs'

const VUE_CONFIRM = 'src/site/modals/c/RowActionConfirm.vue'
const VUE_TABLE = 'src/site/components/collections/CollectionTable.vue'

const t = (key) => `[${key}]`

const collection = { id: 'requests', register: 'filinq', schema: 'request' }

const sign = {
	id: 'sign',
	label: 'Sign',
	type: 'endpoint-forward',
	endpoint: '/apps/filinq/api/sign',
	rowField: 'requestId',
	rowInputs: { from: 'signerInputs', into: 'fields' },
	confirmText: 'You sign this document.',
	successText: 'Signed.',
}

const row = {
	id: 'req-1',
	signerInputs: [
		{ name: 'iban', label: 'Bank account', required: true },
		{ name: 'since', label: 'Since', type: 'date' },
		{ name: 'bad name' },
	],
}

const withdraw = {
	id: 'withdraw',
	label: 'Withdraw from contract here',
	type: 'endpoint-forward',
	endpoint: '/apps/shillinq/api/withdraw',
	rowField: 'bookingId',
	availableWhen: { field: 'withdrawable', equals: true },
	unavailableReasonField: 'blockedReason',
}

function forwardingApi(answer) {
	const calls = []
	return {
		calls,
		async forwardRowAction(col, rowId, actionId, answers = {}) {
			calls.push({ rowId, actionId, answers })
			return typeof answer === 'function' ? answer(calls.length) : answer
		},
	}
}

test('the row declares the inputs, and only well-formed ones are asked', () => {
	assert.deepEqual(
		rowInputsOf(sign, row).map((i) => [i.name, i.label, i.required, i.type]),
		[['iban', 'Bank account', true, 'text'], ['since', 'Since', false, 'date']],
	)
	assert.deepEqual(rowInputsOf({ id: 'x' }, row), [])
	assert.deepEqual(rowInputsOf(sign, { signerInputs: 'junk' }), [])
})

test('an action is offered only where its field equals the value, and the reason shows elsewhere', () => {
	const yes = { id: 'b1', withdrawable: true }
	const no = { id: 'b2', withdrawable: false, blockedReason: 'The withdrawal period ended on 4 October 2026.' }

	assert.equal(offersRowAction(withdraw, yes), true)
	assert.equal(offersRowAction(withdraw, no), false)
	assert.equal(isAvailable({ id: 'plain' }, no), true, 'no condition, every row')
	assert.equal(offersRowAction({ ...withdraw, availableWhen: { equals: true } }, yes), false, 'a malformed condition offers nothing')
	assert.equal(unavailableReason(withdraw, no), 'The withdrawal period ended on 4 October 2026.')
	assert.equal(unavailableReason(withdraw, yes), '')
	assert.equal(unavailableReason(withdraw, { withdrawable: false, blockedReason: 7 }), '')
})

test('the table shows no button on an unavailable row, and the reason instead', async () => {
	const rows = [
		{ id: 'b1', name: 'Yes', withdrawable: true },
		{ id: 'b2', name: 'No', withdrawable: false, blockedReason: 'The period ended.' },
	]
	const table = await mountSfc(VUE_TABLE, {
		collection: { id: 'bookings', kind: 'table', columns: [{ field: 'name', label: 'Name' }] },
		objects: rows,
		rowActions: [withdraw],
		offers: offersRowAction,
		t,
	})

	assert.equal(table.findAll((n) => n.props && n.props['data-testid'] === 'collection-table-action').length, 1)
	assert.equal(table.textOf(table.find('collection-table-reason')), 'The period ended.')
	assert.match(table.text(), /Withdraw from contract here/)
})

test('the confirmation step shows the contribution\'s words and sends the typed inputs', async () => {
	const api = forwardingApi({ ok: true, status: 200, body: { message: 'Your signature was added.' } })
	const step = await mountSfc(VUE_CONFIRM, { action: sign, collection, row, api, t })

	assert.equal(step.textOf(step.find('rowaction-confirm-text')), 'You sign this document.')
	await step.fire(step.find('rowaction-input-iban'), 'input', { value: ' NL91ABNA0417164300 ' })
	await step.fire(step.find('rowaction-continue'), 'click')

	assert.deepEqual(api.calls, [{ rowId: 'req-1', actionId: 'sign', answers: { fields: { iban: 'NL91ABNA0417164300' } } }])
	assert.equal(step.textOf(step.find('rowaction-status')), 'Your signature was added.', "the target's own sentence")
	assert.equal(step.emitted.done.length, 1)
})

test('without a sentence from the target the contribution\'s success text, then ours', async () => {
	const done = await mountSfc(VUE_CONFIRM, { action: { ...sign, rowInputs: undefined }, collection, row, api: forwardingApi({ ok: true, status: 200, body: {} }), t })
	await done.fire(done.find('rowaction-continue'), 'click')
	assert.equal(done.textOf(done.find('rowaction-status')), 'Signed.')

	const plain = await mountSfc(VUE_CONFIRM, { action: { id: 'x', label: 'X', rowField: 'a', endpoint: '/a' }, collection, row, api: forwardingApi({ ok: true, status: 200, body: {} }), t })
	await plain.fire(plain.find('rowaction-continue'), 'click')
	assert.equal(plain.textOf(plain.find('rowaction-status')), '[Done.]')
})

test('a refusal names its inputs, stays open, and the next try can still be sent', async () => {
	let attempt = 0
	const api = forwardingApi(() => {
		attempt += 1
		return attempt === 1
			? { ok: false, status: 422, body: { error: 'invalid', errors: { iban: 'This is not a valid IBAN.' } } }
			: { ok: true, status: 200, body: {} }
	})
	const step = await mountSfc(VUE_CONFIRM, { action: sign, collection, row, api, t })

	await step.fire(step.find('rowaction-input-iban'), 'input', { value: 'nope' })
	await step.fire(step.find('rowaction-continue'), 'click')

	assert.equal(step.textOf(step.find('rowaction-error-iban')), 'This is not a valid IBAN.')
	assert.ok(step.find('rowaction-continue'), 'the dialog stays open')
	assert.equal(step.emitted.done, undefined, 'a refusal is not done')

	await step.fire(step.find('rowaction-input-iban'), 'input', { value: 'NL91ABNA0417164300' })
	await step.fire(step.find('rowaction-continue'), 'click')
	assert.equal(step.find('rowaction-error-iban'), null)
	assert.equal(api.calls.length, 2)
})

test('the server\'s own required marker reads as a sentence', async () => {
	const api = forwardingApi({ ok: false, status: 422, body: { error: 'invalid', errors: { iban: 'required' } } })
	const step = await mountSfc(VUE_CONFIRM, { action: sign, collection, row, api, t })
	await step.fire(step.find('rowaction-continue'), 'click')

	assert.equal(step.textOf(step.find('rowaction-error-iban')), '[This field is required.]')
})

test('the answer\'s words are read defensively', async () => {
	assert.deepEqual(answerWords({ body: { message: ' Hi ', errors: { a: 'x', b: 3, c: '' } } }), { message: 'Hi', errors: { a: 'x' } })
	assert.deepEqual(answerWords({ body: { errors: ['x'] } }), { message: '', errors: {} })
	assert.deepEqual(answerWords(null), { message: '', errors: {} })

	const outcome = await runRowAction(forwardingApi({ ok: true, status: 200, body: {} }), collection, { id: 'r' }, { id: 'a' })
	assert.deepEqual(outcome, { redirect: null, messageKey: 'Done.' }, 'an answer with no words keeps its shape')
})
