#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// site-woo-pages.spec.mjs: the Woo search and publication pages as the
// Zuiddrecht boards Zoeken and Publicatie draw them
// (site-matches-the-zuiddrecht-boards), rendered with fixture data because
// the blocks need OpenCatalogi for the real thing. And the proof that the
// default variants render as before.
//
// Usage:
//   node --test tests/site-woo-pages.spec.mjs
//
// @spec openspec/changes/site-matches-the-zuiddrecht-boards/specs/portaliq-cms/spec.md

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import { loadSfc, renderComponent } from './support/render-sfc.mjs'

// The search block reads the page address when it renders a result's link; a
// plain node has none, so a window with an empty address stands in.
globalThis.window = {
	location: {
		search: '',
		pathname: '/zoeken',
		href: 'http://localhost/zoeken',
		origin: 'http://localhost',
	},
	history: { replaceState() {}, pushState() {} },
	addEventListener() {},
	removeEventListener() {},
}

const root = new URL('../', import.meta.url)
const read = (path) => readFileSync(new URL(path, root), 'utf8')
const nl = JSON.parse(read('src/shared/i18n/nl.json'))
const I18N_STUB = `const nl = ${JSON.stringify(nl)}\nexport function createTranslator() { return (key) => nl[key] || key }\n`
const PUBLIC_STUB = `
export const CnSiteSearch = { props: ['label', 'labelVisible', 'placeholder', 'submitLabel', 'value', 'inputId'], template: '<form class="ac-search-box" role="search"><label class="ac-search-box__label" :for="inputId">{{ label }}</label><div class="ac-search-box__search"><input :id="inputId" class="ac-search-box__input" :value="value" /><button type="submit" class="ac-search-box__button">{{ submitLabel }}</button></div></form>' }
`

const PUBLICATION = {
	id: 'p-1',
	title: 'Verlichting fietspad Lindelaan',
	summary: 'Besluit op het Woo-verzoek "Verlichting fietspad Lindelaan"',
	publicationDate: '2026-10-04T09:00:00+00:00',
	status: 'published',
	themes: [],
	wooCategory: 'infocat014',
}
const DOCUMENTS = [
	{
		id: 'd-1',
		title: 'Besluit op het Woo-verzoek',
		type: 'PDF',
		size: '184 kB',
		href: 'https://example.org/besluit.pdf',
	},
	{
		id: 'd-2',
		title: 'Inventarislijst',
		type: 'PDF',
		size: '62 kB',
		href: 'https://example.org/lijst.pdf',
	},
]
const RESULTS = [
	{
		key: 'r-1',
		id: 'r-1',
		title: 'Verlichting fietspad Lindelaan',
		summary: 'Besluit op het Woo-verzoek',
		date: '4 oktober 2026',
		type: 'infocat014',
		directory: 'Zuiddrecht',
	},
	{
		key: 'r-2',
		id: 'r-2',
		title: 'Raadsvoorstel fietspad Lindelaan',
		summary: 'Voorstel aan de gemeenteraad.',
		date: '15 september 2026',
		type: 'infocat014',
		directory: 'Zuiddrecht',
	},
]

/**
 * The publication page with the fixture loaded, as the existing spec does.
 *
 * @param {object} props The props.
 * @return {Promise<string>} The HTML.
 */
async function publicationPage(props) {
	const component = await loadSfc(
		'src/site/components/PublicationDetailBlock.vue',
		{
			'../../shared/i18n/index.js': I18N_STUB,
		},
	)
	const data = component.data
	return renderComponent(
		{
			...component,
			mounted() {},
			data() {
				return {
					...data.call(this),
					loading: false,
					publication: PUBLICATION,
					themeNames: {},
					documents: DOCUMENTS,
				}
			},
		},
		{ subjectId: 'p-1', ...props },
	)
}

/**
 * The search page with results loaded.
 *
 * @param {object} props The props.
 * @return {Promise<string>} The HTML.
 */
async function searchPage(props, results = RESULTS) {
	const component = await loadSfc('src/site/components/FederatedSearchBlock.vue', {
		'@conduction/nextcloud-vue/public': PUBLIC_STUB,
	})
	const data = component.data
	return renderComponent(
		{
			...component,
			mounted() {},
			data() {
				return {
					...data.call(this),
					results,
					total: 12,
					loading: false,
					error: '',
				}
			},
		},
		props,
	)
}

