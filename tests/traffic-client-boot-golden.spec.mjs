#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// traffic-client-boot-golden.spec.mjs: the traffic client run in a fake
// browser, from boot to the last batch: what it asks the portal, which
// events it queues under which consent, what it stores and forgets, the
// experiment it applies, the recorder it loads and what every batch sends
// (by beacon, or by fetch with the bearer of a linked account), pinned in a
// golden file before the client was split into named steps.
//
// Usage:
//   node --test tests/traffic-client-boot-golden.spec.mjs
//   GOLDEN_RECORD=1 node --test tests/traffic-client-boot-golden.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { boot } from '../src/traffic/client.js'
import { golden } from './support/golden.mjs'

const ALL_EVENTS = [
	'page_view', 'session_start', 'scroll', 'outbound_click', 'file_download', 'search',
	'form_submit', 'form_start', 'form_field', 'form_abandon', 'page_not_found',
	'js_error', 'heat_click', 'heat_scroll',
]

/**
 * A storage that keeps what it is given, and can refuse.
 *
 * @param {object} log Where reads, writes and removals are noted.
 * @param {string} name The storage's name.
 * @return {object} The storage.
 */
function storage(log, name) {
	const items = {}
	return {
		items,
		refuse: false,
		getItem(key) {
			if (this.refuse) {
				throw new Error('refused')
			}
			return Object.hasOwn(items, key) ? items[key] : null
		},
		setItem(key, value) {
			log.push([name, 'set', key, String(value)])
			items[key] = String(value)
		},
		removeItem(key) {
			log.push([name, 'remove', key])
			delete items[key]
		},
	}
}

/**
 * An element.
 *
 * @param {string} tag The tag.
 * @param {object} props Its properties and attributes (`attrs`).
 * @return {object} The node.
 */
function el(tag, props = {}) {
	const attrs = props.attrs || {}
	return {
		nodeType: 1,
		tagName: tag.toUpperCase(),
		getAttribute: (name) => (Object.hasOwn(attrs, name) ? attrs[name] : null),
		hasAttribute: (name) => Object.hasOwn(attrs, name),
		...props,
	}
}

/**
 * A fake browser on one page, with the site's traffic answer.
 *
 * @param {object} setup The site answer, the location and the switches.
 * @return {object} The window and everything it saw.
 */
