#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// site-accessibility-statement.spec.mjs: every portal serves its accessibility
// statement at /toegankelijkheid, linked from the footer's legal strip, with
// the status the server gives and never more (site-accessibility-statement
// REQ-SAS-002 and REQ-SAS-003).
//
// Usage:
//   node --test tests/site-accessibility-statement.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'
import {
	isStatementRoute,
	STATEMENT_ROUTE,
	statementLines,
	withStatementLink,
} from '../src/site/lib/accessibilityStatement.js'

const root = join(dirname(fileURLToPath(import.meta.url)), '..')
const read = (path) => readFileSync(join(root, path), 'utf8')
const nl = JSON.parse(read('src/shared/i18n/nl.json'))
function t(key, vars = {}) {
	return Object.entries(vars).reduce(
		(text, [name, value]) => text.replace(`{${name}}`, String(value)),
		nl[key] || key,
	)
}

test('the statement lives at /toegankelijkheid on every portal', () => {
	assert.equal(STATEMENT_ROUTE, '/toegankelijkheid')
	assert.ok(isStatementRoute('/toegankelijkheid'))
	assert.ok(isStatementRoute('/toegankelijkheid/'))
	assert.equal(isStatementRoute('/toegankelijkheid-oud'), false)
	assert.equal(isStatementRoute('/'), false)
})

test('the statement is linked from the footer, once', () => {
	const portalLinks = [{ label: 'Privacy', href: '/privacy' }]
	assert.deepEqual(withStatementLink(portalLinks, 'Toegankelijkheid'), [
		{ label: 'Privacy', href: '/privacy' },
		{ label: 'Toegankelijkheid', href: '/toegankelijkheid' },
	])
	const already = [{ label: 'Toegankelijkheid', href: '/toegankelijkheid' }]
	assert.deepEqual(withStatementLink(already, 'Toegankelijkheid'), already)
	assert.deepEqual(withStatementLink(undefined, 'Toegankelijkheid'), [
		{ label: 'Toegankelijkheid', href: '/toegankelijkheid' },
	])
})

test('the statement follows the measurement and lists the measurement date', () => {
	const lines = statementLines(
		{
			status: 'C',
			automatedOnly: true,
			measurement: {
				measuredAt: '2026-10-01T09:00:00+00:00',
				axeVersion: '4.10.3',
				pagesMeasured: 4,
				pagesNotMeasured: 1,
			},
			issues: [
				{
					rule: 'color-contrast',
					sentence:
						'Sommige tekst heeft te weinig contrast met de achtergrond.',
					impact: 'serious',
					pages: 2,
					helpUrl:
						'https://dequeuniversity.com/rules/axe/4.10/color-contrast',
				},
			],
			notMeasured: [
				{ url: '/extern', reason: 'The page refused to load in a frame.' },
			],
		},
		t,
		() => '1 oktober 2026',
	)
	assert.match(lines.status, /^Status C/)
	assert.match(lines.automated, /automatische controle bewijst niet/)
	assert.match(lines.evidence, /1 oktober 2026/)
	assert.equal(
		lines.issues[0].sentence,
		'Sommige tekst heeft te weinig contrast met de achtergrond.',
	)
	assert.equal(lines.issues[0].detail, "Ernstig, op 2 pagina's")
	assert.deepEqual(lines.notMeasured, [
		{ url: '/extern', reason: 'The page refused to load in a frame.' },
	])
})

test('the page never shows a status the server did not give', () => {
	assert.match(
		statementLines({ status: null }, t, String).status,
		/nog niet gemeten/,
	)
	assert.match(
		statementLines({ status: 'A+' }, t, String).status,
		/nog niet gemeten/,
	)
	assert.match(statementLines(null, t, String).automated, /automatische meting/)
	const audited = statementLines(
		{
			status: 'B',
			automatedOnly: false,
			audit: {
				party: 'Stichting',
				date: '2026-06-01',
				reportUrl: 'https://x.nl/r.pdf',
			},
		},
		t,
		() => '1 juni 2026',
	)
	assert.match(audited.status, /^Status B/)
	assert.equal(audited.automated, '')
	assert.equal(audited.audit, 'Onderzocht door Stichting op 1 juni 2026.')
})

test('the shell renders the statement page on its route and puts the link in the legal strip', () => {
	const app = read('src/site/App.vue')
	assert.match(app, /<AccessibilityStatementPage\s+v-else-if="statementRoute"/)
	assert.match(
		app,
		/withStatementLink\(\s*legalLinksOf\(this\.site, this\.menus\)/,
	)
	assert.match(app, /if \(isStatementRoute\(route\)\) \{/)
	assert.match(read('src/site/lib/contentApi.js'), /get\('\/accessibility'/)
})

test('every string of the statement page is in the Dutch bundle', () => {
	const sources =
		read('src/site/components/AccessibilityStatementPage.vue')
		+ read('src/site/lib/accessibilityStatement.js')
	const keys = [...sources.matchAll(/\bt\(\s*(['"])((?:(?!\1).)+)\1/gs)].map(
		(match) => match[2],
	)
	assert.ok(keys.length > 15)
	for (const key of keys) {
		assert.ok(nl[key], `nl.json translates "${key}"`)
	}
})
