#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// callouts-steps-tables.spec.mjs: the content page blocks read like the
// boards Contentpagina. A melding carries its button with a chevron, bold
// words, a plain white look and a warning mark; numbered steps are compact
// circles with only the lead phrase bold; a table may name each row in
// bold (site-callouts-steps-and-tables-follow-the-boards).
//
// Usage:
//   node --test tests/site-look/callouts-steps-tables.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import {
	alertAction,
	alertKind,
	boldParts,
} from '../../src/site/widgets/nlAlert/alert.js'
import { listLines, stepLead } from '../../src/site/widgets/nlList/lines.js'
import { renderSfc } from '../support/render-sfc.mjs'

const strip = (html) => html.replace(/<!--[\s\S]*?-->/g, '')
function source(file) {
	return readFileSync(
		new URL(`../../src/site/widgets/${file}`, import.meta.url),
		'utf8',
	).replace(/\s+/g, ' ')
}

globalThis.window = globalThis.window || {
	location: { origin: 'https://school.example', search: '', hash: '' },
}

test('a melding knows five kinds, bold parts and a safe button', () => {
	assert.equal(alertKind('plain'), 'plain')
	assert.equal(alertKind('shout'), 'info')
	assert.deepEqual(boldParts('Bel **[telefoonnummer]**.'), [
		{ text: 'Bel ', strong: false },
		{ text: '[telefoonnummer]', strong: true },
		{ text: '.', strong: false },
	])
	assert.deepEqual(boldParts('Een ** alleen'), [
		{ text: 'Een ** alleen', strong: false },
	])
	assert.deepEqual(boldParts(''), [])
	assert.equal(
		alertAction({ label: 'Afwezig melden', href: '/mijn' }).link.route,
		'/mijn',
	)
	assert.equal(alertAction({ label: 'X', href: 'javascript:alert(1)' }), null)
	assert.equal(alertAction({ href: '/mijn' }), null)
	assert.equal(alertAction(null), null)
})

test('"Online melden" carries its button with a chevron inside the melding', async () => {
	const html = strip(
		await renderSfc('src/site/widgets/nlAlert/NlAlert.vue', {
			kind: 'info',
			heading: 'Online melden',
			text: 'Het snelst.',
			action: { label: 'Afwezig melden', href: '/mijn' },
		}),
	)
	assert.match(html, /^<div class="utrecht-alert nl-alert utrecht-alert--info"/)
	assert.match(
		html,
		/<a class="[^"]*utrecht-button-link--primary-action nl-alert__action"[^>]*>Afwezig melden<svg/,
	)
	assert.ok(html.indexOf('nl-alert__action') > html.indexOf('Het snelst.'))
	assert.match(
		source('nlAlert/NlAlert.vue'),
		/\.nl-alert:has\(\.nl-alert__action\):not\(\.nl-alert--row\) \{ flex-direction: column;/,
	)
	assert.doesNotMatch(
		html,
		/nl-alert--row/,
		'with a heading the button goes under the words',
	)
})

test('without a heading the button stands beside the words', async () => {
	const html = await renderSfc('src/site/widgets/nlAlert/NlAlert.vue', {
		text: 'Het kan vanaf je telefoon.',
		action: { label: 'Inloggen en ziek melden', href: '/mijn' },
	})
	assert.match(html, /nl-alert--row/)
})

test('"Liever bellen?" is a plain card with the number in bold; a warning has its mark', async () => {
	const plain = strip(
		await renderSfc('src/site/widgets/nlAlert/NlAlert.vue', {
			kind: 'plain',
			heading: 'Liever bellen?',
			text: 'Bel **[telefoonnummer]**',
		}),
	)
	assert.match(plain, /utrecht-alert--plain/)
	assert.match(plain, /<strong>\[telefoonnummer\]<\/strong>/)
	assert.doesNotMatch(plain, /nl-alert__mark/)
	assert.match(
		source('nlAlert/NlAlert.vue'),
		/\.utrecht-alert--plain \{ border: 1px solid var\(--nldesign-color-border,[^}]*background-color: var\(--utrecht-document-background-color, Canvas\)/,
	)

	const warning = await renderSfc('src/site/widgets/nlAlert/NlAlert.vue', {
		kind: 'warning',
		text: 'Is uw kind afwezig zonder melding?',
	})
	assert.match(warning, /<svg class="nl-alert__mark"/)
	assert.match(warning, /role="alert"/)
})

test('numbered steps put only the lead phrase in bold', async () => {
	assert.deepEqual(
		stepLead({
			title: 'Meld je ziek op de eerste dag, voor 08.30 uur.',
			text: '',
		}),
		{ lead: 'Meld je ziek op de eerste dag,', rest: 'voor 08.30 uur.' },
	)
	assert.deepEqual(
		stepLead({ title: 'Heb je die dag BPV? Bel dan ook.', text: '' }),
		{ lead: 'Heb je die dag BPV?', rest: 'Bel dan ook.' },
	)
	assert.deepEqual(stepLead({ title: 'Kort', text: '' }), {
		lead: 'Kort',
		rest: '',
	})
	assert.deepEqual(stepLead({ title: 'Titel', text: 'Regel' }), {
		lead: 'Titel',
		rest: 'Regel',
	})
	assert.equal(listLines(['a']).length, 1)

	const html = strip(
		await renderSfc('src/site/widgets/nlList/NlList.vue', {
			display: 'numbered',
			items: ['Weer beter? Meld je beter in Mijn Esdoornveen.'],
		}),
	)
	assert.match(html, /<ol class="utrecht-ordered-list nl-list-numbered"/)
	assert.match(
		html,
		/<span class="nl-list-numbered__number" aria-hidden="true">1<\/span><span><strong>Weer beter\?<\/strong> Meld je beter in Mijn Esdoornveen\.<\/span>/,
	)
	assert.match(
		source('nlList/NlList.vue'),
		/\.nl-list-numbered__number \{[^}]*border-radius: 50%;[^}]*background-color: var\(--nldesign-color-primary, CanvasText\)/,
	)
})

test('a table may name each row in bold with a row header', async () => {
	const html = strip(
		await renderSfc('src/site/widgets/nlTable/NlTable.vue', {
			columns: ['Wat is er', 'Wat doet u'],
			rows: [['Ziek', 'Afwezig melden']],
			display: 'boxed',
			rowHeaders: true,
		}),
	)
	assert.match(
		html,
		/<th class="utrecht-table__header-cell nl-table__row-header" scope="row">Ziek<\/th><td class="utrecht-table__cell">Afwezig melden<\/td>/,
	)
	const plain = strip(
		await renderSfc('src/site/widgets/nlTable/NlTable.vue', {
			columns: ['A'],
			rows: [['Ziek']],
		}),
	)
	assert.doesNotMatch(
		plain,
		/scope="row"/,
		'without the option every cell is data',
	)
	assert.match(
		source('nlTable/NlTable.vue'),
		/\.nl-table__row-header \{ background: none; font-weight: 700;/,
	)
})
