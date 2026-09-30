/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Woo citizen journey, J1 to J6, end to end across portaliq,
 * opencatalogi, pipelinq and dossiq (hydra
 * `openspec/changes/woo-citizen-journey`, task T11).
 *
 * Every step is asserted against the objects OpenRegister stores, not only
 * against what a screen shows: a green screen over an object that was never
 * written is the failure this spec exists to catch.
 *
 * WHAT IT NEEDS
 * -------------
 * - portaliq, opencatalogi, pipelinq and dossiq with the Woo journey build
 *   PRs merged, and their registers imported.
 * - `portaliq/dev_login_enabled = yes` (tests/e2e/ci-seed.sh), and a portal
 *   the site serves (`PORTALIQ_E2E_PORTAL`, default `open-tilburg`).
 * - `occ` for the background jobs: `PORTALIQ_E2E_CONTAINER=nextcloud` (see
 *   shared-instance.ts `occPrefix()`).
 * - On a shared instance: `PORTALIQ_E2E_ALLOW_SHARED_INSTANCE=<origin>`.
 *
 * Every object it creates is recorded and deleted in afterAll, newest first.
 *
 * WHAT IS MARKED fixme, AND WHY (read at the lane branch heads on 30 Sep):
 * - J1 and J5 "publish a decision from dossiq": `POST /woo/assessment`
 *   answers 400 until the app config `dossiq.woo_assessment_schema` points
 *   at a schema, and dossiq ships none; and publish reads documents from
 *   `document_schema` while the case dossier upload writes elsewhere. Without
 *   an assessed-public document, publish answers 409
 *   `no_publishable_documents`. Set `WOO_E2E_DOSSIQ_PUBLISH=1` once dossiq
 *   fixes that, and the steps run.
 * - J1 "publish an opencatalogi Woo batch": publish needs a configured
 *   `woo_publish_approval_chain` and a completed approval task sequence.
 *   Set `WOO_E2E_BATCH_PUBLISH=1` on an instance that has one.
 * Until then J1's publication is created directly in OpenRegister with the
 * fields dossiq's publish writes, so J2 to J6 still run on a real, public
 * publication.
 *
 * Run it:
 *
 *     PORTALIQ_E2E_ALLOW_SHARED_INSTANCE=http://localhost:8080 \
 *     PLAYWRIGHT_BASE_URL=http://localhost:8080 PORTALIQ_E2E_CONTAINER=nextcloud \
 *     npx playwright test -c tests/e2e/playwright.config.ts woo-journey
 *
 * @spec openspec/changes/woo-search-and-detail/specs/portal-federated-search/spec.md
 * @spec openspec/changes/woo-journey-entry-points/specs/portal-contribution-contract/spec.md
 * @spec openspec/changes/my-dossiers/specs/portal-contribution-contract/spec.md
 */

import type { APIRequestContext, APIResponse } from '@playwright/test'

import { expect, request as playwrightRequest, test } from '@playwright/test'
import { execFileSync } from 'node:child_process'
import { BASE_URL } from './base-url.ts'
import { occPrefix } from './shared-instance.ts'

const STAMP = Date.now()
const TOKEN_WORD = `woo${STAMP}`
const PORTAL = process.env.PORTALIQ_E2E_PORTAL ?? 'open-tilburg'
const SEARCH_ROUTE = `/woo-e2e-zoeken-${STAMP}`
const DETAIL_ROUTE = `/woo-e2e-publicatie-${STAMP}`
const SITE = '/index.php/apps/portaliq/site'
const PORTAL_API = '/index.php/apps/portaliq/portal/api'
const OR_API = '/index.php/apps/openregister/api/objects'
const WOO_CASE_TYPE = '3c0f5a00-0000-4000-a000-00000000a001'
const RUN_DOSSIQ_PUBLISH = process.env.WOO_E2E_DOSSIQ_PUBLISH === '1'
const RUN_BATCH_PUBLISH = process.env.WOO_E2E_BATCH_PUBLISH === '1'

const ADMIN_HEADERS = {
	Authorization: `Basic ${Buffer.from('admin:admin').toString('base64')}`,
	'OCS-APIRequest': 'true',
	requesttoken: 'x',
}

/** One object this run created, to delete in afterAll. */
interface Created {
	register: string
	schema: string
	id: string
}

