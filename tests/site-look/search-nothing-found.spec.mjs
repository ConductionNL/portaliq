#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// search-nothing-found.spec.mjs: a search that finds nothing says what to try
// next (board ZoekenGeenTreffers): check the spelling, use fewer words (named
// from the term), take filters off when any are on with a button that does
// it, and the way on the portal offers. Before any answer, nothing is said.
//
// Usage:
//   node --test tests/site-look/search-nothing-found.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import { loadSfc, renderComponent } from '../support/render-sfc.mjs'

const PUBLIC_STUB = `
export const CnSiteSearch = { props: ['label', 'labelVisible', 'placeholder', 'submitLabel', 'value', 'inputId'], template: '<form role="search"></form>' }
`

/**
 * The search block after an answer.
 *
 * @param {object} state  The block's state to render with.
 * @param {object} props  The placement's props.
 * @return {Promise<string>} The markup, without Vue's markers.
 */
async function searched(state, props = {}) {
	const component = await loadSfc('src/site/components/FederatedSearchBlock.vue', {
		'@conduction/nextcloud-vue/public': PUBLIC_STUB,
	})
	const data = component.data
	const html = await renderComponent(
		{
			...component,
			mounted() {},
			data() {
				return { ...data.call(this), loading: false, error: false, ...state }
			},
		},
		props,
	)
	return html.replace(/<!--[\s\S]*?-->/g, '')
}

const site = JSON.parse(
	readFileSync(new URL('../../lib/Settings/sites/zuiddrecht.json', import.meta.url), 'utf8'),
)
const placement = site.pages
	.find((page) => page.route === '/zoeken')
	.body.widgets.find((widget) => widget.widgetKey === 'federatedSearch')

test('nothing found with a filter on: the tips, the button that clears and the way on', async () => {
	const html = await searched(
		{ sequence: 1, total: 0, query: 'fietsenstalling Molenwerf', facets: { wooCategory: ['raadsstuk'] } },
		placement.props,
	)
	assert.match(html, /data-testid="federated-search-empty"/)
	assert.match(html, /<h3 class="utrecht-heading-3">Niets gevonden<\/h3>/)
	assert.match(html, /Controleer de spelling\./)
	assert.match(html, /Gebruik minder woorden, bijvoorbeeld alleen (?:"|&quot;)Molenwerf(?:"|&quot;)\./)
	assert.match(html, /Haal een of meer filters weg\./)
	assert.match(html, /data-testid="federated-search-clear-filters"[^>]*>\s*Alle filters weghalen/)
	assert.match(html, /href="\/mijn\/dossiq\/startWooVerzoekAlgemeen"[^>]*>\s*Woo-verzoek indienen/)
	assert.match(html, /Staat de informatie er niet bij\?/)
})

test('one word and no filter: no fewer-words tip and no clear button', async () => {
	const html = await searched({ sequence: 1, total: 0, query: 'fietsenstalling' })
	assert.match(html, /Niets gevonden/)
	assert.doesNotMatch(html, /Gebruik minder woorden|federated-search-clear-filters|federated-search-empty-action/)
})

test('before the first answer, and when something is found, nothing is said', async () => {
	assert.doesNotMatch(await searched({ sequence: 0, total: 0 }), /federated-search-empty/)
	assert.doesNotMatch(await searched({ sequence: 1, total: 3 }), /federated-search-empty/)
})
