#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// intake-form-traffic-id.spec.mjs: the request form carries the id the traffic
// client counts it under, so form starts, submits, abandons and the field
// people leave on are counted for hosted forms too (buildiq
// capability-requirements-backfill T20, REQ-BQCV-020).
//
// Usage:
//   node --test tests/intake-form-traffic-id.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'

globalThis.window = {
	location: { hash: '', pathname: '/', search: '' },
	localStorage: { getItem: () => null, setItem() {}, removeItem() {} },
	sessionStorage: { getItem: () => null, setItem() {}, removeItem() {} },
	history: { replaceState() {} },
}

const { loadSfc } = await import('./support/render-sfc.mjs')
const BLOCK = await loadSfc('src/site/components/IntakeFormBlock.vue')
const { formIdOf } = await import('../src/traffic/helpers.js')

test('the form element binds the traffic id', () => {
	const source = readFileSync(new URL('../src/site/components/IntakeFormBlock.vue', import.meta.url), 'utf8')
	assert.match(source, /<form[^>]*:data-portaliq-form="trafficFormId"/s)
})

test('the traffic id is the served form id, else the route, else intake', () => {
	const id = (state) => BLOCK.computed.trafficFormId.call(state)
	assert.equal(id({ render: { formId: 'rf-1' }, bindingRoute: 'parkeren' }), 'rf-1')
	assert.equal(id({ render: {}, bindingRoute: 'parkeren' }), 'parkeren')
	assert.equal(id({ render: null, bindingRoute: '' }), 'intake')
})

test('the traffic client reads that id off the form', () => {
	const form = { id: '', getAttribute: (name) => (name === 'data-portaliq-form' ? 'rf-1' : null) }
	assert.equal(formIdOf(form), 'rf-1')
})
