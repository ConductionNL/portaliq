#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// list-pills.spec.mjs: the quick filter pills of four lists carry the pills
// their boards draw (portaliq/PtSessies, PtUitnodigingen, PtMedia,
// PtInzendingen). A pill filters on the schema's own fields and values, so a
// pill on a field or enum value the schema lacks would match nothing and say
// nothing: every filter is held against the register here.
//
// Usage:
//   node --test tests/list-pills.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'

const read = (name) => readFileSync(new URL(`../${name}`, import.meta.url), 'utf8')
const MANIFEST = JSON.parse(read('src/manifest.json'))
const SCHEMAS = JSON.parse(read('lib/Settings/portaliq_register.json')).components.schemas
const NL = JSON.parse(read('l10n/nl.json')).translations
const config = (id) => MANIFEST.pages.find((page) => page.id === id).config
const pills = (id) => config(id).quickFilters
const pill = (id, label) => pills(id).find((entry) => entry.label === label)
const OPERATORS = new Set(['gt', 'gte', 'lt', 'lte'])

/**
 * Assert every field and enum value a list's pills filter on exists in its schema.
 *
 * @param {string} id The page id.
 */
function filtersAreSchemaFields(id) {
	const props = SCHEMAS[config(id).schema].properties
	for (const entry of pills(id)) {
		assert.ok(NL[entry.label], `pill "${entry.label}" is not in the Dutch catalogue`)
		for (const [field, value] of Object.entries(entry.filter)) {
			assert.ok(props[field], `${id}: "${entry.label}" filters on "${field}", which the schema lacks`)
			const values = Array.isArray(value) ? value : [value]
			for (const one of values) {
				if (one && typeof one === 'object') {
					for (const op of Object.keys(one)) assert.ok(OPERATORS.has(op), `${id}: unknown operator ${op}`)
					continue
				}
				if (props[field].enum) {
					assert.ok(props[field].enum.includes(one), `${id}: "${one}" is not a ${field} value`)
				}
			}
		}
	}
}

test('sessions: Actief, Verlopen and Ingetrokken split the sessions without overlap', () => {
	const labels = pills('PortalSessions').map((entry) => entry.label)
	for (const label of ['Actief', 'Verlopen', 'Ingetrokken']) assert.ok(labels.includes(label), label)
	assert.deepEqual(pill('PortalSessions', 'Actief').filter, { revoked: false, expiresAt: { gt: '@now' } })
	assert.deepEqual(pill('PortalSessions', 'Verlopen').filter, { revoked: false, expiresAt: { lte: '@now' } })
	assert.deepEqual(pill('PortalSessions', 'Ingetrokken').filter, { revoked: true })
	filtersAreSchemaFields('PortalSessions')
})

test('invitations: Open holds every invitation not yet answered, Ingetrokken the withdrawn ones', () => {
	const labels = pills('Invitations').map((entry) => entry.label)
	assert.deepEqual(labels, ['Alles', 'Open', 'Geaccepteerd', 'Verlopen', 'Ingetrokken'])
	assert.deepEqual(pill('Invitations', 'Open').filter, { state: ['sent', 'opened'] })
	assert.deepEqual(pill('Invitations', 'Ingetrokken').filter, { state: 'revoked' })
	filtersAreSchemaFields('Invitations')
})

test('media: images and documents have their own pill', () => {
	assert.deepEqual(pill('Media', 'Afbeeldingen').filter, { kind: 'image' })
	assert.deepEqual(pill('Media', 'Documenten').filter, { kind: 'file' })
	filtersAreSchemaFields('Media')
})

test('submissions: Vandaag holds what came in since midnight', () => {
	assert.deepEqual(pill('PortalSubmissions', 'Vandaag').filter, { submittedAt: { gte: '@today' } })
	filtersAreSchemaFields('PortalSubmissions')
})

test('every pill shows its count and one pill per list is the default', () => {
	for (const id of ['PortalSessions', 'Invitations', 'Media', 'PortalSubmissions']) {
		assert.equal(pills(id).filter((entry) => entry.default).length, 1, id)
		for (const entry of pills(id)) assert.equal(entry.showCount, true, `${id} ${entry.label}`)
	}
})
