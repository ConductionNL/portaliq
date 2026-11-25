/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * mail-templates-admin-screen (T07): an administrator edits the text of a
 * portal mail, previews it, sends a test mail to their own address, puts the
 * default text back, and sends a failed mail again from the log.
 *
 * WHAT THIS DRIVES. The admin endpoints of MailTemplateAdminController
 * (`/api/mail-templates`, `/preview`, `/test`, `/api/mail-log/{id}/resend`),
 * the `portalMailTemplate` and `portalMailLog` rows through OpenRegister's
 * object API as the Nextcloud admin, and the two admin index pages
 * (`#/mail-templates`, `#/mail-log`) that list them.
 *
 * WHAT DIFFERS FROM THE SCENARIOS. The scenarios speak of a "status mail"
 * with `{voornaam}`. The kinds that were built (MailTemplateRendererInterface::TEMPLATES)
 * name no status mail and no first-name variable: no variable carries a
 * record's field value. So the edit uses the `invitation` kind and its
 * `{portal}` variable, and the unknown variable is `{besluit}` as in
 * REQ-PMT-002. The editor dialog's chips and the preview panel are not built
 * (T06), so preview and the unknown-variable refusal are driven through the
 * preview endpoint the dialog calls. The log row that failed is seeded
 * directly: making a real delivery fail needs a broken mail transport.
 *
 * The test mail needs a working mail transport and an admin with an e-mail
 * address, as identity-staff-account-screens.spec.ts does. Written, not run
 * here (needs a live instance):
 *
 *     PLAYWRIGHT_BASE_URL=http://localhost:8095 npx playwright test mail-templates-admin-screen
 *
 * @spec openspec/changes/mail-templates-admin-screen/specs/portal-mail-templates/spec.md
 */

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, request, test } from '@playwright/test'
import { resolveBaseURL } from './base-url.ts'

const BASE = resolveBaseURL()
const APP = `${BASE}/index.php/apps/portaliq`
const OBJECTS = `${BASE}/index.php/apps/openregister/api/objects/portaliq`

const ADMIN_USER = process.env.ADMIN_USER ?? process.env.NC_ADMIN_USER ?? 'admin'
const ADMIN_PASS = process.env.ADMIN_PASSWORD ?? process.env.NC_ADMIN_PASS ?? 'admin'

/** The seeded portal (fixtures/seed-cms.sh). */
const PORTAL = process.env.PORTALIQ_E2E_PORTAL || 'open-tilburg'

const STAMP = Date.now()

/** The text this run stores, so no earlier row matches it. */
const SUBJECT = `Uitnodiging e2e ${STAMP}`
const BODY = `Beste bewoner van {portal}, u bent uitgenodigd (${STAMP}). Open {link}.`

/** The rows this run created, by schema and id, removed in afterAll. */
const created: { schema: string; id: string }[] = []

/** One row as OpenRegister answers it. */
type Row = Record<string, unknown>

/**
 * An admin API context, Basic auth with the OCS header so Nextcloud skips
 * its CSRF check.
 *
 * @return the context; the caller disposes it
 */
async function adminApi(): Promise<APIRequestContext> {
	const basic = Buffer.from(`${ADMIN_USER}:${ADMIN_PASS}`).toString('base64')
	return await request.newContext({
		baseURL: BASE,
		extraHTTPHeaders: {
			'OCS-APIRequest': 'true',
			Authorization: `Basic ${basic}`,
			'Content-Type': 'application/json',
		},
	})
}

/**
 * The id of a row.
 *
 * @param row the row
 * @return its id
 */
function idOf(row: Row): string {
	const self = (row['@self'] ?? {}) as Row
	return String(self.id ?? row.id ?? row.uuid ?? '')
}

/**
 * Create one row and remember it for cleanup.
 *
 * @param api    the admin context
 * @param schema the schema slug
 * @param data   the row
 * @return its id
 */
async function createRow(
	api: APIRequestContext,
	schema: string,
	data: Row,
): Promise<string> {
	const response = await api.post(`${OBJECTS}/${schema}`, { data })
	expect(
		response.ok(),
		`creating a ${schema} row: ${response.status()} ${await response.text()}`,
	).toBe(true)
	const id = idOf(await response.json())
	expect(id, `the ${schema} row has an id`).not.toBe('')
	created.push({ schema, id })
	return id
}

/**
 * The rows of one schema.
 *
 * @param api    the admin context
 * @param schema the schema slug
 * @return the rows
 */
async function rowsOf(api: APIRequestContext, schema: string): Promise<Row[]> {
	const listed = await api.get(`${OBJECTS}/${schema}?_limit=500`)
	expect(listed.ok(), await listed.text()).toBe(true)
	const body = await listed.json()
	return Array.isArray(body) ? body : (body.results ?? [])
}

/**
 * Sign the browser in as the Nextcloud admin.
 *
 * @param page the page
 */
async function signIn(page: Page): Promise<void> {
	await page.goto('/login')
	await page.fill('#user', ADMIN_USER)
	await page.fill('#password', ADMIN_PASS)
	await page.click('button[type=submit]')
	await page.waitForURL((url) => !url.pathname.endsWith('/login'))
}