function browser(setup) {
	const seen = { requests: [], beacons: [], storage: [], timers: [], scripts: [], observers: [] }
	const docListeners = {}
	const winListeners = {}
	const marker = { found: null }
	const location = { href: setup.href || 'https://site.example/afval?q=papier', host: 'site.example' }
	const history = {
		state: { s: 1 },
		pushState(state, title, url) { seen.requests.push(['history.push', String(url)]); return 'pushed' },
		replaceState(state, title, url) { seen.requests.push(['history.replace', String(url)]); location.href = new URL(String(url), location.href).href; return 'replaced' },
	}
	const nodes = { '.hero h1': [{ textContent: 'Welkom' }, { textContent: 'Hallo' }] }
	const doc = {
		currentScript: { dataset: { origin: 'https://app.example/', appPath: '/apps/portaliq/', portal: setup.portal ?? 'gemeente-x' } },
		getElementById: () => null,
		documentElement: { scrollTop: 0, scrollHeight: 2000, scrollWidth: 1000 },
		body: { scrollHeight: 2000, scrollWidth: 1000 },
		head: { appendChild: (script) => seen.scripts.push({ src: script.src, async: script.async }) },
		visibilityState: 'visible',
		querySelector: (selector) => (selector === '[data-portaliq-status="404"]' ? marker.found : null),
		querySelectorAll: (selector) => {
			if (selector === 'bad[') {
				throw new Error('bad selector')
			}
			return nodes[selector] || []
		},
		createElement: (tag) => ({ tag }),
		createEvent: () => ({ initEvent() {} }),
		addEventListener: (name, handler) => { (docListeners[name] = docListeners[name] || []).push(handler) },
	}
	const win = {
		document: doc,
		navigator: {
			doNotTrack: setup.doNotTrack || null,
			sendBeacon: (url, body) => {
				seen.beacons.push({ url, body: JSON.parse(body) })
				return setup.beaconRefused !== true
			},
		},
		location,
		history,
		innerHeight: 800,
		innerWidth: 1280,
		pageYOffset: 0,
		crypto: { getRandomValues: (bytes) => { bytes.fill(171); return bytes } },
		localStorage: storage(seen.storage, 'local'),
		sessionStorage: storage(seen.storage, 'session'),
		setTimeout: (callback, wait) => { seen.timers.push({ callback, wait }); return seen.timers.length },
		clearTimeout: () => {},
		addEventListener: (name, handler) => { (winListeners[name] = winListeners[name] || []).push(handler) },
		dispatchEvent: (event) => { seen.requests.push(['dispatch', event.type || 'event']); (winListeners.popstate || []).forEach((h) => h()) },
		PopStateEvent: setup.noPopState ? undefined : function (type) { this.type = type },
		MutationObserver: function (callback) {
			seen.observers.push(callback)
			this.observe = () => {}
			this.disconnect = () => { seen.requests.push(['observer.disconnect']) }
		},
		fetch: (url, init = {}) => {
			seen.requests.push(['fetch', url, init.headers || null, init.body ? JSON.parse(init.body) : null])
			if (String(url).includes('/api/content/site')) {
				return Promise.resolve({ ok: true, json: async () => setup.site })
			}
			return Promise.resolve({ ok: true })
		},
	}
	if (setup.token) {
		win.localStorage.items.portaliq_token = setup.token
	}
	const fire = (target, name, event) => (target === 'doc' ? docListeners : winListeners)[name]?.forEach((h) => h(event))
	return { win, seen, fire, marker, nodes }
}

/**
 * Let the site answer settle.
 *
 * @return {Promise<void>}
 */
async function settle() {
	for (let i = 0; i < 10; i++) {
		await Promise.resolve()
	}
}

/**
 * Everything one run saw, with the clock-dependent timestamps left out.
 *
 * @param {object} seen What the browser saw.
 * @return {object} The plain record.
 */
function plain(seen) {
	const strip = (events) => events.map((event) => {
		assert.match(event.timestamp, /^\d{4}-\d{2}-\d{2}T/)
		const { timestamp, ...rest } = event
		return rest
	})
	return {
		requests: seen.requests.map((request) => {
			if (request[0] === 'fetch' && request[3] && request[3].events) {
				return [...request.slice(0, 3), { ...request[3], events: strip(request[3].events) }]
			}
			return request
		}),
		beacons: seen.beacons.map((beacon) => ({ url: beacon.url, body: { ...beacon.body, events: strip(beacon.body.events) } })),
		storage: seen.storage,
		timers: seen.timers.map((timer) => timer.wait),
		scripts: seen.scripts,
	}
}

const FORM = el('form', { id: 'aanvraag' })
const FIELD = el('input', { id: 'naam', type: 'text', form: FORM })

