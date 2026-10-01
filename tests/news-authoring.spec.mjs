#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// news-authoring.spec.mjs: the staff News screen (staff-news-screen T3, T4).
// A news item is written for the whole school or for groups, saved through
// the staff authoring routes (never the object API), changed and published.
// The wiring tests read the manifest, the registry and the routes.
//
// Usage:
//   node --test tests/news-authoring.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'
import {
	AUDIENCE_CHILDREN,
	AUDIENCE_GROUPS,
	AUDIENCE_SCHOOL,
	audienceOf,
	createNewsApi,
	emptyForm,
	failureKey,
	formFromItem,
	missingFields,
	targetFromForm,
} from '../src/lib/newsAuthoring.js'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')

/**
 * A transport that records each call and answers from a table.
 *
 * @param {Function} answer (method, url, body) => data
 * @return {object}
 */
function transport(answer = () => ({})) {
	const calls = []
	const send = async (method, url, body) => {
		calls.push({ method, url, body })
		return { data: answer(method, url, body) }
	}
	return {
		calls,
		get: (url) => send('GET', url),
		post: (url, body) => send('POST', url, body),
		put: (url, body) => send('PUT', url, body),
		generateUrl: (path) => '/index.php' + path,
	}
}

test('a new item for the whole school targets the school only', () => {
	const form = { ...emptyForm(), title: 'Studiedag', body: 'Vrij', schoolRef: 's1', groupRefs: ['g7'] }
	assert.deepEqual(targetFromForm(form), { schoolRef: 's1' })
	assert.deepEqual(missingFields(form), [])
})

test('an item for groups targets the chosen groups only', () => {
	const form = { ...emptyForm(), audience: AUDIENCE_GROUPS, title: 'Uitje', body: 'Groep 7', schoolRef: 's1', groupRefs: ['g7', 'g8'] }
	assert.deepEqual(targetFromForm(form), { groupRefs: ['g7', 'g8'] })
})

test('a form names what is missing before it can be saved', () => {
	assert.deepEqual(missingFields(emptyForm()), [
		'Give the news item a title.',
		'Write the text of the news item.',
		'Choose the school.',
	])
	assert.deepEqual(
		missingFields({ ...emptyForm(), audience: AUDIENCE_GROUPS, title: 'T', body: 'B' }),
		['Choose at least one group.'],
	)
})

test('an existing item opens with its own audience, and children are kept', () => {
	assert.equal(formFromItem({ target: { schoolRef: 's1' } }).audience, AUDIENCE_SCHOOL)
	assert.equal(formFromItem({ target: { groupRefs: ['g7'] } }).audience, AUDIENCE_GROUPS)
	const forChildren = formFromItem({ title: 'T', body: 'B', target: { childRefs: ['vera'] } })
	assert.equal(forChildren.audience, AUDIENCE_CHILDREN)
	assert.deepEqual(targetFromForm(forChildren), { childRefs: ['vera'] })
	assert.deepEqual(missingFields(forChildren), [])
})

test('the audience reads as names when the choices know them', () => {
	const options = { schools: [{ id: 's1', label: 'De Wilgenboom' }], groups: [{ id: 'g7', label: 'Groep 7' }] }
	assert.deepEqual(audienceOf({ target: { schoolRef: 's1' } }, options), { kind: AUDIENCE_SCHOOL, names: ['De Wilgenboom'] })
	assert.deepEqual(audienceOf({ target: { groupRefs: ['g7', 'g9'] } }, options), { kind: AUDIENCE_GROUPS, names: ['Groep 7', 'g9'] })
	assert.deepEqual(audienceOf({ target: { childRefs: ['vera'] } }, options), { kind: AUDIENCE_CHILDREN, names: [] })
})

