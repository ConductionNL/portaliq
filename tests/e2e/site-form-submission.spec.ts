/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * landing-page-provisioning — a visitor submits a landing page's bound
 * lead-capture form with no portal session, and the submission is recorded.
 *
 * The fixture (tests/e2e/fixtures/seed-cms.sh) seeds the RESULT
 * `LandingPageProvisioningService` would have written — a `form` object and
 * a `page` at /campagne/e2e-form-test with a bound `form` widget — since the
 * seeder has no way to dispatch the PHP `LandingPageRequestedEvent` itself,
 * exactly like every other page fixture in this suite is seeded as an
 * object, not by exercising a write path.
 *
 * See tests/e2e/scenarios/ for the wider phase-two scenario catalogue this
 * file extends.
 */

import { expect, test } from '@playwright/test'
import { resolveBaseURL } from './base-url.ts'

const BASE = resolveBaseURL()
const SITE = `${BASE}/index.php/apps/portaliq/site`
const PORTAL_API = `${BASE}/index.php/apps/portaliq/portal/api`
const REGISTER = 'portaliq'
const SUBMISSION_SCHEMA = 'landingPageSubmission'
const ROUTE = '/campagne/e2e-form-test'

test.describe('site renderer — landing page form submission', () => {
	// @e2e landing-page-provisioning::a-visitor-submits-the-landing-pages-form-anonymously
	// @e2e portaliq-cms::a-landing-page-renders-its-bound-form-and-accepts-a-submission
	test('a visitor submits the landing page form and it is recorded', async ({
		page,
	}) => {
		await page.goto(`${SITE}?route=${ROUTE}`)

		const form = page.getByTestId('site-form')
		await expect(form).toBeVisible()

		// The rendered fields, submitLabel and consentText all come from the
		// fixture's `form` widget props — proving the page's authored config
		// is what the visitor sees, not a hard-coded placeholder.
		await expect(
			form.getByText('Ik ga akkoord met de verwerking van mijn gegevens.'),
		).toBeVisible()

		await form.getByTestId('form-field-name').fill('J. de Vries')
		await form.getByTestId('form-field-email').fill('jane.doe@example.org')
		await form.getByTestId('form-submit').click()

		await expect(page.getByTestId('form-status-success')).toBeVisible()
	})

	// @e2e landing-page-provisioning::first-touch-is-captured-once-last-touch-is-overwritten
	test('first touch is captured once and last touch is overwritten', async ({
		page,
	}) => {
		await page.goto(
			`${SITE}?route=${ROUTE}&utm_campaign=e2e&utm_source=first-link&utm_medium=email`,
		)
		await page.goto(
			`${SITE}?route=${ROUTE}&utm_campaign=e2e&utm_source=second-link&utm_medium=social`,
		)

		const stored = await page.evaluate(() => {
			const read = (key: string) => {
				const raw = window.sessionStorage.getItem(key)
				return raw ? JSON.parse(raw) : null
			}
			return {
				first: read('portaliq:campaign:open-tilburg:first'),
				last: read('portaliq:campaign:open-tilburg:last'),
			}
		})

		expect(stored.first?.source).toBe('first-link')
		expect(stored.last?.source).toBe('second-link')
	})

	// @e2e landing-page-provisioning::a-field-not-declared-on-the-form-is-dropped-never-persisted
	test('a field not declared on the form is dropped before it is persisted', async ({
		request,
	}) => {
		// Direct API call (anonymous, no session) — proves the WHITELIST on the
		// wire, which a UI assertion cannot: the site's own form never offers a
		// way to send an undeclared field, so driving this through the browser
		// would prove the UI is well-behaved, not that the server refuses a
		// hand-crafted body.
		const response = await request.post(
			`${PORTAL_API}/collections/${REGISTER}/${SUBMISSION_SCHEMA}`,
			{
				data: {
					name: 'API test',
					email: 'api-test@example.org',
					isAdmin: true,
					formId: 'client-cannot-override-this',
				},
			},
		)

		expect(response.status()).toBe(200)
		const body = await response.json()

		expect(body.object.name).toBe('API test')
		expect(body.object.email).toBe('api-test@example.org')
		expect(body.object.isAdmin).toBeUndefined()
		// The server-stamped default wins over the client-supplied value.
		expect(body.object.formId).not.toBe('client-cannot-override-this')
	})
})
