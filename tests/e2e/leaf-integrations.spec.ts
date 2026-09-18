/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * End-to-end for leaf-integrations: three integration leaves render on the
 * internal staff pages, and none of them, nor anything they create, reaches a
 * portal visitor.
 *
 * WHAT THIS FILE ANCHORS, AND WHAT IT DOES NOT.
 *
 * Anchored here, against a running instance, because it is the only place the
 * question "did it reach a page" can actually be asked:
 *   - the staff bundle loads and installs the integration registry, and the
 *     three adopted ids are in it with a widget (the two halves ON a page)
 *   - the submissions surface exists and lists facts, not payloadCopy
 *   - a portal visitor's content and inbox responses carry no integrationId,
 *     no Talk join URL and no Forms share URL
 *
 * The probe for the boundary uses the LEAST privileged principal that should
 * still get an answer: an anonymous request to the public content endpoints,
 * and a portal bearer session for the inbox. An admin request proves nothing
 * here, because an admin is exactly the principal a leaf is FOR.
 *
 * NOT anchored here, covered elsewhere and named so anyone can check:
 *   - the projection that strips a leaf-shaped widget:
 *     tests/Unit/Service/CmsReaderTest.php
 *     (testALeafShapedWidgetIsStrippedFromAVisitorResponse, and its control
 *     testTheProjectionStillServesTheWidgetItStripped)
 *   - the declaration halves agreeing and the bootstrap being present:
 *     tests/leaf-integrations.spec.mjs (npm run check:leaf-integrations)
 *
 * Requires the seeded instance tests/e2e/ci-seed.sh provisions. Run manually:
 *
 *     NEXTCLOUD_URL=http://localhost:8080 npx playwright test leaf-integrations
 *
 * @spec openspec/changes/leaf-integrations/specs/portaliq-leaf-integrations/spec.md
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'

const API_BASE = '/apps/portaliq/portal/api'
const OR_OBJECTS_BASE = '/apps/openregister/api/objects'

const ADMIN = Buffer.from('admin:admin').toString('base64')
const ORGANISATION = 'dev-org'

/** The three leaves this app adopts, and the page each one sits on. */
const ADOPTED = [
	{ id: 'forms', route: 'submissions', page: 'PortalSubmissionDetail' },
	{ id: 'talk', route: 'messages', page: 'PortalMessageDetail' },
	{ id: 'calendar', route: 'accounts', page: 'PortalAccountDetail' },
]

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
	expect(
		res.ok(),
		`OpenRegister objects#create must be reachable for ${schema}`,
	).toBeTruthy()
	const body = await res.json()
	const id = (body.id ?? body['@self']?.id) as string
	expect(id).toBeTruthy()
	return id
}

