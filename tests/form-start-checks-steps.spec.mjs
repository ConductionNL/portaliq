#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// form-start-checks-steps.spec.mjs: the values a request form starts with,
// the sentence a request's status reads as and the plain client-side checks
// of a form, pinned before each was split into named steps.
//
// Usage:
//   node --test tests/form-start-checks-steps.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { plainFieldErrors } from '../src/site/components/forms/fields.js'
import { initialValues, statusView } from '../src/site/lib/intakeApi.js'

const EMPTY_ADDRESS = { postcode: '', number: '', letter: '', addition: '', street: '', town: '' }

test('initialValues: the own details first, then a typed empty, then the preset, then empty', () => {
	assert.deepEqual(initialValues(null, null), {})
	assert.deepEqual(
		initialValues(
			[
				{ name: 'naam' },
				{ name: 'leeftijd', preset: 5 },
				{ name: 'kinderen', type: 'familyMembers', preset: 'x' },
				{ name: 'lijst', type: 'group' },
				{ name: 'adres', type: 'addressNL' },
				{ name: 'leeg', preset: null },
				{ name: 'nul', preset: 0 },
				{ name: '' },
				null,
				{ name: 'email', preset: 'p@x.nl' },
				{ name: 'telefoon', preset: '06' },
				{ name: 'kind', type: 'group' },
			],
			{ naam: 'Sanne', email: 'own@x.nl', telefoon: '', kind: 0, leeftijd: null, extra: 'y' },
		),
		{
			naam: 'Sanne',
			leeftijd: '5',
			kinderen: [],
			lijst: [],
			adres: EMPTY_ADDRESS,
			leeg: '',
			nul: '0',
			email: 'own@x.nl',
			telefoon: '06',
			kind: '0',
		},
	)
	const first = initialValues([{ name: 'a', type: 'addressNL' }, { name: 'b', type: 'addressNL' }], {})
	assert.notEqual(first.a, first.b, 'each address starts as its own object')
	assert.deepEqual(initialValues([{ name: 'naam', preset: 'x' }], { naam: 'eigen' }), { naam: 'eigen' })
})

test('statusView: not found, a Woo request with a term, registered, failed and queued', () => {
	assert.deepEqual(statusView(null), {
		tone: 'error',
		sentence: 'We kunnen geen aanvraag vinden met dit kenmerk. Controleer het kenmerk en probeer het opnieuw.',
	})
	const woo = statusView({ reference: 'R1', state: 'registered', dueAt: '2026-12-01', externalReference: 'W-9' })
	assert.equal(woo.tone, 'success')
	assert.match(woo.sentence, /^Uw verzoek R1 is ontvangen onder kenmerk W-9\. U krijgt uiterlijk .+ een besluit\.$/)
	assert.deepEqual(statusView({ reference: 'R1', state: 'registered', externalReference: 'W-9' }), {
		tone: 'success',
		sentence: 'Uw aanvraag R1 is geregistreerd. U vindt de zaak terug in uw eigen omgeving.',
	})
	assert.deepEqual(statusView({ reference: 'R1', state: 'registered', dueAt: '2026-12-01' }).tone, 'success')
	assert.match(statusView({ reference: 'R1', state: 'registered', dueAt: '2026-12-01' }).sentence, /is geregistreerd/)
	assert.deepEqual(statusView({ reference: 'R1', state: 'failed', dueAt: '2026-12-01', externalReference: 'W' }), {
		tone: 'error',
		sentence: 'Uw aanvraag R1 is nog niet geregistreerd. Neem contact met ons op en noem uw kenmerk R1.',
	})
	assert.deepEqual(statusView({ state: 'queued' }), {
		tone: 'info',
		sentence: 'Uw aanvraag  is ontvangen en wordt verwerkt. Kijk later nog eens met hetzelfde kenmerk.',
	})
	assert.deepEqual(statusView({ reference: 'R2', state: 'registered', dueAt: 'geen datum', externalReference: 'W' }).sentence, 'Uw aanvraag R2 is geregistreerd. U vindt de zaak terug in uw eigen omgeving.')
})

test('plainFieldErrors: required, date and format, in that order, with the given words', () => {
	const fields = [
		{ name: 'naam', label: 'Naam', required: true },
		{ name: 'opmerking', label: 'Opmerking' },
		{ name: 'van', label: 'Van', date: true, required: true },
		{ name: 'tot', label: 'Tot', date: true, format: 'iban' },
		{ name: 'iban', label: 'IBAN', format: 'iban' },
		{ name: 'goed', label: 'Goed', date: true },
	]
	const values = { naam: ' ', opmerking: '', van: '2026-02-31', tot: '2026-02-01', iban: 'NL00', goed: '2026-01-31' }
	const dutch = plainFieldErrors(fields, values)
	assert.deepEqual(Object.keys(dutch), ['naam', 'van', 'tot', 'iban'])
	assert.match(dutch.naam, /Naam/)
	assert.match(dutch.van, /Van/)
	const own = plainFieldErrors(fields, values, { required: 'Vul {field} in, {field}!', date: '{field} is geen datum' })
	assert.equal(own.naam, 'Vul Naam in, Naam!')
	assert.equal(own.van, 'Van is geen datum')
	assert.equal(own.tot, dutch.tot)
	assert.equal(own.iban, dutch.iban)
	assert.deepEqual(plainFieldErrors(null, values), {})
	assert.deepEqual(plainFieldErrors([{ name: 'naam', label: 'Naam', required: true }], null), { naam: dutch.naam })
	assert.deepEqual(plainFieldErrors([{ name: 'n', label: 'N', required: true }], { n: 0 }), {})
})
