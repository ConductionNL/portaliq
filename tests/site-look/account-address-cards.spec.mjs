#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// account-address-cards.spec.mjs: the e-mail addresses and phone numbers on
// "Mijn account" as the MijnAccount and MijnAccountZonderDigid boards draw
// them. A section heading (an h2 under the page's h1) with "+ E-mailadres
// toevoegen" at the end of its line; each address a row on a hairline card
// with its state as a pill ("Voorkeur", "Wacht op bevestiging") and its
// actions as links; the form to add one opens from that button.
//
// Usage:
//   node --test tests/site-look/account-address-cards.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { mountSfc } from '../support/mount-sfc.mjs'

const FILE = 'src/site/components/e/AddressList.vue'
const ENTRIES = [
	{ value: 'sanne.devries@example.nl', preferred: true, confirmed: true },
	{ value: 's.devries@werk.example.nl', preferred: false, confirmed: false },
]

/**
 * Mount the e-mail list.
 *
 * @param {Array<object>} entries The addresses.
 * @return {Promise<object>} The mounted list.
 */
function mount(entries = ENTRIES) {
	return mountSfc(FILE, {
		kind: 'email',
		entries,
		t: (key) => key,
		act: async () => true,
	})
}

test('the section heading is an h2 with the add button on its line', async () => {
	const view = await mount()
	const heading = view.findAll((n) => n.tag === 'h2')[0]
	assert.ok(heading, 'an h2 under the page h1')
	assert.equal(view.textOf(heading), 'E-mail addresses')
	const open = view.find('address-open-email')
	assert.ok(open.parent === heading.parent, 'same line as the heading')
	assert.equal(open.props['aria-expanded'], 'false')
})

test('each address is a card row with its state as a pill', async () => {
	const view = await mount()
	const rows = view.findAll((n) => n.props['data-testid'] === 'address-email')
	assert.equal(rows.length, 2)
	assert.match(String(rows[0].props.class), /pq-address__row/)
	const pills = (row) =>
		row.children
			.filter((c) => /pq-address__pill/.test(String(c.props?.class || '')))
			.map((c) => view.textOf(c).trim())
	assert.deepEqual(pills(rows[0]), ['Preferred'])
	assert.deepEqual(pills(rows[1]), ['Waiting for confirmation'])
})

test('the add form opens from the button and closes when it worked', async () => {
	const view = await mount([])
	assert.ok(view.find('address-form-email') === null, 'closed at first')
	await view.fire(view.find('address-open-email'), 'click')
	await view.flush()
	assert.ok(view.find('address-form-email') !== null, 'open')
	assert.equal(view.find('address-open-email').props['aria-expanded'], 'true')
	await view.fire(view.find('address-form-email'), 'submit')
	await view.flush()
	assert.ok(view.find('address-form-email') === null, 'closed after adding')
})
