#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// dutch-formats.spec.mjs: the site checks the Dutch formats with the same
// fixtures as the server (data-lookups-and-checks-in-forms REQ-DIF-002), and
// a wrong IBAN stops the form on the screen with the sentence the board draws.
//
// Usage:
//   node --test tests/dutch-formats.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import { plainFieldErrors } from '../src/site/components/forms/fields.js'
import { formatProblem, FORMATS, normaliseFormat } from '../src/site/components/forms/formats.js'

const fixtures = JSON.parse(readFileSync('tests/fixtures/dutch-formats.json', 'utf8'))

test('every format has fixtures, and only the formats the server knows', () => {
	assert.deepEqual([...FORMATS].sort(), Object.keys(fixtures).sort())
})

for (const [format, cases] of Object.entries(fixtures)) {
	test(`${format}: valid fixtures are stored normalised, invalid ones are refused`, () => {
		for (const [input, stored] of cases.valid) {
			assert.equal(normaliseFormat(format, input), stored, input)
			assert.equal(formatProblem(format, input), '', input)
		}
		for (const input of cases.invalid) {
			assert.equal(normaliseFormat(format, input), null, input)
			if (input !== '') {
				assert.notEqual(formatProblem(format, input), '', input)
			}
		}
	})
}

test('the form stops on a wrong IBAN and says what the board says', () => {
	const fields = [{ name: 'iban', label: 'IBAN', required: true, date: false, format: 'iban' }]
	assert.deepEqual(plainFieldErrors(fields, { iban: 'NL91ABNA0417164301' }), {
		iban: 'Dit IBAN klopt niet. Controleer de cijfers.',
	})
	assert.deepEqual(plainFieldErrors(fields, { iban: 'NL91 ABNA 0417 1643 00' }), {})
	assert.deepEqual(plainFieldErrors([{ ...fields[0], required: false }], { iban: '' }), {}, 'an empty optional field is fine')
})

test('a format this file does not check changes nothing', () => {
	assert.equal(formatProblem('colour', 'red'), '')
})
