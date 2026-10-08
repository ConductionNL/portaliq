/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * End-to-end for portal-visibility-follows-the-party-tree: a holding company
 * sees the cases of the entities below it on one mandate, each case still
 * naming the entity it belongs to, and a flat mandate stays flat.
 *
 * WHAT THIS FILE ANCHORS, AND WHAT IT DOES NOT.
 *
 * Anchored here:
 *   - one mandate covers the group (REQ-PTV-001)
 *   - a flat mandate stays flat (REQ-PTV-001)
 *   - a case type that refuses the tree stays with its own entity (REQ-PTV-002)
 *   - a subsidiary sold today is gone today, one acquired today is there (REQ-PTV-003)
 *   - the list says whose case it is, and the case still belongs to the
 *     subsidiary (REQ-PTV-005)
 *   - the switcher offers the subsidiaries (REQ-PTV-006)
 *
 * NOT anchored here, covered by PHPUnit and named so anyone can check:
 *   - a sibling is not below, and silence is a refusal:
 *     tests/Unit/Service/Identity/PortalPartyTreeResolverTest.php and
 *     tests/Unit/Service/PortalCaseListReaderTest.php
 *   - a deep group refused rather than truncated, and that every read of the
 *     relations carries a limit:
 *     tests/Unit/Service/Identity/PortalPartyTreeResolverTest.php
 *     (testAGroupDeeperThanTheBoundIsRefusedNotTruncated, testEveryReadCarriesALimit)
 *     and tests/Unit/Controller/MyCasesControllerTest.php
 *     (testAGroupPastTheBoundIsRefusedRatherThanTruncated)
 *   - the write naming the entity and the mandate:
 *     tests/Unit/Service/CitizenWriteRecorderTest.php
 *     (testAWriteForAnEntityBelowTheMandateRecordsBoth)
 *
 * Requires the seeded instance tests/e2e/ci-seed.sh provisions. Run manually:
 *
 *     NEXTCLOUD_URL=http://localhost:8080 npx playwright test portal-visibility-follows
 *
 * @spec openspec/changes/portal-visibility-follows-the-party-tree/specs/portal-visibility-and-the-party-tree/spec.md
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'

const API_BASE = '/apps/portaliq/portal/api'
const APP_API_BASE = '/apps/portaliq/api'
const OR_OBJECTS_BASE = '/apps/openregister/api/objects'

const ADMIN = Buffer.from('admin:admin').toString('base64')
const ORGANISATION = 'dev-org'

/** Create one object through OpenRegister's own object API, as the dev admin. */
async function seed(
	request: APIRequestContext,
	schema: string,
	data: Record<string, unknown>,
): Promise<string> {
	const res = await request.post(`${OR_OBJECTS_BASE}/portaliq/${schema}`, {
		headers: { Authorization: `Basic ${ADMIN}`, 'OCS-APIRequest': 'true' },
		data,
	})
	expect(
		res.ok(),
		`OpenRegister objects#create must be reachable for ${schema}`,
	).toBeTruthy()
	const body = await res.json()
	const id = (body.id ?? body['@self']?.id) as string
	expect(id).toBeTruthy()
	return id
}

/** The contribution whose cases collection declares one type parent-reachable. */
async function seedContribution(request: APIRequestContext): Promise<string> {
	return seed(request, 'portalPage', {
		label: 'Mijn zaken',
		audience: 'client',
		status: 'active',
		collections: [
			{
				id: 'cases',
				kind: 'cases',
				label: 'Mijn zaken',
				register: 'portaliq',
				schema: 'portalCase',
				scopeField: 'subjectRef',
				mandateField: 'organisation',
				caseTypeField: 'caseType',
				parentReachableTypes: ['vergunning'],
				columns: [{ field: 'reference', label: 'Zaaknummer' }],
			},
		],
		pages: [
			{
				id: 'mijn-zaken',
				label: 'Mijn zaken',
				blocks: [{ type: 'collection', collection: 'cases' }],
			},
		],
	})
}

/** An entity in the party tree, optionally below a parent. */
async function seedEntity(
	request: APIRequestContext,
	slug: string,
	parent = '',
): Promise<string> {
	return seed(request, 'organisation', { slug, title: slug, parent })
}

/** A case belonging to one entity. */
async function seedCase(
	request: APIRequestContext,
	entity: string,
	reference: string,
	caseType: string,
): Promise<string> {
	return seed(request, 'portalCase', {
		subjectRef: `owner-of-${reference}`,
		organisation: entity,
		reference,
		caseType,
		status: 'ontvangen',
	})
}

/** A portal identity with a mandate on one entity, reaching down or not. */
async function seedMandatedIdentity(
	request: APIRequestContext,
	entity: string,
	reach: 'organisation' | 'tree',
): Promise<string> {
	const res = await request.post(`${APP_API_BASE}/accounts/provision`, {
		headers: {
			Authorization: `Basic ${ADMIN}`,
			'OCS-APIRequest': 'true',
			requesttoken: 'x',
		},
		data: {
			audience: 'client',
			organisation: ORGANISATION,
			identityType: 'eherkenning',
			identityRef: `holder-${entity}-${reach}-${Date.now()}`,
		},
	})
	expect(res.ok()).toBeTruthy()
	const { subjectRef } = await res.json()

	await seed(request, 'portalMandate', {
		subjectRef,
		organisation: ORGANISATION,
		onBehalfOf: entity,
		label: 'Gemachtigd voor de groep',
		caseTypes: [],
		reach,
		status: 'active',
	})

	const login = await request.post(`${API_BASE}/session/dev-login`, {
		data: { subjectRef, audience: 'client', organisation: ORGANISATION },
	})
	expect(
		login.ok(),
		'dev-login must be enabled (see tests/e2e/ci-seed.sh)',
	).toBeTruthy()
	const { token } = await login.json()
	return token as string
}

