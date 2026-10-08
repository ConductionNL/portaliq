// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The language switch (nlLanguageNav) renders only with more than one locale,
// and its docblock says the shell hands them down. Nothing did: WidgetGrid had
// no branch for the widget, so `locales` kept its empty default and the switch
// could never render, and no content request carried a locale. These tests
// read the wiring from the caller: the grid's props, the shell's computed
// data, and the URL each content request goes to.
//
// @spec openspec/changes/language-switch-reaches-the-content/specs/portaliq-cms/spec.md#requirement-the-language-switch-offers-the-portals-locales-and-the-choice-reaches-the-content

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'
import {
	hrefForLocale,
	languageEntries,
	languageName,
	requestedLocale,
} from '../src/site/lib/languageNav.js'
import { loadSfc, renderSfc } from './support/render-sfc.mjs'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')
const PAGE = 'https://loket.example.nl/?portal=demo&route=%2Fover'

// The library's public entry imports `.vue` files node cannot load; the grid
// needs only these two names from it.
const LIBRARY_STUB = {
	'@conduction/nextcloud-vue/public': [
		'export const siteBlockIsBand = () => false',
		'export const siteBlockRegistry = {}',
		'export const CnSiteIcon = {}',
		'export const CnSiteSearch = {}',
		'export const CnSiteSection = {}',
	].join('\n'),
	'@conduction/nextcloud-vue': "export const cnRenderMarkdown = () => ''\n",
}

test('the address carries the chosen language, and only a language tag', () => {
	assert.equal(requestedLocale('?lang=en'), 'en')
	assert.equal(requestedLocale('?portal=demo&lang=EN'), 'en')
	assert.equal(requestedLocale(''), '')
	assert.equal(requestedLocale('?lang=<script>'), '')
})

test('each portal locale becomes a link to this page in that language, named in itself', () => {
	const entries = languageEntries(['nl', 'en', 'nl', '', 7], PAGE)
	assert.deepEqual(
		entries.map((entry) => entry.locale),
		['nl', 'en'],
	)
	assert.equal(entries[0].label, 'Nederlands')
	assert.equal(entries[1].label, 'English')
	const english = new URL(entries[1].href)
	assert.equal(english.searchParams.get('lang'), 'en')
	assert.equal(english.searchParams.get('route'), '/over')
	assert.equal(english.searchParams.get('portal'), 'demo')
	assert.equal(new URL(hrefForLocale(`${PAGE}#top`, 'nl')).hash, '')
	assert.equal(languageName('xx'), 'xx')
	assert.deepEqual(languageEntries(undefined, PAGE), [])
})

test('the grid hands the switch the portal locales, after the authored props', async () => {
	const grid = await loadSfc('src/site/components/WidgetGrid.vue', LIBRARY_STUB)
	const languages = { locales: languageEntries(['nl', 'en'], PAGE), current: 'nl' }

	const props = grid.methods.propsFor.call(
		{ languages },
		{
			widgetKey: 'nlLanguageNav',
			props: { label: 'Kies uw taal', locales: [{ locale: 'xx' }] },
		},
	)

	assert.equal(props.label, 'Kies uw taal', 'a placement may rename the landmark')
	assert.deepEqual(
		props.locales,
		languages.locales,
		'a placement cannot add a language',
	)
	assert.equal(props.current, 'nl')
	assert.ok(grid.props.languages, 'the grid declares the prop the shell binds')

	const none = grid.methods.propsFor.call(
		{},
		{ widgetKey: 'nlLanguageNav', props: {} },
	)
	assert.deepEqual(none.locales, [])
})

test('with the props the grid hands it, the switch renders one link per language', async () => {
	const grid = await loadSfc('src/site/components/WidgetGrid.vue', LIBRARY_STUB)
	const props = grid.methods.propsFor.call(
		{
			languages: {
				locales: languageEntries(['nl', 'en'], PAGE),
				current: 'en',
			},
		},
		{ widgetKey: 'nlLanguageNav', props: {} },
	)

	const html = await renderSfc(
		'src/site/widgets/nlLanguageNav/NlLanguageNav.vue',
		props,
	)

	assert.match(html, /data-testid="nl-language-nav"/)
	assert.match(html, /hreflang="nl"/)
	assert.match(html, /hreflang="en"[^>]*aria-current="true"/)
	assert.match(html, /lang=en/)
})

