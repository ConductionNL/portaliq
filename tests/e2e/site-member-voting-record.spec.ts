/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * site-member-voting-record-and-confidential-papers T3 (REQ-SCR-005,
 * REQ-SCR-006): an anonymous visitor finds a council member on "Hoe stemden
 * de raadsleden", searches by name, opens one member and reads the record on
 * the PtStemgedrag board, with its figures, its filters and its pages.
 *
 * 🔴 THE COUNCIL IS FIXED, NOT WHATEVER DECIDIQ HOLDS TODAY. The list and the
 * record are answered through page.route, in the shapes
 * PublicRecordControllerTest and tests/public-records-board.spec.mjs pin
 * (`{entries}` and `{title, subtitle, summary, columns, rows, note}`), so the
 * counts below ("1 tot 5 van 48") are this spec's own numbers. What is NOT
 * stubbed is everything portaliq does: the page, its block configuration from
 * the Zuiddrecht site, the block, the table, the cards and the pager.
 *
 * 🔴 THE VISITOR HAS NO SESSION. Every context starts from an empty storage
 * state; a record that only rendered for a signed-in reader would fail here.
 *
 * Needs the Zuiddrecht example site installed on the instance
 * (`PORTALIQ_E2E_COUNCIL_PORTAL`, default `zuiddrecht`) and decidiq enabled,
 * since the page is `requiresApp: decidiq`. Written for the live pass
 * (decision 139):
 *
 *     PLAYWRIGHT_BASE_URL=http://localhost:8095 npx playwright test site-member-voting-record
 *
 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/specs/site-council-records/spec.md
 */
