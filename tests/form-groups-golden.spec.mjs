#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// form-groups-golden.spec.mjs: a repeating group's settings, an item's empty
// required sub-fields, the groups' count errors and the confirmation page,
// pinned in a golden file before each was split into named steps.
//
// Usage:
//   node --test tests/form-groups-golden.spec.mjs
//   GOLDEN_RECORD=1 node --test tests/form-groups-golden.spec.mjs

import { test } from 'node:test'
import { confirmationView } from '../src/site/components/forms/confirmation.js'
import { groupCountErrors, itemErrors, repeatOf } from '../src/site/components/forms/group.js'
import { golden } from './support/golden.mjs'

const KIDS = {
	type: 'group',
	name: 'kids',
	repeat: { min: 2, max: 3, itemLabel: 'kind', itemsLabel: 'kinderen', addLabel: 'Kind toevoegen' },
	fields: [
		{ name: 'name', label: 'Naam', required: true },
		{ name: 'age', required: true },
		{ name: 'note' },
	],
}

test('groups and the confirmation page', () => {
	const file = golden('form-groups')
	file.check('repeatOf', [
		null,
		{},
		{ required: true },
		{ required: true, repeat: { min: 0, max: -1 } },
		{ repeat: { min: 1.5, max: '4', itemLabel: '', itemsLabel: 3, addLabel: '' } },
		{ repeat: { itemLabel: 'auto' } },
		KIDS,
	].map((field) => repeatOf(field)))
	file.check('itemErrors', [
		itemErrors(KIDS, { name: ' ', age: 0 }),
		itemErrors(KIDS, null, 'Vul {field} in, {field}!'),
		itemErrors(KIDS, { name: 'Sam', age: '4' }),
		itemErrors({ fields: 'x' }, {}),
		itemErrors({ fields: [{ name: 'a', required: 'yes' }] }, {}),
	])
	const fields = [
		null,
		{ type: 'text', name: 'x' },
		KIDS,
		{ type: 'group', name: 'pets', repeat: { max: 1 } },
		{ type: 'group', name: 'cars', required: true },
	]
	file.check('groupCountErrors', [
		groupCountErrors(fields, { kids: [{}], pets: [{}, {}], cars: [] }),
		groupCountErrors(fields, { kids: [{}, {}, {}, {}], pets: 'x' }),
		groupCountErrors(fields, { kids: [{}, {}], pets: [{}], cars: [{}] }),
		groupCountErrors(fields, null, { addMore: 'Nog {n} {item}', tooMany: 'Max {n}' }),
		groupCountErrors('x', {}),
	])
	file.check('confirmationView', [
		confirmationView(null, 'Bedankt {reference}.', { reference: 'R-1' }, 'Klaar'),
		confirmationView('x', '', { reference: '' }, 'Klaar'),
		confirmationView(
			{ title: '  ', body: 'Nummer {reference}, besluit voor {deadline}.', next: [null, { title: ' ' }, { text: 'Wacht' }, { title: 'Stap', text: 3 }] },
			'fallback',
			{ reference: 'R-2', deadline: '2026-11-01' },
			'Klaar',
		),
		confirmationView({ title: 'Eigen', body: 'Geen datum {deadline}.', next: 'x' }, '', { reference: 'R' }, 'Klaar'),
	])
	file.save()
})