// App.vue imports the shared i18n catalogues as JSON, which plain node will
// not load without import attributes, so the shell's wiring is read from its
// source. Each assertion names one call site; removing any of them turns
// this red.
test('the shell builds the switch from the site answer and binds it on every grid', () => {
	const app = readFileSync(join(ROOT, 'src/site/App.vue'), 'utf8')
	assert.match(
		app,
		/import \{ languageEntries, requestedLocale \} from '\.\/lib\/languageNav\.js'/,
	)
	assert.match(app, /chosenLocale: requestedLocale\(window\.location\.search\)/)
	assert.match(app, /languages: this\.languages,/)
	assert.match(
		app,
		/locales:\s*languageEntries\(\s*this\.site\.locales,\s*this\.hrefForRoute\(this\.route\),?\s*\)/,
	)
	assert.match(app, /current: this\.site\.locale \|\| ''/)
	// Every grid the shell mounts takes the same context.
	const grids = app.match(/<WidgetGrid[\s\S]*?\/>/g) || []
	assert.ok(grids.length > 0)
	for (const grid of grids) {
		assert.match(grid, /v-bind="gridContext"/)
	}
})

test('the shell sends the chosen language on every content read, and links keep it', () => {
	const app = readFileSync(join(ROOT, 'src/site/App.vue'), 'utf8')
	assert.match(app, /fetchSite\(this\.portalSlug, this\.chosenLocale\)/)
	assert.match(app, /fetchMenus\(this\.portalSlug, this\.chosenLocale\)/)
	assert.match(app, /fetchGlossary\(this\.portalSlug, this\.chosenLocale\)/)
	const pageReads = app.match(/fetchPage\([^)]*\{[^}]*\}\)/g) || []
	assert.equal(
		pageReads.length,
		3,
		'three page reads: the route, its parent, the refresh',
	)
	for (const read of pageReads) {
		assert.match(read, /locale: this\.chosenLocale/)
	}
	// goSearch and hrefForRoute rebuild the address; both keep `lang`.
	assert.equal(
		(app.match(/url\.searchParams\.set\('lang', lang\)/g) || []).length,
		2,
	)
})

test('the chosen language reaches every content request', async () => {
	const memory = () => {
		const items = new Map()
		return {
			getItem: (k) => (items.has(k) ? items.get(k) : null),
			setItem: (k, v) => items.set(k, String(v)),
			removeItem: (k) => items.delete(k),
		}
	}
	const calls = []
	const saved = {
		window: globalThis.window,
		document: globalThis.document,
		fetch: globalThis.fetch,
	}
	globalThis.window = {
		location: {
			origin: 'http://localhost:8080',
			hash: '',
			pathname: '/',
			search: '?lang=en',
		},
		history: { replaceState() {} },
		sessionStorage: memory(),
		localStorage: memory(),
		PORTALIQ_SITE_CONFIG: { apiBase: '/apps/portaliq/api/content/site' },
	}
	globalThis.document = { getElementById: () => null, querySelector: () => null }
	globalThis.fetch = async (url) => {
		calls.push(new URL(url))
		return { ok: true, status: 200, json: async () => ({}) }
	}
	try {
		const api = await import('../src/site/lib/contentApi.js')
		await api.fetchSite('demo', 'en')
		await api.fetchMenus('demo', 'en')
		await api.fetchPages('demo', 'en')
		await api.fetchGlossary('demo', 'en')
		await api.fetchPage('/over', 'demo', { locale: 'en' })
		await api.fetchPage('/over', 'demo')
	} finally {
		Object.assign(globalThis, saved)
	}

	assert.deepEqual(
		calls.map((url) => [
			url.pathname.split('/').pop(),
			url.searchParams.get('locale'),
		]),
		[
			['site', 'en'],
			['menus', 'en'],
			['pages', 'en'],
			['glossary', 'en'],
			['page', 'en'],
			['page', null],
		],
	)
})