test.describe('mail-templates-admin-screen', () => {
	let api: APIRequestContext

	test.beforeAll(async () => {
		api = await adminApi()
	})

	test.afterAll(async () => {
		for (const row of created.reverse()) {
			await api.delete(`${OBJECTS}/${row.schema}/${row.id}`)
		}
		await api.dispose()
	})

	test('the kinds of mail name their own variables', async () => {
		const response = await api.get(`${APP}/api/mail-templates`)
		expect(response.status()).toBe(200)
		const kinds = ((await response.json()).kinds ?? []) as {
			key: string
			variables: string[]
		}[]
		const invitation = kinds.find((kind) => kind.key === 'invitation')
		expect(invitation, 'the invitation kind is listed').toBeTruthy()
		expect(invitation?.variables).toEqual(
			expect.arrayContaining(['portal', 'link']),
		)
	})

	test('A changed mail: the preview fills its variables with sample values', async () => {
		const response = await api.post(`${APP}/api/mail-templates/preview`, {
			data: { templateKey: 'invitation', subject: SUBJECT, body: BODY },
		})
		expect(response.status()).toBe(200)
		const preview = await response.json()
		expect(preview.ok).toBe(true)
		expect(preview.unknownVariables).toEqual([])
		expect(preview.body).toContain('Beste bewoner van Gemeente Voorbeeld')
		expect(preview.body).not.toContain('{portal}')
	})

	test('An unknown variable: the text is refused and names the variable', async () => {
		const preview = await api.post(`${APP}/api/mail-templates/preview`, {
			data: {
				templateKey: 'invitation',
				subject: SUBJECT,
				body: 'Het {besluit} is genomen.',
			},
		})
		expect(preview.status()).toBe(200)
		const answer = await preview.json()
		expect(answer.ok).toBe(false)
		expect(answer.unknownVariables).toContain('besluit')

		// A text with an unknown variable is never sent, not even as a test.
		const tried = await api.post(`${APP}/api/mail-templates/test`, {
			data: {
				templateKey: 'invitation',
				subject: SUBJECT,
				body: 'Het {besluit} is genomen.',
			},
		})
		expect(tried.status()).toBe(400)
		expect((await tried.json()).error).toBe('unknown_variable')
	})

	test("Test before saving: the test mail goes to the admin's own address only", async () => {
		const response = await api.post(`${APP}/api/mail-templates/test`, {
			// An address in the request is not a recipient: the endpoint reads
			// the signed-in admin's own address.
			data: {
				templateKey: 'invitation',
				subject: SUBJECT,
				body: BODY,
				to: 'iemand@example.org',
			},
		})
		expect(
			response.status(),
			'the instance needs a working mail transport and an admin address',
		).toBe(200)
		const sent = await response.json()
		expect(sent.sent).toBe(true)
		expect(String(sent.to)).toContain('***')
		expect(String(sent.to)).not.toContain('example.org')
	})

	test('A changed mail is listed on the templates page, and the default comes back on reset', async ({
		page,
	}) => {
		const id = await createRow(api, 'portalMailTemplate', {
			portal: PORTAL,
			templateKey: 'invitation',
			subject: SUBJECT,
			body: BODY,
			active: true,
		})

		await signIn(page)
		await page.goto(`${APP}/mail-templates`)
		await expect(
			page.getByRole('row', { name: new RegExp(SUBJECT) }),
		).toBeVisible({ timeout: 30_000 })

		// "Back to the default text" removes the stored row.
		const removed = await api.delete(`${OBJECTS}/portalMailTemplate/${id}`)
		expect(removed.ok(), await removed.text()).toBe(true)
		created.splice(
			created.findIndex((row) => row.id === id),
			1,
		)

		expect(
			(await rowsOf(api, 'portalMailTemplate')).some(
				(row) => row.subject === SUBJECT,
			),
		).toBe(false)
		await page.reload()
		await expect(
			page.getByRole('row', { name: new RegExp(SUBJECT) }),
		).toHaveCount(0)
	})

	test('Resending a failed mail queues a new row linked to the failed one', async ({
		page,
	}) => {
		const failed = await createRow(api, 'portalMailLog', {
			kind: 'mail',
			portal: PORTAL,
			templateKey: 'invitation',
			recipientMasked: 's***@example.nl',
			caseRef: `E2E-${STAMP}`,
			status: 'failed',
			failureReason: 'mailbox vol',
			sentAt: new Date().toISOString(),
		})

		await signIn(page)
		await page.goto(`${APP}/mail-log`)
		const row = page.getByRole('row', { name: new RegExp(`E2E-${STAMP}`) })
		await expect(row).toBeVisible({ timeout: 30_000 })
		// The status reads in the reader's language (REQ-PMT-003 filters on Mislukt).
		await expect(row).toContainText(/failed|mislukt/i)

		const resend = await api.post(`${APP}/api/mail-log/${failed}/resend`)
		expect(resend.status()).toBe(202)
		expect((await resend.json()).status).toBe('queued')

		const retries = (await rowsOf(api, 'portalMailLog')).filter(
			(log) => log.retryOf === failed,
		)
		expect(retries).toHaveLength(1)
		expect(retries[0].status).toBe('queued')
		created.push({ schema: 'portalMailLog', id: idOf(retries[0]) })
	})

	test('a mail that did not fail is not sent again', async () => {
		const delivered = await createRow(api, 'portalMailLog', {
			kind: 'mail',
			portal: PORTAL,
			templateKey: 'invitation',
			recipientMasked: 's***@example.nl',
			status: 'delivered',
			sentAt: new Date().toISOString(),
		})
		const resend = await api.post(`${APP}/api/mail-log/${delivered}/resend`)
		expect(resend.status()).toBe(409)
		expect((await resend.json()).error).toBe('not_failed')
	})
})
