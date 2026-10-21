#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// list-value-labels.spec.mjs: the admin lists show a stored code as the word
// the boards print ("Actief", "DigiD", "Leverancier", "Afbeelding",
// "Gepubliceerd") instead of the raw value ("active", "digid", "supplier",
// "image", "published"). Each column declares the codes with a label, every
// label has a Dutch translation, and a code the schema constrains is one the
// schema allows.
//
// Usage:
//   node --test tests/list-value-labels.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'

const read = (name) => readFileSync(new URL(`../${name}`, import.meta.url), 'utf8')
const MANIFEST = JSON.parse(read('src/manifest.json'))
const SCHEMAS = JSON.parse(read('lib/Settings/portaliq_register.json')).components.schemas
const NL = JSON.parse(read('l10n/nl.json')).translations

const LABELLED = [
	['PortalAccounts', 'audience', { supplier: 'Leverancier', citizen: 'Inwoner', organisation: 'Bedrijf' }],
	['PortalAccounts', 'identityType', { digid: 'DigiD', eherkenning: 'eHerkenning', email: 'Account' }],
	['PortalAccounts', 'status', { active: 'Actief', pending: 'Wacht op goedkeuring', void: 'Ingetrokken' }],
	['Media', 'kind', { image: 'Afbeelding', file: 'Document' }],
	['Media', 'status', { published: 'Gepubliceerd', draft: 'Concept' }],
	['Portals', 'kind', { site: 'Website' }],
	['Portals', 'status', { published: 'Gepubliceerd' }],
	['Invitations', 'state', { sent: 'Open', accepted: 'Geaccepteerd', expired: 'Verlopen' }],
	['AccessRequests', 'state', { pending: 'Wacht op besluit', granted: 'Toegekend', refused: 'Afgewezen' }],
	['PortalSubmissions', 'deliveryStatus', { delivered: 'Bevestigd', failed: 'Mislukt' }],
	['FormBindings', 'status', { published: 'Gepubliceerd' }],
	['News', 'status', { draft: 'Concept' }],
	['Notices', 'status', { published: 'Gepubliceerd' }],
]

const page = (id) => MANIFEST.pages.find((candidate) => candidate.id === id)

for (const [pageId, key, expected] of LABELLED) {
	test(`${pageId} shows ${key} as words`, () => {
		const config = page(pageId).config
		const column = config.columns.find((c) => typeof c === 'object' && c.key === key)
		assert.ok(column, `${pageId} has no column object for ${key}`)
		assert.deepEqual(column.enum, Object.keys(column.enumLabels))
		for (const [code, dutch] of Object.entries(expected)) {
			assert.equal(NL[column.enumLabels[code]], dutch, `${pageId}.${key}: "${code}" does not read "${dutch}"`)
		}
		for (const label of Object.values(column.enumLabels)) {
			assert.ok(NL[label], `"${label}" is not in the Dutch catalogue`)
		}
		const property = SCHEMAS[config.schema].properties[key]
		if (Array.isArray(property.enum)) {
			for (const code of column.enum) {
				assert.ok(property.enum.includes(code), `${config.schema}.${key} does not allow "${code}"`)
			}
		}
	})
}