/** What one identity's case list answers. */
async function myCases(
	request: APIRequestContext,
	token: string,
): Promise<Record<string, never>> {
	const res = await request.get(`${API_BASE}/my-cases`, {
		headers: { Authorization: `Bearer ${token}` },
	})
	expect(res.ok()).toBeTruthy()
	return res.json()
}

test.describe('portal-visibility-follows-the-party-tree', () => {
	test('one mandate covers the group, and each case names the entity it belongs to', async ({
		request,
	}) => {
		await seedContribution(request)
		const stamp = Date.now()
		const parent = `holding-${stamp}`
		const subOne = `sub-a-${stamp}`
		const subTwo = `sub-b-${stamp}`

		await seedEntity(request, parent)
		await seedEntity(request, subOne, parent)
		await seedEntity(request, subTwo, parent)

		await seedCase(request, parent, `ZAAK-P-${stamp}`, 'vergunning')
		await seedCase(request, subOne, `ZAAK-A-${stamp}`, 'vergunning')
		await seedCase(request, subTwo, `ZAAK-B-${stamp}`, 'vergunning')

		const body = await myCases(
			request,
			await seedMandatedIdentity(request, parent, 'tree'),
		)
		const rows = (body.cases ?? []) as Array<Record<string, never>>
		const references = rows.map((row) => row.reference as unknown as string)

		expect(references).toContain(`ZAAK-P-${stamp}`)
		expect(references).toContain(`ZAAK-A-${stamp}`)
		expect(references).toContain(`ZAAK-B-${stamp}`)

		// The subsidiary's case is never presented as the parent's own.
		const borrowed = rows.find(
			(row) => (row.reference as unknown as string) === `ZAAK-A-${stamp}`,
		)
		expect(borrowed?._entity as unknown as string).toBe(subOne)
		expect(
			(borrowed?._mandate as unknown as Record<string, string>)?.label,
		).toBe('Gemachtigd voor de groep')

		// And the switcher offers what the mandate reaches.
		const entities =
			(body.activeMandate as unknown as Record<string, string[]>)?.entities
			?? []
		expect(entities).toContain(subOne)
		expect(entities).toContain(subTwo)
	})

	test('a flat mandate stays flat, and a case type that refuses the tree stays with its entity', async ({
		request,
	}) => {
		await seedContribution(request)
		const stamp = Date.now()
		const parent = `holding-flat-${stamp}`
		const sub = `sub-flat-${stamp}`

		await seedEntity(request, parent)
		await seedEntity(request, sub, parent)
		await seedCase(request, parent, `ZAAK-P-${stamp}`, 'vergunning')
		await seedCase(request, sub, `ZAAK-S-${stamp}`, 'vergunning')
		// A melding is not declared parent-reachable by the contribution.
		await seedCase(request, sub, `MELDING-${stamp}`, 'melding')

		const flat = await myCases(
			request,
			await seedMandatedIdentity(request, parent, 'organisation'),
		)
		const flatRefs = ((flat.cases ?? []) as Array<Record<string, never>>).map(
			(row) => row.reference as unknown as string,
		)
		expect(flatRefs).toContain(`ZAAK-P-${stamp}`)
		expect(flatRefs).not.toContain(`ZAAK-S-${stamp}`)

		const wide = await myCases(
			request,
			await seedMandatedIdentity(request, parent, 'tree'),
		)
		const wideRefs = ((wide.cases ?? []) as Array<Record<string, never>>).map(
			(row) => row.reference as unknown as string,
		)
		expect(wideRefs).toContain(`ZAAK-S-${stamp}`)
		expect(wideRefs).not.toContain(`MELDING-${stamp}`)
	})

	test('a subsidiary sold today is gone today, and one acquired today is there', async ({
		request,
	}) => {
		await seedContribution(request)
		const stamp = Date.now()
		const parent = `holding-moves-${stamp}`
		const sold = `sold-${stamp}`

		await seedEntity(request, parent)
		const soldId = await seedEntity(request, sold, parent)
		await seedCase(request, sold, `ZAAK-SOLD-${stamp}`, 'vergunning')

		const token = await seedMandatedIdentity(request, parent, 'tree')
		const before = await myCases(request, token)
		expect(
			((before.cases ?? []) as Array<Record<string, never>>).map(
				(row) => row.reference as unknown as string,
			),
		).toContain(`ZAAK-SOLD-${stamp}`)

		// The group changes by being recorded as changed. No grant is revoked.
		const update = await request.put(
			`${OR_OBJECTS_BASE}/portaliq/organisation/${soldId}`,
			{
				headers: {
					Authorization: `Basic ${ADMIN}`,
					'OCS-APIRequest': 'true',
				},
				data: { slug: sold, title: sold, parent: '' },
			},
		)
		expect(update.ok()).toBeTruthy()

		const after = await myCases(request, token)
		expect(
			((after.cases ?? []) as Array<Record<string, never>>).map(
				(row) => row.reference as unknown as string,
			),
		).not.toContain(`ZAAK-SOLD-${stamp}`)
	})
})
