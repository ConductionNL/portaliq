/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * form-flow-repeating-groups-calculations-and-decisions (T08, REQ-FFL-001):
 * the street party form asks the same questions once per neighbour. A
 * resident adds three neighbours by keyboard only; with one neighbour the
 * step is refused with "Voeg nog 1 bewoner toe" linking to the add button;
 * removing "Bewoner 2" renumbers the cards; the add button is gone at the
 * maximum; and the server refuses a neighbour whose required name is empty,
 * naming the item and the field.
 *
 * The spec seeds its own published form (a `group` field with `repeat.min`
 * 2 and `repeat.max` 3, in a step of its own, then a step with the
 * resident's name and the review), its binding and a site page with the
 * intake widget on that binding, all deleted afterwards. The visitor is
 * anonymous and uses the keyboard only.
 *
 * "The server checks each item" is driven through the real submit: the
 * browser's request is changed on its way out so one neighbour's name is
 * empty, which the form itself would not let through.
 *
 * Written, not run here (needs a live instance):
 *
 *     PLAYWRIGHT_BASE_URL=http://localhost:8095 npx playwright test form-flow-repeating-groups
 *
 * Environment (optional): PORTALIQ_E2E_PORTAL, PORTALIQ_E2E_ADMIN.
 *
 * @spec openspec/changes/form-flow-repeating-groups-calculations-and-decisions/specs/portal-form-flow/spec.md#requirement-a-form-can-ask-the-same-questions-once-per-item-req-ffl-001
 */

import type {
	APIRequestContext,
	Browser,
	Locator,
	Page,
	Route,
} from '@playwright/test'

import { expect, test } from '@playwright/test'
import { BASE_URL } from './base-url.ts'
import { SITE_PORTAL, siteAddress } from './portal-nav.ts'

const OR_OBJECTS_BASE = '/apps/openregister/api/objects'
const ADMIN = Buffer.from(process.env.PORTALIQ_E2E_ADMIN || 'admin:admin').toString(
	'base64',
)
const ADMIN_HEADERS = { Authorization: `Basic ${ADMIN}`, 'OCS-APIRequest': 'true' }
const EMPTY_STATE = { cookies: [], origins: [] }

const STAMP = Date.now()
const ROUTE = `aanvragen/straatfeest-${STAMP}`
const PAGE_ROUTE = `/e2e/straatfeest-${STAMP}`

/** The group field's id prefix on the page (`elementId` of field `bewoners`). */
const GROUP = 'intake-field-bewoners'

/** The neighbours a resident adds. */
const NEIGHBOURS = [
	{ naam: 'Anouk Bakker', huisnummer: '12' },
	{ naam: 'Lars de Wit', huisnummer: '14' },
	{ naam: 'Sanne de Vries', huisnummer: '16' },
]

/** What this run created, as `schema/id`, to delete afterwards. */
const created: string[] = []

/**
 * Create one portaliq object through OpenRegister's own object API, as the admin.
 *
 * @param request The request fixture.
 * @param schema The portaliq schema.
 * @param data The object.
 */
async function seed(
	request: APIRequestContext,
	schema: string,
	data: Record<string, unknown>,
): Promise<void> {
	const res = await request.post(`${OR_OBJECTS_BASE}/portaliq/${schema}`, {
		headers: ADMIN_HEADERS,
		data,
	})
	expect(
		res.ok(),
		`OpenRegister objects#create must be reachable for ${schema}: ${await res.text()}`,
	).toBeTruthy()
	const body = await res.json()
	const id = (body.id ?? body['@self']?.id) as string
	expect(id).toBeTruthy()
	created.push(`${schema}/${id}`)
}

/**
 * The street party form, its binding and the page that shows it.
 *
 * @param request The request fixture.
 */
async function seedStreetParty(request: APIRequestContext): Promise<void> {
	const caseType = `straatfeest-${STAMP}`
	await seed(request, 'registrationForm', {
		caseType,
		audience: 'client',
		name: `${caseType}-client`,
		status: 'published',
		fields: [
			{
				name: 'bewoners',
				label: 'Bewoners die meedoen',
				type: 'group',
				order: 1,
				required: true,
				repeat: {
					min: 2,
					max: 3,
					addLabel: 'Nog een bewoner toevoegen',
					itemLabel: 'Bewoner',
					itemsLabel: 'bewoners',
				},
				fields: [
					{ name: 'naam', label: 'Naam', required: true },
					{ name: 'huisnummer', label: 'Huisnummer', required: true },
				],
			},
			{ name: 'aanvrager', label: 'Uw naam', order: 2, required: true },
		],
		steps: [
			{ id: 'buren', title: 'De buren', fields: ['bewoners'] },
			{ id: 'aanvrager', title: 'Uw gegevens', fields: ['aanvrager'] },
			{ id: 'controleren', title: 'Controleren', review: true },
		],
	})
	await seed(request, 'portalFormBinding', {
		portal: SITE_PORTAL,
		route: ROUTE,
		typeRegister: 'portaliq',
		typeSchema: 'portalCaseType',
		typeId: caseType,
		audience: 'client',
		formRegister: 'portaliq',
		formSchema: 'registrationForm',
		destination: { register: 'portaliq', schema: 'portalCase' },
		intakeKind: 'hosted',
		status: 'published',
	})
	await seed(request, 'page', {
		title: `Straatfeest ${STAMP}`,
		route: PAGE_ROUTE,
		portal: SITE_PORTAL,
		status: 'published',
		locale: 'nl',
		summary: 'Testpagina met het straatfeestformulier.',
		body: {
			type: 'grid',
			widgets: [
				{
					id: 'aanvraag',
					widgetKey: 'intakeForm',
					slot: 'body',
					gridX: 0,
					gridY: 0,
					gridWidth: 12,
					gridHeight: 6,
					props: { route: ROUTE },
				},
			],
		},
	})
}

/**
 * A page in a fresh anonymous context, opened on the form.
 *
 * @param browser The browser fixture.
 * @return The page.
 */
async function openForm(browser: Browser): Promise<Page> {
	const context = await browser.newContext({
		baseURL: BASE_URL,
		storageState: EMPTY_STATE,
	})
	const page = await context.newPage()
	await page.goto(siteAddress(PAGE_ROUTE))
	await expect(page.getByTestId('intake-form')).toBeVisible({ timeout: 60_000 })
	return page
}

/**
 * Press Tab until a control has the focus, as a keyboard user would.
 *
 * @param page The page.
 * @param target The control.
 */
async function tabTo(page: Page, target: Locator): Promise<void> {
	await expect(target).toBeVisible()
	for (let presses = 0; presses < 60; presses++) {
		if (await target.evaluate((element) => element === document.activeElement)) {
			return
		}
		await page.keyboard.press('Tab')
	}
	throw new Error('the control was not reached with Tab')
}

/**
 * Add one neighbour by keyboard: the add button, both questions, "Opslaan".
 *
 * @param page The page.
 * @param neighbour The answers.
 * @param neighbour.naam The name.
 * @param neighbour.huisnummer The house number.
 */
async function addNeighbour(
	page: Page,
	neighbour: { naam: string; huisnummer: string },
): Promise<void> {
	const group = page.getByTestId(GROUP)
	await tabTo(page, group.getByTestId('group-add'))
	await page.keyboard.press('Enter')
	// The first question has the focus when the item opens.
	await expect(page.locator(`[id$="-sub-naam"]`)).toBeFocused()
	await page.keyboard.type(neighbour.naam)
	await tabTo(page, page.locator(`[id$="-sub-huisnummer"]`))
	await page.keyboard.type(neighbour.huisnummer)
	await tabTo(page, group.getByTestId('group-save'))
	await page.keyboard.press('Enter')
	await expect(group.getByTestId('group-form')).toHaveCount(0)
}

/**
 * "Volgende stap", by keyboard.
 *
 * @param page The page.
 */
async function nextStep(page: Page): Promise<void> {
	await tabTo(page, page.getByTestId('intake-form-next'))
	await page.keyboard.press('Enter')
}

test.describe('form-flow-repeating-groups-calculations-and-decisions', () => {
	test.beforeAll(async ({ request }) => {
		await seedStreetParty(request)
	})

	test.afterAll(async ({ request }) => {
		for (const ref of created.splice(0).reverse()) {
			await request.delete(`${OR_OBJECTS_BASE}/portaliq/${ref}`, {
				headers: ADMIN_HEADERS,
			})
		}
	})

	// @e2e portal-form-flow::two-neighbours-are-the-minimum
	test('Two neighbours are the minimum: one neighbour is refused with "Voeg nog 1 bewoner toe"', async ({
		browser,
	}) => {
		const page = await openForm(browser)

		await addNeighbour(page, NEIGHBOURS[0])
		await nextStep(page)

		const summary = page.getByTestId('error-summary')
		await expect(summary).toBeVisible()
		const link = summary.getByRole('link', { name: 'Voeg nog 1 bewoner toe' })
		await expect(link).toBeVisible()
		// The message links to the add button, and following it lands there.
		await link.focus()
		await page.keyboard.press('Enter')
		await expect(page.getByTestId(GROUP).getByTestId('group-add')).toBeFocused()
		await expect(page.getByTestId(GROUP).getByTestId('group-add')).toHaveText(
			'Nog een bewoner toevoegen',
		)

		await page.context().close()
	})

	// @e2e portal-form-flow::removing-an-item
	test('three neighbours by keyboard, the add button gone at the maximum, and removing one renumbers the cards', async ({
		browser,
	}) => {
		const page = await openForm(browser)
		const group = page.getByTestId(GROUP)

		for (const neighbour of NEIGHBOURS) {
			await addNeighbour(page, neighbour)
		}
		await expect(group.getByTestId('group-card')).toHaveCount(3)
		await expect(group.getByTestId('group-add')).toHaveCount(0)

		await tabTo(
			page,
			group.getByRole('button', { name: 'Verwijderen: Bewoner 2' }),
		)
		await page.keyboard.press('Enter')

		const cards = group.getByTestId('group-card')
		await expect(cards).toHaveCount(2)
		await expect(cards.nth(0)).toContainText('Bewoner 1')
		await expect(cards.nth(0)).toContainText('Anouk Bakker')
		await expect(cards.nth(1)).toContainText('Bewoner 2')
		await expect(cards.nth(1)).toContainText('Sanne de Vries')
		await expect(group).not.toContainText('Lars de Wit')
		await expect(group.getByTestId('group-add')).toBeVisible()

		await page.context().close()
	})

	// @e2e portal-form-flow::the-server-checks-each-item
	test('The server checks each item: a neighbour with an empty name is refused, naming the item and the field', async ({
		browser,
	}) => {
		const page = await openForm(browser)

		await addNeighbour(page, NEIGHBOURS[0])
		await addNeighbour(page, NEIGHBOURS[1])
		await nextStep(page)
		await tabTo(page, page.getByTestId('intake-field-aanvrager'))
		await page.keyboard.type('Karin Jansen')
		await nextStep(page)

		// Empty the second neighbour's name on the way out, as a tampered
		// request would; the server must refuse it on its own.
		let answered: Record<string, unknown> | null = null
		await page.route('**/portal/api/intake/submit**', async (route: Route) => {
			const body = route.request().postDataJSON() as {
				answers: { bewoners: Record<string, string>[] }
			}
			body.answers.bewoners[1].naam = ''
			const response = await route.fetch({ postData: JSON.stringify(body) })
			expect([400, 422]).toContain(response.status())
			answered = await response.json()
			await route.fulfill({ response })
		})

		await tabTo(page, page.getByTestId('intake-form-submit'))
		await page.keyboard.press('Enter')

		await expect.poll(() => answered).not.toBeNull()
		const errors =
			((answered ?? {}) as { errors?: Record<string, string> }).errors ?? {}
		expect(Object.keys(errors)).toContain('bewoners[1].naam')
		await expect(page.getByTestId('intake-form-done')).toHaveCount(0)

		await page.context().close()
	})
})
