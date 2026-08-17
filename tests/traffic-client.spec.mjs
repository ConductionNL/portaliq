#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// traffic-client.spec.mjs — what the traffic client sends, and what it never
// stores.
//
// Usage:
//   node tests/traffic-client.spec.mjs
//
// THE ASSERTIONS THAT MATTER HERE ARE ABOUT ABSENCE, and an absence cannot be
// checked by looking at a real browser's storage afterwards — an empty
// localStorage is also what a client that wrote and cleaned up leaves behind.
// So every storage call is RECORDED by a double, and the tests assert on the
// call log. "Stored nothing" means setItem was never reached, not that nothing
// survived.
//
// Run as a plain node script to match tests/site-auth.spec.mjs.

import { createTrafficClient, doNotTrack } from '../src/shared/trafficClient.js'

let failures = 0

/**
 * Assert deep equality, reporting the difference rather than a bare boolean.
 *
 * @param {string} what     What is being asserted.
 * @param {*}      actual   The value produced.
 * @param {*}      expected The value wanted.
 * @return {void}
 */
function assertEqual(what, actual, expected) {
	const a = JSON.stringify(actual)
	const e = JSON.stringify(expected)
	if (a === e) {
		console.log(`  ok   ${what}`)
		return
	}
	console.error(`  FAIL ${what}\n       expected ${e}\n       actual   ${a}`)
	failures += 1
}

/**
 * A Storage double that records every call it receives.
 *
 * @return {object} The storage, with a `.writes` log.
 */
function recordingStorage() {
	const map = new Map()
	const writes = []
	return {
		writes,
		getItem: (k) => (map.has(k) ? map.get(k) : null),
		setItem: (k, v) => {
			writes.push(k)
			map.set(k, String(v))
		},
		removeItem: (k) => {
			writes.push(`remove:${k}`)
			map.delete(k)
		},
	}
}

/**
 * An environment for one client, with everything recorded.
 *
 * @param {object} config The traffic config.
 * @param {object} extras Overrides — `nav`, `clock`.
 * @return {object} `{client, storage, beacons, clock}`.
 */
function build(config, extras = {}) {
	const storage = recordingStorage()
	const beacons = []
	let at = extras.startAt ?? 1_700_000_000_000

	const nav = {
		sendBeacon: (url, payload) => {
			beacons.push({ url, payload: String(payload) })
			return true
		},
		...(extras.nav || {}),
	}

	const win = {
		document: { title: 'Meldingen', referrer: 'https://example.gov/' },
		location: { href: 'https://portal.example/diensten/melden', origin: 'https://portal.example' },
		Blob: undefined,
		...(extras.win || {}),
	}

	const client = createTrafficClient({
		config,
		endpoint: '/api/traffic',
		portal: 'lafranken',
		storage,
		navigator: nav,
		window: win,
		now: () => at,
		fetchImpl: null,
	})

	return {
		client,
		storage,
		beacons,
		advance: (ms) => {
			at += ms
		},
	}
}

const ENABLED = {
	enabled: true,
	events: ['page_view', 'search'],
	sessionTimeoutMinutes: 30,
	consent: { required: false, preConsentEvents: [] },
}

console.log('doNotTrack')
assertEqual('reads navigator.doNotTrack', doNotTrack({ doNotTrack: '1' }, {}), true)
assertEqual('reads the window spelling', doNotTrack({}, { doNotTrack: '1' }), true)
assertEqual('reads Global Privacy Control', doNotTrack({ globalPrivacyControl: true }, {}), true)
assertEqual('absent signals are not consent-by-omission... but are silence', doNotTrack({}, {}), false)

// ---------------------------------------------------------------------------
console.log('measurement disabled')

// TASK 5.5, AND THE REASON IT IS WORDED THE WAY IT IS: a script that stores an
// id and sends nothing has still put a stable identifier in the visitor's
// browser. Both halves are asserted.
//
// EACH CONFIG BELOW SETS `consent.required: false` DELIBERATELY. Left at its
// default the consent gate also blocks the send, and the test then passes with
// the disabled-guard removed — which is exactly what happened when this file
// was first written and checked against a deliberate break. A test must fail
// for the reason it names, so every other gate is stood down.
{
	const { client, storage, beacons } = build({
		enabled: false,
		events: ['page_view'],
		consent: { required: false },
	})
	client.pageView()
	client.flush()
	assertEqual('sends nothing', beacons.length, 0)
	assertEqual('stores nothing — not even a client id', storage.writes, [])
	assertEqual('reports itself silent', client.silent, true)
}

