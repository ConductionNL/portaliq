/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * personal-action-list: a signed-in resident keeps their own actions on
 * "Mijn acties", adds one in the dialog, is refused a file over 10 MB, and
 * shares an action with an approved contact who may change its status but
 * not its assignee, and may not delete it. The history replays who changed
 * what. Driven over the routes the page (src/site/pages/e/ActionsPage.vue)
 * and its dialog (src/site/modals/e/ActionEditModal.vue) call, and on the
 * site for the dialog itself.
 *
 * The reminder (REQ-RAL-003) runs in a daily background job and is pinned by
 * tests/Unit/Service/Actions/ActionReminderServiceTest.php, not here.
 */

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { oneOf, PORTAL_API, seedSiteSession, siteAddress } from './portal-nav.ts'

const ACTIONS = `${PORTAL_API}/my-actions`

const OR_OBJECTS_BASE = '/apps/openregister/api/objects'
const ADMIN = Buffer.from('admin:admin').toString('base64')
const ORGANISATION = 'dev-org'

const STAFF_HEADERS = {
	Authorization: `Basic ${ADMIN}`,
	'OCS-APIRequest': 'true',
	requesttoken: 'x',
}

/** A signed-in resident: their subject reference and bearer. */
type Resident = { subjectRef: string, headers: Record<string, string>, token: string }

/**
 * A fresh resident account with a display name, signed in on the portal.
 *
 * @param request The request fixture.
 * @param displayName The name the history and the Wie column show.
 * @return The resident.
 */
async function resident(request: APIRequestContext, displayName: string): Promise<Resident> {
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

	const login = await request.post(`${PORTAL_API}/session/dev-login`, {
		data: { subjectRef, audience: 'client', organisation: ORGANISATION },
	})
	expect(
		login.ok(),
		'dev-login must be enabled (see tests/e2e/ci-seed.sh)',
	).toBeTruthy()
	const { token } = await login.json()
	return { subjectRef, token, headers: { Authorization: `Bearer ${token}` } }
}

/**
 * Make `contact` an approved contact of `owner`, through OpenRegister's own
 * object API as the dev admin, the row the contacts flow writes on approval.
 *
 * @param request The request fixture.
 * @param owner The resident whose contact it is.
 * @param contact The approved contact.
 * @param displayName The name the owner knows the contact by.
 * @return Nothing.
 */
async function approve(
	request: APIRequestContext,
	owner: Resident,
	contact: Resident,
	displayName: string,
): Promise<void> {
	const res = await request.post(`${OR_OBJECTS_BASE}/portaliq/portalContact`, {
		headers: { Authorization: `Basic ${ADMIN}`, 'OCS-APIRequest': 'true' },
		data: {
			owner: owner.subjectRef,
			contactRef: contact.subjectRef,
			displayName,
			state: 'approved',
			organisation: ORGANISATION,
		},
	})
	expect(res.ok(), 'OpenRegister objects#create must be reachable for portalContact').toBeTruthy()
}

/**
 * Add an action over the API and return its id.
 *
 * @param request The request fixture.
 * @param who The resident adding it.
 * @param data The fields.
 * @return The id.
 */
async function add(
	request: APIRequestContext,
	who: Resident,
	data: Record<string, unknown>,
): Promise<string> {
	const created = await request.post(ACTIONS, { headers: who.headers, data })
	expect(created.status()).toBe(201)
	return (await created.json()).id as string
}

/**
 * The resident's overview: `{actions, done, total, assignees}`.
 *
 * @param request The request fixture.
 * @param who The resident.
 * @return The overview.
 */
async function overview(
	request: APIRequestContext,
	who: Resident,
): Promise<{ actions: Array<Record<string, unknown>>, done: number, total: number }> {
	const res = await request.get(ACTIONS, { headers: who.headers })
	expect(res.ok()).toBeTruthy()
	return res.json()
}

/**
 * Sign the resident in on the site and open Mijn acties.
 *
 * @param page The page.
 * @param who The resident.
 * @return Nothing.
 */
async function openActions(page: Page, who: Resident): Promise<void> {
	await seedSiteSession(page, who.token)
	await page.goto(siteAddress('/mijn/acties'))
	await expect(page.getByTestId('actions-page')).toBeVisible()
}

/** A due date next year, so the action never reads as overdue. */
function nextYear(monthDay: string): string {
	return `${new Date().getFullYear() + 1}-${monthDay}`
}

