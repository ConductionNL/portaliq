#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// portal-details.spec.mjs: the "Gegevens van de portal" form refuses what the
// portal cannot use and saves the stored portal with the form on top
// (portal-domains-and-editors REQ-PDE-004).
//
// Usage:
//   node --test tests/portal-details.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { formFrom, portalBody, validateDetails } from '../src/lib/portalDetails.js'

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

test('the body keeps the fields the form does not edit and drops bookkeeping keys', () => {
	const body = portalBody(PORTAL, { ...formFrom(PORTAL), tagline: ' Samen ' })
	assert.equal(body.status, 'published')
	assert.deepEqual(body.domains, PORTAL.domains)
	assert.equal(body.tagline, 'Samen')
	assert.equal('@self' in body, false)
})
