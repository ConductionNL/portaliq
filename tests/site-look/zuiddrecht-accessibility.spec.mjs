#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// zuiddrecht-accessibility.spec.mjs: the Zuiddrecht accessibility page holds
// what board Toegankelijkheid draws: the status message, the facts about the
// statement, what does not work yet, the two ways to act, and the "Over deze
// website" links beside the text. The site authors it, so it takes the route
// from the generated statement.
//
// Usage:
//   node --test tests/site-look/zuiddrecht-accessibility.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'

const site = JSON.parse(
	readFileSync(
		new URL('../../lib/Settings/sites/zuiddrecht.json', import.meta.url),
		'utf8',
	),
)
const page = site.pages.find((entry) => entry.route === '/toegankelijkheid')

/**
 * The page's placements of one widget.
 *
 * @param {string} key The widget key.
 * @return {Array<object>} The placements, in reading order.
 */
function placed(key) {
	return page.body.widgets.filter((widget) => widget.widgetKey === key)
}

test('the page is the published accessibility statement', () => {
	assert.equal(page.title, 'Toegankelijkheidsverklaring')
	assert.equal(page.status, 'published')
})

test('the status stands in a warning message', () => {
	const [alert] = placed('nlAlert')
	assert.equal(alert.props.kind, 'warning')
	assert.equal(alert.props.heading, 'Status: voldoet gedeeltelijk')
})

test('the facts name the website, who checked it and the date', () => {
	const [facts] = placed('nlDescriptionList')
	assert.deepEqual(
		facts.props.items.map((item) => item.term),
		['Website', 'Onderzocht door', 'Datum verklaring'],
	)
})

test('it lists what does not work yet and offers two ways to act', () => {
	assert.deepEqual(
		placed('nlHeading').map((heading) => heading.props.text),
		['Wat nog niet goed werkt', 'Iets niet goed te gebruiken?'],
	)
	assert.equal(placed('nlList')[0].props.items.length, 2)
	const [actions] = placed('nlActionGroup')
	assert.deepEqual(
		actions.props.buttons.map((button) => [button.label, button.kind]),
		[
			['Probleem melden', 'primary'],
			['Verklaring in het register bekijken', 'secondary'],
		],
	)
})

test('the links about the website stand beside the text', () => {
	const [links] = placed('nlLinkList')
	assert.equal(links.gridX, 8)
	assert.equal(links.props.heading, 'Over deze website')
	assert.equal(links.props.display, 'accent')
	assert.ok(links.props.links.some((link) => link.href === '/toegankelijkheid'))
})
