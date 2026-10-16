// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
//
// A provider item on a page of its own: facts, sections, date cards with
// their places, and an action that asks for sign-in first with the choices
// kept (public-detail-page-for-a-provider-item).
//
// @spec openspec/changes/public-detail-page-for-a-provider-item/specs/portal-contribution-contract/spec.md

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { fetchCatalogueDetail } from '../src/site/lib/publicCatalogue.js'
import {
	clampCount,
	isChoosable,
	keepChoice,
	placesLine,
	takeChoice,
} from '../src/site/widgets/nlPublicDetail/detail.js'
import strings from '../src/site/widgets/nlPublicDetail/strings.js'
import { instance, inState } from './support/page-instance.mjs'
import { loadSfc, renderComponent } from './support/render-sfc.mjs'

const NlPublicDetail = await loadSfc('src/site/widgets/nlPublicDetail/NlPublicDetail.vue', {
	'@conduction/nextcloud-vue': 'export const cnRenderMarkdown = (source) => source\n',
})

// MarkdownBlock tags the markup through a template element; node has none.
globalThis.document = {
	createElement: () => {
		const template = { innerHTML: '', content: { querySelectorAll: () => [] } }
		return template
	},
}

const DETAIL = {
	title: 'F-gassen, herhaling en examen',
	facts: [{ label: 'Certificaat', value: 'F-gassen' }, { label: 'Duur', value: '1 dag' }],
	sections: [{ heading: 'Programma', markdown: 'Theorie en praktijk.' }, { heading: 'Meenemen', markdown: 'Legitimatie.' }],
	dates: [
		{ id: 'd1', date: '2026-10-22', places: 7 },
		{ id: 'd2', date: '2026-11-05', places: 1 },
		{ id: 'd3', date: '2026-11-19', places: 0 },
		{ id: 'd4', date: '2026-12-03', placesLine: 'Bijna vol' },
	],
	documents: [{ label: 'Brochure', href: '/media/1' }],
	secondaryLink: { label: 'Incompany aanvragen', href: '/incompany' },
	action: { id: 'enrol', label: 'Deelnemers inschrijven', requiresSignIn: true, countLabel: 'Aantal deelnemers', countMax: 10 },
}

const WAYS = [{ id: 'eherkenning', label: 'Inloggen', href: '/index.php/apps/portaliq/auth/session/oidc/start?provider=eherkenning&returnTo=%2Fcursusaanbod%2Ff-gassen' }]

function render (props = {}, state = {}) {
  return renderComponent(inState(NlPublicDetail, { state: 'ready', detail: DETAIL, item: { title: 'F-gassen' }, ...state }), {
		app: 'learniq',
		kind: 'course',
		portal: 'academie',
		routeParam: 'f-gassen',
		ways: WAYS,
		...props,
	})
}

function memory () {
	const data = new Map()
	return {
		getItem: (key) => (data.has(key) ? data.get(key) : null),
		setItem: (key, value) => data.set(key, String(value)),
		removeItem: (key) => data.delete(key),
	}
}

test('the page shows the facts, the sections and the date cards with their places', async () => {
	const html = await render()
	assert.match(html, /<h1[^>]*>F-gassen, herhaling en examen<\/h1>/)
	assert.match(html, /<dt>Certificaat<\/dt><dd>F-gassen<\/dd>/)
	assert.match(html, /Programma[\s\S]*Meenemen/)
	assert.match(html, /Op deze dag zijn 7 plekken vrij/)
	assert.match(html, /Op deze dag is nog 1 plek vrij/)
	assert.match(html, /Deze dag is vol/)
	assert.match(html, /Bijna vol/, "the app's own places line wins")
	assert.match(html, /Brochure/)
	assert.match(html, /data-testid="nl-public-detail-secondary"[^>]*>Incompany aanvragen/)
})

