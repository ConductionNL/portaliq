#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// traffic-recorder-masking.spec.mjs: what the session recorder writes of a
// page and what it leaves out (a text as its length, an input as the length
// of its value, a box without content, dropped scripts, only the listed
// attributes), when it refuses to record at all, and how its events are
// posted, pinned before the recorder was split into named steps.
//
// Usage:
//   node --test tests/traffic-recorder-masking.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { start } from '../src/traffic/recorder.js'

const realNow = Date.now

/**
 * A text node.
 *
 * @param {string} value The text.
 * @return {object} The node.
 */
function text(value) {
	return { nodeType: 3, nodeValue: value }
}

/**
 * An element node.
 *
 * @param {string} tag The tag.
 * @param {object} attrs The attributes.
 * @param {Array<object>} children The child nodes.
 * @param {object} extra Extra properties (value, textContent, href).
 * @return {object} The node.
 */
function el(tag, attrs = {}, children = [], extra = {}) {
	return {
		nodeType: 1,
		tagName: tag.toUpperCase(),
		childNodes: children,
		hasAttribute: (name) => Object.hasOwn(attrs, name),
		getAttribute: (name) => attrs[name],
		...extra,
	}
}

/**
 * A page that holds every kind of node the recorder treats differently.
 *
 * @return {object} The document element.
 */
function page() {
	let deep = text('diep')
	for (let i = 0; i < 70; i++) {
		deep = el('div', {}, [deep])
	}
	const many = []
	for (let i = 0; i < 510; i++) {
		many.push(el('span'))
	}
	return el('html', { lang: 'nl' }, [
		el('head', {}, [
			el('style', {}, [], { textContent: 'body { color: red }' }),
			el('style', {}, [], { textContent: 'body { color: red }' }),
			el('link', { rel: 'stylesheet', href: '/x.css' }, [], { href: 'https://site.example/x.css' }),
			el('link', { rel: 'icon' }, [], { href: 'https://site.example/i.png' }),
			el('script', {}, [text('secret()')]),
			el('noscript', {}, [text('geen js')]),
		]),
		el('body', { class: 'page', 'data-user': 'sanne', style: 'background: url(https://x/y.png) red', title: 'Sanne' }, [
			text('Hallo Sanne de Vries'),
			el('input', { type: 'text', value: 'BSN 123' }, [], { value: 'BSN 123456782' }),
			el('textarea', {}, [text('mijn verhaal')], { value: 'mijn verhaal' }),
			el('select', {}, [el('option', {}, [text('Ja')])]),
			el('img', { src: 'https://x/face.jpg', width: '10', alt: 'Sanne' }),
			el('iframe', { src: 'https://x' }, [el('p')]),
			el('a', { href: 'https://x/mijn/zaak/42', id: 'l'.repeat(600) }, [text('Mijn zaak')]),
			el('template', {}, [el('p')]),
			{ nodeType: 8, nodeValue: 'comment' },
			deep,
			el('ul', {}, many),
		]),
	])
}

/**
 * A window with a traffic client that asked for a recording.
 *
 * @param {object} options The config, hash and document element.
 * @return {object} The window, the posts and the timers.
 */
function browser(options = {}) {
	const posts = []
	const timers = []
	const listeners = {}
	const docListeners = {}
	const history = { pushState() { return 'pushed' }, replaceState() { return 'replaced' } }
	const cfg = {
		consent: () => true,
		id: 'rec-1',
		endpoint: 'https://app.example/api/traffic/recording',
		portal: 'gemeente-x',
		sessionId: () => 'session-1',
		...options.cfg,
	}
	const doc = {
		documentElement: 'root' in options ? options.root : page(),
		querySelector: options.querySelector || (() => null),
		visibilityState: 'visible',
		addEventListener: (name, handler) => { docListeners[name] = handler },
		removeEventListener: (name) => { docListeners[name] = 'removed' },
	}
	const win = {
		portaliqTraffic: { recording: cfg },
		location: { hash: options.hash || '', pathname: '/mijn' },
		document: doc,
		innerWidth: 1280,
		innerHeight: 800,
		pageXOffset: 0,
		pageYOffset: 120.4,
		history,
		setTimeout: (callback, wait) => { timers.push({ callback, wait }); return timers.length },
		clearTimeout: () => {},
		addEventListener: (name, handler) => { listeners[name] = handler },
		removeEventListener: (name) => { listeners[name] = 'removed' },
		fetch: (url, init) => { posts.push({ url, init, body: JSON.parse(init.body) }); return Promise.resolve() },
	}
	return { win, cfg, posts, timers, listeners, docListeners, history }
}

