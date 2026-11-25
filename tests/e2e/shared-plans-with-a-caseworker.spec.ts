/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * shared-plans-with-a-caseworker (T09, REQ-SPL-001 to REQ-SPL-004): a
 * resident works on plans with an approved contact. She starts one from a
 * template the municipality published, with a contact; the contact uploads
 * a second version of a file and edits an action; neither a stranger nor a
 * non-contact gets in; a participant cannot remove the owner; and a plan
 * near its end date says how many days are left. Driven over the routes the
 * plan pages (src/site/pages/e/PlansPage.vue, PlanPage.vue) call, and on the
 * site for "Samenwerken".
 *
 * Each resident is a fresh account signed in with dev-login, as in
 * personal-action-list.spec.ts. The template is this file's own, seeded as
 * the admin on the served portal and deleted afterwards. The end reminder
 * mail of REQ-SPL-004 is sent by the daily job and is pinned by
 * tests/Unit/Service/Plans/PlanEndReminderServiceTest.php, not here; the
 * scenario's fixed dates become dates relative to today.
 *
 * Written, not run here (needs a live instance with dev-login):
 *
 *     PLAYWRIGHT_BASE_URL=http://localhost:8095 npx playwright test shared-plans-with-a-caseworker
 *
 * @spec openspec/changes/shared-plans-with-a-caseworker/specs/resident-plans/spec.md
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	devLogin,
	oneOf,
	PORTAL_API,
	seedSiteSession,
	SITE_PORTAL,
	siteAddress,
} from './portal-nav.ts'

const PLANS = `${PORTAL_API}/plans`
const OR_OBJECTS_BASE = '/apps/openregister/api/objects'
const ADMIN = Buffer.from('admin:admin').toString('base64')
const ADMIN_HEADERS = { Authorization: `Basic ${ADMIN}`, 'OCS-APIRequest': 'true' }
const STAFF_HEADERS = { ...ADMIN_HEADERS, requesttoken: 'x' }
const ORGANISATION = 'dev-org'

/** The template's length and actions, as the municipality sets them up. */
const DURATION_DAYS = 56
const TEMPLATE_ACTIONS = [
	{
		title: 'Bankafschriften van drie maanden verzamelen',
		kind: 'once',
		offsetDays: 7,
	},
	{ title: 'Vaste lasten op een rij zetten', kind: 'once', offsetDays: 14 },
	{ title: 'Budgetplan maken', kind: 'once', offsetDays: 21 },
	{ title: 'Schuldeisers aanschrijven', kind: 'once', offsetDays: 35 },
	{ title: 'Wekelijks budget bijhouden', kind: 'recurring', offsetDays: 49 },
]

/** A one-page PDF's bytes, enough for the server's first-bytes check. */
const PDF = Buffer.from('%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n')

/** A signed-in resident: their subject reference and bearer. */
type Resident = {
	subjectRef: string
	headers: Record<string, string>
	token: string
}

/** What this run created as the admin, as `schema/id`, to delete afterwards. */
const created: string[] = []

/**
 * A fresh resident account with a display name, signed in on the portal.
 *
 * @param request The request fixture.
 * @param displayName The name the plan pages show.
 * @return The resident.
 */
async function resident(
	request: APIRequestContext,
	displayName: string,
): Promise<Resident> {
	const provisioned = await request.post('/apps/portaliq/api/accounts/provision', {
		headers: STAFF_HEADERS,
		data: {
			audience: 'client',
			organisation: ORGANISATION,
			identityType: 'digid',
			identityRef: `pairwise-${Date.now()}-${Math.random()}`,
			email: '',
			verifiedEmail: false,
			displayName,
		},
	})
	expect(provisioned.ok()).toBeTruthy()
	const { subjectRef } = await provisioned.json()

	// The shared helper waits out dev-login's per-minute limit: this file signs
	// in two residents per test.
	const token = await devLogin(request, subjectRef, 'client', ORGANISATION)
	return { subjectRef, token, headers: { Authorization: `Bearer ${token}` } }
}

/**
 * Create one portaliq object as the admin and remember it.
 *
 * @param request The request fixture.
 * @param schema The schema.
 * @param data The object.
 * @return Its id.
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
	expect(
		res.ok(),
		`OpenRegister objects#create must be reachable for ${schema}: ${await res.text()}`,
	).toBeTruthy()
	const body = await res.json()
	const id = String(body.id ?? body['@self']?.id ?? '')
	expect(id).not.toBe('')
	created.push(`${schema}/${id}`)
	return id
}

/**
 * Make `contact` an approved contact of `owner`, the row the contacts flow
 * writes on approval.
 *
 * @param request The request fixture.
 * @param owner The resident whose contact it is.
 * @param contact The approved contact.
 * @param displayName The name the owner knows the contact by.
 * @return The contact row's id, which a plan names its participants by.
 */
