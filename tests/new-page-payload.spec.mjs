#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// new-page-payload.spec.mjs: the draft page the editor creates (trimmed
// title, normalised route, last among its siblings, a parent only when
// given) and what it refuses, pinned before newPagePayload was split into
// named steps.
//
// Usage:
//   node --test tests/new-page-payload.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { newPagePayload } from '../src/editor/pageTree.js'

const PAGES = [
	{ id: 'home', route: '/' },
	{ id: 'about', route: '/over-ons', parent: '' },
	{ id: 'team', route: '/over-ons/team', parent: 'about' },
	{ id: 'jobs', route: 'over-ons/werk/', parent: 'about' },
]

test('a new page is a draft placed last among its siblings', () => {
	assert.deepEqual(newPagePayload({ title: '  Contact ', route: 'contact', portal: 'p' }, PAGES), {
		title: 'Contact',
		route: '/contact',
		portal: 'p',
		status: 'draft',
		order: 2,
		body: { type: 'grid', widgets: [] },
	})
	const child = newPagePayload({ title: 'Stage', route: '/over-ons/stage', portal: 'p', parent: 'about' }, PAGES)
	assert.deepEqual([child.order, child.parent, Object.keys(child)], [2, 'about', ['title', 'route', 'portal', 'status', 'order', 'body', 'parent']])
	assert.equal(newPagePayload({ title: 'X', route: '/x', portal: 'p', parent: 'none' }, PAGES).order, 0)
	assert.equal(newPagePayload({ title: 'X', route: '/y', portal: 'p', parent: '' }, []).order, 0)
})

test('a page without a title, or on a route in use, is refused', () => {
	assert.throws(() => newPagePayload({ title: '   ', route: '/z', portal: 'p' }, PAGES), { message: 'A page needs a title.' })
	assert.throws(() => newPagePayload({ title: null, route: '/z', portal: 'p' }, PAGES), { message: 'A page needs a title.' })
	assert.throws(() => newPagePayload({ title: 'W', route: '/over-ons/werk', portal: 'p' }, PAGES), { message: 'Another page already uses this route.' })
	assert.throws(() => newPagePayload({ title: '', route: '/over-ons', portal: 'p' }, PAGES), { message: 'A page needs a title.' }, 'the title is checked first')
})
