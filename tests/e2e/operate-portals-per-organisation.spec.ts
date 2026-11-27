/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * operate-portals-per-organisation T05 and T14 (REQ-OPO-003, REQ-OPO-004,
 * REQ-OPO-005): one installation, two organisations, a portal each, and no
 * route that crosses from one to the other.
 *
 * 🔴 THE OTHER ORGANISATION'S ROWS CARRY THE SAME SUBJECT. org-b's message
 * and case are seeded with the org-a resident's own `subjectRef`. A reader
 * that scoped by subject alone would hand them over, so only the organisation
 * keeps them apart, which is the claim this spec exists to prove.
 *
 * 🔴 EVERY CHECK HAS A CONTROL. The resident's inbox must carry org-a's own
 * message and the anonymous news list org-a's own item, so "nothing of org-b
 * came back" cannot pass on a route that returns nothing at all.
 *
 * 🔴 THE ROUTE TABLE IS WRITTEN OUT, NOT READ. `ROUTES` names every
 * `/portal/api/` route in `appinfo/routes.php` with what the spec does with
 * it, and the first test fails for a route the table does not list (design
 * D3). Reads are called with the org-a session; a route with an id is called
 * with org-b's ids; a write that takes an id is called with org-b's ids and
 * must be refused, and org-b's rows must still be there afterwards.
 *
 * Needs an instance with dev-login (debug mode) and the instance admin's
 * credentials in NC_ADMIN_USER / NC_ADMIN_PASSWORD (default admin/admin, the
 * CI container). Non-admin callers run in contexts with an empty storage
 * state. Written for the live pass (decision 139):
 *
 *     PLAYWRIGHT_BASE_URL=http://localhost:8095 npx playwright test operate-portals-per-organisation
 *
 * @spec openspec/changes/operate-portals-per-organisation/specs/portal-tenancy/spec.md
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { BASE_URL } from './base-url.ts'
import { devLogin, PORTAL_API, SITE_PATH } from './portal-nav.ts'

const ADMIN_USER = process.env.NC_ADMIN_USER || 'admin'
const ADMIN_PASSWORD = process.env.NC_ADMIN_PASSWORD || 'admin'
const ADMIN = Buffer.from(`${ADMIN_USER}:${ADMIN_PASSWORD}`).toString('base64')
const ADMIN_HEADERS = { Authorization: `Basic ${ADMIN}`, 'OCS-APIRequest': 'true' }
const OBJECTS = '/apps/openregister/api/objects/portaliq'
const APP_API = '/apps/portaliq/api'
const EMPTY_STATE = { cookies: [], origins: [] }

const STAMP = Date.now()
const SUBJECT = `opo-subject-${STAMP}`

/** One organisation's half of the fixture. */
interface Tenant {
	organisation: string
	/** The OpenRegister organisation's uuid, filled by organisation(). */
	organisationUuid: string
	slug: string
	title: string
	group: string
	member: string
	password: string
	ids: Record<string, string>
	/** Strings that occur only in this tenant's seeded rows. */
	markers: string[]
}

/** The two tenants, filled by beforeAll. */
const A: Tenant = tenant('a')
const B: Tenant = tenant('b')

/**
 * An empty tenant with this run's names.
 *
 * @param letter `a` or `b`.
 * @return The tenant.
 */
function tenant(letter: string): Tenant {
	return {
		organisation: `opo-org-${letter}-${STAMP}`,
		organisationUuid: '',
		slug: `opo-${letter}-${STAMP}`,
		title: `Gemeente OPO ${letter.toUpperCase()} ${STAMP}`,
		group: `opo-beheer-${letter}-${STAMP}`,
		member: `opo-member-${letter}-${STAMP}`,
		password: `Opo-${STAMP}-${letter}-Xq9!long`,
		ids: {},
		markers: [],
	}
}

/**
 * What the spec does with a route.
 *
 * - `read`: GET with the org-a session; the answer must not carry org-b.
 * - `cross`: GET with org-b's ids in the path; the answer must not carry org-b.
 * - `crossWrite`: the write with org-b's ids in the path; it must be refused.
 * - `own: <reason>` / `session: <reason>`: listed, not called, with why.
 */
type Mode = 'read' | 'cross' | 'crossWrite' | `own: ${string}` | `session: ${string}`

