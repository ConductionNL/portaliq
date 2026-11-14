#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// publication-report.spec.mjs: "Fout melden" on a publication page
// (publication-error-reports-and-withheld-notices REQ-PER-001, REQ-PER-002).
// Off, no button and nothing is offered. On, the dialog checks the form and
// posts the portal, publication, document and description, plus an address
// only when one was given, without a cookie, to the report route next to
// the content API.
//
// Usage:
//   node --test tests/publication-report.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import {
	failureKey,
	formProblem,
	offersReport,
	reportsUrlFrom,
	sendReport,
} from '../src/site/lib/publicationReport.js'
import { loadSfc, renderSfc } from './support/render-sfc.mjs'

const TRIGGER = 'src/site/components/PublicationReport.vue'
const DIALOG = 'src/site/modals/PublicationReportModal.vue'
const PUBLICATION = '7f3c2a9e-1b4d-4e8a-9c2f-5d6e7a8b9c0d'

test('no button when off', async () => {
	for (const publications of [
		null,
		{},
		{ errorReports: false, withheldNotices: true },
		{ errorReports: 'true' },
	]) {
		const html = await renderSfc(TRIGGER, {
			publications,
			publication: PUBLICATION,
		})
		assert.doesNotMatch(
			html,
			/publication-report-open/,
			JSON.stringify(publications),
		)
		assert.equal(offersReport(publications, PUBLICATION), false)
	}

	const on = await renderSfc(TRIGGER, {
		publications: { errorReports: true },
		publication: PUBLICATION,
	})
	assert.match(on, /data-testid="publication-report-open"/)
	assert.match(on, /Fout melden/)
	assert.equal(
		offersReport({ errorReports: true }, ''),
		false,
		'no publication, no button',
	)
})

test('the publication page renders the button only behind the switch', () => {
	const block = readFileSync(
		'src/site/components/PublicationDetailBlock.vue',
		'utf8',
	)
	assert.match(
		block,
		/<PublicationReport\s+v-if="publications && publications\.errorReports === true"/,
	)
	const grid = readFileSync('src/site/components/WidgetGrid.vue', 'utf8')
	assert.match(
		grid,
		/subjectId: this\.routeParam,[\s\S]*?publications: this\.publications,/,
		'the host sets it after the authored props',
	)
})

test('the form posts publication, document and description', async () => {
	const dialog = await loadSfc(DIALOG)
	const posted = []
	const vm = {
		portal: 'zuiddrecht',
		publication: PUBLICATION,
		form: {
			document: 'Bijlage 2',
			description: '  Bijlage 2 is de conceptversie ',
			contactEmail: '',
		},
		problem: '',
		sending: false,
		sent: false,
		reportsUrl: () => '/api/site/publication-reports',
		send: async (url, report) => {
			posted.push({ url, report })
			return { ok: true, status: 201 }
		},
	}
	await dialog.methods.submit.call(vm)

	assert.equal(vm.sent, true)
	assert.equal(posted.length, 1)
	assert.equal(posted[0].url, '/api/site/publication-reports')
	assert.deepEqual(posted[0].report, {
		portal: 'zuiddrecht',
		publication: PUBLICATION,
		document: 'Bijlage 2',
		description: '  Bijlage 2 is de conceptversie ',
		contactEmail: '',
	})

	const calls = []
	const fetchImpl = async (url, init) => {
		calls.push({ url, init })
		return { ok: true, status: 201 }
	}
	const answer = await sendReport(
		reportsUrlFrom('/index.php/apps/portaliq/api/content'),
		posted[0].report,
		fetchImpl,
	)
	assert.deepEqual(answer, { ok: true, status: 201 })
	assert.equal(
		calls[0].url,
		'/index.php/apps/portaliq/api/site/publication-reports',
	)
	assert.equal(calls[0].init.method, 'POST')
	assert.equal(calls[0].init.credentials, 'omit')
	assert.deepEqual(
		JSON.parse(calls[0].init.body),
		{
			portal: 'zuiddrecht',
			publication: PUBLICATION,
			document: 'Bijlage 2',
			description: 'Bijlage 2 is de conceptversie',
		},
		'no address key without an address',
	)

	await sendReport(
		'/r',
		{ ...posted[0].report, contactEmail: ' h.bakker@example.nl ' },
		fetchImpl,
	)
	assert.equal(JSON.parse(calls[1].init.body).contactEmail, 'h.bakker@example.nl')
})

test('an empty, too long or badly addressed report is not sent', async () => {
	assert.equal(formProblem({ description: '   ' }), 'empty')
	assert.equal(formProblem({ description: 'é'.repeat(2001) }), 'tooLong')
	assert.equal(formProblem({ description: 'é'.repeat(2000) }), '')
	assert.equal(
		formProblem({ description: 'Fout', contactEmail: 'geen-adres' }),
		'badEmail',
	)

	const dialog = await loadSfc(DIALOG)
	let sends = 0
	const vm = {
		form: { document: '', description: '', contactEmail: '' },
		problem: '',
		sending: false,
		sent: false,
		send: async () => {
			sends++
			return { ok: true, status: 201 }
		},
	}
	await dialog.methods.submit.call(vm)
	assert.equal(vm.problem, 'empty')
	assert.equal(sends, 0)
})

test('a refused send says why, and a failed network says try again', async () => {
	assert.equal(failureKey(413), 'tooLong')
	assert.equal(failureKey(429), 'tooMany')
	assert.equal(failureKey(404), 'gone')
	assert.equal(failureKey(0), 'failed')
	assert.deepEqual(
		await sendReport('/r', { description: 'x' }, async () => {
			throw new Error('down')
		}),
		{ ok: false, status: 0 },
	)

	const dialog = await loadSfc(DIALOG)
	const vm = {
		form: { document: '', description: 'Fout', contactEmail: '' },
		problem: '',
		sending: false,
		sent: false,
		reportsUrl: () => '/r',
		send: async () => ({ ok: false, status: 429 }),
	}
	await dialog.methods.submit.call(vm)
	assert.equal(vm.sent, false)
	assert.equal(vm.problem, 'tooMany')
})

test('the owner closes a report through the close route, and a refusal says why', async () => {
	const { createPublicationReportHandlers, closeFailureKey } =
		await import('../src/lib/publicationReportActions.js')
	const posts = []
	const said = []
	let reloads = 0
	const handlers = createPublicationReportHandlers({
		post: async (url, body) => {
			posts.push({ url, body })
			if (body.status === 'not-applicable') {
				const error = new Error('refused')
				error.response = { status: 403 }
				throw error
			}
			return {}
		},
		generateUrl: (path, params) => path.replace('{id}', params.id),
		notify: (text) => said.push(['ok', text]),
		notifyError: (text) => said.push(['error', text]),
		translate: (text) => text,
		reload: () => reloads++,
	})

	assert.equal(
		await handlers.markReportHandled({
			item: { id: 'report-7', portal: 'zuiddrecht' },
		}),
		true,
	)
	assert.deepEqual(posts[0], {
		url: '/apps/portaliq/api/publication-reports/report-7/close',
		body: { portal: 'zuiddrecht', status: 'handled' },
	})
	assert.equal(reloads, 1)

	assert.equal(
		await handlers.markReportNotApplicable({
			item: { uuid: 'report-8', portal: 'zuiddrecht' },
		}),
		false,
	)
	assert.equal(posts[1].body.status, 'not-applicable')
	assert.deepEqual(said, [
		['ok', 'The report is marked handled.'],
		['error', closeFailureKey(403)],
	])
	assert.equal(reloads, 1, 'a refusal does not reload')
})