/** A collection as the resident's manifest declares it. */
interface ManifestCollection {
	id: string
	register: string
	schema: string
}

/** State shared by the serial steps. */
const state: {
	admin?: APIRequestContext
	created: Created[]
	subjectRef: string
	token: string
	publicationId: string
	secondPublicationId: string
	fileId: string
	dossiers?: ManifestCollection
	savedSearches?: ManifestCollection
	dossierId: string
	shareToken: string
	ticketId: string
	caseFromDossier: string
	savedSearchId: string
} = {
	created: [],
	subjectRef: '',
	token: '',
	publicationId: '',
	secondPublicationId: '',
	fileId: '',
	dossierId: '',
	shareToken: '',
	ticketId: '',
	caseFromDossier: '',
	savedSearchId: '',
}

/**
 * The admin request context.
 *
 * @return The context.
 */
function admin(): APIRequestContext {
	if (!state.admin) throw new Error('beforeAll did not run')
	return state.admin
}

/**
 * The JSON body of an answer, with its status in the failure message.
 *
 * @param res The answer.
 * @param what What was asked, for the message.
 * @return The parsed body.
 */
async function json(res: APIResponse, what: string): Promise<any> {
	const text = await res.text()
	expect(
		res.ok(),
		`${what}: HTTP ${res.status()} ${text.slice(0, 400)}`,
	).toBeTruthy()
	return text === '' ? {} : JSON.parse(text)
}

/**
 * The id of an OpenRegister object in either envelope.
 *
 * @param object The object.
 * @return Its id.
 */
function idOf(object: any): string {
	return String(object?.id ?? object?.uuid ?? object?.['@self']?.id ?? '')
}

/**
 * Create an OpenRegister object as admin and record it for cleanup.
 *
 * @param register Register slug.
 * @param schema Schema slug.
 * @param data The object.
 * @return The stored object.
 */
async function createObject(
	register: string,
	schema: string,
	data: object,
): Promise<any> {
	const res = await admin().post(`${OR_API}/${register}/${schema}`, {
		headers: ADMIN_HEADERS,
		data,
	})
	const object = await json(res, `create ${register}/${schema}`)
	state.created.push({ register, schema, id: idOf(object) })
	return object
}

/**
 * Record an object another app created, for cleanup.
 *
 * @param register Register slug.
 * @param schema Schema slug.
 * @param id The id.
 */
function remember(register: string, schema: string, id: string): void {
	if (id !== '' && !state.created.some((c) => c.id === id)) {
		state.created.push({ register, schema, id })
	}
}

/**
 * Read one OpenRegister object as admin.
 *
 * @param register Register slug.
 * @param schema Schema slug.
 * @param id The id.
 * @return The object.
 */
async function readObject(
	register: string,
	schema: string,
	id: string,
): Promise<any> {
	return json(
		await admin().get(`${OR_API}/${register}/${schema}/${id}`, {
			headers: ADMIN_HEADERS,
		}),
		`read ${register}/${schema}/${id}`,
	)
}

/**
 * List OpenRegister objects as admin, filtered by properties.
 *
 * @param register Register slug.
 * @param schema Schema slug.
 * @param filters Property filters.
 * @return The rows.
 */
async function listObjects(
	register: string,
	schema: string,
	filters: Record<string, string>,
): Promise<any[]> {
	const params = new URLSearchParams({ ...filters, _limit: '200' })
	const body = await json(
		await admin().get(`${OR_API}/${register}/${schema}?${params.toString()}`, {
			headers: ADMIN_HEADERS,
		}),
		`list ${register}/${schema}`,
	)
	return body.results ?? []
}

/**
 * Patch an OpenRegister object as admin.
 *
 * @param register Register slug.
 * @param schema Schema slug.
 * @param id The id.
 * @param data The fields.
 * @return The stored object.
 */
async function patchObject(
	register: string,
	schema: string,
	id: string,
	data: object,
): Promise<any> {
	return json(
		await admin().patch(`${OR_API}/${register}/${schema}/${id}`, {
			headers: ADMIN_HEADERS,
			data,
		}),
		`patch ${register}/${schema}/${id}`,
	)
}

/**
 * The resident's bearer headers.
 *
 * @return Headers.
 */
