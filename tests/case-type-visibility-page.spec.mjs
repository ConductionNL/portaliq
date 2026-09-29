#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// case-type-visibility-page.spec.mjs: the "Case types" widget on a portal's
// page (operate-show-per-case-type T06) sends the case types switched off,
// and warns before a type is hidden.
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

test('the portal page carries the widget, and the app registers it', () => {
	const manifest = JSON.parse(
		readFileSync(new URL('../src/manifest.json', import.meta.url), 'utf8'),
	)
	const detail = manifest.pages.find(
		(candidate) => candidate.id === 'PortalDetail',
	)
	const widget = detail.config.widgets.find(
		(candidate) => candidate.type === 'PortalCaseTypes',
	)
	assert.equal(widget.title, 'Case types')
	assert.ok(
		detail.config.layout.some((item) => item.widgetId === widget.id),
		'the widget has a place in the layout',
	)
	const registry = readFileSync(
		new URL('../src/registry.js', import.meta.url),
		'utf8',
	)
	assert.match(
		registry,
		/PortalCaseTypes: \{[^}]*kind: 'widget',\s*component: PortalCaseTypes,/,
	)
})