/**
 * Wait for the chained posts.
 *
 * @return {Promise<void>}
 */
async function settle() {
	for (let i = 0; i < 5; i++) {
		await Promise.resolve()
	}
}

test('no recording without a config, without consent, once started, or on the e-mail link page', async () => {
	Date.now = () => 1000
	for (const setup of [{ cfg: { consent: () => false } }, { hash: '#email-link=abc' }]) {
		const b = browser(setup)
		start(b.win)
		assert.equal(b.cfg.started, undefined)
		assert.deepEqual(b.listeners, {})
	}
	const started = browser({ cfg: { started: true } })
	start(started.win)
	assert.deepEqual(started.listeners, {})
	start({ portaliqTraffic: {} })
	Date.now = realNow
})

test('the snapshot writes structure only: lengths for text and values, boxes empty, listed attributes', async () => {
	Date.now = () => 1000
	const b = browser()
	start(b.win)
	assert.equal(b.cfg.started, true)
	assert.equal(typeof b.cfg.stop, 'function')
	b.listeners.pagehide()
	await settle()

	assert.equal(b.posts.length, 1)
	const { url, init, body } = b.posts[0]
	assert.equal(url, 'https://app.example/api/traffic/recording')
	assert.equal(init.method, 'POST')
	assert.deepEqual(init.headers, { 'Content-Type': 'text/plain' })
	assert.equal(init.mode, 'cors')
	assert.equal(init.credentials, 'omit')
	assert.equal(init.keepalive, true)
	assert.deepEqual(Object.keys(body), ['consent', 'recording', 'page', 'elapsed', 'sessionId', 'events', 'portal'])
	assert.equal(body.consent, true)
	assert.equal(body.recording, 'rec-1')
	assert.equal(body.page, '/mijn')
	assert.equal(body.elapsed, 0)
	assert.equal(body.sessionId, 'session-1')
	assert.equal(body.portal, 'gemeente-x')

	const [viewport, style, snap] = body.events
	assert.deepEqual(viewport, { k: 'v', t: 0, w: 1280, h: 800 })
	assert.equal(style.k, 'y')
	assert.equal(style.s, 'body { color: red }')
	assert.equal(body.events.length, 3, 'one stylesheet event for two equal styles')
	assert.equal(snap.k, 's')
	assert.equal(snap.w, 1280)

	const html = snap.n
	assert.deepEqual(Object.keys(html), ['n', 'a', 'c'])
	assert.deepEqual(html.a, { lang: 'nl' })
	const [head, bodyNode] = html.c
	assert.deepEqual(head.c, [
		{ n: 'style', a: {}, h: style.h, c: [] },
		{ n: 'style', a: {}, h: style.h, c: [] },
		{ n: 'link', a: { rel: 'stylesheet', href: 'https://site.example/x.css' }, c: [] },
		{ n: 'link', a: { rel: 'icon' }, c: [] },
	])
	assert.deepEqual(bodyNode.a, { class: 'page', style: 'background: none red' })
	const [hello, input, textarea, select, img, iframe, link, deep, list] = bodyNode.c
	assert.deepEqual(hello, { l: 20 })
	assert.deepEqual(input, { n: 'input', a: { type: 'text' }, v: 13, c: [] })
	assert.deepEqual(textarea, { n: 'textarea', a: {}, v: 12, c: [] })
	assert.deepEqual(select, { n: 'select', a: {}, v: 0, c: [] })
	assert.deepEqual(img, { n: 'img', a: { width: '10' }, c: [] })
	assert.deepEqual(iframe, { n: 'iframe', a: {}, c: [] })
	assert.deepEqual(link, { n: 'a', a: { id: 'l'.repeat(512) }, c: [{ l: 9 }] })
	assert.equal(bodyNode.c.length, 9, 'the template and the comment are left out')

	let depth = 0
	let node = deep
	while (node && node.c && node.c.length > 0) {
		depth++
		node = node.c[0]
	}
	assert.equal(depth, 62, 'the walk stops below the maximum depth')
	assert.equal(list.c.length, 500, 'at most 500 children')

	assert.equal(JSON.stringify(b.posts).includes('Sanne'), false)
	assert.equal(JSON.stringify(b.posts).includes('BSN'), false)
	assert.equal(JSON.stringify(b.posts).includes('secret'), false)
	Date.now = realNow
})

