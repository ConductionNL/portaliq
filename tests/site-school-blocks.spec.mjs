#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// site-school-blocks.spec.mjs: the website blocks the school portals need
// (site-school-blocks), tested as the plain functions they decide with.
//
// Usage:
//   node --test tests/site-school-blocks.spec.mjs
//
// @spec openspec/changes/site-school-blocks/specs/portaliq-cms/spec.md

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import {
	dayAndMonth,
	dayLabel,
	isPast,
	longDate,
	toDate,
} from '../src/site/components/mijn/dates.js'
import { authoredLink, staysInSite } from '../src/site/components/mijn/links.js'
import { eventRows } from '../src/site/widgets/nlEventList/events.js'
import { listLines } from '../src/site/widgets/nlList/lines.js'
import { splitLead } from '../src/site/widgets/nlNewsArticle/article.js'
import icons from '../src/site/widgets/nlQuickTasks/icons.js'
import { cardButton, safeWays } from '../src/site/widgets/nlSignIn/signIn.js'

const MONDAY = new Date(2026, 9, 5, 9, 30)

test('a date-only value is a calendar day and never shifts', () => {
	assert.equal(toDate('2026-10-07').getDate(), 7)
	assert.equal(toDate(''), null)
	assert.equal(toDate('soon'), null)
	assert.deepEqual(dayAndMonth('2026-10-07', 'nl'), { day: '7', month: 'okt' })
	assert.deepEqual(dayAndMonth('2026-10-07', 'en'), { day: '7', month: 'Oct' })
	assert.equal(longDate('2026-10-02', 'nl'), '2 oktober 2026')
})

test('a run of days reads as one label', () => {
	assert.equal(dayLabel('2026-11-03', '', 'nl'), '3 nov')
	assert.equal(dayLabel('2026-10-17', '2026-10-25', 'nl'), '17 - 25 okt')
	assert.equal(dayLabel('2026-09-30', '2026-10-02', 'nl'), '30 sep - 2 okt')
	assert.equal(dayLabel('x', '', 'nl'), '')
})

test('a day is over only after its last day', () => {
	assert.equal(isPast('2026-10-04', '', MONDAY), true)
	assert.equal(isPast('2026-10-05', '', MONDAY), false, 'today is not over')
	assert.equal(
		isPast('2026-10-01', '2026-10-09', MONDAY),
		false,
		'a run that is still going',
	)
})

test('an authored link is a route in the site, an outside address, or nothing', () => {
	assert.deepEqual(authoredLink('/praktisch'), {
		href: '/praktisch',
		route: '/praktisch',
	})
	assert.deepEqual(authoredLink('https://example.org/a'), {
		href: 'https://example.org/a',
		route: '',
	})
	assert.equal(authoredLink('mailto:info@example.org').route, '')
	assert.equal(authoredLink('//evil.example'), null)
	assert.equal(authoredLink('javascript:alert(1)'), null)
	assert.equal(authoredLink(''), null)

	assert.equal(staysInSite({ button: 0 }, { route: '/a' }), true)
	assert.equal(staysInSite({ button: 0, ctrlKey: true }, { route: '/a' }), false)
	assert.equal(staysInSite({ button: 0 }, { route: '' }), false)
})

test('the dated list sorts, leaves out what is over, and cuts to the limit', () => {
	const rows = eventRows(
		[
			{ date: '2026-10-29', title: 'Ouderavond', meta: 'Donderdag' },
			{ date: '2026-10-07', title: 'Schoolfotograaf' },
			{ date: '2026-10-01', title: 'Voorbij' },
			{
				date: '2026-10-09',
				title: 'Studiedag',
				note: 'Nog 1 plek',
				noteTone: 'warning',
			},
			{ date: 'nooit', title: 'Geen datum' },
			{ date: '2026-10-08', title: '' },
			{
				date: '2026-10-13',
				endDate: '2026-10-15',
				dateLabel: '13 en 15 okt',
				title: 'Mentorgesprekken',
				href: '/agenda',
			},
		],
		{ upcomingOnly: true, limit: 4, locale: 'nl', now: MONDAY },
	)
	assert.deepEqual(
		rows.map((row) => row.title),
		['Schoolfotograaf', 'Studiedag', 'Mentorgesprekken', 'Ouderavond'],
	)
	assert.equal(rows[1].tone, 'warning')
	assert.equal(rows[2].label, '13 en 15 okt')
	assert.equal(rows[2].link.route, '/agenda')
	assert.equal(rows[0].label, '7 okt')

	const all = eventRows(
		[{ date: '2026-10-01', title: 'Voorbij', noteTone: 'loud' }],
		{ upcomingOnly: false, limit: 99, now: MONDAY },
	)
	assert.equal(all.length, 1, 'the past stays when asked')
	assert.equal(all[0].tone, 'neutral', 'an unknown tone is neutral')
})

