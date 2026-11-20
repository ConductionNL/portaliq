/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * woo-dossier-in-my-cases (task 4.1): a resident works on her Woo request in
 * Mijn zaken, as drawn on the board portaliq/ZaakWooVerzoek. She answers the
 * organisation's question on the case page (REQ-WDM-001), reads the case
 * app's sentence where the decision date would be (REQ-WDM-002), and follows
 * the link to what was made public (REQ-WDM-003).
 *
 * WHAT IS REAL. The case is a real `portalCase` row, created through
 * OpenRegister's object API with `termNote` and `resultLink` as the case app
 * writes them, listed by a seeded `portalPage` contribution and read through
 * the real citizen case endpoint, which filters `resultLink` against https
 * and the instance's `trusted_domains`. The case page, its banner and its
 * dates are the real site.
 *
 * WHAT IS STUBBED. The questions live in dossiq's `aanvullingsverzoek`
 * collection and are answered by dossiq's `beantwoordVraag` endpoint; the
 * test instance carries no dossiq. So the question test, and only that one,
 * stubs two browser calls with page.route: the case read is fetched for real
 * and the open question is added to its answer as dossiq's collection would
 * supply it (CaseQuestions), and the row forward to `beantwoordVraag` records
 * what the page sent and answers 200 as dossiq would. The server side of
 * those (scope, open only, the signed subject) is pinned by
 * CitizenCaseControllerTest and CaseQuestionsTest.
 *
 * Each test runs in a fresh context with an empty storage state; the resident
 * signs in through the portal's dev login, never as a Nextcloud admin. The
 * base URL comes from tests/e2e/base-url.ts.
 *
 * @spec openspec/changes/woo-dossier-in-my-cases/tasks.md#4.1
 */

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { BASE_URL } from './base-url.ts'
import { accountLink, devLogin, seedSiteSession, siteAddress } from './portal-nav.ts'

test.use({ storageState: { cookies: [], origins: [] } })

const OR_OBJECTS_BASE = '/apps/openregister/api/objects'
const ADMIN = Buffer.from(
	`${process.env.ADMIN_USER ?? 'admin'}:${process.env.ADMIN_PASSWORD ?? 'admin'}`,
).toString('base64')
const ORGANISATION = process.env.PORTALIQ_E2E_ORG || 'dev-org'

/** The case number the scenarios name. */
const REFERENCE = '2026-0087'

/** The question dossiq asks back on a request that is too broad (Woo art. 4.1 lid 5). */
const QUESTION_SUMMARY = 'Over welke speeltuinen en welke jaren gaat uw verzoek?'
const ANSWER = 'De speeltuinen in Oosthaven, 2023 tot nu'
const QUESTION_ID = 'vraag-2026-0087'

/** The sentence the case app writes on a paused term. */
const TERM_NOTE = 'De termijn staat stil sinds 6 oktober 2026. Hij loopt weer zodra wij uw antwoord hebben.'

/**
 * A host on the instance's `trusted_domains`: the one the suite reaches it on,
 * unless PORTALIQ_E2E_TRUSTED_HOST names another. The scenario's
 * `zuiddrecht.nl` is not trusted on a test instance, so the server would drop
 * it — which is the allow-list doing its job, not what this test is about.
 */
const TRUSTED_HOST = process.env.PORTALIQ_E2E_TRUSTED_HOST || new URL(BASE_URL).hostname
const RESULT_LABEL = 'Bekijk wat openbaar is gemaakt'
const RESULT_URL = `https://${TRUSTED_HOST}/woo/publicaties/${REFERENCE}`

/** dossiq's answer action, as it declares it on its questions collection. */
const ANSWER_ACTION = {
	id: 'beantwoordVraag',
	type: 'endpoint-forward',
	endpoint: '/index.php/apps/dossiq/api/portal/vragen/antwoord',
	rowField: 'requestId',
	fields: ['antwoord'],
	requiredFields: ['antwoord'],
	fieldConfigs: { antwoord: { label: 'Uw antwoord', maxLength: 4000 } },
	submitLabel: 'Antwoord versturen',
}

/**
 * Create one object through OpenRegister's own object API, as the dev admin
 * (seeding only; the browser never holds these credentials).
 *
 * @param request the request fixture
 * @param schema the portaliq schema
 * @param data the object
 * @return the id
 */
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
	return (body.id ?? body['@self']?.id) as string
}

/**
 * A Woo case of a signed-in resident, listed by a seeded "Mijn zaken" page
 * whose cases collection carries the term sentence and the result link, and
 * opened on the case screen.
 *
 * @param request the request fixture
 * @param page the page, not yet navigated
 * @param fields the case app's fields on this case
 * @return the case id
 */