test.describe('personal-action-list', () => {
	// @e2e resident-actions::adding-an-action
	test('without a bearer no action is read or written', async ({ request }) => {
		expect((await request.get(ACTIONS)).status()).toBe(401)
		expect((await request.post(ACTIONS, { data: { title: 'x' } })).status()).toBe(401)
	})

	// @e2e resident-actions::adding-an-action
	test('a resident adds an action in the dialog and it is listed as to do', async ({
		page,
		request,
	}) => {
		const sanne = await resident(request, 'Sanne de Vries')
		await openActions(page, sanne)
		await expect(page.getByTestId('actions-empty')).toBeVisible()

		await page.getByTestId('actions-add').click()
		const dialog = page.getByTestId('action-edit')
		await expect(dialog).toBeVisible()
		await dialog.getByTestId('action-edit-title').fill('Bankafschriften van drie maanden uploaden')
		await dialog.getByTestId('action-edit-end').fill(nextYear('10-14'))
		await dialog.getByTestId('action-edit-status-todo').check()
		await dialog.getByTestId('action-edit-save').click()

		const rows = page.getByTestId('actions-row')
		await expect(rows).toHaveCount(1)
		await expect(rows.first()).toContainText('Bankafschriften van drie maanden uploaden')
		await expect(rows.first()).toContainText(oneOf('Te doen', 'To do'))
		await expect(page.getByTestId('actions-progress')).toContainText(oneOf('0 van 1', '0 of 1'))

		const listed = await overview(request, sanne)
		expect(listed.total).toBe(1)
		expect(listed.actions[0]).toMatchObject({
			title: 'Bankafschriften van drie maanden uploaden',
			status: 'todo',
			endDate: nextYear('10-14'),
			assignee: sanne.subjectRef,
		})
	})

	// @e2e resident-actions::a-file-too-large
	test('the dialog refuses a 12 MB PDF with the file rule', async ({ page, request }) => {
		const sanne = await resident(request, 'Sanne de Vries')
		await add(request, sanne, { title: 'Loonstrook uploaden', endDate: nextYear('10-14') })
		await openActions(page, sanne)

		await page.getByTestId('actions-edit').first().click()
		const dialog = page.getByTestId('action-edit')
		await expect(dialog).toBeVisible()
		await dialog.getByTestId('action-edit-file-input').setInputFiles({
			name: 'afschriften.pdf',
			mimeType: 'application/pdf',
			buffer: Buffer.alloc(12 * 1024 * 1024, 0x20),
		})

		const rule = dialog.getByTestId('action-edit-file-rule')
		await expect(rule).toHaveAttribute('role', 'alert')
		await expect(rule).toContainText(oneOf(
			'Eén bestand per actie. PDF, JPG of PNG, maximaal 10 MB.',
			'One file per action. PDF, JPG or PNG, at most 10 MB.',
		))
		await expect(dialog.getByTestId('action-edit-file')).toHaveCount(0)
	})

	// @e2e resident-actions::the-caseworker-updates-the-status
	test('an approved contact sets a shared action to in progress and the history says so', async ({
		request,
	}) => {
		const sanne = await resident(request, 'Sanne de Vries')
		const mark = await resident(request, 'Mark Jansen')
		await approve(request, sanne, mark, 'Mark Jansen')

		const people = (await overview(request, sanne)) as unknown as { assignees: Array<{ ref: string }> }
		expect(people.assignees.map((person) => person.ref)).toContain(mark.subjectRef)

		const id = await add(request, sanne, {
			title: 'Aanvraag schuldhulpverlening indienen',
			endDate: nextYear('10-14'),
			assignee: mark.subjectRef,
		})

		const marks = await overview(request, mark)
		const shared = marks.actions.find((action) => action.id === id)
		expect(shared, 'the assignee sees the action shared with them').toBeTruthy()
		expect(shared).toMatchObject({ role: 'assignee', mayDelete: false, readOnly: false })

		const changed = await request.patch(`${ACTIONS}/${id}`, {
			headers: mark.headers,
			data: { status: 'doing' },
		})
		expect(changed.status()).toBe(200)
		expect(await changed.json()).toEqual({ ok: true })

		const mine = await overview(request, sanne)
		expect(mine.actions.find((action) => action.id === id)?.status).toBe('doing')

		const history = await request.get(`${ACTIONS}/${id}/history`, { headers: sanne.headers })
		expect(history.ok()).toBeTruthy()
		const { history: lines } = await history.json()
		expect(lines).toEqual(expect.arrayContaining([
			expect.objectContaining({ who: 'Mark Jansen', field: 'status', from: 'todo', to: 'doing' }),
		]))
	})

	// @e2e resident-actions::an-assignee-cannot-delete
	test('the assignee may not change the assignee or delete the action', async ({ request }) => {
		const sanne = await resident(request, 'Sanne de Vries')
		const mark = await resident(request, 'Mark Jansen')
		await approve(request, sanne, mark, 'Mark Jansen')
		const id = await add(request, sanne, {
			title: 'Budgetplan bespreken',
			assignee: mark.subjectRef,
		})

		const reassigned = await request.patch(`${ACTIONS}/${id}`, {
			headers: mark.headers,
			data: { assignee: mark.subjectRef, status: 'doing' },
		})
		expect(reassigned.status()).toBe(403)

		const deleted = await request.delete(`${ACTIONS}/${id}`, { headers: mark.headers })
		expect(deleted.status()).toBe(403)

		const mine = await overview(request, sanne)
		expect(mine.actions.find((action) => action.id === id)).toMatchObject({
			status: 'todo',
			assignee: mark.subjectRef,
		})
	})

	// @e2e resident-actions::adding-an-action
	test('a stranger reads none of the actions and cannot change one', async ({ request }) => {
		const sanne = await resident(request, 'Sanne de Vries')
		const stranger = await resident(request, 'Iemand Anders')
		const id = await add(request, sanne, { title: 'Huurcontract opzoeken' })

		const theirs = await overview(request, stranger)
		expect(theirs.actions.map((action) => action.id)).not.toContain(id)

		const changed = await request.patch(`${ACTIONS}/${id}`, {
			headers: stranger.headers,
			data: { status: 'done' },
		})
		expect(changed.status()).toBe(404)
		expect((await request.delete(`${ACTIONS}/${id}`, { headers: stranger.headers })).status()).toBe(404)
	})
})