async function approve(
	request: APIRequestContext,
	owner: Resident,
	contact: Resident,
	displayName: string,
): Promise<string> {
	return await seed(request, 'portalContact', {
		owner: owner.subjectRef,
		contactRef: contact.subjectRef,
		displayName,
		state: 'approved',
		organisation: ORGANISATION,
	})
}

/**
 * Start a plan and return its id.
 *
 * @param request The request fixture.
 * @param who The owner.
 * @param data `templateId`, `title`, `contactIds`.
 * @return The plan's id.
 */
async function start(
	request: APIRequestContext,
	who: Resident,
	data: Record<string, unknown>,
): Promise<string> {
	const started = await request.post(`${PLANS}?portal=${SITE_PORTAL}`, {
		headers: who.headers,
		data,
	})
	expect(started.status(), await started.text()).toBe(201)
	return (await started.json()).id as string
}

/**
 * One plan as a participant reads it.
 *
 * @param request The request fixture.
 * @param who The reader.
 * @param id The plan.
 * @return The plan.
 */
async function plan(
	request: APIRequestContext,
	who: Resident,
	id: string,
): Promise<Record<string, any>> {
	const read = await request.get(`${PLANS}/${id}`, { headers: who.headers })
	expect(read.status()).toBe(200)
	return await read.json()
}

/**
 * A date some days from today, `Y-m-d`, in the server's time zone's sense
 * close enough for whole days.
 *
 * @param days The days from today.
 * @return The date.
 */
function daysFromToday(days: number): string {
	const date = new Date()
	date.setUTCHours(12, 0, 0, 0)
	date.setUTCDate(date.getUTCDate() + days)
	return date.toISOString().slice(0, 10)
}