const SKIP_SESSION: Mode = 'session: starts, refreshes or ends a sign-in and returns no records'
const OWN: Mode = 'own: writes a record of the caller\'s own organisation and takes no id of another'

/** Every `/portal/api/` route of appinfo/routes.php, as `VERB url`. */
const ROUTES: Record<string, Mode> = {
	'GET /portal/api/session': SKIP_SESSION,
	'POST /portal/api/session/dev-login': SKIP_SESSION,
	'GET /portal/api/session/nextcloud': SKIP_SESSION,
	'GET /portal/api/session/desk': SKIP_SESSION,
	'GET /portal/api/session/example-resident': SKIP_SESSION,
	'DELETE /portal/api/session': SKIP_SESSION,
	'POST /portal/api/session/refresh': SKIP_SESSION,
	'GET /portal/api/session/branches': SKIP_SESSION,
	'POST /portal/api/session/branch': SKIP_SESSION,
	'GET /portal/api/session/oidc/start': SKIP_SESSION,
	'GET /portal/api/session/oidc/callback': SKIP_SESSION,
	'GET /portal/api/session/broker/start': SKIP_SESSION,
	'GET /portal/api/session/broker/callback': SKIP_SESSION,
	'GET /portal/api/contributions': 'read',
	'GET /portal/api/inbox': 'read',
	'PATCH /portal/api/inbox/{register}/{schema}/{id}/read': 'crossWrite',
	'POST /portal/api/inbox/{register}/{schema}/{id}/reply': 'crossWrite',
	'DELETE /portal/api/inbox/{register}/{schema}/{id}': 'crossWrite',
	'POST /portal/api/embed/submit': OWN,
	'POST /portal/api/intake/decide': OWN,
	'GET /portal/api/intake/catalogue': 'read',
	'GET /portal/api/intake/address': 'read',
	'POST /portal/api/intake/pay': OWN,
	'GET /portal/api/intake/family': 'read',
	'GET /portal/api/intake/form': 'read',
	'POST /portal/api/intake/submit': OWN,
	'POST /portal/api/intake/on-behalf': OWN,
	'POST /portal/api/intake/email-code': OWN,
	'POST /portal/api/intake/email-code/check': OWN,
	'GET /portal/api/cosign': 'read',
	'POST /portal/api/cosign/sign': OWN,
	'POST /portal/api/cosign/refuse': OWN,
	'GET /portal/api/cosign/held': 'read',
	'GET /portal/api/intake/status': 'read',
	'GET /portal/api/identity/challenge': 'read',
	'POST /portal/api/identity/reference-link': OWN,
	'POST /portal/api/identity/reference-link/redeem': OWN,
	'POST /portal/api/identity/email-link': OWN,
	'POST /portal/api/identity/email-link/describe': OWN,
	'POST /portal/api/identity/email-link/redeem': OWN,
	'GET /portal/api/identity/reference-case': 'read',
	'POST /portal/api/identity/register': OWN,
	'POST /portal/api/identity/contact-invitation': OWN,
	'POST /portal/api/identity/activate': OWN,
	'POST /portal/api/identity/invitation/accept': OWN,
	'POST /portal/api/guest/{appId}/{actionId}/preview': OWN,
	'POST /portal/api/guest/{appId}/{actionId}': OWN,
	'PATCH /portal/api/identity/details': OWN,
	'GET /portal/api/identity/details': 'read',
	'GET /portal/api/drafts': 'read',
	'GET /portal/api/drafts/{appId}/{actionId}': 'cross',
	'PUT /portal/api/drafts/{appId}/{actionId}': OWN,
	'DELETE /portal/api/drafts/{appId}/{actionId}': OWN,
	'GET /portal/api/identity/registered-details': 'read',
	'GET /portal/api/identity/notification-preferences': 'read',
	'PATCH /portal/api/identity/notification-preferences': OWN,
	'POST /portal/api/identity/email/confirm': OWN,
	'POST /portal/api/identity/invitation/redeem': OWN,
	'POST /portal/api/identity/addresses': OWN,
	'POST /portal/api/identity/addresses/preferred': OWN,
	'POST /portal/api/identity/addresses/remove': OWN,
	'PUT /portal/api/identity/contact-channel': OWN,
	'GET /portal/api/contacts': 'read',
	'POST /portal/api/contacts/invite': OWN,
	'POST /portal/api/contacts/accept-invitation': OWN,
	'POST /portal/api/contacts/{id}/respond': 'crossWrite',
	'POST /portal/api/contacts/{id}/resend': 'crossWrite',
	'POST /portal/api/contacts/{id}/withdraw': 'crossWrite',
	'DELETE /portal/api/contacts/{id}': 'crossWrite',
	'GET /portal/api/plans': 'read',
	'GET /portal/api/plans/templates': 'read',
	'POST /portal/api/plans': OWN,
	'GET /portal/api/plans/{id}': 'cross',
	'PATCH /portal/api/plans/{id}': 'crossWrite',
	'DELETE /portal/api/plans/{id}': 'crossWrite',
	'GET /portal/api/plans/{id}/pdf': 'cross',
	'POST /portal/api/plans/{id}/files': 'crossWrite',
	'GET /portal/api/plans/{id}/files/{fileId}': 'cross',
	'POST /portal/api/plans/{id}/participants': 'crossWrite',
	'DELETE /portal/api/plans/{id}/participants/{ref}': 'crossWrite',
	'POST /portal/api/plans/{id}/actions': 'crossWrite',
	'PATCH /portal/api/plans/{id}/actions/{actionId}': 'crossWrite',
	'GET /portal/api/my-actions': 'read',
	'POST /portal/api/my-actions': OWN,
	'PATCH /portal/api/my-actions/{id}': 'crossWrite',
	'DELETE /portal/api/my-actions/{id}': 'crossWrite',
	'GET /portal/api/my-actions/{id}/history': 'cross',
	'POST /portal/api/my-actions/{id}/file': 'crossWrite',
	'GET /portal/api/my-actions/{id}/file': 'cross',
	'POST /portal/api/identity/remove': OWN,
	'POST /portal/api/identity/access-requests': OWN,
	'GET /portal/api/identity/access-requests': 'read',
	'GET /portal/api/my-cases': 'read',
	'GET /portal/api/collections/{register}/{schema}': 'cross',
	'POST /portal/api/collections/{register}/{schema}': OWN,
	'GET /portal/api/collections/{register}/{schema}/export.pdf': 'cross',
	'GET /portal/api/collections/{register}/{schema}/{id}/export.pdf': 'cross',
	'GET /portal/api/collections/{register}/{schema}/{id}': 'cross',
	'GET /portal/api/collections/{register}/{schema}/{id}/documents': 'cross',
	'GET /portal/api/collections/{register}/{schema}/{id}/documents/{documentId}': 'cross',
	'GET /portal/api/collections/{register}/{schema}/{id}/timeline': 'cross',
	'GET /portal/api/collections/{register}/{schema}/{id}/steps': 'cross',
	'GET /portal/api/collections/{register}/{schema}/{id}/messages': 'cross',
	'GET /portal/api/collections/{register}/{schema}/{id}/items': 'cross',
	'PATCH /portal/api/collections/{register}/{schema}/{id}': 'crossWrite',
	'POST /portal/api/collections/{register}/{schema}/{id}/files': 'crossWrite',
	'POST /portal/api/collections/{register}/{schema}/{id}/fields/{field}': 'crossWrite',
	'POST /portal/api/collections/{register}/{schema}/{id}/actions/{actionId}': 'crossWrite',
	'GET /portal/api/mandates/given': 'read',
	'GET /portal/api/mandates/case-types': 'read',
	'POST /portal/api/mandates/invitations': OWN,
	'POST /portal/api/mandates/invitations/accept': OWN,
	'DELETE /portal/api/mandates/invitations/{id}': 'crossWrite',
	'POST /portal/api/mandates/{id}/revoke': 'crossWrite',
	'PUT /portal/api/mandates/{id}/expiry': 'crossWrite',
	'GET /portal/api/mandates/held': 'read',
	'POST /portal/api/mandates/held/{id}/stop': 'crossWrite',
	'GET /portal/api/collections/{register}/{schema}/{id}/files/{fileId}': 'cross',
	'GET /portal/api/schema/{schema}': 'cross',
	'POST /portal/api/actions/{appId}/{actionId}': OWN,
	'POST /portal/api/proposals': OWN,
	'GET /portal/api/proposals/mine': 'read',
	'POST /portal/api/proposals/{id}/withdraw': 'crossWrite',
	'GET /portal/api/polls': 'read',
	'POST /portal/api/polls/{id}/respond': 'crossWrite',
	'POST /portal/api/reports': OWN,
	'POST /portal/api/reports/thread': OWN,
	'POST /portal/api/reports/thread/answer': OWN,
	'GET /portal/api/tasks': 'read',
	'GET /portal/api/tasks/{uuid}': 'cross',
	'POST /portal/api/tasks/{uuid}/complete': 'crossWrite',
	'GET /portal/api/citizen/cases/{register}/{schema}/{id}': 'cross',
	'PATCH /portal/api/citizen/cases/{register}/{schema}/{id}': 'crossWrite',
	'POST /portal/api/citizen/cases/{register}/{schema}/{id}/documents': 'crossWrite',
	'GET /portal/api/citizen/cases/{register}/{schema}/{id}/documents/{documentId}': 'cross',
	'POST /portal/api/citizen/cases/{register}/{schema}/{id}/withdraw': 'crossWrite',
	'GET /portal/api/inbox/{register}/{schema}/{id}': 'cross',
	'POST /portal/api/intake/fetch': 'own: looks up data for a step of the caller\'s own form and reads no stored record',
	'GET /portal/api/receipts/{id}/pdf': 'cross',
	'GET /portal/api/media-groups/{register}/{schema}': 'cross',
	'GET /portal/api/media-groups/{register}/{schema}/{id}': 'cross',
	'GET /portal/api/collections/{register}/{schema}/{id}/documents-bundle': 'cross',
	'GET /portal/api/collections/{register}/{schema}/{id}/documents/{documentId}/terms': 'cross',
	'POST /portal/api/collections/{register}/{schema}/{id}/documents/{documentId}/terms': 'crossWrite',
}

