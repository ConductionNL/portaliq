/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * site-mijn-widgets: the Mijn omgeving widgets on a CMS page, signed out
 * (site-nlds-widget-palette T9, REQ-SNW-012). Each shows a sign-in prompt
 * and sends no subject request: no case list, no tasks, no inbox, no
 * collection read.
 *
 * It seeds one published grid page with every signed-in widget, opens it as
 * an anonymous visitor in an empty context, records every request the page
 * makes, and deletes the page afterwards.
 *
 * Environment (all optional): PORTALIQ_E2E_ADMIN (default admin:admin).
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { oneOf, SITE_PORTAL, siteAddress } from './portal-nav.ts'

const OR_OBJECTS_BASE = '/apps/openregister/api/objects'
const ADMIN = Buffer.from(process.env.PORTALIQ_E2E_ADMIN || 'admin:admin').toString(
	'base64',
)
const ADMIN_HEADERS = { Authorization: `Basic ${ADMIN}`, 'OCS-APIRequest': 'true' }

/** The widgets that show the visitor's own data. */
const SIGNED_IN = [
	'nlCases',
	'nlTasks',
	'nlInbox',
	'nlTimeline',
	'nlFigures',
	'nlCalendar',
	'nlRecordSwitcher',
]

/** A request that reads a subject's data, which a signed-out page must not send. */
const SUBJECT_READ =
	/\/api\/portal\/(objects|my-cases|tasks|inbox|contributions|timeline)\b|\/api\/objects\/[^/]+\/[^/]+\?collection=/

/** What this run created, as `schema/id`, to delete afterwards. */
const created: string[] = []

/**
 * Seed one published grid page with every signed-in widget.
 *
 * @param request The admin request context.
 * @return The page's route.
 */
async function seedPage(request: APIRequestContext): Promise<string> {
	const stamp = Date.now()
	const route = `/e2e/mijn-widgets-${stamp}`
	const res = await request.post(`${OR_OBJECTS_BASE}/portaliq/page`, {
		headers: ADMIN_HEADERS,
		data: {
			title: `Mijn widgets ${stamp}`,
			route,
			portal: SITE_PORTAL,
			status: 'published',
			locale: 'nl',
			summary: 'Testpagina met de Mijn omgeving widgets.',
			body: {
				type: 'grid',
				widgets: SIGNED_IN.map((widgetKey, index) => ({
					id: widgetKey,
					widgetKey,
					slot: 'body',
					gridX: 0,
					gridY: index * 3,
					gridWidth: 12,
					gridHeight: 3,
					props: {
						app: 'dossiq',
						collection: 'mijn-zaken',
						page: 'overzicht',
					},
				})),
			},
		},
	})
	expect(
		res.ok(),
		'OpenRegister objects#create must be reachable for page',
	).toBeTruthy()
	const body = await res.json()
	const id = (body.id ?? body['@self']?.id) as string
	expect(id).toBeTruthy()
	created.push(`page/${id}`)
	return route
}

test.describe('site-mijn-widgets: signed out', () => {
	test.afterAll(async ({ request }) => {
		for (const ref of created.splice(0).reverse()) {
			await request.delete(`${OR_OBJECTS_BASE}/portaliq/${ref}`, {
				headers: ADMIN_HEADERS,
			})
		}
	})

	// @e2e portaliq-cms::a-case-list-on-a-cms-page-signed-out
	test('each Mijn omgeving widget shows a sign-in prompt and sends no subject request', async ({
		browser,
		request,
	}) => {
		const route = await seedPage(request)
		const context = await browser.newContext({
			storageState: { cookies: [], origins: [] },
		})
		const page = await context.newPage()
		const reads: string[] = []
		page.on('request', (sent) => {
			if (SUBJECT_READ.test(sent.url())) {
				reads.push(sent.url())
			}
		})

		await page.goto(siteAddress(route))
		await page.waitForLoadState('domcontentloaded')

		for (const key of SIGNED_IN) {
			const cell = page.getByTestId(`widget-${key}`)
			await expect(
				cell.getByTestId('mijn-sign-in-prompt'),
				`${key} prompts`,
			).toBeVisible()
			await expect(cell).toContainText(
				oneOf('Log in om dit te zien.', 'Sign in to see this.'),
			)
		}
		await expect(page.getByTestId('mijn-cases-block')).toHaveCount(0)
		expect(reads, 'a signed-out page reads no subject data').toEqual([])

		await context.close()
	})
})
