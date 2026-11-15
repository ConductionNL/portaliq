#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// idle-warning-dialog.spec.mjs: the warning before an inactivity sign-out as
// the SessieVerloopt board draws it. The sentence also says what is lost
// (WCAG 2.2.6), a countdown to the second as a warning chip, and a footer
// under a rule with "Uitloggen" and then "Ingelogd blijven" at the end.
//
// Usage:
//   node --test tests/site-look/idle-warning-dialog.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import { countdownText } from '../../src/shared/idleSession.js'

const FILE = 'src/site/components/IdleWarningDialog.vue'
const NL = JSON.parse(
	readFileSync(new URL('../../src/shared/i18n/nl.json', import.meta.url), 'utf8'),
)

/**
 * The Dutch translator.
 *
 * @param {string} key The English source string.
 * @param {object} [vars] Placeholder values.
 * @return {string} The text.
 */
function t(key, vars = {}) {
	return (NL[key] ?? key).replace(/\{(\w+)\}/g, (_, name) =>
		String(vars[name] ?? ''),
	)
}

test('the countdown reads to the second', () => {
	assert.equal(countdownText(118, t), '1 minuut en 58 seconden')
	assert.equal(countdownText(120, t), '2 minuten')
	assert.equal(countdownText(61, t), '1 minuut en 1 seconde')
	assert.equal(countdownText(40, t), '40 seconden')
	assert.equal(countdownText(-3, t), '0 seconden')
})

// The component imports the shared Dutch bundle as JSON, which the test
// renderers cannot load, so its template and style are read as source.
test('the dialog says what is lost, shows the countdown chip and ends on the way to stay', () => {
	const source = readFileSync(new URL(`../../${FILE}`, import.meta.url), 'utf8')
	const template = source.slice(0, source.indexOf('<script>'))
	const body = template.slice(template.indexOf('id="pq-idle-body"'))
	assert.match(
		body.slice(0, body.indexOf('</div>')),
		/t\('What you have not sent yet is lost then\.'\)/,
		'the loss is part of the described body',
	)
	assert.match(
		template,
		/data-testid="site-idle-countdown"[\s\S]*t\('\{time\} left', \{ time: countdown\(left\) \}\)/,
	)
	const signout = template.indexOf('data-testid="site-idle-signout"')
	const stay = template.indexOf('data-testid="site-idle-stay"')
	assert.ok(
		signout > 0 && stay > signout,
		'Uitloggen first, Ingelogd blijven last',
	)
	assert.equal(
		t('{time} left', { time: countdownText(118, t) }),
		'Nog 1 minuut en 58 seconden',
	)
	const css = source.slice(source.indexOf('<style')).replace(/\s+/g, ' ')
	assert.match(
		css,
		/\.pq-idle-buttons \{[^}]*border-block-start: 1px solid[^}]*justify-content: flex-end/,
	)
	assert.match(
		css,
		/\.pq-idle-warning__countdown \{[^}]*background: var\(\s*--nldesign-component-status-badge-warning-background-color/,
	)
})
