/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * End-to-end for anonymous-form-file-upload: a landing-page form with a file
 * field answers a one-time upload token, the file goes to the upload route
 * with it, the field's limits are checked before anything is written, and a
 * token takes one file per field (decision 25 Nov 2026 15:15 CET).
 *
 * The form is seeded here (status active, destination e2efixture/signup, the
 * destination tests/e2e/fixtures/seed-cms.sh imports) and removed afterwards.
 *
 *     NEXTCLOUD_URL=http://localhost:8080 npx playwright test anonymous-form-file-upload
 *
 * @spec openspec/changes/anonymous-form-file-upload/specs/landing-page-provisioning/spec.md
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, request, test } from '@playwright/test'
import { resolveBaseURL } from './base-url.ts'

const BASE = resolveBaseURL()
const PORTAL_API = `${BASE}/index.php/apps/portaliq/portal/api`
const OBJECTS = `${BASE}/index.php/apps/openregister/api/objects`
const SUBMIT_ADDRESS = `${PORTAL_API}/collections/portaliq/landingPageSubmission`
const UPLOAD = `${PORTAL_API}/form-upload`
const TOKEN_HEADER = 'X-Portaliq-Upload-Token'

const ADMIN_USER = process.env.ADMIN_USER ?? process.env.NC_ADMIN_USER ?? 'admin'
const ADMIN_PASS = process.env.ADMIN_PASSWORD ?? process.env.NC_ADMIN_PASS ?? 'admin'

/** A 1x1 transparent PNG. */
const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', 'base64')

/** The smallest PDF a type check reads as one. */
const PDF = Buffer.from('%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n')

/**
 * An admin API context.
 *
 * @return the context; the caller disposes it
 */
async function adminApi(): Promise<APIRequestContext> {
	const basic = Buffer.from(`${ADMIN_USER}:${ADMIN_PASS}`).toString('base64')
	return await request.newContext({
		baseURL: BASE,
		extraHTTPHeaders: { 'OCS-APIRequest': 'true', Authorization: `Basic ${basic}` },
	})
}

/**
 * Send one file to a field with a token.
 *
 * @param anonymous the visitor's context
 * @param token the token, '' for none
 * @param field the field
 * @param file the file
 * @param file.name its name
 * @param file.mimeType its type
 * @param file.buffer its bytes
 * @return the response
 */
async function send(anonymous: APIRequestContext, token: string, field: string, file: { name: string, mimeType: string, buffer: Buffer }) {
	const headers: Record<string, string> = {}
	if (token !== '') {
		headers[TOKEN_HEADER] = token
	}
	return await anonymous.post(`${UPLOAD}/${field}`, { headers, multipart: { file } })
}

test.describe('anonymous-form-file-upload', () => {
	let admin: APIRequestContext
	let formId = ''

	test.beforeAll(async () => {
		admin = await adminApi()
		const created = await admin.post(`${OBJECTS}/portaliq/form`, {
			data: {
				title: 'E2E file upload',
				portal: 'open-tilburg',
				pageRoute: `/campagne/e2e-file-${Date.now()}`,
				status: 'active',
				submitLabel: 'Versturen',
				fields: [
					{ id: 'name', type: 'text', label: 'Naam' },
					{ id: 'photo', type: 'file', label: 'Foto', accept: '.png', maxSizeMb: 1 },
				],
				destination: { register: 'e2efixture', schema: 'signup' },
			},
		})
		expect(created.ok(), `the form must be created: ${await created.text()}`).toBeTruthy()
		const body = await created.json()
		formId = String(body['@self']?.id ?? body.id)
	})

	test.afterAll(async () => {
		if (formId !== '') {
			await admin.delete(`${OBJECTS}/portaliq/form/${formId}`)
		}
		await admin.dispose()
	})

	/**
	 * Submit the seeded form as a visitor and return its upload token.
	 *
	 * @param anonymous the visitor's context
	 * @return the token
	 */
	async function submitted(anonymous: APIRequestContext): Promise<string> {
		const response = await anonymous.post(SUBMIT_ADDRESS, {
			data: { actionId: `submit-${formId}`, name: `Bestand ${Date.now()}`, photo: 'someone-elses-file' },
		})
		expect(response.status(), await response.text()).toBe(200)
		const token = (await response.json()).uploadToken
		expect(typeof token).toBe('string')
		return token as string
	}

	// @e2e landing-page-provisioning::a-visitor-sends-a-file-with-a-landing-page-form
	test('a visitor sends a file with a landing-page form', async ({ request: anonymous }) => {
		const token = await submitted(anonymous)

		const uploaded = await send(anonymous, token, 'photo', { name: 'foto.png', mimeType: 'image/png', buffer: PNG })

		expect(uploaded.status(), await uploaded.text()).toBe(200)
		const body = await uploaded.json()
		expect(body.field).toBe('photo')
		expect(String(body.file.id)).not.toBe('')
	})

	// @e2e landing-page-provisioning::a-token-takes-one-file-per-field
	test('a token takes one file per field', async ({ request: anonymous }) => {
		const token = await submitted(anonymous)
		expect((await send(anonymous, token, 'photo', { name: 'een.png', mimeType: 'image/png', buffer: PNG })).status()).toBe(200)

		const again = await send(anonymous, token, 'photo', { name: 'twee.png', mimeType: 'image/png', buffer: PNG })

		expect(again.status()).toBe(403)
	})

	// @e2e landing-page-provisioning::a-file-the-field-does-not-take-is-refused-before-it-is-written
	test('a file the field does not take is refused before it is written', async ({ request: anonymous }) => {
		const token = await submitted(anonymous)

		const refused = await send(anonymous, token, 'photo', { name: 'brief.pdf', mimeType: 'application/pdf', buffer: PDF })
		expect(refused.status()).toBe(415)

		// The refusal did not use the upload.
		expect((await send(anonymous, token, 'photo', { name: 'foto.png', mimeType: 'image/png', buffer: PNG })).status()).toBe(200)
	})

	// @e2e landing-page-provisioning::an-upload-without-a-valid-token-is-refused
	test('an upload without a valid token is refused', async ({ request: anonymous }) => {
		for (const token of ['', 'nobody-minted-this-token']) {
			const response = await send(anonymous, token, 'photo', { name: 'foto.png', mimeType: 'image/png', buffer: PNG })
			expect(response.status()).toBe(403)
			expect(await response.json()).toEqual({ error: 'upload_not_allowed' })
		}
	})
})