test('a cookieless portal without consent: page views, links, forms, scroll, errors and the last batch', async () => {
	let clock = 1000
	const realNow = Date.now
	Date.now = () => clock
	const file = golden('traffic-client-boot')
	const site = {
		collector: 'https://collect.example/c',
		traffic: { enabled: true, events: ALL_EVENTS, customDimensions: [{ id: 'school' }] },
	}
	const b = browser({ site })
	boot(b.win)
	b.win.portaliqTraffic.dimension('school', 'De Linde')
	await settle()

	const span = el('span', { parentNode: el('a', { href: 'https://elders.example/x' }) })
	b.fire('doc', 'click', { target: span })
	b.fire('doc', 'click', { target: el('a', { href: 'https://site.example/stuk.pdf' }) })
	b.fire('doc', 'click', { target: el('a', { href: 'https://site.example/intern' }) })
	b.fire('doc', 'click', { target: el('div', { parentNode: null }) })
	b.fire('doc', 'focusin', { target: FIELD })
	clock = 1500
	b.fire('doc', 'focusout', { target: FIELD })
	b.fire('doc', 'focusin', { target: el('input', { name: 'email', type: 'email', form: FORM }) })
	b.fire('doc', 'submit', { target: FORM })
	b.fire('doc', 'focusin', { target: el('input', { id: 'reden', type: 'text', form: el('form', { attrs: { name: 'bezwaar' } }) }) })
	b.fire('doc', 'focusin', { target: el('input', { id: 'verborgen', type: 'hidden', form: FORM }) })
	b.win.pageYOffset = 1100
	b.fire('win', 'scroll')
	b.fire('win', 'scroll')
	b.fire('win', 'error', { message: 'x is undefined', filename: 'https://site.example/app.js?v=1', lineno: 3, colno: 4 })
	b.fire('win', 'error', { message: '' })
	b.win.portaliqTraffic.track('page_view', { custom: 1 })
	b.win.portaliqTraffic.track('nope', {})
	b.win.history.pushState({}, '', '/zoeken?query=gft')
	b.win.location.href = 'https://site.example/zoeken?query=gft'
	b.seen.timers.find((timer) => timer.wait === 0).callback()
	b.fire('win', 'popstate')
	b.win.document.visibilityState = 'hidden'
	b.fire('doc', 'visibilitychange')
	b.fire('win', 'pagehide')
	b.win.portaliqTraffic.disable()
	b.win.portaliqTraffic.track('page_view', {})
	b.fire('win', 'pagehide')

	file.check('cookieless', plain(b.seen))
	Date.now = realNow
	file.save()
})

test('a consent portal that persists, links accounts, maps heat, records, experiments and finds no page', async () => {
	let clock = 5000
	const realNow = Date.now
	Date.now = () => clock
	const file = golden('traffic-client-boot-consent')
	const site = {
		collector: 'https://collect.example/c',
		traffic: {
			enabled: true,
			consentRequired: true,
			persistClientId: true,
			preConsentEvents: ['page_view'],
			events: ALL_EVENTS,
			sensitive: { accountLinking: true, heatmaps: true, sessionRecording: true },
			experiments: [
				{ id: 'e1', status: 'running', page: '/afval', variants: [{ id: 'a', weight: 1, changes: [{ selector: '.hero h1', text: 'Hallo' }, { selector: 'bad[', text: 'x' }] }, { id: 'b', weight: 0, pageRoute: '/afval-b' }] },
				{ id: 'e2', status: 'running', page: '/oud', variants: [{ id: 'x', weight: 0 }, { id: 'y', weight: 1, pageRoute: '/nieuw' }] },
			],
		},
	}
	const b = browser({ site, token: 'tok', beaconRefused: true, href: 'https://site.example/afval' })
	b.win.sessionStorage.items['portaliq-traffic:gemeente-x:session'] = 'old-session'
	b.win.sessionStorage.items['portaliq-traffic:gemeente-x:sequence'] = '7'
	boot(b.win)
	await settle()
	assert.equal(b.win.portaliqTraffic.recording, null, 'no recording before consent')
	assert.equal(b.nodes['.hero h1'][0].textContent, 'Hallo', 'the variant text is applied')

	b.win.portaliqTraffic.consent(true)
	assert.equal(typeof b.win.portaliqTraffic.recording.consent, 'function')
	assert.equal(b.win.portaliqTraffic.recording.sessionId(), 'old-session')
	assert.equal(b.win.portaliqTraffic.recording.route(), '/afval')
	b.win.portaliqTraffic.recording = { ...b.win.portaliqTraffic.recording, stop: () => b.seen.requests.push(['recording.stop']) }
	b.nodes['.hero h1'][0].textContent = 'Terug'
	assert.equal(b.seen.observers.length, 2, 'one observer for the not-found marker, one for the variant')
	b.seen.observers[1]()
	b.seen.observers[1]()
	const changeTimer = b.seen.timers.filter((timer) => timer.wait === 50)
	assert.equal(changeTimer.length, 1, 'a burst of changes is applied once')
	changeTimer[0].callback()
	assert.equal(b.nodes['.hero h1'][0].textContent, 'Hallo', 'the change survives a re-render')
	b.fire('doc', 'click', { target: el('a', { href: 'https://elders.example/' }), pageX: 500, pageY: 1000 })
	b.win.pageYOffset = 400
	b.fire('win', 'scroll')
	b.marker.found = el('div', { attrs: { 'data-portaliq-path': '/bestaat-niet' } })
	b.seen.observers.forEach((observer) => observer())
	b.win.history.replaceState({}, '', '/oud')
	b.seen.timers.filter((timer) => timer.wait === 0).forEach((timer) => timer.callback())
	clock = 9000
	b.fire('win', 'pagehide')
	b.win.portaliqTraffic.consent(false)
	b.win.portaliqTraffic.track('page_view', {})
	b.win.portaliqTraffic.disable()
	b.fire('win', 'pagehide')

	file.check('consent', plain(b.seen))
	Date.now = realNow
	file.save()
})