test.describe('leaf-integrations', () => {
	// REQ: the forms/talk/calendar leaves, and "a missing app is visible, not
	// invisible". The registry is the authority for whether a leaf reached the
	// page: the DOM can only say a card is absent, which is equally what an
	// uninstalled Nextcloud app looks like.
	test('the staff bundle registers the three adopted leaves', async ({ page }) => {
		await page.goto('/apps/portaliq/accounts')
		await page.waitForFunction(
			() => (window as never as Record<string, never>).OCA !== undefined,
			null,
			{ timeout: 20000 },
		)

		const registered = await page.evaluate(() => {
			const registry = (window as never as Record<string, never>).OCA
				?.OpenRegister?.integrations as never as
				{ list?: () => Array<{ id: string; widget?: unknown }> } | undefined
			if (registry?.list === undefined) {
				return null
			}
			return registry.list().map((entry) => entry.id)
		})

		expect(
			registered,
			'window.OCA.OpenRegister.integrations is absent, so src/main.js did not '
				+ 'install the registry. Every integration widget in the manifest then '
				+ 'resolves to null and renders nothing, silently.',
		).not.toBeNull()

		for (const leaf of ADOPTED) {
			expect(
				registered,
				`the "${leaf.id}" leaf is declared on ${leaf.page} but is not in the `
					+ 'registry on this page, so that widget is dark',
			).toContain(leaf.id)
		}
	})

	// REQ: the submissions surface exists, and shows facts rather than payloads.
	test('a submission is visible to staff, without its payload in the list', async ({
		page,
		request,
	}) => {
		const stamp = Date.now()
		const subjectRef = `subject-${stamp}`
		await seed(request, 'portalSubmission', {
			subjectRef,
			organisation: ORGANISATION,
			appId: 'dossiq',
			actionId: `melding-${stamp}`,
			submittedAt: new Date().toISOString(),
			deliveryStatus: 'delivered',
			payloadCopy: { geheimVeld: `payload-${stamp}` },
		})

		await page.goto('/apps/portaliq/submissions')
		await expect(page.getByText(`melding-${stamp}`)).toBeVisible({
			timeout: 20000,
		})

		// payloadCopy is whatever a citizen typed, and a list renders many rows
		// at once. It belongs on the detail page, where reading one record is
		// the intent.
		await expect(page.getByText(`payload-${stamp}`)).toHaveCount(0)
	})

	// REQ: integration leaves render on the internal staff side only, probed by
	// the least privileged principal there is.
	test('an anonymous visitor is served no leaf and no leaf artifact', async ({
		request,
	}) => {
		for (const kind of ['site', 'pages', 'contributions']) {
			const res = await request.get(`${API_BASE}/${kind}`)
			if (res.status() === 404) {
				// No portal is configured on this instance for this endpoint;
				// a 404 is the honest answer and carries no payload to inspect.
				continue
			}
			expect(res.ok(), `${kind} must answer an anonymous visitor`).toBeTruthy()

			const body = JSON.stringify(await res.json())
			expect(
				body,
				`${kind} served an integrationId to an anonymous visitor. A leaf is a `
					+ 'Nextcloud component for a Nextcloud user (ADR-046).',
			).not.toContain('integrationId')
			expect(body, `${kind} served a Talk join URL`).not.toContain('/call/')
			expect(body, `${kind} served a Forms share URL`).not.toContain(
				'/apps/forms/',
			)
			expect(body, `${kind} served a Calendar app URL`).not.toContain(
				'/apps/calendar/',
			)
		}
	})

	// REQ: the visitor's reply path stays the portal edge. A portal bearer
	// session is a portal session, never an entry into the Nextcloud shell.
	test('a portal session is not a way into a Nextcloud app', async ({
		request,
	}) => {
		const stamp = Date.now()
		const subjectRef = `subject-${stamp}`
		await seed(request, 'portalMessage', {
			subjectRef,
			organisation: ORGANISATION,
			subject: `Bericht ${stamp}`,
			body: 'Uw aanvraag is ontvangen.',
			read: false,
			receivedAt: new Date().toISOString(),
		})

		const login = await request.post(`${API_BASE}/session/dev-login`, {
			data: { subjectRef, audience: 'client', organisation: ORGANISATION },
		})
		expect(
			login.ok(),
			'dev-login must be enabled (see tests/e2e/ci-seed.sh)',
		).toBeTruthy()
		const { token } = await login.json()

		const inbox = await request.get(`${API_BASE}/inbox`, {
			headers: { Authorization: `Bearer ${token}` },
		})
		expect(
			inbox.ok(),
			'the portal inbox must answer a portal session',
		).toBeTruthy()

		const body = JSON.stringify(await inbox.json())
		expect(body, 'the message the visitor reads must be there at all').toContain(
			`Bericht ${stamp}`,
		)
		expect(
			body,
			'the portal inbox carried an integrationId. The staff conversation about '
				+ 'a message is a Talk room between employees; the citizen gets a '
				+ 'portalMessage, not an invitation.',
		).not.toContain('integrationId')
		expect(body, 'the portal inbox carried a Talk join URL').not.toContain(
			'/call/',
		)

		// The staff surface itself must refuse this principal outright: a portal
		// bearer is not a Nextcloud session.
		const staff = await request.get('/apps/portaliq/api/proposals', {
			headers: { Authorization: `Bearer ${token}` },
		})
		expect(
			staff.ok(),
			'a portal bearer token reached an internal staff endpoint',
		).toBeFalsy()
	})
})
