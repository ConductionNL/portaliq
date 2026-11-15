#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// portal-side-cards-as-drawn.spec.mjs: a portal page's side cards read as
// board PtMededelingenActies draws them: the domain card as terms with the
// language in words, the theme as a swatch with its name, the status as a
// pill with the lines under it, the administrators as people with a role.
//
// Usage:
//   node --test tests/portal-side-cards-as-drawn.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import { domainTerms, editorPeople, languageName, statusSummary } from '../src/lib/portalSummary.js'

const NL = { Domain: 'Domein', Certificate: 'Certificaat', Language: 'Taal', 'valid until {date}': 'geldig tot {date}', Published: 'Gepubliceerd', Draft: 'Concept', 'Last changed {date}': 'Laatst gewijzigd {date}', 'Chief editor': 'Hoofdredacteur', Editor: 'Redacteur' }
const t = (key, vars = {}) => (NL[key] || key).replace(/\{(\w+)\}/g, (_, k) => vars[k] ?? '')
const SOURCE = readFileSync(new URL('../src/widgets/PortalSummaryCard.vue', import.meta.url), 'utf8')

test('the domain card names the language in words and the first domain in bold', () => {
	const terms = domainTerms({ domains: ['zuiddrecht.nl', 'www.zuiddrecht.nl'], locales: ['nl'] }, t, 'nl')
	assert.deepEqual(terms.map((x) => [x.label, x.value, x.strong]), [
		['Domein', 'zuiddrecht.nl', true],
		['Domein', 'www.zuiddrecht.nl', false],
		['Taal', 'Nederlands', false],
	])
	assert.equal(languageName('xx-unknown-tag-!', 'nl'), 'xx-unknown-tag-!')
})

test('the status card is a pill with the last change under it', () => {
	const card = statusSummary({ status: 'published', '@self': { updated: '2026-11-02T08:13:04+00:00' } }, t, 'nl')
	assert.deepEqual(card.pill, { label: 'Gepubliceerd', tone: 'success' })
	assert.match(card.lines[0], /^Laatst gewijzigd 2 nov 2026$/)
	assert.deepEqual(statusSummary({ status: 'draft' }, t, 'nl'), { pill: { label: 'Concept', tone: 'warning' }, lines: [] })
})

test('the administrators are people with their initials and role', () => {
	assert.deepEqual(editorPeople({ editors: [{ userId: 'pieter', displayName: 'Pieter Jansen', role: 'chief-editor' }, { userId: 'anouk', displayName: 'Anouk Bakker' }, { displayName: 'no id' }] }, t), [
		{ key: 'pieter', initials: 'PJ', name: 'Pieter Jansen', role: 'Hoofdredacteur' },
		{ key: 'anouk', initials: 'AB', name: 'Anouk Bakker', role: 'Redacteur' },
	])
})

test('the card draws terms, a swatch, a pill and people, 12px apart', () => {
	assert.match(SOURCE, /class="portal-summary__terms"/)
	assert.match(SOURCE, /class="portal-summary__swatch"/)
	assert.match(SOURCE, /<CnStatusBadge/)
	assert.match(SOURCE, /class="portal-summary__initials"/)
	assert.match(SOURCE, /\.portal-summary \{[^}]*gap: 12px;/)
	assert.match(SOURCE, /\.portal-summary__terms \{[^}]*gap: 8px 16px;/)
	assert.match(SOURCE, /\.portal-summary__label,\n\.portal-summary__value \{[^}]*padding: 0;[^}]*text-align: start;/)
})
