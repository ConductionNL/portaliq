#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// page-traffic.spec.mjs: a portal page's traffic (portal-page-traffic).
//
// Usage:
//   node --test tests/page-traffic.spec.mjs
//
// The case that matters most is the null one: a list no day of the period
// counted must stay null, so the widget says "Not available for this
// period" instead of drawing an empty table that reads as nobody.

import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import { flowOf, pageRoute } from '../src/lib/pageTraffic.js'

describe('pageRoute', () => {
	it('keys a page by its route with one leading slash and no trailing one', () => {
		assert.equal(pageRoute({ route: '/contact' }), '/contact')
		assert.equal(pageRoute({ route: 'contact/' }), '/contact')
		assert.equal(pageRoute({ route: '/' }), '/')
		assert.equal(pageRoute({ route: '' }), '/')
	})

	it('answers nothing for a page without a route', () => {
		assert.equal(pageRoute({}), '')
		assert.equal(pageRoute(null), '')
	})
})

describe('flowOf', () => {
	const answer = {
		previous: [{ path: '/', count: 3 }],
		next: [{ path: '/woo', count: 1 }],
		entrances: 4,
		exits: 2,
		referrers: [{ host: 'www.google.com', channel: 'organic', count: 2 }],
		outbound: [{ url: 'https://www.tilburg.nl/', count: 1 }],
	}

	it('reads the previous pages, entrances and referrers for incoming', () => {
		assert.deepEqual(flowOf(answer, 'incoming'), {
			pages: answer.previous,
			boundary: 4,
			sources: answer.referrers,
		})
	})

	it('reads the next pages, exits and outbound links for outgoing', () => {
		assert.deepEqual(flowOf(answer, 'outgoing'), {
			pages: answer.next,
			boundary: 2,
			sources: answer.outbound,
		})
	})

	it('keeps an uncounted list null, never empty', () => {
		const flow = flowOf({ ...answer, referrers: null }, 'incoming')
		assert.equal(flow.sources, null)
		assert.equal(flowOf(null, 'outgoing').pages, null)
	})
})