function resident(): Record<string, string> {
	return { Authorization: `Bearer ${state.token}` }
}

/**
 * Run a page-level portal action as the resident.
 *
 * @param app The contributing app.
 * @param action The action id.
 * @param data The body.
 * @return The answer.
 */
async function portalAction(
	app: string,
	action: string,
	data: object,
): Promise<APIResponse> {
	return admin().post(`${PORTAL_API}/actions/${app}/${action}`, {
		headers: resident(),
		data,
	})
}

/**
 * Run a row action (or another app's attached action) on the resident's row.
 *
 * @param collection The collection.
 * @param rowId The row.
 * @param action The action id.
 * @param data The body.
 * @param actionApp The app of an attached action, or ''.
 * @return The answer.
 */
async function rowAction(
	collection: ManifestCollection,
	rowId: string,
	action: string,
	data: object,
	actionApp = '',
): Promise<APIResponse> {
	const params = new URLSearchParams({ collection: collection.id })
	if (actionApp !== '') params.set('actionApp', actionApp)
	return admin().post(
		`${PORTAL_API}/collections/${collection.register}/${collection.schema}/${rowId}/actions/${action}?${params.toString()}`,
		{ headers: resident(), data },
	)
}

/**
 * Run occ in the instance.
 *
 * @param args The occ arguments.
 * @return Its output.
 */
function occ(...args: string[]): string {
	const [command, ...prefix] = occPrefix()
	return execFileSync(command, [...prefix, ...args], {
		encoding: 'utf8',
		timeout: 300_000,
	})
}

/**
 * Run one background job class once, forced.
 *
 * @param fqcn The job class.
 */
function runJob(fqcn: string): void {
	const listing = occ('background-job:list', `--class=${fqcn}`)
	const ids = [...listing.matchAll(/^\|\s*(\d+)\s*\|/gm)].map((m) => m[1])
	expect(ids.length, `${fqcn} is registered as a background job`).toBeGreaterThan(
		0,
	)
	occ('background-job:execute', ids[0], '--force-execute')
}

/**
 * A portal page with one block, recorded for cleanup.
 *
 * @param route The in-site route.
 * @param title The title.
 * @param widget The block.
 */
async function createPage(
	route: string,
	title: string,
	widget: object,
): Promise<void> {
	await createObject('portaliq', 'page', {
		title,
		route,
		portal: PORTAL,
		status: 'published',
		locale: 'nl',
		body: {
			type: 'grid',
			widgets: [
				{
					id: 'main',
					slot: 'body',
					gridX: 0,
					gridY: 0,
					gridWidth: 12,
					gridHeight: 6,
					...widget,
				},
			],
		},
	})
}

