#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// case-type-visibility-page.spec.mjs: the portal's "Case types" page
// (operate-show-per-case-type T06) sends the case types switched off, warns
// before a type is hidden, and is reachable from the portal's own page.
//
// Usage:
//   node --test tests/case-type-visibility-page.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import {
	caseTypesUrl,
	hiddenFrom,
	hidesMore,
} from '../src/lib/caseTypeVisibility.js'

const ROWS = [
	{
		register: 'dossiq',
		schema: 'caseType',
		typeId: 'vergunning',
		label: 'Omgevingsvergunning',
		shown: true,
	},
	{
		register: 'dossiq',
		schema: 'caseType',
		typeId: 'handhaving',
		label: 'Handhavingsdossier',
		shown: false,
	},
]

test('the save sends only the switched-off types, in the stored shape', () => {
	assert.deepEqual(hiddenFrom(ROWS), [
		{
			register: 'dossiq',
			schema: 'caseType',
			typeId: 'handhaving',
			label: 'Handhavingsdossier',
		},
	])
	assert.deepEqual(hiddenFrom([]), [])
})

test('the warning shows only when a save would hide a type that is shown now', () => {
	const saved = ROWS.map((row) => ({ ...row }))
	assert.equal(hidesMore(saved, ROWS), false)
	const edited = ROWS.map((row) => ({ ...row, shown: false }))
	assert.equal(hidesMore(saved, edited), true)
	const shownAgain = ROWS.map((row) => ({ ...row, shown: true }))
	assert.equal(hidesMore(saved, shownAgain), false)
})

test('the route names the portal by slug', () => {
	assert.equal(
		caseTypesUrl('mijn alkmaar', (path) => `/index.php${path}`),
		'/index.php/apps/portaliq/api/portals/mijn%20alkmaar/case-types',
	)
})

test('the portal page links to the page, and the app registers it', () => {
	const manifest = JSON.parse(
		readFileSync(new URL('../src/manifest.json', import.meta.url), 'utf8'),
	)
	const page = manifest.pages.find(
		(candidate) => candidate.id === 'PortalCaseTypes',
	)
	assert.equal(page.type, 'custom')
	assert.equal(page.route, '/portals/:id/case-types')
	assert.equal(page.component, 'PortalCaseTypeVisibility')
	const detail = manifest.pages.find(
		(candidate) => candidate.id === 'PortalDetail',
	)
	assert.ok(
		(detail.config.headerActions || []).some(
			(action) =>
				action.type === 'open-page' && action.target === 'PortalCaseTypes',
		),
	)
	const registry = readFileSync(
		new URL('../src/registry.js', import.meta.url),
		'utf8',
	)
	assert.match(
		registry,
		/PortalCaseTypeVisibility: \{\s*kind: 'page',\s*component: PortalCaseTypeVisibility,/,
	)
})