test('a fresh session is started and announced, a refused storage is cookieless, and the opted out never start', async () => {
	const realNow = Date.now
	Date.now = () => 100
	const file = golden('traffic-client-boot-identity')
	const site = { collector: 'https://collect.example/c', traffic: { enabled: true, persistClientId: true, events: ALL_EVENTS } }
	const fresh = browser({ site, portal: '' })
	boot(fresh.win)
	await settle()
	fresh.fire('win', 'pagehide')
	file.check('fresh', plain(fresh.seen))

	const returning = browser({ site })
	returning.win.localStorage.items['portaliq-traffic:gemeente-x:client'] = 'client-1'
	boot(returning.win)
	await settle()
	returning.fire('win', 'pagehide')
	file.check('returning', plain(returning.seen))

	const refused = browser({ site })
	refused.win.localStorage.refuse = true
	boot(refused.win)
	await settle()
	refused.fire('win', 'pagehide')
	file.check('refused', plain(refused.seen))

	const opted = browser({ site, doNotTrack: '1' })
	boot(opted.win)
	await settle()
	file.check('optedOut', plain(opted.seen))
	assert.equal(typeof opted.win.portaliqTraffic.track, 'function')

	for (const answer of [null, { traffic: { enabled: false }, collector: 'x' }, { traffic: { enabled: true } }]) {
		const off = browser({ site: answer })
		boot(off.win)
		await settle()
		assert.deepEqual(off.seen.beacons, [])
		assert.equal(off.seen.requests.length, 1)
	}
	const noScript = browser({ site })
	noScript.win.document.currentScript = null
	boot(noScript.win)
	assert.deepEqual(noScript.seen.requests, [])
	Date.now = realNow
	file.save()
})

test('a variant page moves the visitor without a reload, also where PopStateEvent is missing', async () => {
	const realNow = Date.now
	Date.now = () => 100
	const file = golden('traffic-client-boot-variant')
	const site = {
		collector: 'https://collect.example/c',
		traffic: { enabled: true, events: ALL_EVENTS, experiments: [{ id: 'e2', status: 'running', page: '/oud', variants: [{ id: 'x', weight: 0 }, { id: 'y', weight: 1, pageRoute: '/nieuw' }] }] },
	}
	for (const noPopState of [false, true]) {
		const b = browser({ site, href: 'https://site.example/oud', noPopState })
		boot(b.win)
		await settle()
		b.fire('win', 'pagehide')
		file.check(noPopState ? 'withoutPopState' : 'withPopState', plain(b.seen))
	}
	Date.now = realNow
	file.save()
})
