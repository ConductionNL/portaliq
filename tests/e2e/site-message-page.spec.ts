/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * a-resident-opens-one-message (T10): the example resident opens the Woo
 * decision from "Berichten" on its own page, sees it marked "Gelezen" and both
 * attachments, and follows "Naar uw zaak"; a message that is not hers shows
 * the not-found sentence and nothing of the message.
 *
 * The board's message (BerichtDetail) is written by a contributing app in
 * production. The spec seeds the same shape itself, so it runs on CI and on a
 * demo instance alike: one portalPage contribution for this run's own audience
 * with an inbox collection that declares `filesDownload` and a cases page the
 * message's record link resolves to, one portalCase, the message with its
 * 2:10 metadata and two files, and a second message of another resident for
 * the foreign id. Everything it seeds is deleted again afterwards.
 *
 * Every run seeds its own audience, because the built-in provider serves ONE
 * portalPage row per audience (see site-mijn-omgeving-live.spec.ts).
 *
 * Environment (all optional): PORTALIQ_E2E_PORTAL (default open-tilburg),
 * PORTALIQ_E2E_ORG (default dev-org), PORTALIQ_E2E_AUDIENCE (the base of this
 * run's audience, default client), PORTALIQ_E2E_ADMIN (default admin:admin).
 * Needs dev-login on the target instance (see portal-nav.ts devLogin()).
 *
 * @spec openspec/changes/a-resident-opens-one-message/tasks.md#T10
 * @spec openspec/changes/a-resident-opens-one-message/specs/portal-message-detail/spec.md
 */

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { devLogin, oneOf, seedSiteSession, siteAddress } from './portal-nav.ts'

const OR_OBJECTS_BASE = '/apps/openregister/api/objects'
const ADMIN = Buffer.from(process.env.PORTALIQ_E2E_ADMIN || 'admin:admin').toString(
	'base64',
)
const ADMIN_HEADERS = { Authorization: `Basic ${ADMIN}`, 'OCS-APIRequest': 'true' }
const ORGANISATION = process.env.PORTALIQ_E2E_ORG || 'dev-org'
const AUDIENCE_BASE = process.env.PORTALIQ_E2E_AUDIENCE || 'client'

const TITLE = 'Het besluit op uw Woo-verzoek is gepubliceerd'
const CASE_TITLE = 'Verlichting fietspad Lindelaan'
const FILES = ['Besluit Woo-verzoek 2026-0082.pdf', 'Inventarislijst.pdf']

/** What this run created, as `schema/id`, to delete afterwards. */
const created: string[] = []

/**
 * Create one object through OpenRegister's own object API, as the admin.
 *
 * @param request The request fixture.
 * @param schema The portaliq schema.
 * @param data The object.
 * @return The id.
 */
async function seed(
	request: APIRequestContext,
	schema: string,
	data: Record<string, unknown>,
): Promise<string> {
	const res = await request.post(`${OR_OBJECTS_BASE}/portaliq/${schema}`, {
		headers: ADMIN_HEADERS,
		data,
	})
	expect(res.ok(), `OpenRegister objects#create must be reachable for ${schema}`).toBeTruthy()
	const body = await res.json()
	const id = (body.id ?? body['@self']?.id) as string
	expect(id).toBeTruthy()
	created.push(`${schema}/${id}`)
	return id
}

/**
 * Attach one file to a portalMessage through OpenRegister's file API.
 *
 * @param request The request fixture.
 * @param id The message id.
 * @param name The file name.
 */
async function attach(request: APIRequestContext, id: string, name: string): Promise<void> {
	const res = await request.post(
		`${OR_OBJECTS_BASE}/portaliq/portalMessage/${id}/files-multipart`,
		{
			headers: ADMIN_HEADERS,
			multipart: {
				file: {
					name,
					mimeType: 'application/pdf',
					buffer: Buffer.from(`%PDF-1.4\n% ${name}\n`),
				},
			},
		},
	)
	expect(res.ok(), `OpenRegister must attach ${name} to the message`).toBeTruthy()
}

/**
 * The contribution, the case, the messages, and the resident signed in.
 *
 * @param request The request fixture.
 * @param page The page.
 * @return The ids the tests look for.
 */
async function signIn(
	request: APIRequestContext,
	page: Page,
): Promise<{ inbox: string; cases: string; message: string; foreign: string }> {
	const stamp = Date.now()
	const audience = `${AUDIENCE_BASE}-message-${stamp}`
	const inbox = `berichten-${stamp}`
	const cases = `zaken-${stamp}`
	await seed(request, 'portalPage', {
		label: `Berichten ${stamp}`,
		audience,
		status: 'active',
		collections: [
			{
				id: inbox,
				kind: 'inbox',
				label: 'Berichten',
				register: 'portaliq',
				schema: 'portalMessage',
				scopeField: 'subjectRef',
				filesDownload: true,
			},
			{
				id: cases,
				kind: 'cases',
				label: 'Zaken',
				register: 'portaliq',
				schema: 'portalCase',
				scopeField: 'subjectRef',
			},
		],
		pages: [
			{
				id: cases,
				label: `Zaken ${stamp}`,
				blocks: [
					{ type: 'collection', collection: cases },
					{ type: 'detail', collection: cases },
				],
			},
		],
	})
	const subjectRef = `subject-message-${stamp}`
	const mine = { subjectRef, organisation: ORGANISATION }
	const caseId = await seed(request, 'portalCase', {
		...mine,
		reference: CASE_TITLE,
		status: 'Besluit genomen',
	})
	const message = await seed(request, 'portalMessage', {
		...mine,
		subject: TITLE,
		body: 'Het besluit op uw Woo-verzoek staat online.',
		read: false,
		receivedAt: new Date().toISOString(),
		nature: 'Besluit op uw Woo-verzoek',
		rechtsgevolg: 'Dit is een besluit van de gemeente.',
		term: 'Bezwaar maken kan binnen zes weken',
		recordLink: { app: 'portaliq', collection: cases, id: caseId, label: CASE_TITLE },
	})
	for (const name of FILES) {
		await attach(request, message, name)
	}
	const foreign = await seed(request, 'portalMessage', {
		subjectRef: `subject-other-${stamp}`,
		organisation: ORGANISATION,
		subject: 'Een bericht voor iemand anders',
		read: false,
		receivedAt: new Date().toISOString(),
	})
	const token = await devLogin(request, subjectRef, audience, ORGANISATION)
	await seedSiteSession(page, token)
	return { inbox, cases, message, foreign }
}

test.describe('a-resident-opens-one-message', () => {
	test.afterAll(async ({ request }) => {
		for (const ref of created.splice(0).reverse()) {
			await request.delete(`${OR_OBJECTS_BASE}/portaliq/${ref}`, {
				headers: ADMIN_HEADERS,
			})
		}
	})

	test('the resident opens the Woo decision, sees it read with both attachments, and goes to the case', async ({
		page,
		request,
	}) => {
		const { inbox, message } = await signIn(request, page)

		await page.goto(siteAddress('/mijn/inbox'))
		await page.waitForLoadState('domcontentloaded')

		// The message's title in the list links to its own page.
		// The href carries the in-site route URL-encoded, so match the two
		// parts that name this message rather than the slash between them.
		const link = page
			.locator(`a[data-testid="inbox-row-title"][href*="${inbox}"][href*="${message}"]`)
			.first()
		await expect(link).toContainText(TITLE)
		await link.click()

		const detail = page.getByTestId('message-page')
		await expect(detail).toBeVisible()
		await expect(page.getByTestId('message-title')).toHaveText(TITLE)
		expect(decodeURIComponent(page.url())).toContain(`/mijn/inbox/${inbox}/${message}`)

		// Opening an unread message marks it read: the badge says so in words.
		await expect(page.getByTestId('message-read-badge')).toContainText(
			oneOf('Gelezen', 'Read'),
		)

		// "Over dit bericht" carries the 2:10 metadata the message supplies.
		const about = page.getByTestId('message-about')
		await expect(about).toContainText('Besluit op uw Woo-verzoek')
		await expect(about).toContainText('Dit is een besluit van de gemeente.')

		// Both attachments, by name.
		const files = page.getByTestId('message-files')
		// oneOf() matches a whole text, so it is held against the heading, not the section.
		await expect(files.getByRole('heading', { level: 2 })).toHaveText(oneOf('Bijlagen', 'Attachments'))
		for (const name of FILES) {
			await expect(files.getByTestId('message-download').filter({ hasText: name })).toBeVisible()
		}

		// The case card names the case; "Naar uw zaak" leaves the message page
		// for the case's page.
		await expect(page.getByTestId('message-case-card')).toContainText(CASE_TITLE)
		await page.getByTestId('message-to-case').click()
		await expect(page.getByTestId('message-page')).toHaveCount(0)
		await expect
			.poll(() => decodeURIComponent(page.url()))
			.not.toContain('/mijn/inbox/')
		await expect(page.getByText(CASE_TITLE).first()).toBeVisible()
	})

	test('a message of another resident shows the not-found sentence and nothing of it', async ({
		page,
		request,
	}) => {
		const { inbox, foreign } = await signIn(request, page)

		await page.goto(siteAddress(`/mijn/inbox/${inbox}/${foreign}`))
		await page.waitForLoadState('domcontentloaded')

		await expect(page.getByTestId('message-not-found')).toContainText(
			oneOf(
				'Dit bericht bestaat niet of is niet voor u.',
				'This message does not exist or is not for you.',
			),
		)
		await expect(page.getByTestId('message-title')).toHaveCount(0)
		await expect(page.getByText('Een bericht voor iemand anders')).toHaveCount(0)
	})
})
