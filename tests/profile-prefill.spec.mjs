#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// profile-prefill.spec.mjs: an action field that declares `prefill: name` or
// `prefill: email` starts from the signed-in resident's own details
// (action-fields-prefill-from-the-residents-profile).
//
// Usage:
//   node --test tests/profile-prefill.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import {
	prefillFields,
	withProfile,
} from '../src/site/components/forms/profilePrefill.js'

const ACTION = {
	id: 'startRequest',
	fields: ['onderwerp', 'naam', 'email', 'type'],
	fieldConfigs: {
		onderwerp: { label: 'Onderwerp' },
		naam: { label: 'Uw naam', prefill: 'name' },
		email: { label: 'Uw e-mailadres', prefill: 'email' },
		type: { prefill: 'bsn' },
	},
}
const PROFILE = {
	displayName: ' Henk Bakker ',
	email: 'henk@example.nl',
	bsn: '999',
}

test('only the name and e-mail sources count', () => {
	assert.deepEqual(prefillFields(ACTION), [
		['naam', 'displayName'],
		['email', 'email'],
	])
	assert.deepEqual(prefillFields({}), [])
})

test('an empty field starts from the profile; a filled one keeps its value', () => {
	const values = { onderwerp: '', naam: '', email: 'eigen@example.nl', type: '' }
	assert.deepEqual(withProfile(values, ACTION, PROFILE), {
		onderwerp: '',
		naam: 'Henk Bakker',
		email: 'eigen@example.nl',
		type: '',
	})
	assert.equal(values.naam, '', 'the input is not changed')
})

test('without a profile, or with an empty value, nothing changes', () => {
	const values = { onderwerp: '', naam: '', email: '', type: '' }
	assert.deepEqual(withProfile(values, ACTION, null), values)
	assert.deepEqual(
		withProfile(values, ACTION, { displayName: '', email: 7 }),
		values,
	)
})

test('the schema form prefills after the draft and on a new action', () => {
	const source = readFileSync(
		new URL('../src/site/components/c/SchemaForm.vue', import.meta.url),
		'utf8',
	)
	assert.match(
		source,
		/this\.resumeDraft\(\)\.then\(\(\) => this\.prefillFromProfile\(\)\)/,
	)
	assert.match(source, /this\.api\.getDetails\(\)/)
	assert.match(source, /withProfile\(before, this\.action, profile\)/)
})
