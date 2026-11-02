#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// address-block.spec.mjs: the address block asks for a postcode and house
// number, finds the street and town and leaves both editable
// (data-lookups-and-checks-in-forms REQ-DIF-001).
//
// Usage:
//   node --test tests/address-block.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import {
	addressLine,
	addressProblem,
	canLookUp,
	emptyAddress,
	withFound,
} from '../src/site/components/forms/address.js'
import { initialValues, lookupAddress } from '../src/site/lib/intakeApi.js'
import { loadSfc, renderSfc } from './support/render-sfc.mjs'

const BLOCK = { ...emptyAddress(), postcode: '1234 AB', number: '12' }

test('the register is asked only for a whole postcode and number', () => {
	assert.equal(canLookUp(BLOCK), true)
	assert.equal(canLookUp({ ...BLOCK, postcode: '12AB' }), false)
	assert.equal(canLookUp({ ...BLOCK, number: '' }), false)
	assert.equal(canLookUp({ ...BLOCK, number: 'twaalf' }), false)
})

test('a lookup fills street and town, but never over what the resident typed', () => {
	const found = { street: 'Lindelaan', town: 'Zuiddrecht' }
	const filled = withFound(BLOCK, found, { street: false, town: false })
	assert.equal(filled.street, 'Lindelaan')
	assert.equal(filled.town, 'Zuiddrecht')

	const kept = withFound({ ...BLOCK, street: 'Mijn eigen straat' }, found, { street: true, town: false })
	assert.equal(kept.street, 'Mijn eigen straat')
	assert.equal(kept.town, 'Zuiddrecht')

	assert.deepEqual(withFound(BLOCK, null, {}), BLOCK, 'a miss changes nothing')
})

test('lookupAddress reads the route and turns every failure into null', async () => {
	const urls = []
	const ok = async (url) => {
		urls.push(url)
		return { ok: true, json: async () => ({ street: 'Lindelaan', town: 'Zuiddrecht' }) }
	}
	assert.deepEqual(await lookupAddress('/apps/portaliq/portal/api', BLOCK, ok), {
		street: 'Lindelaan',
		town: 'Zuiddrecht',
	})
	assert.match(urls[0], /\/apps\/portaliq\/portal\/api\/intake\/address\?postcode=1234\+AB&number=12$/)

	assert.equal(await lookupAddress('/x', BLOCK, async () => ({ ok: false })), null)
	assert.equal(await lookupAddress('/x', BLOCK, async () => { throw new Error('down') }), null)
	assert.equal(await lookupAddress('/x', BLOCK, async () => ({ ok: true, json: async () => ({}) })), null)
})

test('the block says what is missing, and reads on one line', () => {
	assert.notEqual(addressProblem(BLOCK), '')
	const whole = { ...BLOCK, street: 'Lindelaan', town: 'Zuiddrecht', letter: 'A' }
	assert.equal(addressProblem(whole), '')
	assert.equal(addressLine(whole), 'Lindelaan 12A, 1234 AB Zuiddrecht')
	assert.equal(addressLine(emptyAddress()), '')
})

test('an address field starts as an empty block', () => {
	const values = initialValues([{ name: 'adres', type: 'addressNL' }, { name: 'naam' }], {})
	assert.deepEqual(values.adres, emptyAddress())
	assert.equal(values.naam, '')
})

test('site: the block renders the board labels, the found sentence only after a hit', async () => {
	const html = await renderSfc('src/site/components/forms/AddressNL.vue', {
		modelValue: { ...BLOCK, street: 'Lindelaan', town: 'Zuiddrecht' },
		houseLetter: true,
		locale: 'nl',
	})
	for (const label of ['Postcode', 'Huisnummer', 'Huisletter', 'Toevoeging (niet verplicht)', 'Straat', 'Plaats']) {
		assert.match(html, new RegExp(label.replace(/[()]/g, '\\$&')), label)
	}
	assert.doesNotMatch(html, /data-testid="address-found"/)
})

test('AddressNL asks the register on blur only when the lookup is on, and keeps hand edits', async () => {
	const screen = await loadSfc('src/site/components/forms/AddressNL.vue')
	const emitted = []
	const vm = (lookup) => ({
		lookup,
		base: '/api',
		block: { ...BLOCK },
		touched: { street: false, town: true },
		foundNote: false,
		$emit: (name, value) => emitted.push([name, value]),
	})
	const off = vm(false)
	await screen.methods.find.call(off)
	assert.equal(emitted.length, 0)

	globalThis.fetch = async () => ({ ok: true, json: async () => ({ street: 'Lindelaan', town: 'Zuiddrecht' }) })
	globalThis.window = globalThis
	const on = vm(true)
	on.block = { ...BLOCK, town: 'Eigen plaats' }
	await screen.methods.find.call(on)
	assert.equal(emitted.length, 1)
	assert.equal(emitted[0][1].street, 'Lindelaan')
	assert.equal(emitted[0][1].town, 'Eigen plaats', 'the town the resident typed stays')
	assert.equal(on.foundNote, true)
})
