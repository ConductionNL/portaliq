#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// measure-detail-golden.spec.mjs: the accessibility measurement's rows and
// audit check, and the publication page's visitor rows and file sizes,
// pinned in a golden file before each was split into named steps. The clock
// is passed in, never read.
//
// Usage:
//   node --test tests/measure-detail-golden.spec.mjs
//   GOLDEN_RECORD=1 node --test tests/measure-detail-golden.spec.mjs

import { test } from 'node:test'
import { auditProblem, measurePage, violationsOf } from '../src/lib/accessibilityMeasure.js'
import { humanSize, visitorRows } from '../src/site/lib/publicationDetail.js'
import { golden } from './support/golden.mjs'

const TODAY = new Date('2026-11-10T12:00:00Z')

test('measurement rows and the audit check', async () => {
	const file = golden('measure-detail')
	file.check('violationsOf', violationsOf({
		violations: [
			null,
			{ id: '', impact: 'serious' },
			{ id: 'color-contrast', impact: 'minor', nodes: [1, 2], help: 'Contrast', helpUrl: 'https://x' },
			{ id: 'label', impact: 'critical', nodes: 'x' },
			{ id: 'region', impact: 'cosmetic' },
			{ id: 7, impact: 'moderate', nodes: [] },
		],
	}))
	file.check('measurePage', [
		await measurePage('/a', { open: async () => ({ root: 1 }), run: async () => ({ violations: [{ id: 'x', impact: 'serious', nodes: [1] }] }) }),
		await measurePage('/b', { open: async () => { throw new Error('Blocked by the frame') }, run: async () => ({}) }),
		await measurePage('/c', { open: async () => { throw {} }, run: async () => ({}) }),
		await measurePage('/d', { open: async () => null, run: async () => ({}) }),
		await measurePage('/e', { open: async () => ({}), run: async () => { throw new Error('axe') } }),
	])
	const complete = { party: 'Toegankelijkheid BV', reportUrl: 'https://rapport.example/1', date: '2025-11-11' }
	file.check('auditProblem', [
		auditProblem(null, TODAY),
		auditProblem({ result: 'C' }, TODAY),
		auditProblem({ ...complete, result: 'A' }, TODAY),
		auditProblem({ ...complete, result: 'B', date: '2023-11-09' }, TODAY),
		auditProblem({ ...complete, result: 'B', date: '2023-11-10' }, TODAY),
		auditProblem({ ...complete, result: 'B', date: '2023-11-11' }, TODAY),
		auditProblem({ ...complete, result: 'A', party: '  ' }, TODAY),
		auditProblem({ ...complete, result: 'A', reportUrl: 'http://rapport.example' }, TODAY),
		auditProblem({ ...complete, result: 'A', date: '10-11-2025' }, TODAY),
		auditProblem({ ...complete, result: 'A', date: '2025-13-45' }, TODAY),
		auditProblem({ ...complete, result: 'A', date: '2026-11-11' }, TODAY),
	])
	file.save()
})

test('visitor rows and file sizes', () => {
	const file = golden('publication-detail')
	const t = (key) => `t:${key}`
	const reader = { t, locale: 'nl', themeNames: { th1: 'Wonen', th2: ' ', th3: 3 } }
	file.check('visitorRows', [
		visitorRows(null, reader),
		visitorRows('x', reader),
		visitorRows({}, reader),
		visitorRows({ publicationDate: '2026-09-02T10:00:00Z', wooCategory: 'convenant', themes: ['th1', 'th2', 'th3', 'nope'] }, reader),
		visitorRows({ publicationDate: 'later', wooCategory: [], themes: [] }, { t, locale: 'en' }),
		visitorRows({ publicationDate: '2026-01-31', wooCategory: {} }, { t, locale: 'en' }),
	])
	file.check('humanSize', [null, undefined, '', 'x', -1, 0, 999.4, 999.6, 1000, 123456, 999999, 1000000, 2449999, '5000000', Infinity].map(humanSize))
	file.save()
})
