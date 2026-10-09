#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// accessibility-measure.spec.mjs: the accessibility measurement on a portal's
// page (site-accessibility-statement REQ-SAS-001 and REQ-SAS-003). It runs the
// e2e suite's five rule sets, posts a page it could not frame as not measured,
// refuses an A or B claim without an audit, keeps axe-core out of the site
// entry, and is placed on the portal page.
//
// Usage:
//   node --test tests/accessibility-measure.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'
import {
	auditProblem,
	AXE_TAGS,
	createAccessibilityApi,
	frameAddress,
	measurePage,
	measureRun,
	violationsOf,
} from '../src/lib/accessibilityMeasure.js'

const root = join(dirname(fileURLToPath(import.meta.url)), '..')
const read = (path) => readFileSync(join(root, path), 'utf8')

test('the five tags are the ones the e2e suite uses', () => {
	const e2e = read('tests/e2e/site-accessibility.spec.ts')
	const match = e2e.match(
		/runOnly:\s*\{\s*type:\s*'tag',\s*values:\s*\[([^\]]+)\]/,
	)
	assert.ok(match, 'the e2e suite names its rule sets')
	const e2eTags = match[1].split(',').map((tag) => tag.trim().replace(/'/g, ''))
	assert.deepEqual(AXE_TAGS, e2eTags)
	// The server's statement records the same five.
	const statement = read('lib/Service/Cms/AccessibilityStatement.php')
	assert.match(
		statement,
		/TAGS = \['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'\]/,
	)
})

test('a page that cannot be framed is posted as not measured', async () => {
	const refused = await measurePage('/extern', {
		open: async () => {
			throw new Error('The page refused to load in a frame.')
		},
		run: async () => {
			throw new Error('never reached')
		},
	})
	assert.deepEqual(refused, {
		url: '/extern',
		measured: false,
		reason: 'The page refused to load in a frame.',
	})

	const empty = await measurePage('/leeg', {
		open: async () => null,
		run: async () => ({}),
	})
	assert.equal(empty.measured, false)
	assert.equal(empty.violations, undefined)

	const broken = await measurePage('/stuk', {
		open: async () => ({}),
		run: async () => {
			throw new Error('axe is not defined')
		},
	})
	assert.equal(broken.measured, false)
	assert.equal(broken.reason, 'The measurement could not run on this page.')
})

test('a run measures each page in turn and keeps only what the server stores', async () => {
	const seen = []
	const run = await measureRun(['/', '/zoeken'], {
		axeVersion: '4.10.3',
		theme: 'vng',
		open: async (route) => ({ route }),
		run: async (rootElement) => {
			seen.push(rootElement.route)
			return {
				violations: [
					{
						id: 'color-contrast',
						impact: 'serious',
						nodes: [{}, {}],
						help: 'Contrast',
						helpUrl:
							'https://dequeuniversity.com/rules/axe/4.10/color-contrast',
						html: '<p>x</p>',
					},
					{ id: 'odd', impact: 'unknown', nodes: [] },
				],
			}
		},
	})
	assert.deepEqual(seen, ['/', '/zoeken'])
	assert.equal(run.axeVersion, '4.10.3')
	assert.deepEqual(run.tags, AXE_TAGS)
	assert.equal(run.theme, 'vng')
	assert.deepEqual(run.pages[0], {
		url: '/',
		measured: true,
		violations: [
			{
				rule: 'color-contrast',
				impact: 'serious',
				nodes: 2,
				help: 'Contrast',
				helpUrl: 'https://dequeuniversity.com/rules/axe/4.10/color-contrast',
			},
		],
	})
	assert.deepEqual(violationsOf(null), [])
})

test('the frame asks for the measurement of this portal', () => {
	const address = frameAddress(
		'/index.php/apps/portaliq/site',
		'open-tilburg',
		'/zoeken',
	)
	const query = new URL(address, 'https://example.nl').searchParams
	assert.equal(query.get('portal'), 'open-tilburg')
	assert.equal(query.get('route'), '/zoeken')
	assert.equal(query.get('measure'), '1')
})

test('the admin refuses an A or B claim without an audit', () => {
	const today = new Date('2026-10-09T12:00:00Z')
	assert.equal(auditProblem({ result: 'A' }, today), 'incomplete')
	assert.equal(
		auditProblem(
			{
				result: 'B',
				party: 'Stichting',
				date: '2026-06-01',
				reportUrl: 'http://x.nl',
			},
			today,
		),
		'incomplete',
	)
	assert.equal(
		auditProblem(
			{
				result: 'B',
				party: 'Stichting',
				date: '2023-10-01',
				reportUrl: 'https://x.nl/r.pdf',
			},
			today,
		),
		'expired',
	)
	assert.equal(
		auditProblem(
			{
				result: 'B',
				party: 'Stichting',
				date: '2026-06-01',
				reportUrl: 'https://x.nl/r.pdf',
			},
			today,
		),
		'',
	)
	assert.equal(auditProblem({ result: 'C' }, today), '')
	assert.equal(auditProblem({}, today), '')
})

test('the calls name the portal and report a refusal in its own words', async () => {
	const calls = []
	const api = createAccessibilityApi({
		get: async (url) => {
			calls.push(['GET', url])
			return { data: { pages: ['/'] } }
		},
		put: async (url, body) => {
			calls.push(['PUT', url, body])
			const error = new Error('422')
			error.response = { data: { error: 'audit_incomplete' } }
			throw error
		},
		post: async (url, body) => {
			calls.push(['POST', url, body])
			return { data: { measurement: {} } }
		},
		url: (path, params) => path.replace('{slug}', params.slug),
	})
	assert.deepEqual(await api.load('open-tilburg'), {
		ok: true,
		data: { pages: ['/'] },
		error: '',
	})
	assert.equal(
		(await api.save('open-tilburg', { audit: { result: 'A' } })).error,
		'audit_incomplete',
	)
	assert.equal((await api.store('open-tilburg', { pages: [] })).ok, true)
	assert.deepEqual(
		calls.map((call) => call.slice(0, 2)),
		[
			['GET', '/api/portals/open-tilburg/accessibility'],
			['PUT', '/api/portals/open-tilburg/accessibility'],
			['POST', '/api/portals/open-tilburg/accessibility/measurements'],
		],
	)
})

test('axe-core is a lazy admin chunk and never enters the site', () => {
	const widget = read('src/widgets/PortalAccessibility.vue')
	assert.match(widget, /import\('axe-core'\)/, 'the widget loads axe-core lazily')
	assert.doesNotMatch(
		widget,
		/^import [^\n]*axe-core/m,
		'no static import of axe-core',
	)
	for (const file of ['src/site/main.js', 'src/site/App.vue']) {
		assert.doesNotMatch(read(file), /axe-core/, `${file} does not name axe-core`)
	}
})

test('the widget sits on the portal page and is registered', () => {
	const manifest = JSON.parse(read('src/manifest.json'))
	const widgets = JSON.stringify(manifest)
	assert.match(widgets, /"type":"PortalAccessibility"/)
	assert.match(read('src/registry.js'), /PortalAccessibility: \{/)
})
