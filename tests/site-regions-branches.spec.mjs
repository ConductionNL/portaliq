#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// site-regions-branches.spec.mjs: how a page's regions are read (served,
// grouped by slot, cleared) and resolved against the portal and the default,
// with the order of their keys, pinned before both were split into steps.
//
// Usage:
//   node --test tests/site-regions-branches.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { DEFAULT_REGIONS, pageRegionsOf, regionOf, resolveRegions } from '../src/site/lib/regions.js'

const A = { id: 'a', slot: 'hero' }
const B = { id: 'b', slot: 'body' }
const C = { id: 'c' }
const D = { id: 'd', slot: 'aside' }
const E = { id: 'e', slot: 'sidebar' }
const F = { id: 'f', slot: 'hero' }

/**
 * @param {object} value A regions object.
 * @return {Array} Its entries, in key order.
 */
const entries = (value) => Object.entries(value)

test('no body, served regions and widgets grouped by slot', () => {
	assert.deepEqual(entries(pageRegionsOf(null)), [])
	assert.deepEqual(entries(pageRegionsOf({ regions: { footer: [], hero: [A], other: [B] }, widgets: [D] })), [
		['hero', [A]],
		['footer', []],
	])
	assert.deepEqual(entries(pageRegionsOf({ regions: [A], widgets: [D, A, B, C, E, F] })), [
		['aside', [D]],
		['hero', [A, F]],
		['main', [B, C]],
	])
	assert.deepEqual(entries(pageRegionsOf({})), [])
})

test('cleared regions are added after, never over a stated one', () => {
	assert.deepEqual(entries(pageRegionsOf({ widgets: [A], clearedRegions: ['footer', 'hero', 'nope', 'aside'] })), [
		['hero', [A]],
		['footer', []],
		['aside', []],
	])
	assert.deepEqual(entries(pageRegionsOf({ regions: { main: null }, clearedRegions: ['main', 'header'] })), [
		['main', null],
		['header', []],
	])
})

test('every region resolves page first, then portal, then the default, in render order', () => {
	const resolved = resolveRegions({ hero: [A], main: null }, { hero: [F], aside: [D], footer: null })
	assert.deepEqual(entries(resolved), [
		['header', DEFAULT_REGIONS.header],
		['hero', [A]],
		['main', []],
		['aside', [D]],
		['footer', []],
	])
	assert.notEqual(resolved.header, DEFAULT_REGIONS.header, 'a copy, never the default itself')
	assert.deepEqual(resolveRegions(null, [A]), { ...DEFAULT_REGIONS })
	assert.deepEqual(resolveRegions(undefined, null), { ...DEFAULT_REGIONS })
})

test('a slot names its region; none, empty and body mean main', () => {
	for (const slot of [undefined, null, '', 'body', 'main']) {
		assert.equal(regionOf(slot), 'main', String(slot))
	}
	for (const region of ['header', 'hero', 'aside', 'footer']) {
		assert.equal(regionOf(region), region)
	}
	for (const slot of ['Body', 'sidebar', 0, false, ' ']) {
		assert.equal(regionOf(slot), null, String(slot))
	}
})