/**
 * Create one object through OpenRegister's own object API, as the admin.
 *
 * @param request The admin context.
 * @param schema The portaliq schema.
 * @param data The object.
 * @return The id.
 */
async function seed(request: APIRequestContext, schema: string, data: Record<string, unknown>): Promise<string> {
	const res = await request.post(`${OBJECTS}/${schema}`, { headers: ADMIN_HEADERS, data })
	expect(res.ok(), `seeding ${schema}: ${res.status()} ${(await res.text()).slice(0, 800)}`).toBeTruthy()
	const body = await res.json()
	return String(body.id ?? body.uuid ?? body['@self']?.id)
}

/**
 * Create a Nextcloud group and a user in it, as the admin (OCS provisioning).
 *
 * @param request The admin context.
 * @param who The tenant whose group and member to create.
 */
async function member(request: APIRequestContext, who: Tenant): Promise<void> {
	const groups = await request.post('/ocs/v2.php/cloud/groups', { headers: ADMIN_HEADERS, form: { groupid: who.group } })
	expect(groups.ok(), `creating group ${who.group}: ${groups.status()}`).toBeTruthy()
	const users = await request.post('/ocs/v2.php/cloud/users', {
		headers: ADMIN_HEADERS,
		form: { userid: who.member, password: who.password, 'groups[]': who.group },
	})
	expect(users.ok(), `creating user ${who.member}: ${users.status()}`).toBeTruthy()
}

