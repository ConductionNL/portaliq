#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// notices.spec.mjs: maintenance and warning notices above every page
// (operate-maintenance-notice, REQ-OMN-001 and REQ-OMN-002). The signed-in
// portal and the public site render the same notices the same way: an
// expired one never shows, a closed one stays closed for the visit while
// another still shows, and neither interrupts a screen reader.
//
// Usage:
//   node --test tests/notices.spec.mjs

import babel from '@babel/core'
import assert from 'node:assert/strict'
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath, pathToFileURL } from 'node:url'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')
const OUT_DIR = join(ROOT, 'node_modules', '.cache', 'portaliq-tests-notices')

/**
 * Compile one portal file under src/portal and import it.
 *
 * @param {string} relative The path under src/portal.
 * @return {Promise<object>} The module.
 */
async function load(relative) {
	const source = join(ROOT, 'src', 'portal', relative)
	const compiled = babel.transformSync(readFileSync(source, 'utf8'), {
		filename: source,
		babelrc: false,
		configFile: false,
		presets: [['@babel/preset-react', { runtime: 'automatic' }]],
	})
	mkdirSync(OUT_DIR, { recursive: true })
	const flat = (path) => path.replace(/[\\/]/g, '_').replace(/\.jsx?$/, '.mjs')
	const code = compiled.code
		.replace(/from '\.\.\/lib\/([A-Za-z]+)\.js'/g, (whole, name) => `from './${flat('lib/' + name + '.js')}'`)
	const out = join(OUT_DIR, flat(relative))
	writeFileSync(out, code)
	return import(pathToFileURL(out).href)
}

const { CLOSED_KEY, closeNotice, closedNotices, visibleNotices } = await load('lib/notices.js')
const { default: PortalNotices } = await load('components/PortalNotices.jsx')
const { createElement } = await import('react')
const { renderToStaticMarkup } = await import('react-dom/server')

const NOW = Date.parse('2026-10-03T21:00:00Z')
const warning = { id: 'n-1', message: 'Saturday from 22:00 to 02:00 you cannot submit requests.', level: 'warning', linkLabel: '', linkUrl: '', endsAt: '2026-10-04T02:00:00+00:00' }
const second = { id: 'n-2', message: 'The phone line is closed on Monday.', level: 'info', linkLabel: 'Opening hours', linkUrl: 'https://example.nl/open', endsAt: '2026-10-06T00:00:00+00:00' }

/**
 * An in-memory session storage.
 *
 * @return {object}
 */
function memoryStorage() {
	const data = new Map()
	return {
		getItem: (key) => (data.has(key) ? data.get(key) : null),
		setItem: (key, value) => data.set(key, String(value)),
	}
}

test('a notice whose end has passed is not shown, even if the server sent it', () => {
	const expired = { ...warning, id: 'old', endsAt: '2026-10-03T20:59:59+00:00' }
	assert.deepEqual(visibleNotices([expired, warning], [], NOW).map((n) => n.id), ['n-1'])
	assert.deepEqual(visibleNotices([{ ...warning, endsAt: 'soon' }], [], NOW), [], 'an unreadable end is not shown')
})

test('a closed notice stays closed for the visit and a second notice still shows', () => {
	const storage = memoryStorage()
	closeNotice(storage, 'n-1')
	assert.deepEqual(closedNotices(storage), ['n-1'])
	assert.deepEqual(visibleNotices([warning, second], closedNotices(storage), NOW).map((n) => n.id), ['n-2'])
	assert.equal(storage.getItem(CLOSED_KEY), '["n-1"]')
})

test('a blocked storage never breaks the page', () => {
	const blocked = { getItem() { throw new Error('denied') }, setItem() { throw new Error('denied') } }
	assert.deepEqual(closedNotices(blocked), [])
	assert.doesNotThrow(() => closeNotice(blocked, 'n-1'))
	assert.deepEqual(closedNotices(null), [])
})

test('the portal renders each notice in a labelled section, never as an alert', () => {
	const t = (key) => ({ Notice: 'Melding', 'Close this notice': 'Deze melding sluiten', 'More information': 'Meer informatie' })[key] || key
	const html = renderToStaticMarkup(createElement(PortalNotices, { notices: [warning, second], t }))
	assert.match(html, /<section class="portaliq-notices" aria-label="Melding"/)
	assert.match(html, /utrecht-alert utrecht-alert--warning/)
	assert.match(html, /utrecht-alert utrecht-alert--info/)
	assert.match(html, /Saturday from 22:00 to 02:00 you cannot submit requests\./)
	assert.match(html, /<a class="utrecht-link" href="https:\/\/example.nl\/open">Opening hours<\/a>/)
	assert.equal((html.match(/Deze melding sluiten/g) || []).length, 2)
	assert.doesNotMatch(html, /role="alert"/)
})

test('no notice, no section', () => {
	assert.equal(renderToStaticMarkup(createElement(PortalNotices, { notices: [] })), '')
})

test('the portal and the site both place the notices above the page content', () => {
	const app = readFileSync(join(ROOT, 'src', 'portal', 'App.jsx'), 'utf8')
	assert.ok(app.indexOf('<PortalNotices') > -1 && app.indexOf('<PortalNotices') < app.indexOf('<main'), 'App.jsx renders PortalNotices before <main>')
	assert.match(app, /notices=\{config\.notices\}/)
	const site = readFileSync(join(ROOT, 'src', 'site', 'App.vue'), 'utf8')
	assert.ok(site.indexOf('<SiteNotices') > -1 && site.indexOf('<SiteNotices') < site.indexOf('<main'), 'App.vue renders SiteNotices before <main>')
	const siteNotices = readFileSync(join(ROOT, 'src', 'site', 'components', 'SiteNotices.vue'), 'utf8')
	assert.match(siteNotices, /visibleNotices/)
	assert.doesNotMatch(siteNotices, /role="alert"/)
})

test('the end-after-start guard is registered on every notice create and update', () => {
	const app = readFileSync(join(ROOT, 'lib', 'AppInfo', 'Application.php'), 'utf8')
	assert.match(app, /foreach \(\[ObjectCreatingEvent::class, ObjectUpdatingEvent::class\] as \$event\) \{\n\t\t\t\$context->registerEventListener\(\$event, NoticeWriteGuardListener::class\);/)
})
