/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * site-nlds-widget-palette T8c (REQ-SNW-013): the language switch renders the
 * portal's own locales, and the language a visitor chooses reaches the
 * content: the page re-reads in that language, and a link followed from it
 * stays in that language.
 *
 * The fixture is this file's own: two routes on the seeded portal
 * (`open-tilburg`, locales `nl` and `en`, or PORTALIQ_E2E_PORTAL), each
 * published in Dutch and in English, created through OpenRegister's object
 * API as the Nextcloud admin and deleted again afterwards. The Dutch first
 * page places the `nlLanguageNav` widget and links to the second page. The
 * visitor reads anonymously in a fresh context. Nothing is stubbed.
 *
 * @spec openspec/changes/site-nlds-widget-palette/tasks.md#t8c
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, request, test } from '@playwright/test'
import { resolveBaseURL } from './base-url.ts'
import { SITE_PORTAL } from './portal-nav.ts'

const BASE = resolveBaseURL()
const SITE = `${BASE}/index.php/apps/portaliq/site`
const OBJECTS = `${BASE}/index.php/apps/openregister/api/objects/portaliq/page`

/** Nextcloud admin, as exported by the shared quality.yml Playwright step. */
const ADMIN_USER = process.env.ADMIN_USER ?? process.env.NC_ADMIN_USER ?? 'admin'
const ADMIN_PASS = process.env.ADMIN_PASSWORD ?? process.env.NC_ADMIN_PASS ?? 'admin'

/** This run's own routes, so a dead earlier run cannot collide with them. */
const STAMP = Date.now()
const FIRST = `/e2e-taal-${STAMP}`
const SECOND = `/e2e-taal-${STAMP}-verder`

/** The titles each route carries per language. */
const TITLES = {
	nl: { first: `Afval scheiden ${STAMP}`, second: `Grofvuil ${STAMP}` },
	en: { first: `Separating waste ${STAMP}`, second: `Bulky waste ${STAMP}` },
}

/** The ids of the pages created for this run. */
const pageIds: string[] = []

/**
 * An admin API context, Basic auth with the OCS header so Nextcloud skips
 * its CSRF check. Seeding and cleanup only; the browser never holds it.
 *
 * @return the context; the caller disposes it
 */
async function adminApi(): Promise<APIRequestContext> {
	const basic = Buffer.from(`${ADMIN_USER}:${ADMIN_PASS}`).toString('base64')
	return await request.newContext({
		baseURL: BASE,
		extraHTTPHeaders: {
			'OCS-APIRequest': 'true',
			Authorization: `Basic ${basic}`,
			'Content-Type': 'application/json',
		},
	})
}

/**
 * One published page of this run: the language switch, then a markdown body.
 *
 * @param route the route
 * @param locale the language it is written in
 * @param title the title
 * @param markdown the body text
 * @return the page record
 */
function pageRecord(
	route: string,
	locale: 'nl' | 'en',
	title: string,
	markdown: string,
): object {
	return {
		title,
		route,
		portal: SITE_PORTAL,
		status: 'published',
		locale,
		body: {
			type: 'grid',
			widgets: [
				{
					id: 'taal',
					widgetKey: 'nlLanguageNav',
					slot: 'body',
					gridX: 0,
					gridY: 0,
					gridWidth: 12,
					gridHeight: 1,
					// No `props`: the switch has none, and OpenRegister reads an
					// empty `{}` as an empty list and refuses it as no object.
				},
				{
					id: 'tekst',
					widgetKey: 'markdown',
					slot: 'body',
					gridX: 0,
					gridY: 1,
					gridWidth: 12,
					gridHeight: 3,
					props: { markdown },
				},
			],
		},
	}
}

test.beforeAll(async () => {
	const api = await adminApi()
	const records = [
		pageRecord(
			FIRST,
			'nl',
			TITLES.nl.first,
			`Lees verder bij [grofvuil](${SECOND}).`,
		),
		pageRecord(
			FIRST,
			'en',
			TITLES.en.first,
			`Read on at [bulky waste](${SECOND}).`,
		),
		pageRecord(
			SECOND,
			'nl',
			TITLES.nl.second,
			'Grofvuil halen wij op afspraak op.',
		),
		pageRecord(
			SECOND,
			'en',
			TITLES.en.second,
			'We collect bulky waste by appointment.',
		),
	]
	for (const record of records) {
		const created = await api.post(OBJECTS, { data: record })
		expect(created.ok(), `creating a page failed: ${created.status()}`).toBe(
			true,
		)
		const row = await created.json()
		const id = row['@self']?.id ?? row.id
		expect(id, 'a created page must report an id').toBeTruthy()
		pageIds.push(id)
	}

	await api.dispose()
})

test.afterAll(async () => {
	const api = await adminApi()
	for (const id of pageIds) {
		await api.delete(`${OBJECTS}/${id}`)
	}

	await api.dispose()
})

test('the chosen language reaches the page and stays on a followed link', async ({
	page,
}) => {
	// @e2e portaliq-cms::a-visitor-reads-the-portal-in-english
	await page.goto(`${SITE}?portal=${SITE_PORTAL}&route=${FIRST}`)
	await expect(page.getByTestId('page-title')).toHaveText(TITLES.nl.first)

	// The switch offers the portal's two locales, Dutch in effect.
	const nav = page.getByTestId('nl-language-nav')
	await expect(nav).toBeVisible()
	await expect(nav.getByRole('link')).toHaveCount(2)
	await expect(page.getByTestId('nl-language-nav-nl')).toHaveAttribute(
		'aria-current',
		'true',
	)

	// Choosing English re-reads the page in English, and the address says so.
	await page.getByTestId('nl-language-nav-en').click()
	await expect(page).toHaveURL(/[?&]lang=en(&|$)/)
	await expect(page.getByTestId('page-title')).toHaveText(TITLES.en.first)
	await expect(page.getByTestId('nl-language-nav-en')).toHaveAttribute(
		'aria-current',
		'true',
	)

	// A link followed from the English page opens the next page in English.
	await page.getByRole('link', { name: 'bulky waste' }).click()
	await expect(page.getByTestId('page-title')).toHaveText(TITLES.en.second)
	await expect(page).toHaveURL(/[?&]lang=en(&|$)/)
})
