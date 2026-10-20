#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// zuiddrecht-contact.spec.mjs: the Zuiddrecht contact page holds what board
// Contact draws under its three ways to reach the municipality: the opening
// hours as a boxed table with a named row per day, the note about a question
// on one's own case, reporting in public space as a heading, a line and a
// button, and "Meer contact" beside the text.
//
// Usage:
//   node --test tests/site-look/zuiddrecht-contact.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'

const site = JSON.parse(
	readFileSync(
		new URL('../../lib/Settings/sites/zuiddrecht.json', import.meta.url),
		'utf8',
	),
)
const page = site.pages.find((entry) => entry.route === '/contact')

/**
 * The page's placements of one widget.
 *
 * @param {string} key The widget key.
 * @return {Array<object>} The placements, in declaration order.
 */
function placed(key) {
	return page.body.widgets.filter((widget) => widget.widgetKey === key)
}

test('the opening hours are a boxed table with a named row per day', () => {
	const [table] = placed('nlTable')
	assert.equal(table.props.display, 'boxed')
	assert.equal(table.props.rowHeaders, true)
	assert.deepEqual(
		table.props.rows.map((row) => row[0]),
		['Maandag tot en met donderdag', 'Vrijdag', 'Zaterdag en zondag'],
	)
})

test('a question about one\'s own case is a note, not a card', () => {
	const [note] = placed('nlAlert')
	assert.equal(note.props.kind, 'info')
	assert.match(note.props.text, /^Een vraag over uw eigen zaak\?/)
	assert.ok(
		!placed('nlLinkList').some(
			(list) => list.props.heading === 'Iets melden in de openbare ruimte',
		),
	)
})

test('reporting in public space is a heading, a line and a button', () => {
	assert.ok(
		placed('nlHeading').some(
			(heading) => heading.props.text === 'Iets melden in de openbare ruimte',
		),
	)
	const [button] = placed('nlButtonLink')
	assert.equal(button.props.label, 'Melding indienen')
})

test('"Meer contact" stands beside the text with the accent line', () => {
	const more = placed('nlLinkList').find(
		(list) => list.props.heading === 'Meer contact',
	)
	assert.equal(more.gridX, 8)
	assert.equal(more.props.display, 'accent')
})