/**
 * Create the tenant's OpenRegister organisation and make it the admin's
 * active one, so the rows seeded next belong to it.
 *
 * A portal acts for its organisation (portal-acts-for-its-organisation,
 * REQ-PAO-002): a slug that names no OpenRegister organisation reads and
 * writes nothing, so the dev-login bearer of such an organisation answers 401
 * on every route. OpenRegister derives the slug from the name, so the name
 * is the slug itself.
 *
 * @param request The admin context.
 * @param who The tenant.
 */
async function organisation(request: APIRequestContext, who: Tenant): Promise<void> {
	const created = await request.post('/apps/openregister/api/organisations', { headers: ADMIN_HEADERS, data: { name: who.organisation } })
	expect(created.ok(), `creating organisation ${who.organisation}: ${created.status()} ${(await created.text()).slice(0, 300)}`).toBeTruthy()
	const { organisation: row } = await created.json() as { organisation: { uuid: string, slug: string } }
	expect(row.slug, 'OpenRegister derives the organisation slug from its name').toBe(who.organisation)
	who.organisationUuid = row.uuid
	const active = await request.post(`/apps/openregister/api/organisations/${row.uuid}/set-active`, { headers: ADMIN_HEADERS })
	expect(active.ok(), `making ${who.organisation} active: ${active.status()}`).toBeTruthy()
}