test('a full day cannot be chosen', () => {
	assert.equal(isChoosable({ places: 0 }), false)
	assert.equal(isChoosable({ places: 3 }), true)
	assert.equal(isChoosable({}), true, 'a date that says nothing of places can be chosen')
	assert.equal(placesLine({}, () => 'x'), '')
})

test('signed out, the action shows its fields and a sign-in button, not a submit', async () => {
	const html = await render()
	assert.match(html, /Aantal deelnemers/)
	assert.match(html, /Je logt eerst in bij Mijn Academie\./)
	assert.match(html, /href="[^"]*eherkenning[^"]*"[^>]*>Inloggen om in te schrijven/)
	assert.doesNotMatch(html, />Deelnemers inschrijven</)
})

test('signed in, the action card carries the action and no sign-in line', async () => {
	const html = await render({ signedIn: true })
	assert.doesNotMatch(html, /Je logt eerst in/)
	assert.match(html, /data-testid="nl-public-detail-action-button"[^>]*>Deelnemers inschrijven/)
})

test('the choices wait while the visitor signs in and come back once', () => {
	const storage = memory()
	keepChoice(storage, '/cursusaanbod/f-gassen', { date: 'd1', count: 3 })
	assert.deepEqual(takeChoice(storage, '/cursusaanbod/f-gassen', DETAIL.dates, 10), { date: 'd1', count: 3 })
	assert.equal(takeChoice(storage, '/cursusaanbod/f-gassen', DETAIL.dates, 10), null, 'taken once')

	keepChoice(storage, '/p', { date: 'd3', count: 99 })
	assert.deepEqual(takeChoice(storage, '/p', DETAIL.dates, 10), { date: '', count: 10 }, 'a day that filled up meanwhile is not chosen, a count is capped')
	assert.equal(takeChoice(null, '/p', DETAIL.dates), null)
	assert.equal(clampCount('abc'), 1)
	assert.equal(clampCount(0), 1)
	assert.equal(clampCount(7.9, 10), 7)
})

test('pressing the sign-in button keeps the date and count', () => {
	const storage = memory()
	globalThis.window = { sessionStorage: storage, location: { pathname: '/cursusaanbod/f-gassen', origin: 'https://x.example' } }
	try {
		const page = instance(NlPublicDetail, { app: 'learniq', kind: 'course', ways: WAYS })
		page.detail = DETAIL
		page.chosenDate = 'd2'
		page.count = 3
		page.openAction({ button: 0 })
		assert.deepEqual(JSON.parse(storage.getItem('portaliq.detailChoice:/cursusaanbod/f-gassen')), { date: 'd2', count: 3 })
	} finally {
		delete globalThis.window
	}
})

test('an item the index does not return reads as not found, and a failed read as failed', async () => {
	const stub = (status, body) => {
		globalThis.document = { getElementById: () => null, querySelector: () => null }
		globalThis.window = { location: { origin: 'https://x.example', search: '', hash: '' }, localStorage: { getItem: () => null, setItem() {}, removeItem() {} } }
		globalThis.fetch = async () => ({ ok: status < 300, status, json: async () => body })
	}
	stub(404, {})
	assert.equal(await fetchCatalogueDetail('academie', { app: 'learniq', kind: 'course', slug: 'x' }), null)
	stub(200, { item: { id: 'learniq:c1' }, detail: DETAIL })
	assert.equal((await fetchCatalogueDetail('academie', { app: 'learniq', kind: 'course', slug: 'x' })).detail.title, DETAIL.title)
	stub(500, {})
	await assert.rejects(fetchCatalogueDetail('academie', { app: 'learniq', kind: 'course', slug: 'x' }))

	const html = await renderComponent(inState(NlPublicDetail, { state: 'missing' }), { backHref: '/cursusaanbod' })
	assert.match(html, /nl-public-detail-missing/)
	delete globalThis.window
})

test('every string says the same in both languages', () => {
	assert.deepEqual(Object.keys(strings.nl).sort(), Object.keys(strings.en).sort())
})