test('a new item is created through the authoring route as the signed-in author', async () => {
	const http = transport(() => ({ id: 'n1', status: 'draft' }))
	const api = createNewsApi(http)
	const saved = await api.save({ ...emptyForm(), title: ' Studiedag ', body: 'Vrij ', schoolRef: 's1' }, '', 'po-leerkracht-09')
	assert.equal(saved.id, 'n1')
	assert.deepEqual(http.calls, [{
		method: 'POST',
		url: '/index.php/apps/portaliq/api/news',
		body: { title: 'Studiedag', body: 'Vrij', target: { schoolRef: 's1' }, authorRef: 'po-leerkracht-09' },
	}])
})

test('a change goes to the item, without touching its author', async () => {
	const http = transport(() => ({ id: 'n 1' }))
	await createNewsApi(http).save({ ...emptyForm(), audience: AUDIENCE_GROUPS, title: 'T', body: 'B', groupRefs: ['g7'] }, 'n 1', 'someone-else')
	assert.equal(http.calls[0].method, 'PUT')
	assert.equal(http.calls[0].url, '/index.php/apps/portaliq/api/news/n%201')
	assert.deepEqual(http.calls[0].body, { title: 'T', body: 'B', target: { groupRefs: ['g7'] } })
})

test('publishing and taking back use their own routes', async () => {
	const http = transport()
	const api = createNewsApi(http)
	await api.setPublished('n1', true)
	await api.setPublished('n1', false)
	assert.deepEqual(http.calls.map((call) => [call.method, call.url]), [
		['PUT', '/index.php/apps/portaliq/api/news/n1/publish'],
		['PUT', '/index.php/apps/portaliq/api/news/n1/unpublish'],
	])
})

test('the list is read from the object API, newest first', async () => {
	const http = transport(() => ({
		results: [
			{ id: 'old', '@self': { updated: '2026-09-01T10:00:00+00:00' } },
			{ id: 'new', '@self': { updated: '2026-10-01T10:00:00+00:00' } },
			{ title: 'no id' },
		],
	}))
	const items = await createNewsApi(http).list()
	assert.deepEqual(items.map((item) => item.id), ['new', 'old'])
	assert.match(http.calls[0].url, /^\/index\.php\/apps\/openregister\/api\/objects\/portaliq\/newsItem\?/)
})

test('the choices tolerate an empty answer', async () => {
	const api = createNewsApi(transport(() => ({})))
	assert.deepEqual(await api.audiences(), { schools: [], groups: [] })
})

test('a refusal reads as a plain sentence', () => {
	assert.equal(failureKey({ response: { status: 403 } }), 'You may not write news. Ask an administrator for this right.')
	assert.equal(failureKey({ response: { status: 400, data: { error: 'invalid_target' } } }), 'Give a title, a text and who the news is for.')
	assert.equal(failureKey({ response: { status: 404 } }), 'This news item no longer exists.')
	assert.equal(failureKey(new Error('offline')), 'The news item could not be saved. Try again.')
})

test('the News screen is a menu entry, a registered page and backed by the routes', () => {
	const manifest = JSON.parse(readFileSync(join(ROOT, 'src/manifest.json'), 'utf8'))
	const page = manifest.pages.find((entry) => entry.id === 'News')
	assert.ok(page, 'manifest has a News page')
	assert.equal(page.type, 'custom')
	assert.equal(page.component, 'NewsAuthoring')
	assert.ok(manifest.menu.some((entry) => entry.route === 'News'), 'the menu links the News page')

	const registry = readFileSync(join(ROOT, 'src/registry.js'), 'utf8')
	assert.match(registry, /NewsAuthoring: \{\s*kind: 'page',\s*component: NewsAuthoring,/)

	const routes = readFileSync(join(ROOT, 'appinfo/routes.php'), 'utf8')
	for (const route of ["'news#update', 'url' => '/api/news/{id}', 'verb' => 'PUT'", "'news#audiences', 'url' => '/api/news/audiences', 'verb' => 'GET'"]) {
		assert.ok(routes.includes(route), route)
	}
})
