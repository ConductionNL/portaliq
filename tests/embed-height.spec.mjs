#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// embed-height.spec.mjs — the frame's half of the height negotiation.
//
// Usage:
//   node --test tests/embed-height.spec.mjs
//
// 🔴 THE HOST PAGE CANNOT SEE INSIDE THE FRAME. Cross-origin, unless the frame
// says how tall it is, nobody knows. An iframe sized by nothing renders at
// whatever the host's CSS left it, and on plenty of sites that is zero: a blank
// strip where a form should be, with nothing logged and nothing on screen to
// say why.
//
// These pin the frame side. The declared floor in the pasted snippet is pinned
// in PortalEmbedHeightTest, and that floor is what makes the form visible when
// this script never runs at all.

import assert from 'node:assert/strict'
import { test } from 'node:test'
import {
	EMBED_HEIGHT_MESSAGE,
	EMBED_MAXIMUM_HEIGHT,
	EMBED_MINIMUM_HEIGHT,
	heightToReport,
	measureDocument,
	startHeightReporting,
} from '../src/portal/embedHeight.js'

test('an unmeasurable document reports the floor rather than nothing', () => {
	assert.equal(heightToReport(undefined), EMBED_MINIMUM_HEIGHT)
	assert.equal(heightToReport(null), EMBED_MINIMUM_HEIGHT)
	assert.equal(heightToReport(Number.NaN), EMBED_MINIMUM_HEIGHT)
	assert.equal(measureDocument(null), EMBED_MINIMUM_HEIGHT)
})

// 🔴 A form that measures itself at 40 pixels mid-render would hide itself just
// as effectively as never reporting at all.
test('a measurement below the floor is raised to it', () => {
	assert.equal(heightToReport(40), EMBED_MINIMUM_HEIGHT)
	assert.equal(heightToReport(0), EMBED_MINIMUM_HEIGHT)
	assert.equal(heightToReport(-20), EMBED_MINIMUM_HEIGHT)
})

test('a taller form is honoured, so the floor is a floor and not a fixed size', () => {
	assert.equal(heightToReport(1320), 1320)
	assert.equal(heightToReport(1319.6), 1320)
})

test('an absurd measurement is capped', () => {
	assert.equal(heightToReport(6000000), EMBED_MAXIMUM_HEIGHT)
})

/**
 * A window double that records what was posted.
 *
 * @param {number} height The document height it will measure.
 * @param {boolean} framed Whether it is inside a frame.
 * @return {object} The double.
 */
function fakeWindow(height, framed = true) {
	const posted = []
	const listeners = {}
	const win = {
		posted,
		listeners,
		document: { body: { scrollHeight: height, offsetHeight: height }, documentElement: { clientHeight: 0, scrollHeight: height, offsetHeight: height } },
		addEventListener: (name, fn) => { listeners[name] = fn },
		removeEventListener: (name) => { delete listeners[name] },
	}
	win.parent = framed ? { postMessage: (data) => posted.push(data) } : win
	return win
}

test('the frame posts its height under the namespaced type', () => {
	const win = fakeWindow(1200)

	startHeightReporting({ win })

	assert.equal(win.posted.length, 1)
	assert.equal(win.posted[0].type, EMBED_HEIGHT_MESSAGE)
	assert.equal(win.posted[0].height, 1200)
})

// 🔴 A bare {height: 900} from an advertisement on the host page would resize
// this frame if the type were not namespaced, because window.onmessage hears
// from every frame on the page.
test('the message type is namespaced rather than a bare height', () => {
	assert.ok(EMBED_HEIGHT_MESSAGE.startsWith('portaliq:'))
})

test('a page that is not framed posts nothing', () => {
	const win = fakeWindow(1200, false)

	startHeightReporting({ win })

	assert.equal(win.posted.length, 0)
})

// A host listener handed the same number forty times a second is a host
// listener somebody will remove.
test('an unchanged height is not posted again', () => {
	const win = fakeWindow(1200)

	startHeightReporting({ win })
	win.listeners.resize()

	assert.equal(win.posted.length, 1)
})

test('a changed height is posted', () => {
	const win = fakeWindow(1200)

	startHeightReporting({ win })
	win.document.body.scrollHeight = 1800
	win.document.documentElement.scrollHeight = 1800
	win.listeners.resize()

	assert.equal(win.posted.length, 2)
	assert.equal(win.posted[1].height, 1800)
})

test('stopping removes the listeners it added', () => {
	const win = fakeWindow(1200)

	const stop = startHeightReporting({ win })
	assert.ok(win.listeners.resize)

	stop()
	assert.equal(win.listeners.resize, undefined)
})

// The reported height never drops below the floor even when the document
// genuinely measures smaller, so an empty or still-rendering form cannot
// collapse the frame on the host page.
test('a short document still reports the floor', () => {
	const win = fakeWindow(60)

	startHeightReporting({ win })

	assert.equal(win.posted[0].height, EMBED_MINIMUM_HEIGHT)
})
