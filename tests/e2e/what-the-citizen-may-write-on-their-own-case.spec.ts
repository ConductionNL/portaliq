/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * End-to-end for what-the-citizen-may-write-on-their-own-case: a citizen
 * corrects an answer, adds the document that was missing, and reads the words
 * the municipality chose, all without phoning the desk.
 *
 * WHAT THIS FILE ANCHORS, AND WHAT IT DOES NOT.
 *
 * Anchored here (citizen-writes-on-their-own-case):
 *   - only the declared fields are editable
 *   - a closed field says why
 *   - a correction lands without a phone call
 *   - the window closes
 *   - an aanvulling reaches the case
 *   - nothing already on the case is replaced
 *   - the citizen sees the public label
 *
 * NOT anchored here: the three task scenarios ("the citizen answers what was
 * asked", "a task is answered once", "a deadline is visible before it
 * passes"). The task leg runs through openregister's portal task seam, which
 * no portaliq e2e exercises today and which a fresh CI instance does not
 * provision. They are covered at the PHPUnit level by
 * tests/Unit/Controller/PortalTaskProxyControllerTest.php — specifically
 * testASecondAnswerIsRefusedWithASentence and
 * testAClientAnswerRaisesTheCitizenWriteEvent. Naming those here rather than
 * writing a task fixture that cannot reach a seam is deliberate: a test that
 * cannot fail reports the same green as one that passed.
 *
 * Everything the run needs is seeded here through OpenRegister's own object
 * API as the dev admin, the way tests/e2e/portal-inbox.spec.ts does: a
 * portalCaseType carrying the flags and the two windows, one portalCase owned
 * by the signed-in citizen, and the portalPage contribution that puts the
 * citizen case block on a page. Nothing is assumed about demo data.
 *
 * Requires the seeded instance tests/e2e/ci-seed.sh provisions (the register,
 * the signing secret and the dev-login flag). Run manually with:
 *
 *     NEXTCLOUD_URL=http://localhost:8080 npx playwright test what-the-citizen
 *
 * @spec openspec/changes/what-the-citizen-may-write-on-their-own-case/specs/citizen-writes-on-their-own-case/spec.md
 */

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'

const PORTAL_PATH = '/apps/portaliq/portal'
const API_BASE = '/apps/portaliq/portal/api'
const OR_OBJECTS_BASE = '/apps/openregister/api/objects'

const CLOSED_REASON = 'De aanvraag is in behandeling genomen.'
const DOCUMENTS_CLOSED_REASON = 'De zaak neemt geen stukken meer aan.'
const PUBLIC_LABEL = 'Wij hebben uw aanvraag ontvangen'

/**
 * Create one object through OpenRegister's own object API as the dev admin.
 * This stands in for the case app having written the row server-side.
 */
async function seed(
	request: APIRequestContext,
	schema: string,
	data: Record<string, unknown>,
): Promise<string> {
	const admin = Buffer.from('admin:admin').toString('base64')
	const res = await request.post(`${OR_OBJECTS_BASE}/portaliq/${schema}`, {
		headers: { Authorization: `Basic ${admin}`, 'OCS-APIRequest': 'true' },
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

/**
 * The case type: two fields open to the client audience, an amendment window
 * and a document window, and the public words for the status.
 */
async function seedCaseType(
	request: APIRequestContext,
	openStatuses: string[],
	documentStatuses: string[],
): Promise<string> {
	return seed(request, 'portalCaseType', {
		title: `E2E zaaktype ${Date.now()}`,
		portalWritable: [
			{ field: 'omschrijving', audiences: ['client'] },
			{ field: 'toelichting', audiences: ['client'] },
		],
		portalAmendmentWindow: { openStatuses, closedReason: CLOSED_REASON },
		portalDocumentWindow: {
			openStatuses: documentStatuses,
			closedReason: DOCUMENTS_CLOSED_REASON,
		},
		portalStatusLabels: {
			ontvangen: {
				label: PUBLIC_LABEL,
				description: 'U hoort binnen acht weken van ons.',
			},
		},
	})
}

/**
 * The contribution that puts the citizen case block on a page for the client
 * audience, with the update action carrying the citizen write declaration.
 */
async function seedContribution(
	request: APIRequestContext,
	typeRegister: string,
): Promise<string> {
	return seed(request, 'portalPage', {
		label: 'Mijn zaken',
		audience: 'client',
		active: true,
		collections: [
			{
				id: 'cases',
				label: 'Mijn zaken',
				register: typeRegister,
				schema: 'portalCase',
				scopeField: 'subjectRef',
				columns: [
					{ field: 'reference', label: 'Zaaknummer' },
					{ field: 'omschrijving', label: 'Omschrijving' },
				],
			},
		],
		actions: [
			{
				id: 'amend-case',
				type: 'update',
				label: 'Mijn aanvraag wijzigen',
				register: typeRegister,
				schema: 'portalCase',
				scopeField: 'subjectRef',
				fields: ['omschrijving', 'toelichting'],
				citizenWrite: {
					typeField: 'caseType',
					typeRegister,
					typeSchema: 'portalCaseType',
					statusField: 'status',
					recordField: 'portalWrites',
				},
			},
		],
		pages: [
			{
				id: 'mijn-zaken',
				label: 'Mijn zaken',
				blocks: [
					{ type: 'collection', collection: 'cases' },
					{ type: 'citizenCase', collection: 'cases' },
				],
			},
		],
	})
}

/**
 * Mint a client dev session and seed it into the SPA's token slot before the
 * app boots, so the portal loads already signed in.
 */
async function loginAsCitizen(
	request: APIRequestContext,
	page: Page,
	subjectRef: string,
	organisation: string,
): Promise<void> {
	const res = await request.post(`${API_BASE}/session/dev-login`, {
		data: { subjectRef, audience: 'client', organisation },
	})
	expect(
		res.ok(),
		'dev-login must be enabled on the target instance (see tests/e2e/ci-seed.sh)',
	).toBeTruthy()
	const body = await res.json()
	const token = body.token as string
	expect(token).toBeTruthy()

	await page.addInitScript((t) => {
		window.localStorage.setItem('portaliq_token', t)
	}, token)
}

/**
 * Open the portal on the citizen's case: the page loads, the case row is
 * clicked, and the citizen case block renders it.
 */
async function openTheCase(page: Page, reference: string): Promise<void> {
	await page.goto(PORTAL_PATH)
	await page.waitForLoadState('domcontentloaded')
	await page
		.getByRole('button', { name: /Mijn zaken/ })
		.first()
		.click()
	await page.getByText(reference).first().click()
	await expect(page.getByTestId('citizen-case')).toBeVisible()
}

test.describe('what a citizen may write on their own case', () => {
	// @e2e citizen-writes-on-their-own-case::only-the-declared-fields-are-editable
	// @e2e citizen-writes-on-their-own-case::a-closed-field-says-why
	// @e2e citizen-writes-on-their-own-case::a-correction-lands-without-a-phone-call
	// @e2e citizen-writes-on-their-own-case::the-citizen-sees-the-public-label
	// @e2e citizen-writes-on-their-own-case::an-aanvulling-reaches-the-case
	// @e2e citizen-writes-on-their-own-case::nothing-already-on-the-case-is-replaced
	test('a citizen corrects an answer and adds the missing document', async ({
		page,
		request,
	}) => {
		const subjectRef = `e2e-client-${Date.now()}`
		const organisation = 'e2e-org'
		const reference = `Z-${Date.now()}`

		const caseType = await seedCaseType(
			request,
			['ontvangen', 'aanvullen'],
			['ontvangen', 'aanvullen'],
		)
		await seedContribution(request, 'portaliq')
		await seed(request, 'portalCase', {
			subjectRef,
			organisation,
			caseType,
			reference,
			status: 'ontvangen',
			omschrijving: 'Een dakkapel',
			toelichting: 'Aan de achterzijde',
		})

		await loginAsCitizen(request, page, subjectRef, organisation)
		await openTheCase(page, reference)

		// The public label the case app supplied, rendered as given.
		await expect(page.getByTestId('case-status')).toContainText(PUBLIC_LABEL)

		// The two flagged fields are editable …
		await expect(page.getByTestId('case-input-omschrijving')).toBeVisible()
		await expect(page.getByTestId('case-input-toelichting')).toBeVisible()

		// … and the case number, which carries no flag, is not: it is text with
		// a sentence beside it, never a control that fails on submit.
		await expect(page.getByTestId('case-input-reference')).toHaveCount(0)
		await expect(page.getByTestId('case-value-reference')).toHaveText(reference)
		await expect(page.getByTestId('case-reason-reference')).not.toHaveText('')

		// The correction lands.
		await page
			.getByTestId('case-input-omschrijving')
			.fill('Een dakkapel aan de achterzijde')
		await page.getByTestId('case-save').click()
		await expect(page.getByTestId('case-notice')).toContainText('opgeslagen')

		// It is on the case after a reload, so this is the stored answer and
		// not a screen that only looks saved.
		await page.reload()
		await page
			.getByRole('button', { name: /Mijn zaken/ })
			.first()
			.click()
		await page.getByText(reference).first().click()
		await expect(page.getByTestId('case-input-omschrijving')).toHaveValue(
			'Een dakkapel aan de achterzijde',
		)

		// The aanvulling reaches the case.
		await page.getByTestId('case-add-document').setInputFiles({
			name: 'aanvulling.pdf',
			mimeType: 'application/pdf',
			buffer: Buffer.from('%PDF-1.4 aanvulling'),
		})
		await expect(page.getByTestId('case-notice')).toContainText('aanvulling.pdf')
		await expect(page.getByTestId('case-document')).toHaveCount(1)

		// A second document of the SAME name adds rather than replaces: two
		// documents exist afterwards and the first keeps its name.
		await page.getByTestId('case-add-document').setInputFiles({
			name: 'aanvulling.pdf',
			mimeType: 'application/pdf',
			buffer: Buffer.from('%PDF-1.4 tweede aanvulling'),
		})
		await expect(page.getByTestId('case-document')).toHaveCount(2)
		await expect(page.getByTestId('case-document').first()).toHaveText(
			'aanvulling.pdf',
		)
	})

	// @e2e citizen-writes-on-their-own-case::the-window-closes
	test('the window closes and the case says why', async ({ page, request }) => {
		const subjectRef = `e2e-client-closed-${Date.now()}`
		const organisation = 'e2e-org'
		const reference = `Z-closed-${Date.now()}`

		// The windows are open only in `concept`; the case is past it.
		const caseType = await seedCaseType(request, ['concept'], ['concept'])
		await seedContribution(request, 'portaliq')
		await seed(request, 'portalCase', {
			subjectRef,
			organisation,
			caseType,
			reference,
			status: 'in_behandeling',
			omschrijving: 'Een uitbouw',
			toelichting: 'Aan de zijkant',
		})

		await loginAsCitizen(request, page, subjectRef, organisation)
		await openTheCase(page, reference)

		// The answers are read-only, the reason is shown, and there is no save
		// button to press at all.
		await expect(page.getByTestId('case-window-closed')).toHaveText(
			CLOSED_REASON,
		)
		await expect(page.getByTestId('case-input-omschrijving')).toHaveCount(0)
		await expect(page.getByTestId('case-value-omschrijving')).toHaveText(
			'Een uitbouw',
		)
		await expect(page.getByTestId('case-save')).toHaveCount(0)
		await expect(page.getByTestId('case-documents-closed')).toHaveText(
			DOCUMENTS_CLOSED_REASON,
		)
		await expect(page.getByTestId('case-add-document')).toHaveCount(0)
	})
})