/**
 * Seed one tenant: its portal (published, with its administrators' group), a
 * page, a menu and a public news item, and for the shared subject a message,
 * a case and a submission.
 *
 * @param request The admin context.
 * @param who The tenant.
 */
async function seedTenant(request: APIRequestContext, who: Tenant): Promise<void> {
	const mark = (name: string) => {
		const value = `${name}-${who.slug}`
		who.markers.push(value)
		return value
	}
	who.markers.push(who.title)
	who.ids.portal = await seed(request, 'portal', {
		title: who.title,
		slug: who.slug,
		status: 'published',
		organisation: who.organisation,
		adminGroups: [who.group],
	})
	who.ids.page = await seed(request, 'page', {
		title: mark('Pagina'),
		route: `/opo-${STAMP}`,
		portal: who.slug,
		status: 'published',
	})
	who.ids.menu = await seed(request, 'menu', {
		title: mark('Menu'),
		portal: who.slug,
		items: [{ name: mark('Menu-item'), link: `/opo-${STAMP}` }],
	})
	who.ids.news = await seed(request, 'newsItem', {
		title: mark('Nieuws'),
		body: mark('Nieuwsbericht'),
		target: { schoolRef: who.slug },
		authorRef: 'admin',
		status: 'published',
		public: true,
		portal: who.slug,
		publishedAt: new Date().toISOString(),
	})
	who.ids.message = await seed(request, 'portalMessage', {
		subjectRef: SUBJECT,
		organisation: who.organisation,
		subject: mark('Bericht'),
		body: mark('Berichttekst'),
	})
	who.ids.case = await seed(request, 'portalCase', {
		subjectRef: SUBJECT,
		organisation: who.organisation,
		reference: mark('Zaak'),
		omschrijving: mark('Omschrijving'),
	})
	who.ids.submission = await seed(request, 'portalSubmission', {
		subjectRef: SUBJECT,
		organisation: who.organisation,
		appId: 'portaliq',
		actionId: mark('Actie'),
		submittedAt: new Date().toISOString(),
		deliveryStatus: 'delivered',
	})
	who.markers.push(...Object.values(who.ids))
}

/**
 * The org-b strings an answer carries, if any.
 *
 * @param text The answer's body.
 * @return The leaked markers.
 */
function leaked(text: string): string[] {
	return B.markers.filter((marker) => marker !== '' && text.includes(marker))
}

/**
 * A route's url with org-b's ids in its placeholders.
 *
 * @param url The route url, with `{placeholders}`.
 * @return The path to call, under the app.
 */
function filled(url: string): string {
	const inbox = url.startsWith('/portal/api/inbox/')
	const values: Record<string, string> = {
		register: 'portaliq',
		schema: inbox ? 'portalMessage' : 'portalCase',
		id: inbox ? B.ids.message : B.ids.case,
		uuid: B.ids.case,
	}
	return '/apps/portaliq' + url.replace(/\{(\w+)\}/g, (_, name: string) => values[name] ?? 'opo-none')
}

// Runs without an instance: it reads only appinfo/routes.php.
test('the route table names every portal API route', () => {
	const php = readFileSync(join(__dirname, '..', '..', 'appinfo', 'routes.php'), 'utf8')
	const declared = [...php.matchAll(/'url'\s*=>\s*'(\/portal\/api\/[^']*)'\s*,\s*'verb'\s*=>\s*'([A-Z]+)'/g)]
		.map(([, url, verb]) => `${verb} ${url}`)
	expect(declared.length, 'no /portal/api/ route found in appinfo/routes.php').toBeGreaterThan(0)
	const unlisted = declared.filter((route) => !(route in ROUTES))
	expect(unlisted, 'routes without a line in ROUTES: decide what this spec does with them').toEqual([])
	const stale = Object.keys(ROUTES).filter((route) => !declared.includes(route))
	expect(stale, 'ROUTES lines for routes that no longer exist').toEqual([])
})

let admin: APIRequestContext
let resident: APIRequestContext
let anonymous: APIRequestContext
let token = ''
let previousActive = ''

test.describe.configure({ mode: 'serial' })