test('list lines take a string or a title with a line', () => {
	assert.deepEqual(
		listLines([
			'Een',
			'  ',
			{ title: 'Twee', text: 'Uitleg' },
			{ text: 'zonder titel' },
			null,
		]),
		[
			{ title: 'Een', text: '' },
			{ title: 'Twee', text: 'Uitleg' },
		],
	)
	assert.deepEqual(listLines('niet een lijst'), [])
})

test('an article leads with its first paragraph unless that is not a paragraph', () => {
	assert.deepEqual(splitLead('Eerste alinea\nloopt door.\n\nTweede.\n\n## Kop'), {
		lead: 'Eerste alinea loopt door.',
		rest: 'Tweede.\n\n## Kop',
	})
	assert.deepEqual(splitLead('## Kop\n\nTekst'), {
		lead: '',
		rest: '## Kop\n\nTekst',
	})
	assert.deepEqual(splitLead(''), { lead: '', rest: '' })
})

test('every task icon is a path on the 24 grid with a name that says what it shows', () => {
	for (const [name, path] of Object.entries(icons)) {
		assert.match(name, /^[a-z][A-Za-z]+$/, name)
		assert.match(
			path,
			/^M[\d.\s,MLHVCSQTAZmlhvcsqtaz-]+$/,
			`${name} is not a path`,
		)
	}
	assert.ok(Object.keys(icons).length >= 18)
})

test('the sign-in card never offers a way the portal did not declare', () => {
	const say = (key, vars) =>
		({
			signIn: 'Inloggen',
			goTo: `Naar ${vars?.name}`,
			ownArea: 'Naar uw eigen omgeving',
		})[key]
	const digid = {
		id: 'digid',
		label: 'Inloggen met DigiD',
		href: '/portal/api/session/oidc/start?provider=digid',
	}

	assert.deepEqual(
		safeWays([
			digid,
			{ id: 'x', label: 'Elders', href: 'https://evil.example/login' },
			{ id: 'y', label: 'Ook', href: '//evil.example' },
			{ id: '', label: 'Leeg', href: '/a' },
		]),
		[digid],
	)

	const one = cardButton({
		ways: [digid],
		signedIn: false,
		heading: 'Mijn Wilgenboom',
		buttonLabel: '',
		signInHref: '/mijn',
		say,
	})
	assert.equal(one.href, digid.href, 'one way in: straight to it')
	assert.equal(one.label, 'Inloggen met DigiD')

	const several = cardButton({
		ways: [digid, { ...digid, id: 'nextcloud' }],
		signedIn: false,
		heading: '',
		buttonLabel: '',
		signInHref: 'https://evil.example',
		say,
	})
	assert.equal(
		several.route,
		'/mijn',
		'several: to the sign-in page, and an outside page is refused',
	)
	assert.equal(several.label, 'Inloggen')

	const signedIn = cardButton({
		ways: [digid],
		signedIn: true,
		heading: 'Mijn Wilgenboom',
		buttonLabel: 'Inloggen',
		signInHref: '/mijn',
		say,
	})
	assert.deepEqual(
		[signedIn.label, signedIn.route],
		['Naar Mijn Wilgenboom', '/mijn'],
	)
})

test('the grid hands the host data to the new widgets after the authored props', () => {
	const source = readFileSync(
		new URL('../src/site/components/WidgetGrid.vue', import.meta.url),
		'utf8',
	)
	assert.match(
		source,
		/'nlNewsArticle'\s*\)\s*\{\s*const news = \{ \.\.\.props, portal: this\.portal, routeParam: this\.routeParam \}/,
	)

	assert.match(
		source,
		/widgetKey === 'nlSignIn'\) \{\s*return \{\s*\.\.\.props,\s*signedIn:[^}]*ways: this\.signInRoutes/,
	)
})

test('T7: the app hands its sign-in routes to the grid, and the grid to nlSignIn', () => {
	const app = readFileSync('src/site/App.vue', 'utf8')
	const context = app.slice(app.indexOf('gridContext() {'))
	assert.match(
		context.slice(0, context.indexOf('},')),
		/signInRoutes: this\.signInRoutes/,
	)
	const grid = readFileSync('src/site/components/WidgetGrid.vue', 'utf8')
	assert.match(grid, /ways: this\.signInRoutes\.map/)
})
