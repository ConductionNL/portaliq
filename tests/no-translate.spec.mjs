// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// personal-data-left-untranslated T04 (REQ-PDU-001): names, addresses and
// reference numbers sit inside translate="no" and the words around them do
// not.
//
// Usage:
//   node --test tests/no-translate.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { markAround } from '../src/site/lib/markAround.js'
import { mountSfc } from './support/mount-sfc.mjs'

/**
 * The text of every element carrying translate="no".
 *
 * @param {object} mounted The mounted component.
 * @return {string[]} The marked texts.
 */
function marked(mounted) {
	return mounted
		.findAll((n) => n.props?.translate === 'no')
		.map((n) => mounted.textOf(n).trim())
}

test('the sentence splits around the value, and not when the value is absent', () => {
	assert.deepEqual(markAround('Goedemorgen, Sanne!', 'Sanne'), {
		before: 'Goedemorgen, ',
		value: 'Sanne',
		after: '!',
	})
	assert.equal(markAround('Goedemorgen', 'Sanne'), null)
	assert.equal(markAround('Goedemorgen', ''), null)
})

test('the greeting marks the name and not the greeting word', async () => {
	const block = await mountSfc('src/site/components/mijn/GreetingBlock.vue', {
		block: {},
		session: { displayName: 'Sanne Visser', subjectRef: 'u1' },
		now: new Date(2026, 9, 5, 9, 0),
	})
	await block.flush()
	assert.deepEqual(marked(block), ['Sanne'])
	assert.match(block.textOf(block.find('mijn-greeting')), /Sanne/)
})

test('the acting-for bar marks the party and not the sentence', async () => {
	const bar = await mountSfc('src/site/components/mijn/ActingForBar.vue', {
		mandates: [{ id: 'm1', label: 'Bakkerij De Korenaar' }],
		value: 'm1',
	})
	await bar.flush()
	assert.deepEqual(marked(bar), ['Bakkerij De Korenaar'])
})

test('an address list marks each value and not its label', async () => {
	const list = await mountSfc('src/site/components/e/AddressList.vue', {
		kind: 'email',
		entries: [{ value: 'sanne@example.nl', confirmed: true }],
		t: (key) => key,
	})
	await list.flush()
	assert.deepEqual(marked(list), ['sanne@example.nl'])
})

test('a case field marks a personal value, an email value and nothing else', async () => {
	const render = async (props) => {
		const field = await mountSfc('src/site/components/e/CaseField.vue', {
			field: 'kenteken',
			value: 'AB-123-C',
			t: (key) => key,
			...props,
		})
		await field.flush()
		return marked(field)
	}
	assert.deepEqual(await render({ personal: true }), ['AB-123-C'])
	assert.deepEqual(await render({ format: 'email' }), ['AB-123-C'])
	assert.deepEqual(await render({}), [])
})

test('a case card marks its number and reference, not its title', async () => {
	const card = await mountSfc('src/site/components/mijn/CaseCard.vue', {
		card: {
			number: 'Z-2026-001',
			reference: 'REF-9',
			title: 'Vergunning',
			typeName: 'Bouw',
		},
		display: 'compact',
	})
	await card.flush()
	assert.ok(marked(card).includes('Z-2026-001'))
	assert.ok(!marked(card).includes('Vergunning'))
})
