/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * End-to-end for change-proposals-reach-the-case-page: on a dossiq case a
 * colleague who may read the case but not review proposals proposes a change
 * from the portaliq-change-proposal-queue widget, a handler accepts it, the
 * case carries the value and its audit trail names the handler.
 *
 * WHAT THIS FILE ANCHORS, AND WHAT IT DOES NOT.
 *
 * Anchored here:
 *   - a colleague proposes a new value and the proposal is queued with only
 *     that property, on channel `staff`, under their own proposals
 *     (REQ-CPC-001, scenario "A colleague proposes a new phone number")
 *   - a request-supplied `proposable` list is ignored (REQ-CPC-003)
 *   - the colleague cannot accept their own proposal; the handler sees the
 *     queue, not the form (REQ-CPC-001, scenario "A reviewer sees the queue,
 *     not the form")
 *   - accepting writes the value onto the case, and the case's audit trail
 *     names the handler
 *   - the widget on the dossiq case page, for the colleague and the handler,
 *     each in its own browser context with an empty storage state
 *
 * NOT anchored here, covered by PHPUnit and named so anyone can check:
 *   - only the caller's own proposals from /api/proposals/mine:
 *     tests/Unit/Controller/ProposalControllerTest.php
 *     (testMineOnARecordListsOnlyTheColleaguesOwnProposalsThere)
 *   - a property outside the declaration refused, and a record without a
 *     declaration refused: same file
 *     (testAColleagueProposesAgainstTheDeclaredAllowListNotTheRequests,
 *     testARecordWithoutADeclarationTakesNoColleagueProposal)
 *
 * Needs dossiq enabled beside portaliq and OpenRegister: dossiq declares
 * `title` and `description` proposable on its `case` schema and places the
 * widget on its case page (dossiq#3215). Without dossiq the file skips and
 * says why.
 *
 * Environment (no default target, see base-url.ts):
 *
 *     PLAYWRIGHT_BASE_URL=<instance> \
 *     E2E_ADMIN_USER=<handler with the review right> E2E_ADMIN_PASSWORD=... \
 *     npx playwright test change-proposals-reach-the-case-page
 *
 * Optional: E2E_DOSSIQ_CASE_ID to use an existing case instead of seeding one,
 * E2E_DOSSIQ_CASE_PATH (default `/apps/dossiq/cases/{id}`) for the case page.
 *
 * @spec openspec/changes/change-proposals-reach-the-case-page/specs/change-proposal-queue/spec.md
 */

import type { APIRequestContext, Browser, Page } from '@playwright/test'

import { expect, request as playwrightRequest, test } from '@playwright/test'
import { BASE_URL } from './base-url.ts'

const APP_API_BASE = '/apps/portaliq/api'
const OR_OBJECTS_BASE = '/apps/openregister/api/objects'
const OCS_USERS = '/ocs/v2.php/cloud/users'

const REGISTER = 'dossiq'
const SCHEMA = 'case'
const PROPERTY = 'description'

const ADMIN_USER = process.env.E2E_ADMIN_USER ?? process.env.NC_ADMIN_USER ?? 'admin'
const ADMIN_PASSWORD =
	process.env.E2E_ADMIN_PASSWORD ?? process.env.NC_ADMIN_PASSWORD ?? 'admin'
const CASE_PATH = process.env.E2E_DOSSIQ_CASE_PATH ?? '/apps/dossiq/cases/{id}'

const STAMP = Date.now()
const COLLEAGUE = `cpc-colleague-${STAMP}`
const COLLEAGUE_PASSWORD = `Cpc-${STAMP}-colleague!`

/** No cookies, no localStorage: every context starts signed out. */
const EMPTY_STATE = { cookies: [], origins: [] }

/** Headers for an app API call with HTTP Basic, which needs no CSRF token. */
const API_HEADERS = { 'OCS-APIRequest': 'true', requesttoken: 'x' }

/**
 * An API context signed in as one user, with an empty storage state.
 *
 * @param username The Nextcloud user.
 * @param password Their password.
 * @return the context; dispose it after use
 */
async function apiAs(username: string, password: string): Promise<APIRequestContext> {
	return playwrightRequest.newContext({
		baseURL: BASE_URL,
		storageState: EMPTY_STATE,
		httpCredentials: { username, password, send: 'always' },
		extraHTTPHeaders: API_HEADERS,
	})
}

/**
 * A browser page signed in through the login form, in a fresh context.
 *
 * @param browser The browser.
 * @param username The Nextcloud user.
 * @param password Their password.
 * @return the page
 */
async function pageAs(browser: Browser, username: string, password: string): Promise<Page> {
	const context = await browser.newContext({ baseURL: BASE_URL, storageState: EMPTY_STATE })
	const page = await context.newPage()
	await page.goto('/login')
	await page.fill('#user', username)
	await page.fill('#password', password)
	await page.click('button[type=submit]')
	await page.waitForURL((url) => !url.pathname.startsWith('/login'))
	return page
}

/** The id an OpenRegister or portaliq answer carries, whichever key it uses. */
function idOf(body: Record<string, unknown>): string {
	const self = (body['@self'] ?? {}) as Record<string, unknown>
	const nested = (body.proposal ?? {}) as Record<string, unknown>
	return String(body.id ?? body.uuid ?? self.id ?? nested.id ?? nested.uuid ?? '')
}

/** The proposals in a `{proposals}` answer. */
async function proposalsIn(answer: { json: () => Promise<unknown> }): Promise<Array<Record<string, unknown>>> {
	const body = (await answer.json()) as { proposals?: Array<Record<string, unknown>> }
	return body.proposals ?? []
}

function recordQuery (id: string): string {
  return `register=${REGISTER}&schema=${SCHEMA}&id=${encodeURIComponent(id)}`
}

test.describe('change proposals reach the case page', () => {
	test.describe.configure({ mode: 'serial' })

	let admin: APIRequestContext
	let colleague: APIRequestContext
	let caseId = ''
	let proposalId = ''
	const proposedValue = `Voorgesteld door een collega ${STAMP}`

	test.beforeAll(async () => {
		admin = await apiAs(ADMIN_USER, ADMIN_PASSWORD)

		const apps = await admin.get('/ocs/v2.php/cloud/apps?filter=enabled&format=json')
		const enabled = apps.ok()
			? (((await apps.json()).ocs?.data?.apps ?? []) as string[])
			: []
		test.skip(!enabled.includes('dossiq'), 'dossiq is not enabled on this instance: it declares the proposable fields and places the widget')

		// The colleague: an ordinary account with no group, so it may read
		// what OpenRegister lets any user read and review nothing.
		const created = await admin.post(`${OCS_USERS}?format=json`, {
			form: { userid: COLLEAGUE, password: COLLEAGUE_PASSWORD },
		})
		expect(created.ok(), 'the admin may create the colleague account').toBeTruthy()
		colleague = await apiAs(COLLEAGUE, COLLEAGUE_PASSWORD)

		if (process.env.E2E_DOSSIQ_CASE_ID) {
			caseId = process.env.E2E_DOSSIQ_CASE_ID
			return
		}

		// A dossiq case requires its case type, and OpenRegister starts it in that
		// type's initial status (x-openregister-lifecycle initial from caseType).
		// Any case type will do; E2E_DOSSIQ_CASE_TYPE names one.
		let caseType = process.env.E2E_DOSSIQ_CASE_TYPE ?? ''
		if (caseType === '') {
			const types = await admin.get(`${OR_OBJECTS_BASE}/${REGISTER}/caseType?_limit=1`)
			expect(types.ok(), 'the admin may list dossiq case types').toBeTruthy()
			const first = ((await types.json()).results ?? [])[0] ?? {}
			caseType = String(first.id ?? first['@self']?.id ?? '')
		}
		expect(caseType, 'dossiq must have a case type to open a case with').not.toBe('')

		const seeded = await admin.post(`${OR_OBJECTS_BASE}/${REGISTER}/${SCHEMA}`, {
			data: {
				title: `Zaak ${STAMP}`,
				caseType,
				// dossiq lets an authenticated user read a case only when it is
				// assigned to them (case schema authorization: read matches
				// assignee = $userId), so the colleague is the case's assignee.
				assignee: COLLEAGUE,
				[PROPERTY]: 'Oorspronkelijke omschrijving',
			},
		})
		expect(seeded.ok(), `OpenRegister objects#create must accept a dossiq case: ${await seeded.text()}`).toBeTruthy()
		caseId = idOf(await seeded.json())
		expect(caseId).toBeTruthy()
	})

	test.afterAll(async () => {
		if (admin) {
			await admin.delete(`${OCS_USERS}/${encodeURIComponent(COLLEAGUE)}`)
			await admin.dispose()
		}
		if (colleague) {
			await colleague.dispose()
		}
	})

	test('the colleague gets the form over the declared properties, the handler the queue', async () => {
		const asColleague = await colleague.get(`${APP_API_BASE}/proposals/context?${recordQuery(caseId)}`)
		expect(asColleague.ok(), 'a colleague who may read the case gets its context').toBeTruthy()
		const colleagueContext = await asColleague.json()
		expect(colleagueContext.mayReview).toBe(false)
		const properties = (colleagueContext.proposable as Array<{ property: string }>).map((field) => field.property)
		expect(properties).toContain(PROPERTY)

		const asHandler = await admin.get(`${APP_API_BASE}/proposals/context?${recordQuery(caseId)}`)
		expect(asHandler.ok()).toBeTruthy()
		expect((await asHandler.json()).mayReview).toBe(true)
	})

	test('a colleague proposes a change and it queues under their own proposals', async () => {
		const proposed = await colleague.post(`${APP_API_BASE}/proposals`, {
			data: {
				register: REGISTER,
				schema: SCHEMA,
				id: caseId,
				changes: [{ property: PROPERTY, proposedValue }],
				note: 'De aanvrager belde met een correctie.',
				// REQ-CPC-003: a list in the request is never the allow-list.
				proposable: ['status'],
			},
		})
		expect(proposed.ok(), 'a colleague may propose a declared property').toBeTruthy()
		const proposal = (await proposed.json()) as Record<string, unknown>
		proposalId = idOf(proposal)
		expect(proposalId).toBeTruthy()

		const mine = await proposalsIn(
			await colleague.get(`${APP_API_BASE}/proposals/mine?${recordQuery(caseId)}`),
		)
		const own = mine.find((row) => idOf(row) === proposalId)
		expect(own, 'the proposal is listed under the colleague\'s own proposals').toBeTruthy()
		expect(own?.channel).toBe('staff')
		expect(own?.proposedBy).toBe(COLLEAGUE)
		const changes = (own?.changes ?? []) as Array<Record<string, unknown>>
		expect(changes.map((change) => change.property)).toEqual([PROPERTY])

		// The proposer does not decide their own proposal.
		const selfAccept = await colleague.post(`${APP_API_BASE}/proposals/${encodeURIComponent(proposalId)}/accept`, { data: {} })
		expect(selfAccept.status()).toBe(403)
	})

	test('the widget shows the colleague the form and their proposal with a withdraw button', async ({ browser }) => {
		const page = await pageAs(browser, COLLEAGUE, COLLEAGUE_PASSWORD)
		await page.goto(CASE_PATH.replace('{id}', encodeURIComponent(caseId)))

		const widget = page.locator('.portaliq-proposal-queue')
		await expect(widget.getByRole('heading', { name: /Propose a change|Wijziging voorstellen/ })).toBeVisible()
		await expect(widget.locator(`#portaliq-propose-${caseId}-${PROPERTY}`)).toBeVisible()
		await expect(widget.getByText(proposedValue)).toBeVisible()
		await expect(widget.getByRole('button', { name: /Withdraw|Intrekken/ })).toBeVisible()
		await page.context().close()
	})

	test('the handler sees the queued proposal with accept and reject', async ({ browser }) => {
		const page = await pageAs(browser, ADMIN_USER, ADMIN_PASSWORD)
		await page.goto(CASE_PATH.replace('{id}', encodeURIComponent(caseId)))

		const widget = page.locator('.portaliq-proposal-queue')
		await expect(widget.getByText(proposedValue)).toBeVisible()
		await expect(widget.getByRole('button', { name: /^(Accept|Accepteren)$/ })).toBeVisible()
		await expect(widget.getByRole('button', { name: /^(Reject|Afwijzen)$/ })).toBeVisible()
		await expect(widget.getByRole('heading', { name: /Propose a change|Wijziging voorstellen/ })).toHaveCount(0)
		await page.context().close()
	})

	test('the handler accepts, the case carries the value and its audit trail names the handler', async () => {
		const queue = await proposalsIn(
			await admin.get(`${APP_API_BASE}/proposals?${recordQuery(caseId)}`),
		)
		expect(queue.some((row) => idOf(row) === proposalId), 'the proposal waits in the handler\'s queue').toBeTruthy()

		const accepted = await admin.post(`${APP_API_BASE}/proposals/${encodeURIComponent(proposalId)}/accept`, { data: {} })
		expect(accepted.ok(), `a handler with the review right may accept: ${accepted.status()} ${await accepted.text()}`).toBeTruthy()

		const after = await admin.get(`${OR_OBJECTS_BASE}/${REGISTER}/${SCHEMA}/${encodeURIComponent(caseId)}`)
		expect(after.ok()).toBeTruthy()
		expect((await after.json())[PROPERTY]).toBe(proposedValue)

		const trail = await admin.get(`${OR_OBJECTS_BASE}/${REGISTER}/${SCHEMA}/${encodeURIComponent(caseId)}/audit-trails`)
		expect(trail.ok()).toBeTruthy()
		const body = await trail.json()
		const entries = (Array.isArray(body) ? body : (body.results ?? [])) as Array<Record<string, unknown>>
		const write = entries.find((entry) => JSON.stringify(entry.changed ?? {}).includes(proposedValue))
		expect(write, 'the accepted value is in the case\'s audit trail').toBeTruthy()
		expect(write?.user).toBe(ADMIN_USER)

		const mine = await proposalsIn(
			await colleague.get(`${APP_API_BASE}/proposals/mine?${recordQuery(caseId)}`),
		)
		expect(mine.find((row) => idOf(row) === proposalId)?.state).toBe('accepted')
	})
})
