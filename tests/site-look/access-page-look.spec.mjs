#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// access-page-look.spec.mjs: "Toegang tot zaken" as the Machtigingen board
// draws its lower half. The party field says what to type, tied to the field
// with aria-describedby; both fields are 520px; each request is a card with
// its state as a pill; the note about DigiD Machtigen is an info alert.
//
// Usage:
//   node --test tests/site-look/access-page-look.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import { renderSfc } from '../support/render-sfc.mjs'

const PAGE = 'src/site/pages/e/AccessRequestsPage.vue'
const REQUESTS = [
	{
		id: 'a',
		onBehalfOf: 'Bakkerij Lindelaan B.V.',
		state: 'granted',
		requestedAt: '2026-09-12',
	},
	{
		id: 'b',
		onBehalfOf: 'Henk de Vries',
		state: 'pending',
		requestedAt: '2026-10-02',
	},
]

/**
 * Render the page with two requests.
 *
 * @return {Promise<string>} The HTML.
 */
function render() {
	return renderSfc(PAGE, {
		api: {},
		t: (key, vars = {}) => key.replace('{party}', vars.party || ''),
		initialRequests: REQUESTS,
	})
}

test('the party field carries its example, tied to the field', async () => {
	const html = await render()
	assert.match(
		html,
		/id="access-request-party-help"[^>]*>\s*For example the name or the KvK number of a company/,
	)
	assert.match(
		html,
		/id="access-request-party"[^>]*aria-describedby="access-request-party-help"/,
	)
})

test('each request is a card with its state as a pill in its tone', async () => {
	const html = await render()
	assert.equal((html.match(/class="pq-access__request"/g) || []).length, 2)
	assert.match(html, /class="[^"]*pq-access__pill--pending[^"]*"/)
	assert.match(html, /class="[^"]*pq-access__pill--granted[^"]*"/)
	const source = readFileSync(new URL(`../../${PAGE}`, import.meta.url), 'utf8')
	const css = source.slice(source.indexOf('<style')).replace(/\s+/g, ' ')
	assert.match(css, /\.pq-access__request \{[^}]*border: 1px solid/)
	assert.match(
		css,
		/\.pq-access__pill--granted \{[^}]*background: var\(\s*--nldesign-component-status-badge-success-background-color/,
	)
})

test('the DigiD Machtigen note is an info alert with its own heading', async () => {
	const html = await render()
	assert.match(html, /class="utrecht-alert utrecht-alert--info pq-access__digid"/)
	assert.match(html, /<h2 id="pq-access-digid"/)
})
