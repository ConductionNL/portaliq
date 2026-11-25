/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * catalogue-entry-shows-costs-and-conditions (T05): a visitor browsing the
 * intake catalogue reads what a request costs under its summary, as drawn on
 * the boards portaliq/PtProducten and portaliq/ProductPagina.
 *
 * Only the scenario tagged for e2e is here, "A parking permit with a fee"
 * (REQ-CSC-001). "No fee declared" and "Conditions as a list" are rendering
 * rules, `@e2e exclude` in the spec and pinned by
 * tests/intake-catalogue-costs.spec.mjs; the server's fee and conditions
 * shaping is pinned by PortalCatalogueReaderTest.
 *
 * WHAT IS REAL, ALL OF IT. The fee is read on the server, never in the
 * browser: the case type (`portaliq/portalCaseType` with `portalFee`) and the
 * published form binding are created through OpenRegister's object API, the
 * catalogue endpoint (`/portal/api/intake/catalogue`) joins them to the
 * publication, and the site's `intakeCatalogue` block words the fee. Nothing
 * is stubbed with page.route: stubbing the catalogue answer would bypass the
 * very server read this change adds.
 *
 * THE ONE PRECONDITION. The catalogue rows are opencatalogi's publications,
 * kept in OpenRegister under register `opencatalogi`, schema `publication`.
 * The publication is seeded there through OpenRegister's object API, as a
 * draft that is then published (opencatalogi's lifecycle; the instance needs
 * `publication_review_required` off). On an instance without that register, or
 * one that requires a review, the test SKIPS, saying why, rather than passing
 * on a stubbed answer.
 *
 * The visitor is a dev-login resident in a fresh context: the intake routes
 * sit behind the portal session gate, so the catalogue answers 401 signed out. Everything seeded is deleted again afterwards. The base URL comes
 * from tests/e2e/base-url.ts.
 *
 * @spec openspec/changes/catalogue-entry-shows-costs-and-conditions/tasks.md#T05
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, request, test } from '@playwright/test'
import { resolveBaseURL } from './base-url.ts'
import { devLogin, seedSiteSession, SITE_PATH, SITE_PORTAL } from './portal-nav.ts'

test.use({ storageState: { cookies: [], origins: [] } })

const BASE = resolveBaseURL()
const OBJECTS = '/index.php/apps/openregister/api/objects'

/** Nextcloud admin, as exported by the shared quality.yml Playwright step. */
const ADMIN_USER = process.env.ADMIN_USER ?? process.env.NC_ADMIN_USER ?? 'admin'
const ADMIN_PASS = process.env.ADMIN_PASSWORD ?? process.env.NC_ADMIN_PASS ?? 'admin'

/** The entry the scenario names. */
const TITLE = 'Parkeervergunning bewoners'
const SUMMARY = 'Vraag een vergunning aan om in uw eigen wijk te parkeren.'

/** Unique per run, so a dead earlier run cannot collide. */
const STAMP = Date.now()
const TOPIC = `Parkeren ${STAMP}`
const FORM_ROUTE = `aanvragen/parkeervergunning-${STAMP}`
const CATALOGUE_PAGE = `/e2e-producten-${STAMP}`

/** What was created, for cleanup: `<register>/<schema>/<id>`. */
const created: string[] = []

/** The admin API context, opened once. */
let api: APIRequestContext

/**
 * Create one object through OpenRegister's own object API, as the dev admin
 * (seeding only; the browser never holds these credentials).
 *
 * @param register the register slug
 * @param schema the schema slug
 * @param data the object
 * @return the id, or '' when OpenRegister refused it
 */
async function seed(register: string, schema: string, data: Record<string, unknown>): Promise<string> {
	const response = await api.post(`${OBJECTS}/${register}/${schema}`, { data })
	if (!response.ok()) {
		return ''
	}
	const body = await response.json()
	const id = String(body['@self']?.id ?? body.id ?? '')
	if (id !== '') {
		created.push(`${register}/${schema}/${id}`)
	}
	return id
}

/**
 * The destination the binding points at: a binding is published only against
 * a schema a public visitor may create in, so the spec makes its own and
 * removes it afterwards. The catalogue never writes to it.
 */
const DESTINATION = `e2e-parkeren-${STAMP}`
const destinationIds: { schema?: number, register?: number } = {}

/**
 * Create the destination register and schema.
 */
async function seedDestination(): Promise<void> {
	const schema = await api.post('/index.php/apps/openregister/api/schemas', {
		data: {
			title: `E2E ${DESTINATION}`,
			slug: DESTINATION,
			properties: { caseType: { type: 'string' }, subjectRef: { type: 'string' } },
			authorization: { create: ['public'], read: ['admin'] },
		},
	})
	expect(schema.ok(), `the destination schema must be created: ${await schema.text()}`).toBeTruthy()
	destinationIds.schema = (await schema.json()).id
	const register = await api.post('/index.php/apps/openregister/api/registers', {
		data: { title: `E2E ${DESTINATION}`, slug: DESTINATION, schemas: [destinationIds.schema] },
	})
	expect(register.ok(), `the destination register must be created: ${await register.text()}`).toBeTruthy()
	destinationIds.register = (await register.json()).id
}

test.beforeAll(async () => {
	const basic = Buffer.from(`${ADMIN_USER}:${ADMIN_PASS}`).toString('base64')
	api = await request.newContext({
		baseURL: BASE,
		extraHTTPHeaders: { 'OCS-APIRequest': 'true', Authorization: `Basic ${basic}` },
	})
})

