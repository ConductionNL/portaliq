/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A lost visitor finds a way on, and a resident asks a question without a
 * case (contact-page-question-form-and-not-found, T06).
 *
 * The journey the change describes: open an address that does not exist,
 * follow Contact from the not-found page, and send a question signed in.
 *
 * The site under test is the Zuiddrecht example site
 * (`occ portaliq:example-site:install zuiddrecht`), which carries `/contact`
 * and `/contact/vraag` with the `contactForm` block. Name another portal with
 * `PORTALIQ_E2E_CONTACT_PORTAL` when the instance installed it under a
 * different slug.
 *
 * The question itself is answered by pipelinq's `askQuestion` contribution.
 * The test instance carries no pipelinq, so the signed-in test stubs only the
 * browser's two calls to it (the contributions list and the create) and
 * asserts what the page sends; everything else is the real site. Each test
 * runs in a fresh, non-admin context with an empty storage state; the base
 * URL comes from the environment through `tests/e2e/base-url.ts`.
 *
 * @spec openspec/changes/archive/2026-11-25-contact-page-question-form-and-not-found/tasks.md#T06
 */

import type { Page, Request } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { devLogin, seedSiteSession, SITE_PATH } from './portal-nav.ts'

/** The portal that carries the example contact pages. */
const PORTAL = process.env.PORTALIQ_E2E_CONTACT_PORTAL || 'zuiddrecht'

/** A demo instance names its own organisation and audience. */
const ORGANISATION = process.env.PORTALIQ_E2E_ORG || 'dev-org'
const AUDIENCE = process.env.PORTALIQ_E2E_AUDIENCE || 'citizen'

/** The question action the example site's `/contact/vraag` block names. */
const QUESTION_ACTION = {
	id: 'askQuestion',
	type: 'create',
	label: 'Stel een vraag',
	register: 'pipelinq',
	schema: 'ticket',
	fields: ['title', 'description'],
	required: ['title', 'description'],
	fieldConfigs: {
		title: { label: 'Onderwerp' },
		description: { label: 'Uw vraag', input: 'textarea' },
	},
}

// Not-found pages and anonymous visits need no stored login.
test.use({ storageState: { cookies: [], origins: [] } })

/**
 * The site address of one route of the contact portal.
 *
 * @param route the in-site route
 * @return the address
 */
function address(route: string): string {
	const params = new URLSearchParams({ portal: PORTAL, route })
	return `${SITE_PATH}?${params.toString()}`
}

/**
 * Collect every create the page sends to the question's collection.
 *
 * @param page the page
 * @return the requests seen so far, filled as they arrive
 */
function createsOf(page: Page): Request[] {
	const seen: Request[] = []
	page.on('request', (request) => {
		if (
			request.method() === 'POST'
			&& request.url().includes('/collections/pipelinq/ticket')
		) {
			seen.push(request)
		}
	})
	return seen
}

test.describe('contact-page-question-form-and-not-found', () => {
	// @e2e site-not-found::a-mistyped-address
	test('a missing route offers the homepage, the resident area and Contact, and Contact opens the contact page', async ({
		page,
	}) => {
		await page.goto(address('/parkeren-vergunning'))

		const error = page.getByTestId('site-error')
		await expect(error).toBeVisible()
		await expect(page.getByTestId('not-found-code')).toContainText('404')
		await expect(page.getByTestId('not-found-home')).toBeVisible()
		await expect(page.getByTestId('not-found-resident')).toBeVisible()
		await expect(page.getByTestId('not-found-contact')).toBeVisible()
		await expect(page.getByTestId('not-found-report')).toBeVisible()

		await page.getByTestId('not-found-contact').click()
		await expect(page.getByTestId('site-error')).toHaveCount(0)
		await expect(page.getByText('Bellen').first()).toBeVisible()
		await expect(page.getByText('Langskomen').first()).toBeVisible()
	})

	// @e2e site-contact-question::signed-out
	test('signed out, the question form says to sign in and posts nothing', async ({
		page,
	}) => {
		const creates = createsOf(page)
		await page.goto(address('/contact/vraag'))

		const signedOut = page.getByTestId('contact-form-signed-out')
		await expect(signedOut).toBeVisible()
		await expect(signedOut).toContainText(
			/Log in om een vraag te stellen|Log in to ask a question/,
		)
		await expect(page.getByTestId('schema-form')).toHaveCount(0)
		expect(creates).toHaveLength(0)
	})

	// @e2e site-contact-question::a-signed-in-resident-asks-a-question
	test('a signed-in resident sends a question and is told where to find it', async ({
		page,
		request,
	}) => {
		const token = await devLogin(
			request,
			`subject-contact-${Date.now()}`,
			AUDIENCE,
			ORGANISATION,
		)
		await seedSiteSession(page, token)

		await page.route('**/portal/api/contributions', async (route) => {
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					contributions: [{ app: 'pipelinq', actions: [QUESTION_ACTION] }],
				}),
			})
		})
		const sent: Array<Record<string, unknown>> = []
		await page.route('**/collections/pipelinq/ticket**', async (route) => {
			if (route.request().method() !== 'POST') {
				await route.fallback()
				return
			}
			sent.push(route.request().postDataJSON() as Record<string, unknown>)
			await route.fulfill({
				status: 201,
				contentType: 'application/json',
				body: JSON.stringify({
					object: { id: 'q-1', title: 'Afval', ticketType: 'request' },
				}),
			})
		})

		await page.goto(address('/contact/vraag'))
		const form = page.getByTestId('schema-form')
		await expect(form).toBeVisible()
		await form.getByLabel('Onderwerp').fill('Afval')
		await form.getByLabel('Uw vraag').fill('Wanneer wordt het grofvuil opgehaald?')
		await form.getByTestId('schema-form-submit').click()

		const done = page.getByTestId('contact-form-sent')
		await expect(done).toBeVisible()
		await expect(done).toContainText(/Wij hebben uw vraag ontvangen|We have received your question/)
		await expect(page.getByTestId('contact-form-questions')).toHaveAttribute(
			'href',
			/mijn/,
		)
		expect(sent).toHaveLength(1)
		expect(sent[0]).toMatchObject({
			title: 'Afval',
			description: 'Wanneer wordt het grofvuil opgehaald?',
		})
	})
})
