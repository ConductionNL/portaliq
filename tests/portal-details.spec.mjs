#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// portal-details.spec.mjs: the "Gegevens van de portal" form refuses what the
// portal cannot use and saves only the fields that changed
// (portal-domains-and-editors REQ-PDE-004).
//
// Usage:
//   node --test tests/portal-details.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import { afterSave, formFrom, portalPatch, validateDetails } from '../src/lib/portalDetails.js'

const PORTAL = {
	title: 'Gemeente Zuiddrecht',
	slug: 'zuiddrecht',
	status: 'published',
	domains: [{ hostname: 'zuiddrecht.nl', verified: true }],
	'@self': { id: 'p1' },
}

test('a complete form is valid', () => {
	assert.deepEqual(validateDetails(formFrom(PORTAL)), {})
})

test('an empty name, a bad address, no language and a bad origin are each refused', () => {
	const errors = validateDetails({
		...formFrom(PORTAL),
		title: '  ',
		slug: 'Bad Slug',
		locales: [],
		frameAncestors: ['www.example.nl'],
	})
	assert.deepEqual(Object.keys(errors).sort(), ['frameAncestors', 'locales', 'slug', 'title'])
})

test('an origin with a scheme and a port is accepted', () => {
	const errors = validateDetails({ ...formFrom(PORTAL), frameAncestors: ['https://www.example.nl', 'http://localhost:8080'] })
	assert.equal(errors.frameAncestors, undefined)
})

test('the patch holds only the fields that changed, so nothing else is sent', () => {
	const patch = portalPatch(PORTAL, { ...formFrom(PORTAL), tagline: ' Samen ' })
	assert.deepEqual(patch, { tagline: 'Samen' })
})

test('a form left as loaded sends nothing', () => {
	assert.deepEqual(portalPatch(PORTAL, formFrom(PORTAL)), {})
})

test('a list that changed is sent whole', () => {
	const patch = portalPatch(PORTAL, { ...formFrom(PORTAL), locales: ['nl', 'en'] })
	assert.deepEqual(patch, { locales: ['nl', 'en'] })
})

test('a second save compares against the first save, not against the page as it loaded', () => {
	// Save "B" over "A", then edit back to "A": the server holds "B", so "A" must be sent.
	let baseline = PORTAL
	const first = portalPatch(baseline, { ...formFrom(baseline), title: 'B' })
	assert.deepEqual(first, { title: 'B' })
	baseline = afterSave(baseline, first)
	const back = portalPatch(baseline, { ...formFrom(baseline), title: PORTAL.title })
	assert.deepEqual(back, { title: PORTAL.title })
	// Against the stale starting values the revert looked like "nothing changed".
	assert.deepEqual(portalPatch(PORTAL, { ...formFrom(PORTAL), title: PORTAL.title }), {})
})

test('the form saves against its baseline and moves it after a save', () => {
	const src = readFileSync(new URL('../src/widgets/PortalDetailsForm.vue', import.meta.url), 'utf8')
	assert.match(src, /portalPatch\(this\.baseline, this\.form\)/)
	assert.match(src, /this\.baseline = afterSave\(this\.baseline, patch\)/)
	assert.doesNotMatch(src, /portalPatch\(this\.objectData/)
})
