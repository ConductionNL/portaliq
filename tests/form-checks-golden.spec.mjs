#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// form-checks-golden.spec.mjs: what the form's format checks, calculations,
// summary sentence and address check answer, pinned in a golden file before
// each was split into named steps. Dates are fixed, never today's.
//
// Usage:
//   node --test tests/form-checks-golden.spec.mjs
//   GOLDEN_RECORD=1 node --test tests/form-checks-golden.spec.mjs

import { test } from 'node:test'
import { addressProblem } from '../src/site/components/forms/address.js'
import { calculatedValues, evaluate } from '../src/site/components/forms/calculate.js'
import { normaliseFormat } from '../src/site/components/forms/formats.js'
import { summarySentence } from '../src/site/components/forms/summary.js'
import { golden } from './support/golden.mjs'

// The day words read the day in the reader's time zone: pin one.
process.env.TZ = 'Europe/Amsterdam'

test('the form checks answer as they did', () => {
	const file = golden('form-checks')
	file.check('normaliseFormat', [
		['bsn', ' 111222333 '], ['bsn', '123456789'], ['iban', 'nl91 abna 0417 1643 00'], ['iban', 'NL91ABNA041716430'],
		['nl-licence-plate', 'ab-12-34'], ['phone-nl', '06-1234 5678'], ['phone-international', '+44 20 7946 0958'],
		['postcode', '1234ab'], ['postcode', '1234 SA'], ['kvk', '12345678'], ['kvk', '1234567'], ['kvk-branch', '123456789012'],
		['email', 'a@b.nl'], ['toString', 'x'], ['__proto__', 'x'], [undefined, 'x'], ['bsn', null],
	].map(([format, value]) => normaliseFormat(format, value)))
	const answers = {
		a: '2', b: 3, c: 'x', d: '', blank: null, obj: { x: 1 }, list: [1, 2],
		from: '2026-10-01', to: '2026-10-15', bad: '2026-02-30', days: '5.9',
		kids: [{ age: '4' }, { age: 6 }, { name: 'z' }, null], none: [],
	}
	file.check('evaluate', [
		null, {}, { op: 'divide', args: ['a'] }, { op: 'sum', args: [] }, { op: 'sum', args: 'a' },
		{ op: 'count', args: ['list'] }, { op: 'count', args: ['a'] },
		{ op: 'addDays', args: ['from', 'days'] }, { op: 'addDays', args: ['from', 'c'] }, { op: 'addDays', args: ['bad', 1] },
		{ op: 'addDays', args: ['from', 'kids[].age'] }, { op: 'addDays', args: ['from', -20] },
		{ op: 'diffDays', args: ['from', 'to'] }, { op: 'diffDays', args: ['to', 'from'] }, { op: 'diffDays', args: ['from', 'bad'] },
		{ op: 'sum', args: ['a', 'b', 4, '1.5'] }, { op: 'sum', args: ['a', 'missing'] }, { op: 'sum', args: ['a', 'c'] },
		{ op: 'sum', args: ['kids[].age', 'a'] }, { op: 'sum', args: ['none[].age'] }, { op: 'sum', args: ['none[].age', 1] },
		{ op: 'multiply', args: ['a', 'b'] }, { op: 'subtract', args: [10, 'a', 'b'] }, { op: 'subtract', args: ['obj', 1] },
		{ op: 'sum', args: ['list'] }, { op: 'sum', args: ['blank'] }, { op: 'sum', args: [Infinity] }, { op: 'sum', args: [true] },
	].map((calculate) => evaluate(calculate, answers)))
	file.check('calculatedValues', calculatedValues([
		{ name: 'total', calculate: { op: 'sum', args: ['a', 'b'] } },
		null,
		{ name: 'plain' },
		{ name: 'double', calculate: { op: 'multiply', args: ['total', 2] } },
		{ name: 'b', calculate: { op: 'sum', args: ['b', 1] } },
		{ name: 'broken', calculate: { op: 'sum', args: ['c'] } },
	], answers))
	file.check('calculatedValues of nothing', [calculatedValues(null, null), calculatedValues('x', {})])
	const context = { locale: 'nl', now: new Date(Date.UTC(2026, 9, 6, 12)) }
	const summary = {
		template: '{who} meldt {child} ziek op {day}, {extra} {list}.',
		phrases: { who: { ouder: 'de ouder', leeg: '  ' } },
	}
	const options = { child: [{ value: 1, label: ' Sam ' }, { value: 2, label: '' }], list: [{ value: 'a', label: 'A' }] }
	file.check('summarySentence', [
		summarySentence(summary, { who: 'ouder', child: 1, day: '2026-10-07', extra: 'ook', list: ['a', 'b', '', null] }, options, context),
		summarySentence(summary, { who: 'leeg', child: 2, day: 'morgen', extra: ' x ', list: ['a'] }, options, context),
		summarySentence(summary, { who: 'ouder', child: 1, day: '2026-10-07', extra: { a: 1 }, list: ['a'] }, options, context),
		summarySentence(summary, { who: 'ouder', child: 1, day: '2026-10-07', extra: '  ', list: ['a'] }, options, context),
		summarySentence(summary, { who: 'ouder', child: 1, day: '2026-10-07', extra: 'x', list: [] }, options, context),
		summarySentence({ template: '{a}  en   {b}' }, { a: 'een', b: 2 }, undefined, { locale: 'en', now: context.now }),
		summarySentence({ template: '  ' }, {}, {}, context),
		summarySentence(null, {}, {}, context),
	])
	file.check('addressProblem', [
		null, 'x', {},
		{ postcode: '1234 AB', number: '12', street: 'Hoofdstraat', town: 'Utrecht' },
		{ postcode: '1234ab', number: 7, street: ' S ', town: ' T ' },
		{ postcode: '0234 AB', number: '12', street: 'S', town: 'T' },
		{ postcode: '1234 AB', number: '0', street: 'S', town: 'T' },
		{ postcode: '1234 AB', number: '123456', street: 'S', town: 'T' },
		{ postcode: '1234 AB', number: '12', street: ' ', town: 'T' },
		{ postcode: '1234 AB', number: '12', street: 'S', town: '' },
		{ number: '12', street: 'S', town: 'T' },
	].map((block) => addressProblem(block)))
	file.save()
})
