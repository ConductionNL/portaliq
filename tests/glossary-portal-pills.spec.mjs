#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// glossary-portal-pills.spec.mjs: the glossary list draws a pill per portal
// with the portal's name and its count, "Alles 31 / Gemeente Zuiddrecht 24 /
// Mijn Zuiddrecht 7" (portaliq/PtBegrippen). A term names its portal by slug,
// so the pills come from the `portal` values and are labelled by the portal
// whose `slug` matches (nextcloud-vue quickFiltersFrom.valueField).
//
// Usage:
//   node --test tests/glossary-portal-pills.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'

const read = (name) => readFileSync(new URL(`../${name}`, import.meta.url), 'utf8')
const MANIFEST = JSON.parse(read('src/manifest.json'))
const SCHEMAS = JSON.parse(read('lib/Settings/portaliq_register.json')).components.schemas
const NL = JSON.parse(read('l10n/nl.json')).translations
const GLOSSARY = MANIFEST.pages.find((page) => page.id === 'Glossary').config

test('the pills come from the terms\' portals, named by the portal with that slug', () => {
	assert.equal(GLOSSARY.quickFilters, undefined, 'declared pills would switch the generated ones off')
	assert.deepEqual(GLOSSARY.quickFiltersFrom, {
		field: 'portal',
		register: 'portaliq',
		schema: 'portal',
		valueField: 'slug',
		labelField: 'title',
		allLabel: 'Alles',
	})
	assert.ok(SCHEMAS.glossaryTerm.properties.portal, 'a term has no portal')
	assert.ok(SCHEMAS.portal.properties.slug && SCHEMAS.portal.properties.title, 'a portal has no slug or title')
	assert.ok(NL[GLOSSARY.quickFiltersFrom.allLabel])
})
