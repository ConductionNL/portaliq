#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// zuiddrecht-cookies.spec.mjs: the Zuiddrecht cookie page holds what board
// Cookieverklaring draws: the "nothing to choose" message, the table of what
// the site keeps in the browser with its rows named, what the site does not
// do, and the "Over deze website" links beside the text.
//
// Usage:
//   node --test tests/site-look/zuiddrecht-cookies.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'

const site = JSON.parse(
	readFileSync(
		new URL('../../lib/Settings/sites/zuiddrecht.json', import.meta.url),
		'utf8',
	),
)
const page = site.pages.find((entry) => entry.route === '/cookies')

/**
 * The page's placements of one widget.
 *
 * @param {string} key The widget key.
 * @return {Array<object>} The placements, in reading order.
 */
function placed(key) {
	return page.body.widgets.filter((widget) => widget.widgetKey === key)
}

test('the page is the cookie statement', () => {
	assert.equal(page.title, 'Cookieverklaring')
})

test('it says there is nothing to choose, as a success message', () => {
	const [alert] = placed('nlAlert')
	assert.equal(alert.props.kind, 'ok')
	assert.equal(alert.props.heading, 'U hoeft niets te kiezen')
})

test('it lists what the site keeps, one named row each', () => {
	const [table] = placed('nlTable')
	assert.deepEqual(table.props.columns, ['Naam', 'Soort', 'Waarvoor', 'Hoe lang'])
	assert.equal(table.props.rows.length, 3)
	assert.equal(table.props.rowHeaders, true)
	assert.equal(table.props.captionVisible, true)
})

test('the links about the website stand beside the text', () => {
	const [links] = placed('nlLinkList')
	assert.equal(links.gridX, 8)
	assert.equal(links.props.heading, 'Over deze website')
	assert.ok(links.props.links.some((link) => link.href === '/cookies'))
})
