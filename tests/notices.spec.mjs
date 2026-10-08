#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// notices.spec.mjs: maintenance and warning notices above every page
// (operate-maintenance-notice, REQ-OMN-001 and REQ-OMN-002). The site renders
// them signed in and signed out the same way: an expired one never shows, a
// closed one stays closed for the visit while another still shows, and none
// interrupts a screen reader.
//
// Usage:
//   node --test tests/notices.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { register } from 'node:module'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'
import { CLOSED_KEY, closedNotices, closeNotice, noticesFor, visibleNotices } from '../src/shared/notices.js'
import { renderSfc } from './support/render-sfc.mjs'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')

// SiteNotices.vue imports the Utrecht alert stylesheet, which node cannot
// load; a stylesheet is nothing to a render, so it loads as an empty module.
register(
	'data:text/javascript,' +
		encodeURIComponent(
			"export async function load(url, context, next) { return url.endsWith('.css') ? { format: 'module', source: '', shortCircuit: true } : next(url, context) }",
		),
)

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

test('the site renders each notice in a labelled section, never as an alert', async () => {
	// Far in the future, so the render does not depend on today's date.
	const later = { ...warning, endsAt: '2999-01-01T00:00:00+00:00' }
	const later2 = { ...second, endsAt: '2999-01-01T00:00:00+00:00' }
	const html = await renderSfc('src/site/components/SiteNotices.vue', { notices: [later, later2], locale: 'nl' })
	assert.match(html, /<section class="pq-site-notices" aria-label="Melding" data-testid="site-notices"/)
	assert.match(html, /class="utrecht-alert--warning utrecht-alert"/)
	assert.match(html, /class="utrecht-alert--info utrecht-alert"/)
	assert.match(html, /Saturday from 22:00 to 02:00 you cannot submit requests\./)
	assert.match(html, /<a class="utrecht-link" href="https:\/\/example.nl\/open">Opening hours<\/a>/)
	assert.equal((html.match(/Deze melding sluiten/g) || []).length, 2)
	assert.doesNotMatch(html, /role="alert"/)

	const english = await renderSfc('src/site/components/SiteNotices.vue', { notices: [later], locale: 'en' })
	assert.match(english, /aria-label="Notice"/)
	assert.match(english, /Close this notice/)
})

test('no notice, no section', async () => {
	const html = await renderSfc('src/site/components/SiteNotices.vue', { notices: [] })
	assert.doesNotMatch(html, /<section/)
})

test('the site places the notices above the page content, signed in and signed out', () => {
	const site = readFileSync(join(ROOT, 'src', 'site', 'App.vue'), 'utf8')
	assert.ok(site.indexOf('<SiteNotices') > -1 && site.indexOf('<SiteNotices') < site.indexOf('<main'), 'App.vue renders SiteNotices before <main>')
	assert.match(site, /<SiteNotices\s+v-if="shownNotices\.length > 0"\s+:notices="shownNotices"/)
	const siteNotices = readFileSync(join(ROOT, 'src', 'site', 'components', 'SiteNotices.vue'), 'utf8')
	assert.match(siteNotices, /visibleNotices/)
	assert.doesNotMatch(siteNotices, /role="alert"/)
})

test('the end-after-start guard is registered on every notice create and update', () => {
	const app = readFileSync(join(ROOT, 'lib', 'AppInfo', 'Application.php'), 'utf8')
	assert.match(app, /foreach \(\[ObjectCreatingEvent::class, ObjectUpdatingEvent::class\] as \$event\) \{\n\t\t\t\$context->registerEventListener\(\$event, NoticeWriteGuardListener::class\);/)
})

test('signed in, the site adds the signed-in notices the shell carries, each once', () => {
	const signedInOnly = { ...second, id: 'n-3' }
	assert.deepEqual(noticesFor([warning], [second, signedInOnly], false), [warning], 'signed out: the public ones only')
	assert.deepEqual(noticesFor([warning], [{ ...warning }, signedInOnly], true).map((n) => n.id), ['n-1', 'n-3'], 'signed in: both, each once')
	assert.deepEqual(noticesFor(null, null, true), [])
	assert.deepEqual(noticesFor(undefined, [signedInOnly], true).map((n) => n.id), ['n-3'])

	const source = readFileSync(join(ROOT, 'src', 'site', 'App.vue'), 'utf8')
	assert.match(source, /noticesFor\(\s*this\.site\.notices,\s*runtimeConfig\(\)\.portalNotices,\s*this\.session !== null,\s*\)/)
})