import type { Browser, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { BASE_URL } from './base-url.ts'
import { SITE_PATH } from './portal-nav.ts'

const PORTAL = process.env.PORTALIQ_E2E_COUNCIL_PORTAL || 'zuiddrecht'
const ROUTE = '/bestuur/hoe-stemden-de-raadsleden'
const LIST = '**/api/public-records/decidiq/memberVotingRecords'
const EMPTY_STATE = { cookies: [], origins: [] }

const COLUMNS = [
	{ key: 'date', label: 'Datum' },
	{ key: 'subject', label: 'Onderwerp' },
	{ key: 'choice', label: 'Stem' },
	{ key: 'result', label: 'Uitslag' },
]

const ENTRIES = [
	{ id: 'sanne-mulder', title: 'Sanne Mulder', subtitle: 'Raadslid, GroenLinks Zuiddrecht' },
	{ id: 'joost-van-dalen', title: 'Joost van Dalen', subtitle: 'Raadslid, Lokaal Zuiddrecht' },
	{ id: 'pieter-bos', title: 'Pieter Bos', subtitle: 'Fractievoorzitter, VVD Zuiddrecht' },
]

const SANNE = {
	title: 'Sanne Mulder',
	subtitle: 'Raadslid, GroenLinks Zuiddrecht',
	summary: [
		{ label: 'Deelname', value: '100%', detail: 'aan 1 stemming' },
		{ label: 'Voor', value: '1', detail: '' },
		{ label: 'Tegen', value: '0', detail: '' },
		{ label: 'Onthouden', value: '0', detail: '' },
	],
	columns: COLUMNS,
	rows: [
		{ date: '1 okt 2026', subject: 'Groen dak op het stadhuis', choice: 'Voor', result: 'Aangenomen' },
	],
	note: 'Alleen openbare stemmingen.',
}

const MEETINGS = ['1 okt 2026', '17 sep 2026', '3 sep 2026', '9 jul 2026']

/**
 * Joost van Dalen's 48 public votes over four meetings, 12 each.
 *
 * @return The record.
 */
function joost() {
	const rows = []
	for (let index = 0; index < 48; index++) {
		rows.push({
			date: MEETINGS[Math.floor(index / 12)],
			subject: `Voorstel ${index + 1}`,
			choice: index % 4 === 0 ? 'Tegen' : 'Voor',
			result: 'Aangenomen',
		})
	}
	return {
		title: 'Joost van Dalen',
		subtitle: 'Raadslid, Lokaal Zuiddrecht',
		summary: [
			{ label: 'Deelname', value: '100%', detail: 'aan 48 stemmingen' },
			{ label: 'Voor', value: '36', detail: '' },
			{ label: 'Tegen', value: '12', detail: '' },
			{ label: 'Onthouden', value: '0', detail: '' },
		],
		columns: COLUMNS,
		rows,
		note: 'Alleen openbare stemmingen.',
	}
}

const RECORDS: Record<string, unknown> = {
	'sanne-mulder': SANNE,
	'joost-van-dalen': joost(),
}

/**
 * A fresh anonymous page with decidiq's answers fixed.
 *
 * @param browser The browser.
 * @return The page.
 */
async function visitor(browser: Browser): Promise<Page> {
	const context = await browser.newContext({ baseURL: BASE_URL, storageState: EMPTY_STATE })
	const page = await context.newPage()
	await page.route(LIST, (route) => route.fulfill({ json: { entries: ENTRIES } }))
	await page.route(`${LIST}/*`, (route) => {
		const id = decodeURIComponent(new URL(route.request().url()).pathname.split('/').pop() || '')
		const record = RECORDS[id]
		return record
			? route.fulfill({ json: record })
			: route.fulfill({ status: 404, json: { error: 'not_found' } })
	})
	return page
}

/**
 * The site address of the council page, optionally on one record.
 *
 * @param record The record id, or ''.
 * @return The path.
 */
function councilPage(record = ''): string {
	const params = new URLSearchParams({ portal: PORTAL, route: ROUTE })
	if (record) {
		params.set('record', record)
	}
	return `${SITE_PATH}?${params.toString()}`
}

test.describe('site-member-voting-record', () => {
	// e2e: site-council-records::the-communications-officer-builds-the-council-page
	test('The communications officer builds the council page', async ({ browser }) => {
		const page = await visitor(browser)
		await page.goto(councilPage())
		const list = page.getByTestId('public-records-list')
		await expect(list).toBeVisible()
		for (const entry of ENTRIES) {
			await expect(list.getByRole('link', { name: entry.title })).toBeVisible()
			await expect(list).toContainText(entry.subtitle)
		}

		await list.getByLabel('Zoek een raadslid').fill('mulder')
		await expect(list.getByRole('link')).toHaveCount(1)
		await expect(list.getByRole('link', { name: 'Sanne Mulder' })).toBeVisible()
		await page.context().close()
	})

	// e2e: site-council-records::a-resident-checks-how-her-councillor-voted
	test('A resident checks how her councillor voted', async ({ browser }) => {
		const page = await visitor(browser)
		await page.goto(councilPage())
		await page.getByTestId('public-records-list').getByRole('link', { name: 'Sanne Mulder' }).click()
		await expect(page).toHaveURL(/record=sanne-mulder/)

		const record = page.getByTestId('public-record')
		await expect(record).toBeVisible()
		const side = page.getByTestId('public-record-side')
		for (const card of ['Deelname', 'Voor', 'Tegen', 'Onthouden']) {
			await expect(side).toContainText(card)
		}
		const row = record.getByRole('row').filter({ hasText: 'Groen dak op het stadhuis' })
		await expect(row).toHaveCount(1)
		await expect(row).toContainText('Voor')
		await expect(row).toContainText('Aangenomen')
		for (const header of ['Datum', 'Onderwerp', 'Stem', 'Uitslag']) {
			await expect(record.getByRole('columnheader', { name: header })).toBeVisible()
		}
		await expect(page.getByTestId('public-record-note')).toHaveText('Alleen openbare stemmingen.')
		await page.context().close()
	})

	// e2e: site-council-records::a-resident-narrows-a-voting-record-to-one-meeting
	test('A resident narrows a voting record to one meeting', async ({ browser }) => {
		const page = await visitor(browser)
		await page.goto(councilPage('joost-van-dalen'))
		const record = page.getByTestId('public-record')
		await expect(record.getByRole('heading', { name: 'Stemgedrag van Joost van Dalen' })).toBeVisible()

		const rows = record.getByRole('row').filter({ hasText: 'Voorstel' })
		await expect(rows).toHaveCount(5)
		await expect(rows.first()).toContainText('Voorstel 1')
		const pager = page.getByTestId('public-record-pager')
		await expect(pager).toContainText('1 tot 5 van 48')
		await expect(page.getByTestId('public-record-page-10')).toBeVisible()

		const side = page.getByTestId('public-record-side')
		await expect(side).toContainText('JD')
		await expect(side).toContainText('Lokaal Zuiddrecht')
		await expect(side).toContainText('36')
		await expect(page.getByTestId('public-record-back')).toHaveText('Alle raadsleden')

		// A later page first, so the filter is seen to start again at page one.
		await page.getByTestId('public-record-page-next').click()
		await expect(rows.first()).toContainText('Voorstel 6')

		await page.getByTestId('public-record-filter-date').selectOption('1 okt 2026')
		await page.getByTestId('public-record-filter-apply').click()
		await expect(page.getByTestId('public-record-count')).toContainText('12 stemmingen')
		await expect(rows).toHaveCount(5)
		await expect(rows.first()).toContainText('Voorstel 1')
		await expect(pager).toContainText('1 tot 5 van 12')
		for (const other of MEETINGS.slice(1)) {
			await expect(record.getByRole('row').filter({ hasText: other })).toHaveCount(0)
		}
		await page.context().close()
	})
})