test.describe('operate-portals-per-organisation', () => {
	test.beforeAll(async ({ playwright }) => {
		admin = await playwright.request.newContext({ baseURL: BASE_URL })
		resident = await playwright.request.newContext({ baseURL: BASE_URL, storageState: EMPTY_STATE })
		anonymous = await playwright.request.newContext({ baseURL: BASE_URL, storageState: EMPTY_STATE })
		const before = await admin.get('/apps/openregister/api/organisations/active', { headers: ADMIN_HEADERS })
		previousActive = before.ok() ? String((await before.json()).activeOrganisation?.uuid ?? '') : ''
		for (const who of [A, B]) {
			await member(admin, who)
			await organisation(admin, who)
			await seedTenant(admin, who)
		}
		if (previousActive !== '') {
			await admin.post(`/apps/openregister/api/organisations/${previousActive}/set-active`, { headers: ADMIN_HEADERS })
		}
		token = await devLogin(resident, SUBJECT, 'client', A.organisation)
	})

	test.afterAll(async () => {
		for (const who of [A, B]) {
			for (const [schema, id] of [
				['portalSubmission', who.ids.submission], ['portalCase', who.ids.case],
				['portalMessage', who.ids.message], ['newsItem', who.ids.news],
				['menu', who.ids.menu], ['page', who.ids.page], ['portal', who.ids.portal],
			]) {
				if (id) {
					await admin.delete(`${OBJECTS}/${schema}/${id}`, { headers: ADMIN_HEADERS })
				}
			}
			await admin.delete(`/ocs/v2.php/cloud/users/${who.member}`, { headers: ADMIN_HEADERS })
			await admin.delete(`/ocs/v2.php/cloud/groups/${who.group}`, { headers: ADMIN_HEADERS })
		}
		await Promise.all([admin, resident, anonymous].map((context) => context?.dispose()))
	})

	// e2e: portal-tenancy::a-resident-of-one-municipality-sees-only-their-own
	test('A resident of one municipality sees only their own', async () => {
		const headers = { Authorization: `Bearer ${token}` }
		const inbox = await resident.get(`${PORTAL_API}/inbox?portal=${A.slug}`, { headers })
		const inboxText = await inbox.text()
		expect(inbox.ok(), `inbox answered ${inbox.status()}`).toBeTruthy()
		expect(inboxText, 'control: the resident\'s own message is in the inbox').toContain(A.ids.message)

		const leaks: string[] = []
		for (const [route, mode] of Object.entries(ROUTES)) {
			if (mode !== 'read' && mode !== 'cross' && mode !== 'crossWrite') {
				continue
			}
			const [verb, url] = route.split(' ')
			const path = mode === 'read' ? '/apps/portaliq' + url : filled(url)
			const res = await resident.fetch(`${path}?portal=${A.slug}`, { method: verb, headers, data: verb === 'GET' ? undefined : {} })
			// An id the request itself carried may come back in a refusal (an
			// RFC 9457 problem names its `instance`, the URL asked for): that is
			// the caller's own input, not org-b's record. The record's strings
			// still count wherever they appear.
			const found = leaked(await res.text()).filter((marker) => !path.includes(marker))
			if (found.length > 0) {
				leaks.push(`${route} (${res.status()}): ${found.join(', ')}`)
			}
			if (mode === 'crossWrite') {
				expect(res.status(), `${route} with org-b's ids must be refused`).toBeGreaterThanOrEqual(400)
			}
		}
		expect(leaks, 'responses that carried an org-b record').toEqual([])

		// The refused writes changed nothing of org-b.
		for (const [schema, id] of [['portalMessage', B.ids.message], ['portalCase', B.ids.case]]) {
			const still = await admin.get(`${OBJECTS}/${schema}/${id}`, { headers: ADMIN_HEADERS })
			expect(still.ok(), `org-b's ${schema} must survive the org-a resident's writes`).toBeTruthy()
		}
	})

	// e2e: portal-tenancy::an-anonymous-visitor-sees-only-that-portals-content
	test('An anonymous visitor sees only that portal\'s content', async ({ browser }) => {
		const news = await anonymous.get(`${APP_API}/content/news?portal=${A.slug}`)
		expect(await news.text(), 'control: org-a\'s own news item is listed').toContain(`Nieuws-${A.slug}`)

		const leaks: string[] = []
		for (const path of ['site', 'pages', 'menus', 'news', 'glossary', 'faq', 'finder', 'start-tiles', 'catalogue', 'accessibility']) {
			const res = await anonymous.get(`${APP_API}/content/${path}?portal=${A.slug}`)
			const found = leaked(await res.text())
			if (found.length > 0) {
				leaks.push(`/api/content/${path}: ${found.join(', ')}`)
			}
		}
		const other = await anonymous.get(`${APP_API}/content/news/${B.ids.news}?portal=${A.slug}`)
		if (leaked(await other.text()).length > 0) {
			leaks.push('/api/content/news/{id} with org-b\'s item')
		}
		expect(leaks, 'org-a content routes that carried org-b').toEqual([])

		const context = await browser.newContext({ baseURL: BASE_URL, storageState: EMPTY_STATE })
		const page = await context.newPage()
		await page.goto(`${SITE_PATH}?portal=${A.slug}`)
		await expect(page.locator('body')).toContainText(A.title)
		expect(leaked(await page.content()), 'the org-a site page shows org-b').toEqual([])
		await context.close()
	})

	// e2e: portal-tenancy::a-host-already-in-use-is-refused
	test('A host already in use is refused', async () => {
		const host = `mijn.opo-${STAMP}.example`
		const first = await admin.post(`${APP_API}/portals/provision`, {
			headers: ADMIN_HEADERS,
			data: { organisation: B.organisation, slug: `opo-host-b-${STAMP}`, title: 'OPO host B', host },
		})
		expect(first.status(), `provisioning the first portal: ${(await first.text()).slice(0, 200)}`).toBe(201)

		const second = await admin.post(`${APP_API}/portals/provision`, {
			headers: ADMIN_HEADERS,
			data: { organisation: `opo-org-c-${STAMP}`, slug: `opo-host-c-${STAMP}`, title: 'OPO host C', host },
		})
		expect(second.status()).toBe(409)
		expect((await second.json()).message).toBe('This web address is already used by another portal.')

		const written = await admin.get(`${OBJECTS}/portal?slug=opo-host-c-${STAMP}`, { headers: ADMIN_HEADERS })
		// The list echoes the slug filter in `@self.query`, so count the rows.
		expect(((await written.json()).results || []).length, 'the refused portal must not be written').toBe(0)

		const created = await admin.get(`${OBJECTS}/portal?slug=opo-host-b-${STAMP}`, { headers: ADMIN_HEADERS })
		for (const row of ((await created.json()).results || []) as Array<Record<string, unknown>>) {
			await admin.delete(`${OBJECTS}/portal/${String(row.id ?? (row['@self'] as Record<string, unknown>)?.id)}`, { headers: ADMIN_HEADERS })
		}
	})

	// e2e: portal-tenancy::a-municipality-changes-its-own-theme
	test('A municipality changes its own theme, and only its own', async ({ playwright }) => {
		for (const [own, other] of [[A, B], [B, A]]) {
			const basic = Buffer.from(`${own.member}:${own.password}`).toString('base64')
			const headers = { Authorization: `Basic ${basic}`, 'OCS-APIRequest': 'true' }
			const context = await playwright.request.newContext({ baseURL: BASE_URL, storageState: EMPTY_STATE })

			const list = await context.get(`${APP_API}/portals/${own.slug}/theme`, { headers })
			expect(list.status(), `${own.member} reads the theme of their own portal`).toBe(200)
			const { current, sets } = await list.json() as { current: string, sets: Array<{ id: string }> }
			expect(sets.length, 'the instance offers no theme set to choose').toBeGreaterThan(0)
			const choice = sets.find((set) => set.id !== current)?.id ?? sets[0].id

			const save = await context.put(`${APP_API}/portals/${own.slug}/theme`, { headers, data: { theme: choice, acceptFindings: true } })
			expect(save.status(), `${own.member} saves the theme of their own portal`).toBe(200)
			expect((await save.json()).current).toBe(choice)

			const closed = await context.get(`${APP_API}/portals/${other.slug}/theme`, { headers })
			expect(closed.status(), `${own.member} must not see the other organisation's portal`).toBe(404)
			const refused = await context.put(`${APP_API}/portals/${other.slug}/theme`, { headers, data: { theme: choice, acceptFindings: true } })
			expect(refused.status(), `${own.member} must not change the other organisation's theme`).toBe(404)
			await context.dispose()
		}
	})
})
