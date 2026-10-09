#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// mijn-overview-follows-the-boards.spec.mjs: the overviews of the school
// portals as their MijnOverzicht boards draw them: blocks in two columns and
// in frames, an "Alle ..." link in a block's heading row, the week after the
// greeting's date, tinted pills, and an e-mail ask in the portal's words or
// not at all (mijn-overview-follows-the-boards).
//
// Usage:
//   node --test tests/site-look/mijn-overview-follows-the-boards.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'
import { isoWeek } from '../../src/site/components/mijn/greeting.js'
import {
	blockClasses,
	blockPlaces,
	placeStyle,
} from '../../src/site/pages/collections/pageLayout.js'
import { renderSfc } from '../support/render-sfc.mjs'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..', '..')

/**
 * Items as ContributionPage hands them to the layout.
 *
 * @param {Array<string|undefined>} columns Each block's column.
 * @return {Array<{index: number, block: object}>}
 */
function items(columns) {
	return columns.map((column, index) => ({
		index,
		block: column ? { type: 'collection', column } : { type: 'greeting' },
	}))
}

test('the vaartveld overview: timetable left, homework and grades right, absence across', () => {
	// greeting, first lesson, timetable (main), homework (side), grades (side), absence
	const layout = blockPlaces(
		items([undefined, undefined, 'main', 'side', 'side', undefined]),
	)
	assert.equal(layout.columns, true)
	assert.deepEqual(layout.places, {
		0: { column: '1 / -1', row: '1' },
		1: { column: '1 / -1', row: '2' },
		2: { column: '1', row: '3 / 5' },
		3: { column: '2', row: '3' },
		4: { column: '2', row: '4 / 5' },
		5: { column: '1 / -1', row: '5' },
	})
	assert.deepEqual(placeStyle(layout, 2), {
		'--pq-block-column': '1',
		'--pq-block-row': '3 / 5',
	})
})

test('the wilgenboom overview: news beside "Deze maand" after the child cards', () => {
	const layout = blockPlaces(
		items([undefined, undefined, undefined, 'main', 'side']),
	)
	assert.deepEqual(layout.places[3], { column: '1', row: '4 / 5' })
	assert.deepEqual(layout.places[4], { column: '2', row: '4 / 5' })
})

test('a page without columns keeps its flow and no inline places', () => {
	const layout = blockPlaces(items([undefined, undefined]))
	assert.equal(layout.columns, false)
	assert.equal(placeStyle(layout, 0), undefined)
	assert.equal(placeStyle(layout, 1), undefined)
})

test('frames and the heading link are classes the theme styles', () => {
	assert.deepEqual(blockClasses({ frame: 'line' }), [
		'pq-block',
		'pq-block--framed',
		'pq-block--line',
	])
	assert.deepEqual(
		blockClasses({ frame: 'tinted', more: { label: 'Bekijken' } }),
		[
			'pq-block',
			'pq-block--framed',
			'pq-block--tinted',
			'pq-block--more-heading',
		],
	)
	assert.deepEqual(
		blockClasses({ more: { label: 'Alle cijfers', placement: 'end' } }),
		['pq-block'],
	)
	assert.deepEqual(blockClasses({ frame: 'shadow' }), ['pq-block'])
})

test('the shell draws a box only where the page asks for one', async () => {
	const boxed = await renderSfc('src/site/pages/collections/BlockShell.vue', {
		wrap: true,
		classes: ['pq-block', 'pq-block--more-heading'],
		more: { label: 'Hele week', route: '/mijn/learniq/rooster', href: '?r=x' },
		type: 'calendar',
	})
	assert.match(boxed, /data-testid="contribution-page-block"/)
	assert.match(boxed, /data-block="calendar"/)
	assert.match(
		boxed,
		/class="utrecht-link pq-block__more" href="\?r=x"[^>]*>\s*Hele week/,
	)
	const bare = await renderSfc('src/site/pages/collections/BlockShell.vue', {
		wrap: false,
		more: { label: 'Hele week', route: '/x', href: '?r=x' },
	})
	assert.equal(bare.includes('<div'), false)
	assert.equal(bare.includes('Hele week'), false)
})

test('the greeting can name the ISO week: Monday 5 October 2026 is week 41', () => {
	assert.equal(isoWeek(new Date(2026, 9, 5)), 41)
	assert.equal(isoWeek(new Date(2026, 0, 1)), 1)
	assert.equal(isoWeek(new Date(2027, 0, 1)), 53)
	const source = readFileSync(
		join(ROOT, 'src/site/components/mijn/GreetingBlock.vue'),
		'utf8',
	)
	assert.match(source, /this\.block\.showWeek === true/)
})

test('pills are tinted: no outline unless the theme names one', () => {
	const source = readFileSync(
		join(ROOT, 'src/site/components/mijn/DataBadge.vue'),
		'utf8',
	)
	assert.match(source, /var\(--nl-data-badge-border-color, transparent\)/)
	assert.doesNotMatch(source, /--nl-data-badge-border-color, currentcolor/)
})

test('the e-mail ask takes the portal words, and a portal can leave it out', async () => {
	const own = await renderSfc('src/site/components/e/ContactPrompt.vue', {
		t: (key) => key,
		texts: {
			text: 'Voeg je e-mailadres toe.',
			button: 'Naar mijn account',
			dismiss: 'Later',
		},
	})
	assert.match(own, /Voeg je e-mailadres toe\./)
	assert.match(own, /Naar mijn account/)
	assert.match(own, /Later/)
	const site = await renderSfc('src/site/components/e/ContactPrompt.vue', {
		t: (key) => key,
	})
	assert.match(site, /Add an e-mail address so we can tell you/)
	const app = readFileSync(join(ROOT, 'src/site/App.vue'), 'utf8')
	assert.match(app, /this\.site\?\.contactPrompt\?\.show !== false/)
	assert.equal(app.split(':texts="site.contactPrompt || {}"').length - 1, 2)
})

test('the page wraps its blocks in shells, and the theme styles the grid from tokens', () => {
	const page = readFileSync(
		join(ROOT, 'src/site/pages/collections/ContributionPage.vue'),
		'utf8',
	)
	assert.match(page, /<BlockShell\s+kind="grid"/)
	assert.match(page, /:more="moreOf\(item\)"/)
	const shell = readFileSync(
		join(ROOT, 'src/site/pages/collections/BlockShell.vue'),
		'utf8',
	)
	const style = shell.slice(shell.indexOf('<style>'))
	assert.match(style, /grid-template-columns: minmax\(0, 3fr\) minmax\(0, 2fr\)/)
	assert.doesNotMatch(style, /#[0-9a-f]{3,6}\b/i)
})

test('the absence strip reads "1 dag ziek" on one line', async () => {
	const html = await renderSfc('src/site/components/collections/KpiCards.vue', {
		t: (key) => key,
		label: 'Afwezigheid dit schooljaar',
		display: 'strip',
		row: { absentDays: 1, lateCount: 2 },
		cards: [
			{
				field: 'absentDays',
				label: 'Afwezig',
				stripLabel: 'ziek',
				unit: { one: 'dag', other: 'dagen' },
			},
			{
				field: 'lateCount',
				label: 'te laat',
				unit: { one: 'keer', other: 'keer' },
			},
		],
	})
	assert.match(html, /pq-kpi--strip/)
	assert.match(html, /<strong>1 dag<\/strong> ziek/)
	assert.match(html, /<strong>2 keer<\/strong> te laat/)
	assert.equal(html.includes('kpi-card"'), false)
})