async function openWooCase(
	request: APIRequestContext,
	page: Page,
	fields: Record<string, unknown>,
): Promise<string> {
	const stamp = Date.now()
	await seed(request, 'portalPage', {
		label: 'Mijn zaken',
		audience: 'client',
		status: 'active',
		collections: [
			{
				id: 'cases',
				kind: 'cases',
				label: 'Mijn zaken',
				register: 'portaliq',
				schema: 'portalCase',
				scopeField: 'subjectRef',
				closedField: 'closedAt',
				fields: [
					'reference',
					'status',
					'omschrijving',
					'legalDecisionDate',
					'termNote',
					'resultLink',
				],
			},
		],
		pages: [
			{
				id: 'mijn-zaken',
				label: 'Mijn zaken',
				blocks: [
					{ type: 'collection', collection: 'cases' },
					{ type: 'citizenCase', collection: 'cases' },
				],
			},
		],
	})
	const subjectRef = `subject-woo-${stamp}-${Math.floor(Math.random() * 10000)}`
	const caseId = await seed(request, 'portalCase', {
		subjectRef,
		organisation: ORGANISATION,
		reference: `${REFERENCE}-${stamp}`,
		status: 'in behandeling',
		omschrijving: 'Woo-verzoek speeltuinen',
		...fields,
	})

	const token = await devLogin(request, subjectRef, 'client', ORGANISATION)
	await seedSiteSession(page, token)
	await page.goto(siteAddress())
	// The seeded "Mijn zaken" page, not the shell's own cases section.
	await accountLink(page, 'portaliq/mijn-zaken').first().click()
	await page.getByTestId('collection-table-select').first().click()
	await expect(page.getByTestId('citizen-case')).toBeVisible()
	return caseId
}

test.describe('woo-dossier-in-my-cases', () => {
	// REQ-WDM-001 — Scenario: The resident answers the question on the case
	test('the resident answers the question on the case', async ({ page, request }) => {
		// dossiq's open question on this case, added to the real case read
		// the way CaseQuestions would answer it with dossiq installed. This
		// fresh resident has one case, so every single-case read is hers.
		await page.route('**/citizen/cases/portaliq/portalCase/**', async (route) => {
			if (route.request().method() !== 'GET') {
				await route.fallback()
				return
			}
			const response = await route.fetch()
			const body = await response.json()
			body.questions = [
				{
					id: QUESTION_ID,
					collection: { id: 'vragenAanU', register: 'dossiq', schema: 'aanvullingsverzoek' },
					summary: QUESTION_SUMMARY,
					hersteltermijn: '2026-11-28',
					action: ANSWER_ACTION,
				},
			]
			await route.fulfill({ response, json: body })
		})

		// dossiq's `beantwoordVraag`, reached through the portal's row forward.
		const sent: Array<{ url: string, body: Record<string, unknown> }> = []
		await page.route('**/collections/dossiq/aanvullingsverzoek/**/actions/beantwoordVraag**', async (route) => {
			sent.push({
				url: route.request().url(),
				body: route.request().postDataJSON() as Record<string, unknown>,
			})
			await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true }) })
		})

		await openWooCase(request, page, { termNote: TERM_NOTE })

		const question = page.getByTestId('case-question')
		await expect(question).toBeVisible()
		await expect(question).toContainText(QUESTION_SUMMARY)
		await expect(question).toHaveClass(/utrecht-alert--warning/)

		// Above the status steps, when the screen shows them.
		const status = page.locator('.pq-case-status')
		if ((await status.count()) > 0) {
			const above = await question.evaluate(
				(node, other) => Boolean(other && node.compareDocumentPosition(other) & Node.DOCUMENT_POSITION_FOLLOWING),
				await status.first().elementHandle(),
			)
			expect(above, 'the question comes before the status steps').toBe(true)
		}

		await question.getByLabel('Uw antwoord').fill(ANSWER)
		await question.getByTestId('case-question-send').click()

		const done = page.getByTestId('case-question-sent')
		await expect(done).toHaveText(
			/Wij hebben uw antwoord\. De behandelaar kijkt ernaar\.|We have your answer\. The handler is looking at it\./,
		)
		await expect(page.getByTestId('case-question-form')).toHaveCount(0)

		expect(sent).toHaveLength(1)
		expect(sent[0].url).toContain(`/collections/dossiq/aanvullingsverzoek/${QUESTION_ID}/actions/beantwoordVraag`)
		expect(sent[0].url).toContain('collection=vragenAanU')
		expect(sent[0].body).toEqual({ antwoord: ANSWER })
	})

	// REQ-WDM-002 — Scenario: A paused term shows the case app's sentence instead of a date
	test('a paused term shows the sentence', async ({ page, request }) => {
		await openWooCase(request, page, { termNote: TERM_NOTE })

		const row = page.getByTestId('case-date-termNote')
		await expect(row).toBeVisible()
		await expect(row.locator('dt')).toHaveText(/Uiterlijk klaar op|Ready by/)
		await expect(row.locator('dd')).toHaveText(TERM_NOTE)
		// In the date's place, not under it: there is no date.
		await expect(page.getByTestId('case-date-legalDecisionDate')).toHaveCount(0)
	})

	// REQ-WDM-003 — Scenario: The decision is public and the case links there
	test('the case links to the publication', async ({ page, request }) => {
		await openWooCase(request, page, {
			resultLink: { label: RESULT_LABEL, url: RESULT_URL },
		})

		const link = page.getByTestId('case-result-link').getByRole('link', { name: RESULT_LABEL })
		await expect(link).toBeVisible()
		await expect(link).toHaveAttribute('href', RESULT_URL)
	})
})
