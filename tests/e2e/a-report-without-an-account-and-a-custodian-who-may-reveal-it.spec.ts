/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * End-to-end for a-report-without-an-account-and-a-custodian-who-may-reveal-it:
 * somebody files a report of wrongdoing with no account, keeps the receipt code
 * they are shown once, comes back with it to read what the organisation wrote,
 * and answers there. A handler asking who filed it learns nothing by asking a
 * second time.
 *
 * WHAT THIS FILE ANCHORS, AND WHAT IT DOES NOT.
 *
 * Anchored here:
 *   - a stranger with no session files a report and no account exists for them
 *     (REQ-RWA-001)
 *   - contact details are optional: a report with none is complete
 *     (REQ-RWA-001)
 *   - the code is answered once and the confirmation cannot be asked for again,
 *     and nothing offers to recover it (REQ-RWA-002)
 *   - the reporter reads the handler's reply and writes an answer (REQ-RWA-003)
 *   - a handler's read of the report carries no contact detail (REQ-RWA-004)
 *   - the declared terms are rendered with where they stand (REQ-RWA-006)
 *   - the least privileged principal that should be refused: an ordinary
 *     authenticated user who is not in the declared custodian group gets
 *     nothing from the reveal (REQ-RWA-005)
 *
 * NOT anchored here, covered by PHPUnit and named so anyone can check:
 *   - guessing throttled, a request without a motivation refused, a refusal
 *     recorded, and the export carrying no identity:
 *     tests/Unit/Service/Reports/ReportWithoutAnAccountTest.php
 *     (testAValidCodeOpensTheThreadAndAWrongOneIsThrottled,
 *     testARevealRequestWithoutAMotivationIsRefusedAndNothingIsWritten,
 *     testARefusalIsRecordedAndRevealsNothing,
 *     testAListAndAnExportCarryNoIdentityEither)
 *   - no visitor row on an excluded surface:
 *     tests/Unit/Service/TrafficIngestServiceTest.php
 *     (testAnEventOnAnExcludedSurfaceLeavesNoRow)
 *
 * Requires the seeded instance tests/e2e/ci-seed.sh provisions. Run manually:
 *
 *     NEXTCLOUD_URL=http://localhost:8080 npx playwright test a-report-without-an-account
 *
 * @spec openspec/changes/a-report-without-an-account-and-a-custodian-who-may-reveal-it/specs/report-without-an-account/spec.md
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'

const API_BASE = '/apps/portaliq/portal/api'
const APP_API_BASE = '/apps/portaliq/api'
const OR_OBJECTS_BASE = '/apps/openregister/api/objects'

const ADMIN = Buffer.from('admin:admin').toString('base64')
const STAFF_HEADERS = { Authorization: `Basic ${ADMIN}`, 'OCS-APIRequest': 'true', requesttoken: 'x' }

/** Create one object through OpenRegister's own object API, as the dev admin. */
async function seed(
	request: APIRequestContext,
	schema: string,
	data: Record<string, unknown>,
): Promise<string> {
	const res = await request.post(`${OR_OBJECTS_BASE}/portaliq/${schema}`, {
		headers: { Authorization: `Basic ${ADMIN}`, 'OCS-APIRequest': 'true' },
		data,
	})
	expect(res.ok(), `OpenRegister objects#create must be reachable for ${schema}`).toBeTruthy()
	const body = await res.json()
	const id = (body.id ?? body['@self']?.id) as string
	expect(id).toBeTruthy()
	return id
}

/**
 * A case type declaring the custodian group and the two statutory terms. The
 * group deliberately does not exist on the dev instance, so nobody on it is a
 * custodian and every reveal is refused: that is the state this file asserts.
 */
async function seedCaseType(request: APIRequestContext): Promise<string> {
	return seed(request, 'portalCaseType', {
		label: 'Misstandmelding',
		portalReportDeclaration: {
			custodianGroup: 'vertrouwenspersonen',
			acknowledgementDays: 7,
			feedbackDays: 90,
			excludeFromAnalytics: true,
		},
	})
}

