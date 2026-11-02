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
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import { contactRouteOf } from '../src/site/lib/notFound.js'
import { hasPage, notFoundView } from '../src/site/lib/notFoundView.js'
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
		locale: 'en',
		site: { accountLabel: 'Mijn Zuiddrecht' },
		initialPages: PAGES,
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

	const bare = await renderSfc(PAGE, { path: '/x', initialPages: [{ route: '/' }], t })
	assert.doesNotMatch(bare, /not-found-search|not-found-contact|not-found-report/)
})

test('site: the page reads like board NietGevonden', async () => {
	const html = (
		await renderSfc(PAGE, {
			path: '/x',
			locale: 'nl',
			site: { accountLabel: 'Mijn Zuiddrecht' },
			initialPages: PAGES,
			searchEnabled: true,
			t,
		})
	).replace(/<!--[\s\S]*?-->/g, '')
	// The page's own heading is its h1; the way on is a heading of its own.
	assert.match(html, /<h1 class="utrecht-heading-1">\s*Page not found\s*<\/h1>/)
	assert.match(html, /<h2 class="utrecht-heading-3 pq-not-found__more">\s*Of ga verder naar\s*<\/h2>/)
	// The field is the plain search's: input and button joined.
	assert.match(
		html,
		/<div class="ac-search-box__search"><input[^>]*class="ac-search-box__input [^"]*"[^>]*><button[^>]*class="ac-search-box__button /,
	)
	// Each way on is a link with a chevron before its words.
	assert.match(
		html,
		/<a [^>]*class="utrecht-link pq-not-found__link"[^>]*data-testid="not-found-home"[^>]*><svg class="pq-not-found__chevron"[^>]*aria-hidden="true"/,
	)

	const css = readFileSync(new URL('../css/site-theme.css', import.meta.url), 'utf8')
	for (const part of ['search', 'input.utrecht-textbox', 'button.utrecht-button']) {
		assert.match(
			css,
			new RegExp(`\\.pq-site \\.pq-not-found \\.ac-search-box__${part.replace('.', '\\.')}`),
			part,
		)
	}
})

test('draft and missing look the same: the page takes no word that says which', async () => {
	const draft = await renderSfc(PAGE, { path: '/concept', initialPages: PAGES, t })
	const missing = await renderSfc(PAGE, { path: '/concept', initialPages: PAGES, t })
	assert.equal(draft, missing)
})

test('pages that cannot be read leave the contact link out', async () => {
	const { loadSfc } = await import('./support/render-sfc.mjs')
	const screen = await loadSfc(PAGE)
	const vm = { portalSlug: 'zuiddrecht', locale: 'nl', pages: PAGES }
	delete globalThis.window
	await screen.methods.loadPages.call(vm)
	assert.equal(vm.pages, null)
})
