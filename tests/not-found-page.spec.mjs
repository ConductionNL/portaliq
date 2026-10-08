#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// not-found-page.spec.mjs: the page for a route that is missing or not
// published offers a way on (contact-page-question-form-and-not-found).
// Search when the portal has it, links to the homepage, the resident area and
// the contact page, and the sentence that asks for a report through Contact.
// With no contact page there is no link and no sentence. The answer for an
// unpublished page and a route that never existed is the same page.
//
// Usage:
//   node --test tests/not-found-page.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { contactRouteOf, hasPage, notFoundView } from '../src/site/lib/notFound.js'
import { t } from './support/page-instance.mjs'
import { renderSfc } from './support/render-sfc.mjs'

const PAGES = [{ route: '/' }, { route: '/contact' }, { route: '/afval/' }]
const PAGE = 'src/site/components/NotFoundPage.vue'

test('contactRouteOf takes the portal\'s own in-site route, else /contact', () => {
	assert.equal(contactRouteOf({}), '/contact')
	assert.equal(contactRouteOf({ contactRoute: '/vraag-het-ons' }), '/vraag-het-ons')
	assert.equal(contactRouteOf({ contactRoute: '//evil.example' }), '/contact')
	assert.equal(contactRouteOf({ contactRoute: 'https://evil.example' }), '/contact')
})

test('hasPage ignores a trailing slash', () => {
	assert.equal(hasPage(PAGES, '/afval'), true)
	assert.equal(hasPage(PAGES, '/parkeren'), false)
	assert.equal(hasPage(null, '/contact'), false)
})

test('a mistyped address: search and links to the homepage, the resident area and contact', () => {
	const view = notFoundView({
		contactRoute: '/contact',
		pages: PAGES,
		hasResidentArea: true,
		residentLabel: 'Mijn Zuiddrecht',
		searchEnabled: true,
		t,
	})
	assert.equal(view.search, true)
	assert.deepEqual(
		view.links.map((link) => [link.route, link.label]),
		[
			['/', 'The homepage'],
			['/mijn', 'Mijn Zuiddrecht'],
			['/contact', 'Contact'],
		],
	)
	assert.match(view.report, /Let us know through Contact/)
})

test('a portal without a contact page shows no contact link and no report sentence', () => {
	const view = notFoundView({
		contactRoute: '/contact',
		pages: [{ route: '/' }],
		hasResidentArea: false,
		residentLabel: '',
		searchEnabled: false,
		t,
	})
	assert.deepEqual(view.links.map((link) => link.kind), ['home'])
	assert.equal(view.report, '')
	assert.equal(view.search, false)

	const unknown = notFoundView({ contactRoute: '/contact', pages: null, hasResidentArea: false, residentLabel: '', searchEnabled: false, t })
	assert.equal(unknown.report, '', 'pages that could not be read confirm nothing')
})

test('site: the page renders the code, the heading, the links and the search box', async () => {
	const html = await renderSfc(PAGE, {
		path: '/parkeren-vergunning',
		contactRoute: '/contact',
		pages: PAGES,
		hasResidentArea: true,
		residentLabel: 'Mijn Zuiddrecht',
		searchEnabled: true,
		t,
	})
	assert.match(html, /data-portaliq-status="404"/)
	assert.match(html, /data-portaliq-path="\/parkeren-vergunning"/)
	assert.match(html, /data-testid="not-found-code"[^>]*>\s*Error code 404/)
	assert.match(html, /Page not found/)
	assert.match(html, /data-testid="not-found-search"/)
	for (const kind of ['home', 'resident', 'contact']) {
		assert.match(html, new RegExp(`data-testid="not-found-${kind}"`), kind)
	}
	assert.match(html, /data-testid="not-found-report"/)

	const bare = await renderSfc(PAGE, { path: '/x', pages: [{ route: '/' }], t })
	assert.doesNotMatch(bare, /not-found-search|not-found-contact|not-found-report/)
})

test('draft and missing look the same: the page takes no word that says which', async () => {
	const draft = await renderSfc(PAGE, { path: '/concept', pages: PAGES, t })
	const missing = await renderSfc(PAGE, { path: '/concept', pages: PAGES, t })
	assert.equal(draft, missing)
})