test('pointer, clicks, scrolling, resizing and navigation are positions and routes, throttled', async () => {
	let clock = 1000
	Date.now = () => clock
	const b = browser({ root: el('html') })
	start(b.win)
	clock = 1100
	b.docListeners.mousemove({ clientX: 10.4, clientY: 20.6 })
	clock = 1120
	b.docListeners.mousemove({ clientX: 11, clientY: 21 })
	b.docListeners.click({ clientX: 5, clientY: 6 })
	b.listeners.scroll()
	clock = 1150
	b.listeners.scroll()
	b.listeners.resize()
	b.listeners.popstate()
	assert.equal(b.history.pushState('a'), 'pushed')
	b.docListeners.visibilitychange()
	b.win.document.visibilityState = 'hidden'
	b.docListeners.visibilitychange()
	await settle()

	assert.equal(b.posts.length, 1)
	const kinds = b.posts[0].body.events.map((event) => event.k)
	assert.deepEqual(kinds, ['v', 's', 'm', 'c', 'r', 'v', 'n'])
	const events = b.posts[0].body.events
	assert.deepEqual(events[2], { k: 'm', t: 100, x: 10, y: 21 })
	assert.deepEqual(events[3], { k: 'c', t: 120, x: 5, y: 6 })
	assert.deepEqual(events[4], { k: 'r', t: 120, x: 0, y: 120 })
	assert.deepEqual(events[6], { k: 'n', t: 150, p: '/mijn' })
	assert.ok(b.timers.some((timer) => timer.wait === 0), 'a pushState schedules a navigation')
	assert.ok(b.timers.some((timer) => timer.wait === 800), 'a navigation schedules a snapshot')
	assert.ok(b.timers.some((timer) => timer.wait === 5000), 'the queue is flushed every five seconds')
	Date.now = realNow
})

test('a page that marks itself unrecordable stops the recording before anything is queued', async () => {
	Date.now = () => 1000
	const b = browser({ querySelector: (selector) => (selector === '[data-traffic-no-recording]' ? {} : null) })
	start(b.win)
	b.listeners.pagehide()
	await settle()
	assert.equal(b.posts.length, 0)
	assert.equal(b.listeners.scroll, 'removed')
	assert.equal(b.docListeners.click, 'removed')
	Date.now = realNow
})

test('a route function names the page, no session function sends an empty session, no portal leaves it out', async () => {
	Date.now = () => 1000
	const b = browser({ root: el('html'), cfg: { route: () => '/zaken', sessionId: null, portal: '' } })
	start(b.win)
	b.listeners.pagehide()
	await settle()
	const body = b.posts[0].body
	assert.equal(body.page, '/zaken')
	assert.equal(body.sessionId, '')
	assert.equal(Object.hasOwn(body, 'portal'), false)
	Date.now = realNow
})

test('a snapshot over the chunk budget stops the recording', async () => {
	Date.now = () => 1000
	const big = []
	for (let i = 0; i < 600; i++) {
		big.push(el('div', { class: 'c'.repeat(500) }))
	}
	const b = browser({ root: el('html', {}, [el('body', {}, big)]) })
	start(b.win)
	b.listeners.pagehide()
	await settle()
	assert.equal(b.posts.length, 0, 'the queue is dropped with the recording')
	assert.equal(b.listeners.resize, 'removed')
	assert.equal(b.docListeners.mousemove, 'removed')
	Date.now = realNow
})

test('a large queue is posted in batches under the post budget, without keepalive', async () => {
	Date.now = () => 1000
	const rows = []
	for (let i = 0; i < 300; i++) {
		rows.push(el('div', { class: 'c'.repeat(500) }))
	}
	const half = (count) => el('body', {}, rows.slice(0, count))
	const root = el('html', {}, [half(150)])
	const b = browser({ root })
	let observed = null
	b.win.MutationObserver = function (callback) {
		observed = callback
		this.observe = () => {}
		this.disconnect = () => {}
	}
	start(b.win)
	root.childNodes = [half(300)]
	observed()
	const snapshotTimer = b.timers.find((timer) => timer.wait === 800)
	snapshotTimer.callback()
	b.listeners.pagehide()
	await settle()
	assert.equal(b.posts.length, 2)
	assert.deepEqual(b.posts[0].body.events.map((event) => event.k), ['v', 's'])
	assert.deepEqual(b.posts[1].body.events.map((event) => event.k), ['s'])
	assert.equal(b.posts[0].init.keepalive, false)
	Date.now = realNow
})
