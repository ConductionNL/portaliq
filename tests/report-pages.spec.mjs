#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// report-pages.spec.mjs: the reporter's two public pages of intake-report-pages
// (REQ-IRP-001, REQ-IRP-002): the report form that shows the receipt code
// once, and the follow-up with the code alone. The server decides what a code
// opens; these tests cover what the blocks send, show and never keep.
//
// Usage:
//   node --test tests/report-pages.spec.mjs
//
// @spec openspec/changes/intake-report-pages/specs/report-pages/spec.md

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import { contactOf, reportApi, reportWords, termLines } from '../src/site/lib/reportApi.js'
import { loadSfc, renderSfc } from './support/render-sfc.mjs'

const FORM = 'src/site/components/ReportBlock.vue'
const THREAD = 'src/site/components/ReportThreadBlock.vue'

/**
 * A fetch that records each call and answers from a table by path.
 *
 * @param {Record<string, {status: number, body: object}>} answers The answers by path without query.
 * @param {Array<object>} calls Collects `{url, method, body, credentials}`.
 * @return {Function} The fetch.
 */
function fetchFor(answers, calls) {
	return async (url, init = {}) => {
		calls.push({ url, method: init.method || 'GET', body: init.body ? JSON.parse(init.body) : null, credentials: init.credentials })
		const answer = answers[url.split('?')[0]] || { status: 404, body: {} }
		return { ok: answer.status < 300, status: answer.status, json: async () => answer.body }
	}
}

test('filing solves the report challenge, sends the honeypot inside the report and answers the code once', async () => {
	const calls = []
	const api = reportApi('/portal/api', 'zuid', fetchFor({
		'/portal/api/identity/challenge': { status: 200, body: { nonce: 'n1', difficulty: 4, expiresAt: 99, signature: 's1', honeypotField: 'website' } },
		'/portal/api/reports': { status: 200, body: { code: 'ABCD1234EFGH5678', codeShownOnce: true } },
	}, calls), async (nonce, difficulty) => `${nonce}-${difficulty}`)

	const filed = await api.file({ caseType: 'misstand', register: 'r', schema: 's', report: { body: 'Dienstauto' }, contact: { email: 'a@b.nl' }, trap: '' })

	assert.deepEqual(filed, { ok: true, status: 200, error: '', code: 'ABCD1234EFGH5678' })
	assert.equal(calls[0].url, '/portal/api/identity/challenge?surface=report&portal=zuid')
	assert.equal(calls[1].url, '/portal/api/reports?portal=zuid')
	assert.deepEqual(calls[1].body, {
		caseType: 'misstand', register: 'r', schema: 's',
		report: { body: 'Dienstauto', website: '' },
		contact: { email: 'a@b.nl' },
		nonce: 'n1', solution: 'n1-4', expiresAt: 99, signature: 's1',
	})
	assert.ok(calls.every((call) => call.credentials === 'omit'), 'a reporter sends no cookie')
})

test('a refused filing gives no code, and a code that opens nothing reads as null', async () => {
	const calls = []
	const api = reportApi('/portal/api', 'zuid', fetchFor({
		'/portal/api/identity/challenge': { status: 200, body: {} },
		'/portal/api/reports': { status: 403, body: { error: 'challenge_failed' } },
		'/portal/api/reports/thread': { status: 401, body: { error: 'code_not_valid' } },
		'/portal/api/reports/thread/answer': { status: 401, body: {} },
	}, calls))
	assert.deepEqual(await api.file({ caseType: 'c', report: { body: 'x' }, contact: {} }), { ok: false, status: 403, error: 'challenge_failed', code: '' })
	assert.equal(calls[1].body.solution, '', 'a portal without a challenge needs no solution')
	assert.equal(await api.thread('WRONG'), null)
	assert.equal(await api.answer('WRONG', 'x'), false)
	assert.deepEqual(calls[2].body, { code: 'WRONG' }, 'the code travels in the body, never the address')
	assert.ok(!calls.some((call) => call.url.includes('WRONG')))
})

test('the contact block sends only what was given, as name and e-mail or phone', () => {
	assert.deepEqual(contactOf('', ''), {})
	assert.deepEqual(contactOf(' Piet ', 'piet@example.nl'), { name: 'Piet', email: 'piet@example.nl' })
	assert.deepEqual(contactOf('', '06 12345678'), { phone: '06 12345678' })
})

