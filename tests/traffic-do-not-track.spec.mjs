#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// traffic-do-not-track.spec.mjs — the served traffic client honours Do Not
// Track (REQ-CRG-013, openspec/changes/capability-rows-without-a-requirement).
//
// Usage:
//   node --test tests/traffic-do-not-track.spec.mjs
//
// Boots the real client (src/traffic/client.js) in a stand-in window and
// records everything it does to the network and to storage. With Do Not
// Track on, three page opens reach no collector, fetch nothing and write
// no key, whatever consent the portal asks for. The same window without
// Do Not Track does ask the portal what to measure, so the stand-in is
// not silent by construction.

import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import { boot } from '../src/traffic/client.js'

/**
 * A storage stand-in that records every write.
 *
 * @param {Array<string>} writes Where the writes are recorded.
 * @param {string} name Which storage this is.
 * @return {object} The storage.
 */
function recordingStorage(writes, name) {
	const items = new Map()
	return {
		getItem: (key) => (items.has(key) ? items.get(key) : null),
		setItem: (key, value) => {
			writes.push(`${name}.setItem ${key}`)
			items.set(key, String(value))
		},
		removeItem: (key) => {
			writes.push(`${name}.removeItem ${key}`)
			items.delete(key)
		},
	}
}

/**
 * A window whose script tag points at a measuring portal that asks for consent.
 *
 * @param {object} navigator The navigator the browser reports.
 * @return {{win: object, sent: Array<string>, writes: Array<string>}} The window and what it saw.
 */
function measuringWindow(navigator) {
	const sent = []
	const writes = []
	const site = {
		portal: 'demo',
		collector: 'https://portaal.example/index.php/apps/portaliq/api/traffic/collect',
		traffic: {
			enabled: true,
			consentRequired: true,
			events: ['page_view'],
			preConsentEvents: ['page_view'],
			persist: true,
		},
	}
	const win = {
		navigator: {
			...navigator,
			sendBeacon: (url) => {
				sent.push(`sendBeacon ${url}`)
				return true
			},
		},
		document: {
			currentScript: {
				src: 'https://portaal.example/index.php/apps/portaliq/api/traffic-client.js',
				dataset: {
					origin: 'https://portaal.example/',
					portal: 'demo',
					appPath: '/index.php/apps/portaliq/',
				},
			},
			getElementById: () => null,
			addEventListener: () => {},
			cookie: '',
		},
		location: { href: 'https://portaal.example/demo/', pathname: '/demo/', search: '' },
		fetch: (url) => {
			sent.push(`fetch ${url}`)
			return Promise.resolve({ ok: true, json: () => Promise.resolve(site) })
		},
		addEventListener: () => {},
		removeEventListener: () => {},
		setTimeout: () => 0,
		clearTimeout: () => {},
		setInterval: () => 0,
		clearInterval: () => {},
		localStorage: recordingStorage(writes, 'localStorage'),
		sessionStorage: recordingStorage(writes, 'sessionStorage'),
	}

	return { win, sent, writes }
}

describe('Do Not Track (REQ-CRG-013)', () => {
	it('sends no event and writes nothing over three page opens', async () => {
		const { win, sent, writes } = measuringWindow({ doNotTrack: '1' })

		for (let page = 0; page < 3; page++) {
			boot(win)
			win.portaliqTraffic.consent(true)
			win.portaliqTraffic.track('page_view', {})
		}
		await new Promise((resolve) => setImmediate(resolve))

		assert.deepEqual(sent, [])
		assert.deepEqual(writes, [])
		assert.equal(win.portaliqTraffic.recording, null)
	})

	it('still asks the portal what to measure without Do Not Track', async () => {
		const { win, sent } = measuringWindow({ doNotTrack: 'unspecified' })

		boot(win)
		await new Promise((resolve) => setImmediate(resolve))

		assert.equal(
			sent[0],
			'fetch https://portaal.example/index.php/apps/portaliq/api/content/site?portal=demo',
		)
	})
})