test('the publication page draws the boards cards: pill, boxed rows, document cards with a download button', async () => {
	const html = await publicationPage({ variant: 'cards' })
	assert.match(html, /pq-detail__body--cards/)
	assert.match(
		html,
		/data-testid="publication-detail-pill"[^>]*>\s*Woo-verzoeken en -besluiten/,
	)
	assert.match(html, /pq-detail__fields--boxed/)
	assert.equal((html.match(/pq-detail__document-card"/g) || []).length, 2)
	assert.match(
		html,
		/pq-detail__download[^>]*href="https:\/\/example.org\/besluit.pdf"[^>]*download/,
	)
	assert.match(
		html,
		/Downloaden<span class="sr-only"> Besluit op het Woo-verzoek<\/span>/,
	)
	assert.match(html, /PDF, 184 kB/)
})

test('the plain publication page renders as before', async () => {
	const html = await publicationPage({})
	assert.doesNotMatch(
		html,
		/pq-detail__body--cards|publication-detail-pill|pq-detail__document-card|Downloaden/,
	)
	assert.match(html, /pq-detail__document-list/)
	assert.match(html, /\(PDF, 184 kB\)/)
})

test('the search page wears the plain variant only when asked, and keeps its count, sort and results', async () => {
	const plain = await searchPage({
		variant: 'plain',
		facetLabel: 'Filters',
		periodFromLabel: 'Vanaf',
		periodToLabel: 'Tot en met',
	})
	assert.match(plain, /class="pq-search pq-search--plain"/)
	assert.match(plain, /12 resultaten/)
	assert.match(plain, /Vanaf/)
	assert.match(plain, /Tot en met/)
	assert.equal(
		(plain.match(/data-testid="federated-search-result"/g) || []).length,
		2,
	)
	const reference = await searchPage({})
	assert.doesNotMatch(reference, /pq-search--plain/)
	assert.match(reference, /class="pq-search"/)
})

test('the site stylesheet draws the plain search: joined form, filters left, bordered cards', () => {
	const css = read('css/site-theme.css')
	assert.match(
		css,
		/\.pq-site \.pq-search--plain \.ac-search-box__input\.utrecht-textbox/,
	)
	assert.match(
		css,
		/\.pq-site \.pq-search--plain \.pq-search__layout--faceted \{\n\tgrid-template-columns: minmax\(16\.25rem, 18\.75rem\)/,
	)
	assert.match(css, /\.pq-site \.pq-search--plain \.pq-search__result\.ac-card/)
})

test('the Zuiddrecht declaration places the two Woo pages as the boards draw them', () => {
	const site = JSON.parse(read('lib/Settings/sites/zuiddrecht.json'))
	const zoeken = site.pages.find((page) => page.route === '/zoeken').body.widgets
	const search = zoeken.find(
		(widget) => widget.widgetKey === 'federatedSearch',
	).props
	assert.equal(search.variant, 'plain')
	assert.equal(search.facetLabel, 'Filters')
	assert.equal(search.periodFromLabel, 'Vanaf')
	assert.equal(search.periodToLabel, 'Tot en met')
	const publicatie = site.pages.find((page) => page.route === '/publicatie').body
		.widgets
	const detail = publicatie.find(
		(widget) => widget.widgetKey === 'publicationDetail',
	)
	assert.equal(detail.props.variant, 'cards')
	assert.equal(detail.gridWidth, 8)
	assert.deepEqual(
		publicatie.map((widget) => widget.widgetKey),
		['publicationDetail', 'nlSignIn', 'nlLinkList'],
	)
	assert.equal(publicatie[1].props.heading, 'Bewaren of volgen')
	assert.equal(publicatie[2].props.links[0].label, 'Woo-verzoek indienen')
})

test('"Download" reads in both languages', () => {
	assert.equal(nl.Download, 'Downloaden')
	assert.equal(JSON.parse(read('src/shared/i18n/en.json')).Download, 'Download')
})

test('a document found by its text links to its own page and names its publication (REQ-PFS-CONTENT-001)', async () => {
	const html = await searchPage({}, [
		{
			key: 'd-42',
			id: 'd-42',
			kind: 'document',
			publication: 'Besluit Stationsweg',
			title: 'Rapport geluidsscherm.pdf',
			summary: '',
			href: '',
			directory: 'local',
			date: '',
			type: '',
		},
	])
	assert.match(html, /route=%2Fdocument%2Fd-42/)
	assert.match(html, /Onderdeel van Besluit Stationsweg/)
})
