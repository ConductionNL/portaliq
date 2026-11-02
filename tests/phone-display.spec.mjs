#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// phone-display.spec.mjs: a phone number the account keeps in E.164
// (+31612345678) reads the Dutch way on screen ("06 12 34 56 78", board
// MijnAccount). The stored value, and what an action sends, stay E.164.
//
// Usage:
//   node --test tests/phone-display.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { phoneDisplay } from '../src/site/lib/phoneDisplay.js'
import { t } from './support/page-instance.mjs'
import { loadSfc, renderComponent } from './support/render-sfc.mjs'

test('a Dutch number in E.164 reads the Dutch way', () => {
	assert.equal(phoneDisplay('+31612345678'), '06 12 34 56 78', 'mobile')
	assert.equal(phoneDisplay('+31101234567'), '010 123 4567', 'a two-digit area code')
	assert.equal(phoneDisplay('+31781234567'), '078 123 4567', 'Zuiddrecht\'s area')
	assert.equal(phoneDisplay('+31591123456'), '0591 123 456', 'a three-digit area code')
	assert.equal(phoneDisplay('+31201234567'), '020 123 4567')
})

test('anything else is shown as it is stored', () => {
	assert.equal(phoneDisplay('+4930123456'), '+4930123456', 'a foreign number')
	assert.equal(phoneDisplay('06 12 34 56 78'), '06 12 34 56 78', 'already written the Dutch way')
	assert.equal(phoneDisplay('14 0999'), '14 0999')
	assert.equal(phoneDisplay(''), '')
	assert.equal(phoneDisplay(null), '')
})

test('the account page lists the phone number the Dutch way and keeps e-mail addresses as they are', async () => {
	const AddressList = await loadSfc('src/site/components/e/AddressList.vue')
	const phones = await renderComponent(AddressList, {
		kind: 'phone',
		entries: [{ value: '+31612345678', preferred: true, confirmed: false }],
		t,
		act: async () => true,
	})
	assert.match(phones, /06 12 34 56 78/)
	assert.doesNotMatch(phones, /\+31612345678/)
	const emails = await renderComponent(AddressList, {
		kind: 'email',
		entries: [{ value: 'sanne.devries@example.nl', preferred: true, confirmed: true }],
		t,
		act: async () => true,
	})
	assert.match(emails, /sanne\.devries@example\.nl/)
})
