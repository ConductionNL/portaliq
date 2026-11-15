#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// resident-controls.spec.mjs: the controls on the Mijn Zuiddrecht pages draw
// as the boards do (MijnAccount, Machtigingen, Zaak).
//
// Usage:
//   node --test tests/site-look/resident-controls.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..', '..')
const css = readFileSync(join(ROOT, 'css/site-theme.css'), 'utf8')
	.replace(/\/\*[\s\S]*?\*\//g, '')
	.replace(/\s+/g, ' ')

test('an outlined button reads its own ink over the vendored !important', () => {
	assert.match(
		css,
		/\.pq-site \.utrecht-button--secondary-action \{ border-color: var\(--_utrecht-button-border-color\) !important; color: var\(--_utrecht-button-color\) !important; \}/,
	)
})

test('a resident list page opens with a 44px title on the page title line', () => {
	assert.match(
		css,
		/:is\(#pq-account-title, #pq-details-title, #pq-access-title\) \{ margin-block-start: 0; font-size: var\(--utrecht-heading-1-font-size, 2\.75rem\); line-height: var\( --nldesign-website-page-title-line-height,/,
	)
})

test('a select reads the theme select tokens instead of the browser default', () => {
	assert.match(
		css,
		/\.pq-site \.utrecht-select \{[^}]*min-block-size: var\( --utrecht-select-min-block-size, var\(--utrecht-pointer-target-min-size, 44px\) \);[^}]*border: var\(--utrecht-select-border-width, 1px\) solid var\(--utrecht-select-border-color, currentcolor\);/,
	)
})

function sfcStyle (rel) {
  return readFileSync(join(ROOT, rel), 'utf8')
		.split('<style')
		.slice(1)
		.join(' ')
		.replace(/\/\*[\s\S]*?\*\//g, '')
		.replace(/\s+/g, ' ')
}

test('a toned data badge takes the set status badge colours first', () => {
	const badge = sfcStyle('src/site/components/mijn/DataBadge.vue')
	for (const tone of ['success', 'warning', 'error']) {
		assert.match(
			badge,
			new RegExp(
				`\\.pq-data-badge\\.nl-data-badge--${tone} \\{[^}]*background-color: var\\( --nldesign-component-status-badge-${tone}-background-color, var\\(--nl-data-badge-${tone}-background-color`,
			),
		)
	}
})

test('an access request tag is the boards 28px pill in 15px', () => {
	const page = sfcStyle('src/site/pages/e/AccessRequestsPage.vue')
	assert.match(
		page,
		/\.pq-access__pill \{[^}]*font-size: 0\.9375rem;[^}]*line-height: 1\.75rem; padding-inline: 0\.75rem;/,
	)
})

test('a message, a case and an access request frame in the website card line', () => {
	assert.match(
		css,
		/\.pq-inbox-row \{[^}]*border: 1px solid var\( --nldesign-website-card-border-color,/,
	)
	assert.match(
		sfcStyle('src/site/components/mijn/CaseCard.vue'),
		/border: 1px solid var\( --nldesign-website-card-border-color,/,
	)
	assert.match(
		sfcStyle('src/site/pages/e/AccessRequestsPage.vue'),
		/\.pq-access__request \{[^}]*border: 1px solid var\( --nldesign-website-card-border-color,/,
	)
})

test('the mark-as-read text button has square ends', () => {
	assert.match(css, /\.pq-site \.pq-inbox-row \.utrecht-button--subtle \{ border-radius: 0; \}/)
})

test('a partial accessibility status is the attention strip', () => {
	const page = sfcStyle('src/site/components/AccessibilityStatementPage.vue')
	assert.match(
		page,
		/\.pq-accessibility-statement__status\.utrecht-alert--warning \{ border-color: var\( --nldesign-website-attention-border-color,/,
	)
	assert.match(page, /\.pq-accessibility-statement__status h2 \{[^}]*font-size: 1\.1875rem;/)
})

test('the sign-in ways stand three in a row on the designed site', () => {
	assert.match(
		css,
		/\.pq-site:has\(\.pq-site__header--designed\) \.pq-signin__cards \{ grid-template-columns: repeat\(auto-fit, minmax\(min\(100%, 320px\), 1fr\)\); max-inline-size: none; \}/,
	)
})

test('the add, authorise and invite buttons lead with the boards icon', () => {
	assert.match(css, /\.pq-site \.utrecht-button \.pq-button-icon \{ inline-size: 20px; block-size: 20px;/)
	for (const rel of [
		'src/site/components/e/AddressList.vue',
		'src/site/components/e/MandatesGiven.vue',
		'src/site/modals/e/MandateInviteModal.vue',
	]) {
		assert.match(readFileSync(join(ROOT, rel), 'utf8'), /class="pq-button-icon"/, rel)
	}
})