{
	// Enabled, but the portal named no events. This must NOT widen to
	// everything — it is the state a half-finished admin screen leaves behind.
	const { client, storage, beacons } = build({
		enabled: true,
		events: [],
		consent: { required: false },
	})
	client.pageView()
	client.flush()
	assertEqual('an empty event list collects nothing', beacons.length, 0)
	assertEqual('and stores nothing', storage.writes, [])
}

// ---------------------------------------------------------------------------
console.log('Do Not Track')
{
	const { client, storage, beacons } = build(
		{ ...ENABLED, consent: { required: false, preConsentEvents: [] } },
		{ nav: { doNotTrack: '1' } },
	)
	client.pageView()
	client.flush()
	assertEqual("the visitor's signal overrides the portal's setting", beacons.length, 0)
	assertEqual('and nothing is written to the browser', storage.writes, [])
}

// ---------------------------------------------------------------------------
console.log('consent')
{
	const config = {
		...ENABLED,
		consent: { required: true, preConsentEvents: ['page_view'] },
	}
	const { client, storage, beacons } = build(config)

	assertEqual('starts unconsented', client.consented, false)

	// A pre-consent event still travels — the portal permitted it — but it must
	// leave NO trace in the browser, which is the whole point of the distinction.
	assertEqual('a pre-consent event is sent', client.pageView(), true)
	assertEqual('an event outside the pre-consent list is not', client.track('search'), false)
	client.flush()
	assertEqual('the pre-consent event reached the collector', beacons.length, 1)
	assertEqual('and wrote NOTHING to persistent storage', storage.writes, [])

	client.consent(true)
	assertEqual('consent is recorded', storage.writes, ['portaliq.consent'])
	assertEqual('now everything permitted is allowed', client.track('search'), true)

	client.consent(false)
	assertEqual(
		'withdrawal clears the id as well as the flag',
		storage.writes.slice(-3),
		['remove:portaliq.consent', 'remove:portaliq.clientId', 'remove:portaliq.session'],
	)
}

// ---------------------------------------------------------------------------
console.log('the event vocabulary')
{
	const { client } = build(ENABLED)
	assertEqual('a permitted event is queued', client.track('page_view'), true)
	assertEqual('an unlisted event is refused', client.track('form_submit'), false)
	assertEqual('an invented event is refused', client.track('exfiltrate'), false)
}

// ---------------------------------------------------------------------------
console.log('sessions and ordering')
{
	const { client, beacons, advance } = build(ENABLED)

	client.pageView()
	client.pageView()
	client.flush()

	const first = JSON.parse(beacons[0].payload)
	assertEqual('the batch names its portal', first.portal, 'lafranken')
	assertEqual('sequence starts at zero', first.events[0].sequence, 0)
	assertEqual('and advances within the session', first.events[1].sequence, 1)
	assertEqual(
		'both events share one session',
		first.events[0].sessionId === first.events[1].sessionId,
		true,
	)
	assertEqual('the page is reported in full', first.events[0].pageLocation, 'https://portal.example/diensten/melden')
	assertEqual('as is the title', first.events[0].pageTitle, 'Meldingen')

	// THE IDLE WINDOW IS MEASURED FROM THE LAST EVENT. Just inside it is the
	// same session; past it is a new one, restarting the sequence.
	advance(29 * 60 * 1000)
	client.pageView()
	client.flush()
	const second = JSON.parse(beacons[1].payload)
	assertEqual('29 idle minutes is still one session', second.events[0].sessionId, first.events[0].sessionId)
	assertEqual('and the sequence keeps climbing', second.events[0].sequence, 2)

	advance(31 * 60 * 1000)
	client.pageView()
	client.flush()
	const third = JSON.parse(beacons[2].payload)
	assertEqual(
		'past the window a new session starts',
		third.events[0].sessionId !== first.events[0].sessionId,
		true,
	)
	assertEqual('with the sequence back at zero', third.events[0].sequence, 0)
	assertEqual(
		'and the client id survives across sessions',
		third.events[0].clientId === first.events[0].clientId,
		true,
	)
}

