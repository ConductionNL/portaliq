#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// inbox-links-and-routes.spec.mjs: every branch of the inbox's record route,
// of cutting the "Open" address out of a body line and of naming a link into
// this site (src/site/pages/inbox/inbox.js), pinned before they were split
// into named steps.
//
// Usage:
//   node --test tests/inbox-links-and-routes.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { bodyParts, bodyWithoutOpenLink, recordRoute } from '../src/site/pages/inbox/inbox.js'

const contribution = { app: 'dossiq' }
const casePage = {
	key: 'dossiq:mijnZaken',
	contribution,
	page: { id: 'mijnZaken', record: { collection: 'mijnZaken' }, blocks: [] },
}
const listPage = {
	key: 'dossiq:lijst',
	contribution,
	page: { id: 'lijst', blocks: [{ type: 'collection', collection: 'mijnZaken' }] },
}

test('a record route opens the record page on its record, else the page', () => {
	const link = { app: 'dossiq', collection: 'mijnZaken', id: 'a/b' }
	assert.equal(recordRoute([casePage], link), '/mijn/dossiq/mijnZaken/a%2Fb')
	assert.equal(recordRoute([casePage], { ...link, id: '' }), '/mijn/dossiq/mijnZaken')
	assert.equal(recordRoute([listPage], link), '/mijn/dossiq/lijst')
	assert.equal(recordRoute([casePage], { ...link, app: 'learniq' }), null)
	assert.equal(recordRoute(null, link), null)
	assert.equal(recordRoute([], link), null)
})

const SITE = 'https://gemeente.nl/index.php/apps/portaliq/site'
const OPEN = SITE + '#open=dossiq/mijnZaken/7'
const link = { app: 'dossiq', collection: 'mijnZaken', id: 7 }

test('a body line loses the "Open" address with its lead-in, a list line only ": <url>"', () => {
	const body = [
		'Er is een besluit. Lees het besluit hier: ' + OPEN + '.',
		'- Besluit: ' + OPEN,
		'Lees hier: ' + OPEN,
		OPEN,
		'Zie ' + OPEN + ' en verder',
		'Elders: https://elders.example/x',
		'Twee: ' + OPEN + ' en ' + SITE + '?route=/mijn/zaken',
		'Andere zaak: ' + SITE + '#open=dossiq/mijnZaken/8',
		'Ander pad: https://gemeente.nl/index.php/apps/portaliq/other#open=dossiq/mijnZaken/7',
		'Kapot: https://[',
		'',
		'',
		'',
		'Slot.',
	].join('\n')
	assert.equal(
		bodyWithoutOpenLink(body, link, '/mijn/zaken'),
		[
			'Er is een besluit..',
			'- Besluit',
			'Zie en verder',
			'Elders: https://elders.example/x',
			' en',
			'Andere zaak: ' + SITE + '#open=dossiq/mijnZaken/8',
			'Ander pad: https://gemeente.nl/index.php/apps/portaliq/other#open=dossiq/mijnZaken/7',
			'Kapot: https://[',
			'',
			'Slot.',
		].join('\n'),
	)
	assert.equal(bodyWithoutOpenLink('Route: ' + SITE + '?route=/mijn/zaken/', link, '/mijn/zaken'), '', 'a lead-in route line goes as a whole')
	assert.equal(bodyWithoutOpenLink('Leeg: ' + SITE + '?route=', link, '/mijn/zaken'), 'Leeg: ' + SITE + '?route=')
	assert.equal(bodyWithoutOpenLink('* Ster: ' + OPEN + ' !', link, '/x'), '* Ster !')
	assert.equal(bodyWithoutOpenLink(OPEN + ' ...', link, '/x'), '...')
	assert.equal(bodyWithoutOpenLink(42, link, '/x'), '')
	assert.equal(bodyWithoutOpenLink('Tekst ' + OPEN, null, '/x'), 'Tekst ' + OPEN)
	assert.equal(bodyWithoutOpenLink('Tekst ' + OPEN, link, null), 'Tekst ' + OPEN)
})

test('only an http(s) address on this origin under the site becomes a named link', () => {
	const labels = { publication: 'Bekijk de publicatie', link: 'Bekijk' }
	const origin = 'https://gemeente.nl'
	const text = (url) => bodyParts('Zie ' + url, origin, labels)
	assert.deepEqual(text(SITE + '?route=/zaken'), [
		{ text: 'Zie ' },
		{ text: 'Bekijk', href: SITE + '?route=/zaken', route: '/zaken' },
	])
	assert.deepEqual(text(SITE + '?route=/zaken#open=a/b/c')[1].route, null, 'a fragment loads the site')
	assert.deepEqual(text(SITE + '?route=zaken')[1].route, null, 'a route must start with a slash')
	assert.deepEqual(text(SITE)[1], { text: 'Bekijk', href: SITE, route: null })
	assert.deepEqual(text('http://gemeente.nl/index.php/apps/portaliq/site'), [
		{ text: 'Zie http://gemeente.nl/index.php/apps/portaliq/site' },
	], 'another origin (http) stays text')
	assert.deepEqual(text('https://elders.nl/index.php/apps/portaliq/site'), [{ text: 'Zie https://elders.nl/index.php/apps/portaliq/site' }])
	assert.deepEqual(text('https://gemeente.nl/index.php/apps/other'), [{ text: 'Zie https://gemeente.nl/index.php/apps/other' }])
	assert.deepEqual(text('https://[bad'), [{ text: 'Zie https://[bad' }])
	assert.deepEqual(bodyParts('Zie ' + SITE, '', labels), [{ text: 'Zie ' + SITE }], 'no origin, no links')
	assert.deepEqual(bodyParts('Zie http://localhost/apps/portaliq/site', 'http://localhost', labels)[1].href, 'http://localhost/apps/portaliq/site')
	const ftp = bodyParts('Zie ftp://gemeente.nl/apps/portaliq/site', origin, labels)
	assert.deepEqual(ftp, [{ text: 'Zie ftp://gemeente.nl/apps/portaliq/site' }])
})