test('a term reads as the board writes it', () => {
	const nl = reportWords('nl')
	const date = (iso) => iso.slice(0, 10)
	assert.deepEqual(
		termLines({ term: 'acknowledgement', days: 7, dueAt: '2026-10-08T00:00:00Z', met: true, metAt: '2026-10-02T10:12:00Z', daysLeft: 6 }, nl, date),
		{ title: 'Bevestiging binnen 7 dagen', state: 'Gehaald op 2026-10-02', due: 'Uiterlijk 2026-10-08' },
	)
	assert.equal(termLines({ term: 'feedback', days: 90, dueAt: '2026-12-30', met: false, metAt: '', daysLeft: 84 }, nl, date).state, 'Loopt nog, 84 dagen over')
	assert.equal(termLines({ term: 'feedback', days: 90, dueAt: '2026-12-30', met: false, metAt: '', daysLeft: -1 }, nl, date).state, nl.overdue)
})

test('the words are Dutch and English with the same keys, and no em-dash', () => {
	const nl = reportWords('nl')
	const en = reportWords('en-GB')
	assert.deepEqual(Object.keys(nl).sort(), Object.keys(en).sort())
	assert.equal(en.keepCode, 'Keep this code. We cannot send it to you again.')
	assert.equal(en.wrongCode, 'We could not open a report with this code.')
	assert.equal(en.send, 'Send report')
	for (const text of [...Object.values(nl), ...Object.values(en)]) {
		assert.ok(!text.includes('—'), text)
	}
})

test('the form says what to fill in, sends nothing empty and shows the code once with the line to keep it', async () => {
	const html = await renderSfc(FORM, { locale: 'nl', threadRoute: '/melding', apiOverride: {} })
	assert.match(html, /Wat wilt u melden\?/)
	assert.match(html, /Wilt u dat wij weten wie u bent\?/)
	assert.match(html, /Melding versturen/)
	assert.match(html, /href="\/melding"/)
	assert.doesNotMatch(html, /report-code-panel/)

	const block = await loadSfc(FORM)
	const filed = []
	const vm = {
		...block.data(),
		words: reportWords('nl'),
		caseType: 'misstand', register: 'r', schema: 's',
		api: { file: async (filing) => { filed.push(filing); return { ok: true, status: 200, error: '', code: 'CODE1234' } } },
	}
	await block.methods.send.call(vm)
	assert.equal(filed.length, 0, 'an empty report is not sent')
	assert.equal(vm.problem, reportWords('nl').empty)

	Object.assign(vm, { body: ' Dienstauto ', whenWhere: 'Kerkstraat', reach: '06 1' })
	await block.methods.send.call(vm)
	assert.deepEqual(filed[0], { caseType: 'misstand', register: 'r', schema: 's', report: { body: 'Dienstauto', whenWhere: 'Kerkstraat' }, contact: { phone: '06 1' }, trap: '' })
	assert.equal(vm.code, 'CODE1234')
	assert.equal(vm.body, '', 'the report leaves the form once filed')
	assert.equal(vm.reach, '')

	const shown = await renderSfc(FORM, { locale: 'en', apiOverride: {} })
	assert.match(shown, /data-testid="report-form"/)
	const source = readFileSync(FORM, 'utf8') + readFileSync(THREAD, 'utf8')
	for (const leak of ['localStorage', 'sessionStorage', 'document.title', 'history.', 'location.', 'trackEvent', 'sendBeacon']) {
		assert.ok(!source.includes(leak), `the blocks never touch ${leak}`)
	}
})

test('the follow-up opens with the code, says the same for a wrong code, and answers on the thread', async () => {
	const html = await renderSfc(THREAD, { locale: 'nl', apiOverride: {} })
	assert.match(html, /Uw meldcode/)
	assert.match(html, /Bekijk het antwoord/)

	const block = await loadSfc(THREAD)
	const asked = []
	const thread = { report: { subject: 'Dienstauto', body: 'b', receivedAt: '2026-10-01T09:00:00Z' }, messages: [{ author: 'handler', authorName: 'R. Bos', body: 'Sinds wanneer?', writtenAt: '2026-10-06T12:40:00Z' }], terms: [] }
	const vm = {
		...block.data(),
		words: reportWords('nl'),
		api: {
			thread: async (code) => { asked.push(['thread', code]); return code === 'GOOD' ? thread : null },
			answer: async (code, body) => { asked.push(['answer', code, body]); return true },
		},
	}
	vm.codeDraft = 'nope'
	await block.methods.open.call(vm)
	assert.equal(vm.problem, 'Wij konden geen melding openen met deze code.')
	assert.equal(vm.thread, null)

	vm.codeDraft = ' good '
	await block.methods.open.call(vm)
	assert.equal(vm.thread, thread)
	assert.equal(vm.code, 'GOOD')
	assert.equal(vm.codeDraft, '', 'the typed code leaves the field')

	vm.reply = ' Sinds juli. '
	await block.methods.answer.call(vm)
	assert.deepEqual(asked.slice(-2), [['answer', 'GOOD', 'Sinds juli.'], ['thread', 'GOOD']])
	assert.equal(vm.notice, 'Uw antwoord is verstuurd.')
	assert.equal(block.methods.authorOf.call(vm, { author: 'reporter' }), 'U')
	assert.equal(block.methods.authorOf.call(vm, { author: 'handler', authorName: 'R. Bos' }), 'R. Bos')
})