test.describe('shared-plans-with-a-caseworker', () => {
	let templateId = ''

	test.beforeAll(async ({ request }) => {
		templateId = await seed(request, 'portalPlanTemplate', {
			portal: SITE_PORTAL,
			title: `Schuldhulp op orde ${Date.now()}`,
			summary: 'Samen met uw schuldhulpverlener uw geldzaken op orde brengen.',
			goal: 'Over acht weken een overzicht van alle schulden en een budget dat klopt.',
			durationDays: DURATION_DAYS,
			actions: TEMPLATE_ACTIONS,
			published: true,
		})
	})

	test.afterAll(async ({ request }) => {
		for (const ref of created.splice(0).reverse()) {
			await request.delete(`${OR_OBJECTS_BASE}/portaliq/${ref}`, {
				headers: ADMIN_HEADERS,
			})
		}
	})

	// @e2e resident-plans::two-running-plans
	test('Two running plans: Samenwerken shows the own plan and the one shared with her', async ({
		page,
		request,
	}) => {
		const sanne = await resident(request, 'Sanne de Vries')
		const linda = await resident(request, 'Linda Smit')
		const sanneAtLinda = await approve(request, linda, sanne, 'Sanne de Vries')

		await start(request, sanne, { title: 'Schuldhulp op orde' })
		await start(request, linda, {
			title: 'Terug naar werk',
			contactIds: [sanneAtLinda],
		})

		await seedSiteSession(page, sanne.token)
		await page.goto(siteAddress('/mijn/samenwerken'))
		await expect(page.getByTestId('plans-page')).toBeVisible()
		const cards = page.getByTestId('plan-card')
		await expect(cards).toHaveCount(2)
		await expect(cards.filter({ hasText: 'Schuldhulp op orde' })).toContainText(
			oneOf('Door u gemaakt', 'Made by you'),
		)
		await expect(cards.filter({ hasText: 'Terug naar werk' })).toContainText(
			oneOf('Door Linda Smit met u gedeeld', 'Shared with you by Linda Smit'),
		)
	})

	// @e2e resident-plans::someone-else-s-plan
	test("Someone else's plan: a resident who is not a participant is refused", async ({
		request,
	}) => {
		const sanne = await resident(request, 'Sanne de Vries')
		const stranger = await resident(request, 'Iemand Anders')
		const id = await start(request, sanne, { title: 'Schuldhulp op orde' })

		expect(
			(
				await request.get(`${PLANS}/${id}`, { headers: stranger.headers })
			).status(),
		).toBe(404)
		expect(
			(
				await request.patch(`${PLANS}/${id}`, {
					headers: stranger.headers,
					data: { note: 'x' },
				})
			).status(),
		).toBe(404)
		expect((await request.get(`${PLANS}/${id}`)).status()).toBe(401)
	})

	// @e2e resident-plans::debt-help-from-a-template
	test('Debt help from a template: the goal, five actions and the end date, and the contact sees it', async ({
		request,
	}) => {
		const sanne = await resident(request, 'Sanne de Vries')
		const mark = await resident(request, 'Mark Jansen')
		const markAtSanne = await approve(request, sanne, mark, 'Mark Jansen')

		const listed = await request.get(
			`${PLANS}/templates?portal=${SITE_PORTAL}`,
			{ headers: sanne.headers },
		)
		expect(listed.ok()).toBeTruthy()
		expect(JSON.stringify(await listed.json())).toContain(templateId)

		const id = await start(request, sanne, {
			templateId,
			contactIds: [markAtSanne],
		})
		const own = await plan(request, sanne, id)
		expect(own.goal).toBe(
			'Over acht weken een overzicht van alle schulden en een budget dat klopt.',
		)
		expect(own.actions).toHaveLength(5)
		expect(own.endDate).toBe(daysFromToday(DURATION_DAYS))
		expect(own.isOwner).toBe(true)

		const shared = await plan(request, mark, id)
		expect(shared.role).toBe('participant')
		expect(shared.sharedBy).toBe('Sanne de Vries')
	})

	// @e2e resident-plans::not-a-contact
	test('Not a contact: a participant who is no approved contact is refused', async ({
		request,
	}) => {
		const sanne = await resident(request, 'Sanne de Vries')
		const mark = await resident(request, 'Mark Jansen')
		const stranger = await resident(request, 'Iemand Anders')
		// Mark is Sanne's contact, so his row exists; it is not a contact of the stranger.
		const markAtSanne = await approve(request, sanne, mark, 'Mark Jansen')

		const refused = await request.post(`${PLANS}?portal=${SITE_PORTAL}`, {
			headers: stranger.headers,
			data: { title: 'Mijn plan', contactIds: [markAtSanne] },
		})
		expect(refused.status()).toBe(403)

		const id = await start(request, stranger, { title: 'Mijn plan' })
		const added = await request.post(`${PLANS}/${id}/participants`, {
			headers: stranger.headers,
			data: { contactIds: [markAtSanne] },
		})
		expect(added.status()).toBe(403)
	})

	// @e2e resident-plans::mark-uploads-a-new-budget-plan
	// @e2e resident-plans::a-participant-cannot-remove-the-owner
	test('the contact uploads a second version, edits an action, and cannot remove the owner', async ({
		request,
	}) => {
		const sanne = await resident(request, 'Sanne de Vries')
		const mark = await resident(request, 'Mark Jansen')
		const markAtSanne = await approve(request, sanne, mark, 'Mark Jansen')
		const id = await start(request, sanne, {
			templateId,
			contactIds: [markAtSanne],
		})

		const first = await request.post(`${PLANS}/${id}/files`, {
			headers: sanne.headers,
			multipart: {
				file: {
					name: 'Budgetplan.pdf',
					mimeType: 'application/pdf',
					buffer: PDF,
				},
			},
		})
		expect(first.ok(), await first.text()).toBeTruthy()
		const firstId = String((await plan(request, sanne, id)).files[0].current.id)

		const second = await request.post(`${PLANS}/${id}/files`, {
			headers: mark.headers,
			multipart: {
				file: {
					name: 'Budgetplan.pdf',
					mimeType: 'application/pdf',
					buffer: PDF,
				},
				previousVersion: firstId,
			},
		})
		expect(second.ok(), await second.text()).toBeTruthy()

		const files = (await plan(request, sanne, id)).files
		expect(files).toHaveLength(1)
		expect(files[0].current).toMatchObject({
			name: 'Budgetplan.pdf',
			version: 2,
			addedBy: 'Mark Jansen',
		})
		expect(files[0].earlier).toHaveLength(1)
		expect(files[0].earlier[0].version).toBe(1)

		// The participant edits an action of the plan.
		const action = (await plan(request, mark, id)).actions[0]
		const edited = await request.patch(`${PLANS}/${id}/actions/${action.id}`, {
			headers: mark.headers,
			data: { status: 'done' },
		})
		expect(edited.status()).toBe(200)
		const after = (await plan(request, sanne, id)).actions.find(
			(row: Record<string, unknown>) => row.id === action.id,
		)
		expect(after.status).toBe('done')

		// But he cannot remove the owner.
		const removed = await request.delete(
			`${PLANS}/${id}/participants/${sanne.subjectRef}`,
			{
				headers: mark.headers,
			},
		)
		expect(removed.status()).toBe(403)
		expect((await plan(request, sanne, id)).isOwner).toBe(true)
	})

	// @e2e resident-plans::twelve-days-left
	test('Twelve days left: the plan says how many days remain', async ({
		page,
		request,
	}) => {
		const sanne = await resident(request, 'Sanne de Vries')
		const id = await start(request, sanne, { templateId })
		const moved = await request.patch(`${PLANS}/${id}`, {
			headers: sanne.headers,
			data: { endDate: daysFromToday(12) },
		})
		expect(moved.status()).toBe(200)

		const read = await plan(request, sanne, id)
		expect(read.daysLeft).toBe(12)
		expect(read.openActions).toBeGreaterThan(0)

		await seedSiteSession(page, sanne.token)
		await page.goto(siteAddress('/mijn/samenwerken'))
		await expect(page.getByTestId('plan-card')).toContainText(
			oneOf('Loopt over 12 dagen af', 'Ends in 12 days'),
		)
	})
})