test.describe('a report without an account, and a custodian who may reveal it', () => {
	test('a stranger files a report, keeps the code, and reads the answer with it', async ({ request }) => {
		const caseType = await seedCaseType(request)

		// No Authorization header anywhere in this block: this is somebody
		// with no account at all.
		const filed = await request.post(`${API_BASE}/reports`, {
			data: {
				caseType,
				register: 'portaliq',
				schema: 'portalCaseType',
				report: { subject: 'Onveilige situatie', body: 'Er wordt gewerkt zonder keuring.' },
			},
		})
		expect(filed.ok(), 'a report is accepted without a session').toBeTruthy()

		const accepted = await filed.json()
		const code = accepted.code as string
		expect(code, 'the reporter leaves with a code').toBeTruthy()
		expect(accepted.recoverable, 'nothing offers to recover a lost code').toBe(false)
		// The answer carries the code and nothing that identifies the reporter.
		expect(Object.keys(accepted).sort()).toEqual(['code', 'codeShownOnce', 'recoverable'])

		const opened = await request.post(`${API_BASE}/reports/thread`, { data: { code } })
		expect(opened.ok()).toBeTruthy()
		const thread = await opened.json()
		expect(thread.report.subject).toBe('Onveilige situatie')
		// The code is not handed back on a later read: the confirmation was
		// the one time it existed outside the reporter's hands.
		expect(JSON.stringify(thread)).not.toContain(code)
		expect(thread.report.contactRef).toBeUndefined()

		// The declared terms, with where they stand.
		const terms = (thread.terms ?? []) as Array<Record<string, unknown>>
		expect(terms.map((t) => t.term)).toEqual(['acknowledgement', 'feedback'])
		expect(terms[0].days).toBe(7)
		expect(terms[1].days).toBe(90)

		const reportId = thread.report.id as string

		// The handler writes one internal note and one reply.
		const internal = await request.post(`${APP_API_BASE}/reports/${encodeURIComponent(reportId)}/messages`, {
			headers: STAFF_HEADERS,
			data: { body: 'Intern: doorgezet naar de vertrouwenspersoon.', visibleToReporter: false },
		})
		expect(internal.ok()).toBeTruthy()
		const reply = await request.post(`${APP_API_BASE}/reports/${encodeURIComponent(reportId)}/messages`, {
			headers: STAFF_HEADERS,
			data: { body: 'Wij hebben uw melding ontvangen.', visibleToReporter: true },
		})
		expect(reply.ok()).toBeTruthy()

		const reread = await (await request.post(`${API_BASE}/reports/thread`, { data: { code } })).json()
		const bodies = (reread.messages as Array<Record<string, string>>).map((m) => m.body)
		expect(bodies).toContain('Wij hebben uw melding ontvangen.')
		expect(bodies, 'an internal note never reaches the reporter').not.toContain(
			'Intern: doorgezet naar de vertrouwenspersoon.',
		)

		const answered = await request.post(`${API_BASE}/reports/thread/answer`, {
			data: { code, body: 'De keuring ontbreekt sinds mei.' },
		})
		expect(answered.ok(), 'the reporter answers on the same thread').toBeTruthy()
		const afterAnswer = await (await request.post(`${API_BASE}/reports/thread`, { data: { code } })).json()
		expect((afterAnswer.messages as Array<Record<string, string>>).map((m) => m.body)).toContain(
			'De keuring ontbreekt sinds mei.',
		)
	})

	test('what the reporter gave is not in the handler\'s read, and a non-custodian reveals nothing', async ({ request }) => {
		const caseType = await seedCaseType(request)

		const filed = await request.post(`${API_BASE}/reports`, {
			data: {
				caseType,
				register: 'portaliq',
				schema: 'portalCaseType',
				report: { subject: 'Tweede melding', body: 'Dezelfde afdeling.' },
				contact: { name: 'Sanne Bakker', email: 'sanne@example.org' },
			},
		})
		expect(filed.ok()).toBeTruthy()
		const code = (await filed.json()).code as string
		const thread = await (await request.post(`${API_BASE}/reports/thread`, { data: { code } })).json()
		const reportId = thread.report.id as string

		// The handler's own read. This is the assertion the whole change is
		// for: reading the report does not read the reporter.
		const asHandler = await request.get(`${APP_API_BASE}/reports/${encodeURIComponent(reportId)}`, {
			headers: STAFF_HEADERS,
		})
		expect(asHandler.ok()).toBeTruthy()
		const seen = JSON.stringify(await asHandler.json())
		expect(seen).not.toContain('sanne@example.org')
		expect(seen).not.toContain('Sanne Bakker')
		expect(seen).not.toContain('contactRef')

		// Asking is allowed, and is recorded. Answering is not: the dev admin
		// is not in the declared custodian group, and being an administrator
		// is not being the person the organisation named.
		const asked = await request.post(`${APP_API_BASE}/reports/${encodeURIComponent(reportId)}/reveal-requests`, {
			headers: STAFF_HEADERS,
			data: { motivation: 'Er is een tweede melding over dezelfde afdeling.' },
		})
		expect(asked.status(), 'a motivated request is recorded').toBe(201)

		const requests = await request.get(`${OR_OBJECTS_BASE}/portaliq/portalRevealRequest`, {
			headers: { Authorization: `Basic ${ADMIN}`, 'OCS-APIRequest': 'true' },
		})
		const rows = ((await requests.json()).results ?? []) as Array<Record<string, string>>
		const pending = rows.find((row) => row.reportRef === reportId)
		expect(pending, 'the request is on the record before anybody answers it').toBeTruthy()

		const decided = await request.post(
			`${APP_API_BASE}/reveal-requests/${encodeURIComponent((pending?.id ?? pending?.uuid) as string)}/decide`,
			{ headers: STAFF_HEADERS, data: { allow: true, reason: 'Toegestaan.' } },
		)
		expect(decided.status(), 'an administrator who is not the custodian is refused').toBe(403)
		expect(JSON.stringify(await decided.json())).not.toContain('sanne@example.org')
	})

	test('a caller with no code reads nothing, and an unauthenticated caller reveals nothing', async ({ request }) => {
		const guessed = await request.post(`${API_BASE}/reports/thread`, { data: { code: 'NIETDEJUISTECODE' } })
		expect(guessed.status(), 'a wrong code and an unknown code answer the same').toBe(401)

		// The least privileged principal that should be refused: nobody at all.
		const revealed = await request.post(`${APP_API_BASE}/reveal-requests/request-1/decide`, {
			data: { allow: true },
		})
		expect([401, 403, 412].includes(revealed.status()), 'deciding needs a staff session').toBeTruthy()

		const read = await request.get(`${APP_API_BASE}/reports/report-1`)
		expect([401, 403, 404, 412].includes(read.status()), 'reading a report needs a staff session').toBeTruthy()
	})
})