test.afterAll(async () => {
	for (const path of created.reverse()) {
		await api.delete(`${OBJECTS}/${path}`)
	}
	if (destinationIds.register) {
		await api.delete(`/index.php/apps/openregister/api/registers/${destinationIds.register}`)
	}
	if (destinationIds.schema) {
		await api.delete(`/index.php/apps/openregister/api/schemas/${destinationIds.schema}`)
	}
	await api.dispose()
})

test.describe('catalogue-entry-shows-costs-and-conditions', () => {
	// REQ-CSC-001 — Scenario: A parking permit with a fee
	test('a parking permit whose case type declares 38.50 EUR shows "Kosten: € 38,50" under its summary', async ({
		page,
	}) => {
		// The case type declares the fee; the portal holds no price of its own.
		const caseType = await seed('portaliq', 'portalCaseType', {
			title: `Parkeervergunning ${STAMP}`,
			portalFee: {
				amount: '38.50',
				currency: 'EUR',
				description: 'Leges parkeervergunning',
				payAction: 'betaalLeges',
			},
		})
		expect(caseType, 'the case type must be created').not.toBe('')

		// The published binding that resolves the entry's route to that case type.
		await seedDestination()
		const binding = await seed('portaliq', 'portalFormBinding', {
			portal: SITE_PORTAL,
			route: FORM_ROUTE,
			typeRegister: 'portaliq',
			typeSchema: 'portalCaseType',
			typeId: caseType,
			audience: 'citizen',
			formRegister: 'buildiq',
			formSchema: 'registrationForm',
			destination: { register: DESTINATION, schema: DESTINATION, subjectField: 'subjectRef' },
			intakeKind: 'hosted',
			status: 'published',
		})
		expect(binding, 'the form binding must be created').not.toBe('')

		// opencatalogi's publication: the catalogue entry itself.
		const publication = await seed('opencatalogi', 'publication', {
			portal: SITE_PORTAL,
			topic: TOPIC,
			title: TITLE,
			summary: SUMMARY,
			route: FORM_ROUTE,
		})
		test.skip(
			publication === '',
			'OpenRegister has no opencatalogi/publication register on this instance; '
				+ 'the catalogue reads its entries there, so this scenario needs opencatalogi installed.',
		)
		// A publication starts as a draft (opencatalogi's lifecycle) and is
		// published in a second step, which opencatalogi allows without a
		// review only while `publication_review_required` is off.
		const published = await api.patch(`${OBJECTS}/opencatalogi/publication/${publication}`, {
			data: { status: 'published' },
		})
		test.skip(
			!published.ok(),
			'opencatalogi refused to publish the entry without a review; run with '
				+ '`occ config:app:set opencatalogi publication_review_required --value=false`. '
				+ (await published.text()).slice(0, 200),
		)

		// A page of the portal that holds the catalogue block.
		const catalogue = await seed('portaliq', 'page', {
			title: 'Producten',
			route: CATALOGUE_PAGE,
			portal: SITE_PORTAL,
			status: 'published',
			locale: 'nl',
			body: {
				type: 'grid',
				widgets: [
					{
						id: 'catalogue',
						widgetKey: 'intakeCatalogue',
						slot: 'body',
						gridX: 0,
						gridY: 0,
						gridWidth: 12,
						gridHeight: 8,
						props: { heading: 'Producten en onderwerpen' },
					},
				],
			},
		})
		expect(catalogue, 'the catalogue page must be created').not.toBe('')

		// The intake routes sit behind the portal session gate
		// (PortalIntakeController is PortalProtected), so the catalogue
		// answers a signed-in resident; signed out it answers 401.
		await seedSiteSession(page, await devLogin(api, `e2e-parkeren-${STAMP}`, 'client', 'dev-org'))
		const params = new URLSearchParams({ portal: SITE_PORTAL, route: CATALOGUE_PAGE })
		await page.goto(`${SITE_PATH}?${params.toString()}`)

		const topic = page.getByTestId('intake-catalogue-topic').filter({ hasText: TOPIC })
		await expect(topic).toBeVisible({ timeout: 30_000 })
		const entry = topic.locator('li', { has: page.getByTestId(`intake-entry-${FORM_ROUTE}`) })
		await expect(entry.getByTestId(`intake-entry-${FORM_ROUTE}`)).toHaveText(TITLE)

		// The server's fee in the portal's language; `\s` takes the
		// non-breaking space Intl puts after the euro sign.
		const cost = entry.getByTestId('intake-entry-cost')
		await expect(cost).toHaveText(/^\s*(Kosten: €\s?38,50|Costs: €38\.50)\s*$/)

		// Under the summary, not above it.
		const summary = entry.locator('.pq-intake-catalogue__summary')
		await expect(summary).toHaveText(SUMMARY)
		const below = await summary.evaluate(
			(node, other) => Boolean(other && node.compareDocumentPosition(other) & Node.DOCUMENT_POSITION_FOLLOWING),
			await cost.elementHandle(),
		)
		expect(below, 'the cost line comes after the summary').toBe(true)

		// The fee changes nothing about which form the entry starts.
		await expect(entry.getByTestId(`intake-entry-${FORM_ROUTE}`)).toHaveAttribute(
			'href',
			new RegExp(`parkeervergunning-${STAMP}`),
		)
	})
})