test.describe.serial('the Woo citizen journey across four apps', () => {
	test.beforeAll(async () => {
		state.admin = await playwrightRequest.newContext({ baseURL: BASE_URL })

		// A resident with a portal account, so notices have somewhere to go.
		const account = await json(
			await admin().post('/index.php/apps/portaliq/api/accounts/provision', {
				headers: ADMIN_HEADERS,
				data: {
					audience: 'client',
					organisation: 'dev-org',
					identityType: 'digid',
					identityRef: `e2e-${STAMP}`,
					email: `woo-e2e-${STAMP}@example.org`,
					verifiedEmail: true,
					displayName: 'Inwoner Woo',
				},
			}),
			'provision a portal account',
		)
		state.subjectRef = String(account.subjectRef)
		remember('portaliq', 'portalAccount', idOf(account))

		const login = await json(
			await admin().post(`${PORTAL_API}/session/dev-login`, {
				data: {
					subjectRef: state.subjectRef,
					audience: 'client',
					organisation: 'dev-org',
				},
			}),
			'dev-login (portaliq/dev_login_enabled must be yes)',
		)
		state.token = String(login.token)

		// The resident's manifest names the collections the steps act on.
		const manifest = await json(
			await admin().get(`${PORTAL_API}/contributions`, {
				headers: resident(),
			}),
			'the resident manifest',
		)
		const catalogi = (manifest.contributions ?? []).find(
			(c: any) => c.app === 'opencatalogi',
		)
		expect(
			catalogi,
			'opencatalogi contributes to a client resident',
		).toBeTruthy()
		state.dossiers = catalogi.collections.find(
			(c: any) => c.schema === 'collection',
		)
		state.savedSearches = catalogi.collections.find(
			(c: any) => c.schema === 'savedSearch',
		)
		expect(
			state.dossiers?.itemList,
			'the dossier collection declares its item list',
		).toBeTruthy()
		const attached = (state.dossiers as any).attachedActions ?? []
		expect(attached.map((a: any) => `${a.app}:${a.id}`)).toEqual(
			expect.arrayContaining([
				'pipelinq:askAboutDossier',
				'dossiq:startWooVerzoek',
			]),
		)

		// The two site pages this run searches on and reads from.
		await createPage(SEARCH_ROUTE, 'Woo zoeken (e2e)', {
			widgetKey: 'federatedSearch',
			props: { detailRoute: DETAIL_ROUTE },
		})
		await createPage(DETAIL_ROUTE, 'Woo publicatie (e2e)', {
			widgetKey: 'publicationDetail',
			// OpenRegister refuses an empty object here; the default endpoint,
			// spelled out, is the smallest valid props.
			props: {
				endpoint: '/index.php/apps/opencatalogi/api/federation/publications',
			},
		})
	})

	test.afterAll(async () => {
		if (!state.admin) return
		// Notices written for this resident by portaliq itself.
		if (state.subjectRef !== '') {
			for (const message of await listObjects('portaliq', 'portalMessage', {
				subjectRef: state.subjectRef,
			}).catch(() => [])) {
				remember('portaliq', 'portalMessage', idOf(message))
			}
		}
		for (const { register, schema, id } of [...state.created].reverse()) {
			await admin()
				.delete(`${OR_API}/${register}/${schema}/${id}`, {
					headers: ADMIN_HEADERS,
				})
				.catch(() => undefined)
		}
		await state.admin.dispose()
	})

	// ------------------------------------------------------------------ J1
	test('J1 a Woo request decision and an active disclosure become public publications', async () => {
		if (RUN_DOSSIQ_PUBLISH) {
			const wooCase = await createObject('dossiq', 'case', {
				title: `Woo-verzoek e2e ${TOKEN_WORD}`,
				caseType: WOO_CASE_TYPE,
				startDate: new Date().toISOString().slice(0, 10),
				wooRequest: {
					onderwerp: `Fietspad ${TOKEN_WORD}`,
					omschrijving: 'e2e',
					origin: 'portal',
				},
			})
			const caseId = idOf(wooCase)
			const document = await createObject('dossiq', 'document', {
				title: `Besluit ${TOKEN_WORD}`,
				case: caseId,
				fileName: 'besluit.txt',
				format: 'text/plain',
				content: Buffer.from(`Besluit ${TOKEN_WORD}`).toString('base64'),
			})
			await json(
				await admin().post(
					`/index.php/apps/dossiq/api/cases/${caseId}/woo/assessment`,
					{
						headers: ADMIN_HEADERS,
						data: {
							assessments: [
								{
									documentRef: idOf(document),
									classification: 'openbaar',
								},
							],
						},
					},
				),
				'assess the document as public',
			)
			await json(
				await admin().post(
					`/index.php/apps/dossiq/api/cases/${caseId}/woo/decision`,
					{ headers: ADMIN_HEADERS, data: { decision: {} } },
				),
				'assemble the Woo decision',
			)
			const published = await json(
				await admin().post(
					`/index.php/apps/dossiq/api/cases/${caseId}/woo/publish`,
					{ headers: ADMIN_HEADERS, data: {} },
				),
				'publish the decision',
			)
			state.publicationId = String(published.publicationId)
			remember('publication', 'publication', state.publicationId)
			const publication = await readObject(
				'publication',
				'publication',
				state.publicationId,
			)
			expect(publication.publicationKind).toBe('woo-besluit')
			expect(publication.wooCategory).toBe('infocat014')
			expect(publication.caseReference).toBe(caseId)
			expect(
				(await readObject('dossiq', 'case', caseId)).wooPublicationStatus,
			).toBe('published')
		} else {
			// dossiq's publish is blocked (see the file header): the publication
			// it would write, written directly.
			const publication = await createObject('publication', 'publication', {
				title: `Besluit op Woo-verzoek fietspad ${TOKEN_WORD}`,
				summary: 'Besluit op een Woo-verzoek, e2e.',
				publicationDate: new Date(Date.now() - 60_000).toISOString(),
				publicationKind: 'woo-besluit',
				wooCategory: 'infocat014',
				caseReference: `e2e-case-${STAMP}`,
				status: 'published',
			})
			state.publicationId = idOf(publication)
		}

		// One document on the publication, shared so the public read lists it.
		const upload = await json(
			await admin().post(
				`${OR_API}/publication/publication/${state.publicationId}/files`,
				{
					headers: ADMIN_HEADERS,
					data: {
						name: `besluit-${STAMP}.txt`,
						content: `Besluit ${TOKEN_WORD}`,
						share: true,
					},
				},
			),
			'attach a document to the publication',
		)
		state.fileId = String(upload.id ?? '')

		const stored = await readObject(
			'publication',
			'publication',
			state.publicationId,
		)
		expect(stored.publicationKind).toBe('woo-besluit')
		expect(stored.wooCategory).toBe('infocat014')
		expect(String(stored.caseReference ?? '')).not.toBe('')

		// The active disclosure path: an opencatalogi Woo batch.
		test.fixme(
			!RUN_BATCH_PUBLISH,
			'batch publish needs woo_publish_approval_chain and a completed approval sequence (WooService::assertPublishApproved)',
		)
		const batch = await json(
			await admin().post('/index.php/apps/opencatalogi/api/woo/batches', {
				headers: ADMIN_HEADERS,
				data: {
					caseReference: `e2e-batch-${STAMP}`,
					title: `Actieve openbaarmaking ${TOKEN_WORD}`,
					wooCategory: 'infocat009',
					documents: [],
				},
			}),
			'create a Woo batch',
		)
		remember('publication', 'wooBatch', idOf(batch))
		await json(
			await admin().post(
				`/index.php/apps/opencatalogi/api/woo/batches/${idOf(batch)}/ready-for-review`,
				{ headers: ADMIN_HEADERS },
			),
			'batch ready for review',
		)
		await json(
			await admin().post(
				`/index.php/apps/opencatalogi/api/woo/batches/${idOf(batch)}/publish`,
				{ headers: ADMIN_HEADERS },
			),
			'publish the batch',
		)
		const after = await readObject('publication', 'wooBatch', idOf(batch))
		const batchPublication = String(after.wooPublication?.publication ?? '')
		expect(batchPublication).not.toBe('')
		remember('publication', 'publication', batchPublication)
		expect(
			(await readObject('publication', 'publication', batchPublication))
				.publicationKind,
		).toBe('actief')
	})

	// ------------------------------------------------------------------ J2
	// @e2e portal-federated-search::a-resident-narrows-by-information-category
	// @e2e portal-federated-search::a-resident-downloads-a-decision
	// @e2e portal-federated-search::an-anonymous-visitor-searches
	test('J2 an anonymous visitor finds the publication by category and downloads its document', async ({
		page,
	}) => {
		await page.goto(`${SITE}?route=${SEARCH_ROUTE}&_search=${TOKEN_WORD}`)
		const facet = page.getByTestId('federated-search-facet-infocat014')
		await expect(facet).toBeVisible()
		await facet.check()
		await expect(page).toHaveURL(/f\.wooCategory=infocat014/)
		await expect(
			page
				.getByText(`Besluit op Woo-verzoek fietspad ${TOKEN_WORD}`)
				.or(page.getByText(`Woo-verzoek e2e ${TOKEN_WORD}`))
				.first(),
		).toBeVisible()
		await expect(page.getByTestId('save-search')).toHaveCount(0)

		await page.goto(`${SITE}?route=${DETAIL_ROUTE}/${state.publicationId}`)
		const documents = page.getByTestId('publication-documents')
		await expect(documents).toBeVisible()
		const link = documents.getByRole('link', { name: `besluit-${STAMP}.txt` })
		await expect(link).toBeVisible()
		expect(await link.getAttribute('href')).toMatch(/^(https?:\/\/|\/)/)
		await expect(page.getByTestId('save-to-dossier')).toHaveCount(0)
	})

	// ------------------------------------------------------------------ J3
	// @e2e portal-contribution-contract::a-resident-opens-their-own-dossier
	// @e2e portal-contribution-contract::a-depublished-item-in-the-owners-view
	// @e2e portal-contribution-contract::a-resident-shares-a-dossier
	test('J3 a resident keeps the publication in a dossier, shares it, and revokes the link', async ({
		page,
	}) => {
		// The site button, signed in through the fragment the edge hands back.
		await page.goto(
			`${SITE}?route=${DETAIL_ROUTE}/${state.publicationId}#token=${encodeURIComponent(state.token)}`,
		)
		const save = page.getByTestId('save-to-dossier-open').first()
		await expect(save).toBeVisible()
		await save.click()
		await page.getByTestId('save-to-dossier-select').first().selectOption('')
		await page
			.getByTestId('save-to-dossier-title')
			.first()
			.fill(`Fietspad ${TOKEN_WORD}`)
		await page.getByTestId('save-to-dossier-submit').first().click()
		await expect(
			page.getByTestId('save-to-dossier-status').first(),
		).toContainText(`Bewaard in Fietspad ${TOKEN_WORD}`)

		const dossiers = await listObjects('publication', 'collection', {
			owner: state.subjectRef,
		})
		expect(dossiers).toHaveLength(1)
		state.dossierId = idOf(dossiers[0])
		remember('publication', 'collection', state.dossierId)
		expect(dossiers[0].items.map((i: any) => i.publication)).toContain(
			state.publicationId,
		)
		expect(dossiers[0].items[0].addedBy).toBe('resident')

		// A second publication, kept and then depublished: the owner still sees it.
		const second = await createObject('publication', 'publication', {
			title: `Bijlage ${TOKEN_WORD}`,
			publicationDate: new Date(Date.now() - 60_000).toISOString(),
			wooCategory: 'infocat014',
		})
		state.secondPublicationId = idOf(second)
		await json(
			await portalAction('opencatalogi', 'addToDossier', {
				collection: state.dossierId,
				publication: state.secondPublicationId,
			}),
			'keep a second publication',
		)
		await patchObject('publication', 'publication', state.secondPublicationId, {
			depublicationDate: new Date(Date.now() - 1000).toISOString(),
		})

		// Mijn dossiers: what the detail block reads.
		const items = await json(
			await admin().get(
				`${PORTAL_API}/collections/${state.dossiers!.register}/${state.dossiers!.schema}/${state.dossierId}/items?collection=${state.dossiers!.id}`,
				{ headers: resident() },
			),
			'the dossier items',
		)
		expect(items.items).toHaveLength(2)
		expect(items.items.filter((i: any) => i.public === false)).toHaveLength(1)

		// Share: only public items, and no owner.
		const share = await json(
			await rowAction(state.dossiers!, state.dossierId, 'shareDossier', {}),
			'share the dossier',
		)
		expect(String(share.link ?? '')).not.toBe('')
		state.shareToken = String(share.token)
		const shared = await json(
			await admin().get(
				`/index.php/apps/opencatalogi/api/collections/shared/${state.shareToken}`,
			),
			'the shared dossier',
		)
		expect(shared.items.map((i: any) => i.publication)).toEqual([
			state.publicationId,
		])
		expect(JSON.stringify(shared)).not.toContain(state.subjectRef)
		expect(shared).not.toHaveProperty('owner')

		// Revoke: the old link is gone.
		await json(
			await rowAction(state.dossiers!, state.dossierId, 'unshareDossier', {}),
			'revoke the link',
		)
		const revoked = await admin().get(
			`/index.php/apps/opencatalogi/api/collections/shared/${state.shareToken}`,
		)
		expect(revoked.status()).toBe(404)
	})

	// ------------------------------------------------------------------ J4
	// @e2e portal-contribution-contract::a-resident-asks-a-question-about-their-dossier
	// @e2e portal-notifications-and-preferences::an-answer-to-a-question
	test('J4 a resident asks about the dossier, gets an answer, replies, and the question becomes a Woo request', async () => {
		const asked = await json(
			await rowAction(
				state.dossiers!,
				state.dossierId,
				'askAboutDossier',
				{ question: `Wanneer wordt ${TOKEN_WORD} besloten?` },
				'pipelinq',
			),
			'ask about the dossier',
		)
		state.ticketId = String(asked.id)
		remember('pipelinq', 'ticket', state.ticketId)

		const ticket = await readObject('pipelinq', 'ticket', state.ticketId)
		expect(ticket.portalSubject).toBe(state.subjectRef)
		expect(ticket.subjectReference.type).toBe('opencatalogi.collection')
		expect(ticket.subjectReference.id).toBe(state.dossierId)
		expect(ticket.subjectReference.items.length).toBeGreaterThan(0)

		// The KCC employee answers on the ticket.
		await patchObject('pipelinq', 'ticket', state.ticketId, {
			customerMessage: `Het besluit volgt binnen vier weken. ${TOKEN_WORD}`,
		})

		// portaliq wrote the inbox notice and queued the email for the rule key.
		await expect
			.poll(
				async () =>
					(
						await listObjects('portaliq', 'portalMessage', {
							subjectRef: state.subjectRef,
						})
					).some(
						(m: any) =>
							m.recordLink?.app === 'pipelinq'
							&& m.recordLink?.id === state.ticketId,
					),
				{ timeout: 30_000 },
			)
			.toBe(true)
		expect(
			occ(
				'background-job:list',
				'--class=OCA\\Portaliq\\BackgroundJob\\NotificationDispatchJob',
			),
		).toContain('pipelinq.question.answered')

		// The resident replies.
		await json(
			await portalAction('pipelinq', 'replyToQuestion', {
				ticket: state.ticketId,
				message: `Dank, ${TOKEN_WORD}`,
			}),
			'reply to the answer',
		)
		const replied = await readObject('pipelinq', 'ticket', state.ticketId)
		expect(JSON.stringify(replied.portalReplies ?? [])).toContain(
			`Dank, ${TOKEN_WORD}`,
		)

		// The employee converts the question into a Woo request.
		const converted = await json(
			await admin().post(
				`/index.php/apps/pipelinq/api/tickets/${state.ticketId}/woo-request`,
				{ headers: ADMIN_HEADERS },
			),
			'convert to a Woo request',
		)
		const caseId = String(converted.caseReference)
		remember('dossiq', 'case', caseId)
		const caseObjects = await listObjects('dossiq', 'caseObject', {
			case: caseId,
		})
		caseObjects.forEach((o) => remember('dossiq', 'caseObject', idOf(o)))
		expect(caseObjects.length).toBe(ticket.subjectReference.items.length)
		expect(
			caseObjects.every((o) => o.objectType === 'opencatalogi.publication'),
		).toBeTruthy()
		const after = await readObject('pipelinq', 'ticket', state.ticketId)
		expect(after.status).toBe('converted')
		expect(after.caseReference).toBe(caseId)
	})

	// ------------------------------------------------------------------ J5
	test('J5 a resident starts a Woo request from the dossier and the decision comes back to it', async () => {
		const started = await json(
			await rowAction(
				state.dossiers!,
				state.dossierId,
				'startWooVerzoek',
				{
					onderwerp: `Fietspad ${TOKEN_WORD}`,
					omschrijving: 'Alle stukken.',
				},
				'dossiq',
			),
			'start a Woo request',
		)
		state.caseFromDossier = String(started.caseId)
		remember('dossiq', 'case', state.caseFromDossier)

		const dossier = await readObject(
			'publication',
			'collection',
			state.dossierId,
		)
		const caseObjects = await listObjects('dossiq', 'caseObject', {
			case: state.caseFromDossier,
		})
		caseObjects.forEach((o) => remember('dossiq', 'caseObject', idOf(o)))
		expect(caseObjects).toHaveLength(dossier.items.length)
		expect(dossier.sourceOf).toContain(`dossiq:case:${state.caseFromDossier}`)

		const myCases = await json(
			await admin().get(`${PORTAL_API}/my-cases`, { headers: resident() }),
			'Mijn zaken',
		)
		expect(JSON.stringify(myCases)).toContain(state.caseFromDossier)

		test.fixme(
			!RUN_DOSSIQ_PUBLISH,
			'dossiq publish needs an assessed-public document; see the file header (B1/B2)',
		)
		const document = await createObject('dossiq', 'document', {
			title: `Besluit ${TOKEN_WORD}`,
			case: state.caseFromDossier,
			fileName: 'besluit.txt',
			format: 'text/plain',
			content: Buffer.from('Besluit').toString('base64'),
		})
		await json(
			await admin().post(
				`/index.php/apps/dossiq/api/cases/${state.caseFromDossier}/woo/assessment`,
				{
					headers: ADMIN_HEADERS,
					data: {
						assessments: [
							{
								documentRef: idOf(document),
								classification: 'openbaar',
							},
						],
					},
				},
			),
			'assess',
		)
		await json(
			await admin().post(
				`/index.php/apps/dossiq/api/cases/${state.caseFromDossier}/woo/decision`,
				{ headers: ADMIN_HEADERS, data: { decision: {} } },
			),
			'decide',
		)
		const published = await json(
			await admin().post(
				`/index.php/apps/dossiq/api/cases/${state.caseFromDossier}/woo/publish`,
				{ headers: ADMIN_HEADERS, data: {} },
			),
			'publish',
		)
		remember('publication', 'publication', String(published.publicationId))
		const back = await readObject('publication', 'collection', state.dossierId)
		const returned = back.items.find(
			(i: any) => i.publication === String(published.publicationId),
		)
		expect(returned?.addedBy).toBe('dossiq')
		await expect
			.poll(
				async () =>
					(
						await listObjects('portaliq', 'portalMessage', {
							subjectRef: state.subjectRef,
						})
					).some((m: any) => m.recordLink?.app === 'dossiq'),
				{ timeout: 30_000 },
			)
			.toBe(true)
	})

	// ------------------------------------------------------------------ J6
	// @e2e portal-federated-search::a-resident-saves-a-daily-search
	test('J6 a resident saves a daily search, a new publication matches it, and pausing stops it', async () => {
		const saved = await json(
			await portalAction('opencatalogi', 'saveSearch', {
				title: `Fietspaden ${TOKEN_WORD}`,
				frequency: 'daily',
				query: {
					text: TOKEN_WORD,
					filters: {
						informatiecategorie: ['infocat014'],
						organisation: [],
						periodFrom: '',
						periodTo: '',
					},
					catalog: '',
				},
			}),
			'save the search',
		)
		state.savedSearchId = idOf(saved)
		remember('publication', 'savedSearch', state.savedSearchId)
		const stored = await readObject(
			'publication',
			'savedSearch',
			state.savedSearchId,
		)
		expect(stored.owner).toBe(state.subjectRef)
		expect(stored.frequency).toBe('daily')

		// A daily search runs once after 07:00 when it last ran before 07:00.
		const yesterday = new Date(Date.now() - 36 * 3600_000).toISOString()
		await patchObject('publication', 'savedSearch', state.savedSearchId, {
			lastRunAt: yesterday,
		})
		const fresh = await createObject('publication', 'publication', {
			title: `Nieuw besluit ${TOKEN_WORD}`,
			publicationDate: new Date(Date.now() - 30_000).toISOString(),
			wooCategory: 'infocat014',
			publicationKind: 'woo-besluit',
		})

		const localHour = new Date().getHours()
		test.skip(
			localHour < 7,
			'a daily search is due only after 07:00 on the instance clock',
		)
		runJob('OCA\\OpenCatalogi\\BackgroundJob\\SavedSearchMatchingJob')

		const matched = await readObject(
			'publication',
			'savedSearch',
			state.savedSearchId,
		)
		expect(String(matched.lastNotifiedAt ?? '')).not.toBe('')
		expect((matched.lastMatches ?? []).map((m: any) => m.publication)).toContain(
			idOf(fresh),
		)
		await expect
			.poll(
				async () =>
					(
						await listObjects('portaliq', 'portalMessage', {
							subjectRef: state.subjectRef,
						})
					).some(
						(m: any) =>
							m.recordLink?.app === 'opencatalogi'
							&& m.recordLink?.id === state.savedSearchId,
					),
				{ timeout: 30_000 },
			)
			.toBe(true)
		expect(
			occ(
				'background-job:list',
				'--class=OCA\\Portaliq\\BackgroundJob\\NotificationDispatchJob',
			),
		).toContain('opencatalogi.savedSearch.matched')

		// Pause.
		const paused = await json(
			await rowAction(
				state.savedSearches!,
				state.savedSearchId,
				'pauseSavedSearch',
				{},
			),
			'pause the search',
		)
		expect(paused.active).toBe(false)
		expect(
			(await readObject('publication', 'savedSearch', state.savedSearchId))
				.active,
		).toBe(false)
	})
})
