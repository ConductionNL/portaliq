/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * operate-availability-report: an administrator opens Reports, chooses
 * Availability and reads a portal's last twelve months, its outages and how
 * it is measured, and can download the CSV.
 *
 * Runs on the seed of seed-cms.sh (the portal open-tilburg). Seeds one daily
 * record and one outage in last month through the object API, and removes
 * them again. The probe job, the gap arithmetic and retention are pinned by
 * AvailabilityProbeJobTest and AvailabilityRollupTest.
 */

import { expect, test } from '@playwright/test'
import { ADMIN_HEADERS, APP, ENABLED, login, OR_OBJECTS } from './lib/traffic.ts'

/**
 * The tenth of last month, as YYYY-MM-DD.
 *
 * @return The date.
 */
function lastMonthsTenth(): string {
	const now = new Date()
	const day = new Date(Date.UTC(now.getUTCFullYear(), now.getUTCMonth() - 1, 10))
	return day.toISOString().slice(0, 10)
}

test.describe('operate-availability-report', () => {
	const created: Array<{ schema: string; id: string }> = []
	const date = lastMonthsTenth()

	test.beforeAll(async ({ request }) => {
		for (const [schema, data] of [
			[
				'portalAvailabilityDaily',
				{
					portal: ENABLED,
					date,
					intervals: 288,
					available: 276,
					degraded: 0,
					down: 12,
					noCheck: 12,
				},
			],
			[
				'portalAvailabilityOutage',
				{
					portal: ENABLED,
					startedAt: `${date}T03:00:00+00:00`,
					endedAt: `${date}T04:00:00+00:00`,
					durationMinutes: 60,
					cause: 'no-check',
				},
			],
		] as const) {
			const res = await request.post(`${OR_OBJECTS}/${schema}`, {
				headers: ADMIN_HEADERS,
				data,
			})
			expect(res.ok()).toBeTruthy()
			const body = await res.json()
			created.push({
				schema,
				id: String(body.id ?? body.uuid ?? body['@self']?.id ?? ''),
			})
		}
	})

	test.afterAll(async ({ request }) => {
		for (const { schema, id } of created) {
			if (id !== '') {
				await request.delete(`${OR_OBJECTS}/${schema}/${id}`, {
					headers: ADMIN_HEADERS,
				})
			}
		}
	})

	// @e2e portal-availability::an-administrator-prepares-a-service-level-review
	test('an administrator reads twelve months, the outages and how it is measured', async ({
		page,
	}) => {
		await login(page)
		await page.goto(`${APP}/reports`)
		await page.getByText('Availability', { exact: true }).first().click()
		await expect(page).toHaveURL(/\/availability$/)

		const select = page.getByTestId('availability-portal-select')
		await select.click()
		// "Open Tilburg" by its whole name: the seed also has "Extern Tilburg"
		// and "Tilburg en Venray samen", and /Tilburg/ picked whichever the
		// list showed first, a portal with no measured day.
		await page
			.getByRole('option')
			.filter({ hasText: /^\s*Open Tilburg\s*$/ })
			.click()

		const months = page.getByTestId('availability-months')
		await expect(months.locator('tbody tr')).toHaveCount(12)
		await expect(months).toContainText('95.83%')
		await expect(page.getByTestId('availability-outages')).toContainText(
			'No check ran',
		)
		await expect(page.getByTestId('availability-outages')).toContainText(
			'60 minutes',
		)
		await expect(page.getByTestId('availability-how')).toContainText(
			'An interval in which no check ran counts as down.',
		)

		const download = page.getByTestId('availability-download')
		await expect(download).toHaveAttribute(
			'href',
			new RegExp(`/api/availability/${ENABLED}/export`),
		)
	})
})