// ---------------------------------------------------------------------------
console.log('delivery')
{
	// A beacon the browser REFUSES must not silently drop the batch. The
	// fallback is the branch nobody exercises in a browser, so it is exercised
	// here.
	const sent = []
	const storage = recordingStorage()
	const client = createTrafficClient({
		config: ENABLED,
		endpoint: '/api/traffic',
		portal: 'demo',
		storage,
		navigator: { sendBeacon: () => false },
		window: { document: {}, location: {} },
		now: () => 1,
		fetchImpl: (url, init) => {
			sent.push({ url, init })
			return Promise.resolve({ ok: true })
		},
	})

	client.pageView()
	client.flush()
	assertEqual('a refused beacon falls back to fetch', sent.length, 1)
	assertEqual('with keepalive, so an unloading page still delivers', sent[0].init.keepalive, true)
	assertEqual('as JSON', sent[0].init.headers['Content-Type'], 'application/json')
}

{
	// A CROSS-ORIGIN COLLECTOR MUST NOT BE SENT A BEACON, and this pins a
	// defect a browser found that no unit test would have: `sendBeacon` always
	// sends with credentials mode `include`, so the browser refuses a response
	// carrying `Access-Control-Allow-Origin: *`. A statically built portal
	// reporting to its portal's collector is exactly that case, and it failed
	// with "must not be the wildcard '*' when the request's credentials mode
	// is 'include'". The transport switches; the server stays strict.
	const beacons = []
	const sent = []
	const client = createTrafficClient({
		config: ENABLED,
		endpoint: 'https://portal.example/index.php/apps/portaliq/api/traffic',
		portal: 'demo',
		storage: recordingStorage(),
		navigator: {
			sendBeacon: (url, payload) => {
				beacons.push(String(payload))
				return true
			},
		},
		// The page is on the STATIC host, the collector is on the portal.
		window: { document: {}, location: { href: 'https://static.example/x', origin: 'https://static.example' } },
		now: () => 1,
		fetchImpl: (url, init) => {
			sent.push(init)
			return Promise.resolve({ ok: true })
		},
	})

	client.pageView()
	client.flush()

	assertEqual('no beacon is sent cross-origin', beacons.length, 0)
	assertEqual('a keepalive fetch is used instead', sent.length, 1)
	assertEqual('carrying no credentials at all', sent[0].credentials, 'omit')
	assertEqual('and still surviving unload', sent[0].keepalive, true)
}

{
	// SAME-ORIGIN STILL PREFERS THE BEACON — it is the only transport a
	// browser guarantees during unload, and the credentials problem does not
	// arise. Asserted so the cross-origin fix above cannot quietly disable it
	// everywhere.
	const { client, beacons } = build(ENABLED)
	client.pageView()
	client.flush()
	assertEqual('a same-origin collector gets a beacon', beacons.length, 1)
}

{
	// A full queue flushes on its own — a visitor who reads twenty pages must
	// not carry twenty events into an unload that may never fire.
	const { client, beacons } = build(ENABLED)
	for (let i = 0; i < 10; i++) {
		client.pageView()
	}
	assertEqual('ten events flush without being asked', beacons.length, 1)
	assertEqual('carrying the whole batch', JSON.parse(beacons[0].payload).events.length, 10)
}

{
	// A ONE-PAGE VISIT MUST STILL ARRIVE. Waiting for `pagehide` loses it on
	// every browser that discards the tab instead — which iOS Safari does
	// routinely — so the client schedules its own flush and does not depend on
	// an unload event firing.
	const scheduled = []
	const beacons = []
	const client = createTrafficClient({
		config: ENABLED,
		endpoint: '/api/traffic',
		portal: 'demo',
		storage: recordingStorage(),
		navigator: {
			sendBeacon: (url, payload) => {
				beacons.push(String(payload))
				return true
			},
		},
		window: { document: {}, location: {} },
		now: () => 1,
		setTimeoutImpl: (fn, ms) => {
			scheduled.push({ fn, ms })
			return scheduled.length
		},
	})

	client.pageView()
	assertEqual('one event schedules a flush', scheduled.length, 1)
	assertEqual('nothing has been sent yet', beacons.length, 0)

	client.pageView()
	assertEqual('a second event does not stack a second timer', scheduled.length, 1)

	scheduled[0].fn()
	assertEqual('the scheduled flush delivers', beacons.length, 1)
	assertEqual('both events, in one batch', JSON.parse(beacons[0]).events.length, 2)

	client.pageView()
	assertEqual('and the next event schedules again', scheduled.length, 2)
}

if (failures > 0) {
	console.error(`\n${failures} failure(s)`)
	process.exit(1)
}

console.log('\nall assertions passed')