test('both blocks are public widgets the host hands the portal, and the designer names them', () => {
	const grid = readFileSync('src/site/components/WidgetGrid.vue', 'utf8')
	assert.match(grid, /report: ReportBlock/)
	assert.match(grid, /reportThread: ReportThreadBlock/)
	assert.match(grid, /widgetKey === 'report'\s*\|\|\s*widget\.widgetKey === 'reportThread'/)
	const catalogue = readFileSync('src/lib/pageWidgetCatalogue.js', 'utf8')
	assert.match(catalogue, /report: \['portal', 'apiOverride'\]/)
	assert.match(catalogue, /reportThread: \['portal', 'apiOverride'\]/)
	const labels = readFileSync('src/lib/widgetLabels.js', 'utf8')
	assert.match(labels, /report: 'Misstand melden'/)
	assert.match(labels, /reportThread: 'Uw melding met meldcode'/)
})

// REQ-IRP-003: the designer warns on a report page that is still counted. ----

test('a report widget on a page outside the excluded paths is counted; one inside, or below one, is not', async () => {
	const { reportPageIsCounted, isExcludedPath, holdsReportWidget } = await import('../src/editor/reportExclusion.js')
	const report = [{ id: 'a', widgetKey: 'text' }, { id: 'b', widgetKey: 'report' }]
	const thread = [{ id: 'c', widgetKey: 'reportThread' }]

	assert.equal(reportPageIsCounted(report, '/meldpunt', []), true)
	assert.equal(reportPageIsCounted(report, '/meldpunt', ['/nieuws']), true)
	assert.equal(reportPageIsCounted(report, '/meldpunt/', ['meldpunt']), false, 'the collector normalises both sides')
	assert.equal(reportPageIsCounted(thread, '/meldpunt/gesprek', ['/meldpunt']), false, 'a path below an excluded one is excluded')
	assert.equal(reportPageIsCounted(thread, '/meldpuntje', ['/meldpunt']), true, 'a prefix is a path segment, not a string prefix')
	assert.equal(reportPageIsCounted([{ widgetKey: 'text' }], '/meldpunt', []), false, 'a page without a report widget is not warned about')
	assert.equal(reportPageIsCounted(report, '/meldpunt', null), false, 'an unreadable portal gives no warning')

	assert.equal(isExcludedPath('', ['/']), true, 'an empty route is the root')
	assert.equal(isExcludedPath('/x', [' ', 7, '']), false)
	assert.equal(holdsReportWidget(null), false)
})

test('the excluded paths are read from the page\'s own portal, and an unreadable portal reads as unknown', async () => {
	const { loadExcludedPaths } = await import('../src/editor/reportExclusion.js')
	const calls = []
	const get = async (url, config) => {
		calls.push({ url, config })
		return { data: { results: [{ slug: 'other', traffic: { excludedPaths: ['/no'] } }, { slug: 'zuid', traffic: { excludedPaths: ['/meldpunt', 3] } }] } }
	}

	assert.deepEqual(await loadExcludedPaths(get, '/portal-objects', 'zuid'), ['/meldpunt'])
	assert.deepEqual(calls[0], { url: '/portal-objects', config: { params: { slug: 'zuid', _limit: 1 } } })
	assert.deepEqual(
		await loadExcludedPaths(async () => ({ data: { results: [{ slug: 'zuid', traffic: {} }] } }), '/p', 'zuid'),
		[],
		'a portal without the list excludes nothing, so the warning shows',
	)
	assert.equal(await loadExcludedPaths(get, '/p', ''), null)
	assert.equal(await loadExcludedPaths(get, '/p', 'nowhere'), null)
	assert.equal(await loadExcludedPaths(async () => { throw new Error('403') }, '/p', 'zuid'), null)
})

test('both editor hosts hand the portal\'s excluded paths to the one editor, which shows the warning', () => {
	const editor = readFileSync('src/editor/PageGridEditor.vue', 'utf8')
	assert.match(editor, /v-if="reportCounted"/)
	assert.match(editor, /This page is not excluded from visitor statistics\. Add it before you publish\./)
	assert.match(editor, /reportPageIsCounted\(/)
	for (const host of ['src/views/PageLayoutDesigner.vue', 'src/editor/SiteEditMode.vue']) {
		const source = readFileSync(host, 'utf8')
		assert.match(source, /:excludedPaths="excludedPaths"/, host)
		assert.match(source, /loadExcludedPaths\(/, host)
	}
})
