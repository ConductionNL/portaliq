// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Proposing a change on the site (site-reaches-portal-parity T12,
// REQ-SRP-024): the form starts from the row's own values, sends only the
// fields that changed with the note, and refuses a proposal with nothing
// changed. The queue shows only this record's proposals, their state in
// words, and withdraws a queued one.
//
// Usage:
//   node --test tests/propose-change.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import {
	proposalsOn,
	proposalStart,
	proposalStateKey,
	proposedChanges,
} from '../src/site/components/c/forms.js'
import { mountSfc } from './support/mount-sfc.mjs'

const ACTION = {
	id: 'proposeProfileChange',
	type: 'propose-change',
	register: 'learniq',
	schema: 'guardian',
	proposable: ['phone', 'address'],
}
const ROW = {
	id: 'guardian-1',
	phone: '0612345678',
	address: 'Dorpsstraat 1',
	name: 'Fatima',
}

test('only the changed field is proposed', () => {
	const values = { ...proposalStart(ACTION, ROW), phone: '0687654321' }
	assert.deepEqual(proposalStart(ACTION, { id: 'x', phone: null }), {
		phone: '',
		address: '',
	})
	assert.deepEqual(proposedChanges(ACTION, ROW, values), [
		{ property: 'phone', proposedValue: '0687654321' },
	])
	assert.deepEqual(proposedChanges(ACTION, ROW, proposalStart(ACTION, ROW)), [])
})

test('the form sends only the phone number and the note', async () => {
	const sent = []
	const form = await mountSfc('src/site/components/c/ProposeChangeForm.vue', {
		action: ACTION,
		row: ROW,
		send: async (changes, note) => {
			sent.push({ changes, note })
			return { ok: true }
		},
	})
	const input = (id) => form.findAll((n) => n.props.id === id)[0]
	assert.equal(
		input('propose-proposeProfileChange-phone').props.value,
		'0612345678',
		'filled from the row',
	)

	await form.fire(input('propose-proposeProfileChange-phone'), 'input', {
		value: '0687654321',
	})
	await form.fire(input('propose-proposeProfileChange-note'), 'input', {
		value: 'Nieuw nummer',
	})
	await form.fire(form.find('propose-form'), 'submit')

	assert.deepEqual(sent, [
		{
			changes: [{ property: 'phone', proposedValue: '0687654321' }],
			note: 'Nieuw nummer',
		},
	])
	assert.equal(form.emitted.sent.length, 1)
})

test('nothing changed is refused before anything is sent', async () => {
	let calls = 0
	const form = await mountSfc('src/site/components/c/ProposeChangeForm.vue', {
		action: ACTION,
		row: ROW,
		send: async () => {
			calls++
			return { ok: true }
		},
	})
	await form.fire(form.find('propose-form'), 'submit')
	assert.equal(calls, 0)
	assert.equal(
		form.textOf(form.find('propose-error')),
		'Change at least one field before you send a proposal.',
	)
})

test('a refused proposal says so', async () => {
	const form = await mountSfc('src/site/components/c/ProposeChangeForm.vue', {
		action: ACTION,
		row: ROW,
		send: async () => ({ ok: false }),
	})
	await form.fire(
		form.findAll(
			(n) => n.props.id === 'propose-proposeProfileChange-address',
		)[0],
		'input',
		{ value: 'Kerkplein 2' },
	)
	await form.fire(form.find('propose-form'), 'submit')
	assert.equal(
		form.textOf(form.find('propose-error')),
		'Sending the proposal did not work.',
	)
})

test('the queue shows this record only, the state in words, and withdraws', async () => {
	const mine = [
		{
			id: 'p1',
			state: 'queued',
			subjectId: 'guardian-1',
			subjectRegister: 'learniq',
			subjectSchema: 'guardian',
			changes: [{ property: 'phone', proposedValue: '06' }],
		},
		{
			id: 'p2',
			state: 'accepted',
			subjectId: 'guardian-1',
			subjectRegister: 'learniq',
			subjectSchema: 'guardian',
			changes: [{ property: 'address', proposedValue: 'X' }],
		},
		{
			id: 'p3',
			state: 'queued',
			subjectId: 'someone-else',
			subjectRegister: 'learniq',
			subjectSchema: 'guardian',
			changes: [],
		},
	]
	assert.deepEqual(
		proposalsOn(mine, ACTION, 'guardian-1').map((p) => p.id),
		['p1', 'p2'],
	)
	assert.equal(proposalStateKey('queued'), 'Waiting for review')

	const withdrawn = []
	const api = {
		async fetchMyProposals() {
			return mine
		},
		async withdrawProposal(id) {
			withdrawn.push(id)
			return { ok: true }
		},
		async proposeChange() {
			return { ok: true }
		},
	}
	const queue = await mountSfc('src/site/components/c/ProposalQueue.vue', {
		action: ACTION,
		row: ROW,
		api,
	})
	await queue.flush()
	const text = queue.text()
	assert.match(text, /phone: 06 Waiting for review Withdraw/)
	assert.match(text, /address: X Accepted/)
	assert.doesNotMatch(text, /someone-else/)

	const withdraw = queue.findAll(
		(n) => n.tag === 'button' && queue.textOf(n) === 'Withdraw',
	)
	assert.equal(withdraw.length, 1, 'only a queued proposal can be withdrawn')
	await queue.fire(withdraw[0], 'click')
	assert.deepEqual(withdrawn, ['p1'])

	await queue.fire(queue.find('propose-open'), 'click')
	assert.ok(queue.find('propose-form'), 'the button opens the form')
})
